#!/usr/bin/env bash
# =============================================================================
#  Переход портала на новую версию PHP (режим native; Debian, Ubuntu, AlmaLinux, Rocky Linux, RHEL).
#
#    sudo docportal php-upgrade [--to 8.4] [--yes] [--keep-old]
#    sudo docportal php-upgrade --check        — только показать, что будет сделано
#
#  Ставит пакеты новой версии (из ОС, а если в ней старее — из deb.sury.org / ppa:ondrej/php), переносит
#  настройки PHP и пула PHP-FPM портала, прогревает кэш новой версией, переключает nginx на её PHP-FPM, проверяет
#  /health. Если портал не ответил — всё возвращается на прежнюю версию. Прежний PHP-FPM потом останавливается
#  (если им больше никто не пользуется); пакеты прежней версии остаются — для возврата.
#  На AlmaLinux, Rocky и RHEL PHP один на сервер (модуль dnf): поток модуля переключается (из AppStream, а если в нём
#  нет нужной версии — из репозитория Remi), портал проверяется на новой версии, при неудаче поток возвращается.
#  Зачем: Symfony 8, на которую портал перейдёт после выхода Symfony 8.4 LTS, требует PHP 8.4+ (РУКОВОДСТВО, 8.4).
# =============================================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/common.sh
. "${SCRIPT_DIR}/common.sh"

usage() {
    cat <<EOF
Переход портала «${APP_TITLE}» на новую версию PHP (режим native: Debian, Ubuntu, AlmaLinux, Rocky, RHEL).

Использование: sudo ${APP_ID} php-upgrade [параметры]
  --to X.Y      Версия PHP (по умолчанию — ${PHP_RECOMMENDED_VERSION} или новее из репозиториев ОС); с ним же —
                возврат на прежнюю версию.
  --check       Только показать текущую и новую версию и откуда возьмутся пакеты — ничего не менять.
  --keep-old    Не останавливать прежний PHP-FPM после перехода.
  --yes, -y     Не спрашивать подтверждения.
EOF
}

TARGET=""
EXPLICIT="0"
CHECK_ONLY="0"
KEEP_OLD="0"
ASSUME_YES="0"
while [[ $# -gt 0 ]]; do
    case "$1" in
        --to) TARGET="${2:-}"; EXPLICIT="1"; shift; [[ $# -gt 0 ]] && shift ;;
        --check) CHECK_ONLY="1"; shift ;;
        --keep-old) KEEP_OLD="1"; shift ;;
        --yes|-y) ASSUME_YES="1"; shift ;;
        --help|-h) usage; exit 0 ;;
        *) usage; die "Неизвестный параметр: $1" ;;
    esac
done
if [[ -n "${TARGET}" ]] && { [[ ! "${TARGET}" =~ ^[0-9]+\.[0-9]+$ ]] || ! version_ge "${TARGET}" "${PHP_MIN_VERSION}"; }; then
    die "--to: укажите версию как ${PHP_RECOMMENDED_VERSION} (не ниже ${PHP_MIN_VERSION})."
fi

require_root "$@"
if [[ "${CHECK_ONLY}" == "1" ]]; then LOG_FILE="/dev/null"; else log_init php-upgrade "$@"; fi
load_conf || die "Портал не установлен: не найден ${CONF_FILE}."
[[ "${INSTALL_MODE}" == "native" ]] || die "В режиме Docker версию PHP задаёт образ приложения (PHP_IMAGE в ${APP_DIR}/docker/.env, по умолчанию php:8.4-fpm-alpine) — переход не нужен."
detect_os

# --- AlmaLinux, Rocky, RHEL: поток модуля php в dnf ------------------------------------------------------
# PHP на сервере один: пакеты заменяются на месте (dnf module switch-to), служба и сокет PHP-FPM те же. Работающий
# PHP-FPM до перезапуска продолжает обслуживать сайт прежней версией; после перезапуска портал проверяется, и при
# ошибке поток модуля возвращается.
rhel_streams() { dnf -q module list php 2>/dev/null | awk '$1 == "php" {print $2}' | sort -u; }
rhel_enabled_stream() { dnf -q module list --enabled php 2>/dev/null | awk '$1 == "php" {print $2; exit}'; }
RHEL_PHP=$(command -v php 2>/dev/null || echo /usr/bin/php)
rhel_php_version() { "${RHEL_PHP}" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null; }
# Поток для версии: из AppStream (8.4), иначе из Remi (remi-8.4); без --to — рекомендуемая, а если её нет —
# самая новая не ниже рекомендуемой.
rhel_find_stream() {
    local streams v
    streams=$(rhel_streams)
    if [[ -n "${TARGET}" ]]; then
        if grep -qx "${TARGET}" <<<"${streams}"; then printf '%s' "${TARGET}"; return 0; fi
        if grep -qx "remi-${TARGET}" <<<"${streams}"; then printf 'remi-%s' "${TARGET}"; return 0; fi
        return 1
    fi
    if grep -Eqx "(remi-)?${PHP_RECOMMENDED_VERSION//./\\.}" <<<"${streams}"; then
        TARGET="${PHP_RECOMMENDED_VERSION}"
        rhel_find_stream
        return
    fi
    v=$(sed 's/^remi-//' <<<"${streams}" | grep -E '^[0-9]+\.[0-9]+$' | sort -V | tail -n1 || true)
    [[ -n "${v}" ]] && version_ge "${v}" "${PHP_RECOMMENDED_VERSION}" || return 1
    TARGET="${v}"
    rhel_find_stream
}
rhel_add_remi() {
    local major
    major=$(cut -d. -f1 <<<"${OS_VERSION}")
    [[ "${major}" =~ ^(8|9|10)$ ]] || return 1
    rpm -q remi-release >/dev/null 2>&1 && return 0
    pkg_install epel-release || true
    timeout -k 30 900 dnf install -y -q "https://rpms.remirepo.net/enterprise/remi-release-${major}.rpm" </dev/null >>"${LOG_FILE}" 2>&1 9>&-
}
rhel_switch() {
    if timeout -k 60 3600 dnf module switch-to -y "php:$1" </dev/null >>"${LOG_FILE}" 2>&1 9>&-; then
        return 0
    fi
    # dnf без switch-to (старые RHEL 8): сброс, новый поток и синхронизация пакетов.
    dnf module reset -y php </dev/null >>"${LOG_FILE}" 2>&1 9>&- || return 1
    dnf module enable -y "php:$1" </dev/null >>"${LOG_FILE}" 2>&1 9>&- || return 1
    timeout -k 60 3600 dnf distro-sync -y 'php*' </dev/null >>"${LOG_FILE}" 2>&1 9>&-
}
rhel_console() { runuser -u "${SERVICE_USER}" -- "${RHEL_PHP}" -d memory_limit=512M "${APP_DIR}/current/bin/console" "$@" --env=prod --no-interaction; }
RHEL_OLD_STREAM=""
RHEL_OLD_VERSION=""
rhel_rollback() {
    error "Возврат на PHP ${RHEL_OLD_VERSION} (php:${RHEL_OLD_STREAM})…"
    rhel_switch "${RHEL_OLD_STREAM}" || warn "Модуль php не вернулся на ${RHEL_OLD_STREAM} — проверьте: dnf module list php; dnf module switch-to php:${RHEL_OLD_STREAM}"
    svc restart "${PHP_FPM_SERVICE:-php-fpm}" >>"${LOG_FILE}" 2>&1 || true
    rhel_console cache:warmup >>"${LOG_FILE}" 2>&1 || true
    PHP_VERSION="${RHEL_OLD_VERSION}"
    save_conf
}
# Ошибка после начала замены пакетов: возврат прежнего потока и выход.
rhel_fail() {
    trap - INT TERM
    rhel_rollback
    die "$1 Портал работает на PHP ${RHEL_OLD_VERSION}. Подробности — ${LOG_FILE}."
}
rhel_upgrade() {
    local stream new_v body missing
    RHEL_OLD_STREAM=$(rhel_enabled_stream || true)
    RHEL_OLD_VERSION="${PHP_VERSION:-}"
    if [[ "${CHECK_ONLY}" == "1" ]]; then
        printf 'Сейчас: PHP %s (модуль dnf php:%s).\n' "${RHEL_OLD_VERSION:-?}" "${RHEL_OLD_STREAM:-?}"
        if stream=$(rhel_find_stream); then
            if [[ "${stream#remi-}" == "${RHEL_OLD_VERSION}" ]]; then printf 'Переход не нужен: портал уже на PHP %s.\n' "${RHEL_OLD_VERSION}"; exit 0; fi
            printf 'Будет: PHP %s (модуль php:%s).\n' "${stream#remi-}" "${stream}"
        else
            printf 'Будет: PHP %s из репозитория Remi (в подключённых репозиториях его нет; репозиторий подключится).\n' "${TARGET:-${PHP_RECOMMENDED_VERSION}}"
        fi
        printf 'Запустить переход: sudo %s php-upgrade\n' "${APP_ID}"
        exit 0
    fi
    [[ -n "${RHEL_OLD_STREAM}" ]] || die "Не удалось определить включённый модуль php в dnf (dnf module list --enabled php)."
    [[ -n "${RHEL_OLD_VERSION}" ]] || die "В ${CONF_FILE} не указана версия PHP (PHP_VERSION)."
    acquire_lock 0
    if ! stream=$(rhel_find_stream); then
        info "В подключённых репозиториях нет PHP ${TARGET:-${PHP_RECOMMENDED_VERSION}+} — подключается репозиторий Remi…"
        rhel_add_remi || die "Не удалось подключить репозиторий Remi (rpms.remirepo.net). Портал продолжает работать на PHP ${RHEL_OLD_VERSION}."
        stream=$(rhel_find_stream) || die "PHP ${TARGET:-${PHP_RECOMMENDED_VERSION}+} нет и в репозитории Remi для «${PRETTY_NAME:-$OS_ID $OS_VERSION}». Портал продолжает работать на PHP ${RHEL_OLD_VERSION}."
    fi
    new_v="${stream#remi-}"
    if [[ "${RHEL_OLD_STREAM}" == "${stream}" || "${new_v}" == "${RHEL_OLD_VERSION}" ]] || { [[ "${EXPLICIT}" != "1" ]] && version_ge "${RHEL_OLD_VERSION}" "${new_v}"; }; then
        ok "Портал уже работает на PHP ${RHEL_OLD_VERSION} — переход не нужен."
        exit 0
    fi
    printf '\nПереход портала с PHP %s (php:%s) на PHP %s (php:%s). PHP на сервере один: пакеты заменяются, PHP-FPM перезапускается.\n' "${RHEL_OLD_VERSION}" "${RHEL_OLD_STREAM}" "${new_v}" "${stream}"
    printf 'Если после перезапуска портал не ответит — модуль php вернётся на %s.\n\n' "${RHEL_OLD_STREAM}"
    confirm "Продолжить?" || die "Отменено."

    step "PHP ${new_v}: пакеты (модуль php:${stream})"
    # Прерывание посреди замены пакетов — возврат прежнего потока, а не наполовину обновлённый PHP.
    trap 'rhel_fail "Переход прерван."' INT TERM
    rhel_switch "${stream}" || rhel_fail "Пакеты PHP ${new_v} не установились."
    pkg_install php-fpm php-cli php-mysqlnd php-xml php-mbstring php-intl php-pecl-zip php-gd php-opcache php-process php-json php-ldap \
        || rhel_fail "Не все пакеты PHP ${new_v} установились."
    [[ "$(rhel_php_version || true)" == "${new_v}" ]] || rhel_fail "После переключения ${RHEL_PHP} — не версии ${new_v}."
    missing=$(php_missing_extensions "${RHEL_PHP}" | tr "\n" " ")
    [[ -z "${missing// /}" ]] || rhel_fail "У PHP ${new_v} не хватает расширений: ${missing}."

    step "Проверка портала на PHP ${new_v}"
    rhel_console cache:warmup >>"${LOG_FILE}" 2>&1 || rhel_fail "Портал не запускается на PHP ${new_v} (cache:warmup)."
    svc restart "${PHP_FPM_SERVICE:-php-fpm}" || rhel_fail "PHP-FPM ${new_v} не запустился."
    body=$(wait_for_health 20 3) || rhel_fail "Портал не отвечает на PHP ${new_v} (${body:-нет ответа})."
    trap - INT TERM
    PHP_VERSION="${new_v}"
    save_conf
    install_cli_wrapper || true
    ok "Портал работает на PHP ${new_v}: ${body}"
    info "Возврат при необходимости: sudo ${APP_ID} php-upgrade --to ${RHEL_OLD_VERSION} (модуль php:${RHEL_OLD_STREAM})."
    printf '\n%sГотово: портал работает на PHP %s.%s Журнал: %s\n' "${C_GREEN}${C_BOLD}" "${new_v}" "${C_RESET}" "${LOG_FILE}"
    print_warnings_summary
    exit 0
}
if [[ "${OS_FAMILY}" == "rhel" ]]; then
    rhel_upgrade
fi
[[ "${OS_FAMILY}" == "debian" ]] || die "Переход поддерживается на Debian, Ubuntu, AlmaLinux, Rocky и RHEL."
OLD="${PHP_VERSION:-}"
[[ -n "${OLD}" ]] || die "В ${CONF_FILE} не указана версия PHP (PHP_VERSION)."
OLD_SOCK="${PHP_FPM_SOCK:-/run/php/php${OLD}-fpm.sock}"
OLD_SERVICE="${PHP_FPM_SERVICE:-php${OLD}-fpm}"

# --- Какая версия и откуда ------------------------------------------------------------------
REPO_NOTE="репозиториев ОС"
find_target() {
    if [[ -n "${TARGET}" ]]; then
        apt_has_php "${TARGET}" && return 0
    else
        TARGET=$(detect_apt_php_version "${PHP_RECOMMENDED_VERSION}" || true)
        [[ -n "${TARGET}" ]] && return 0
    fi
    return 1
}
if [[ "${CHECK_ONLY}" == "1" ]]; then
    printf 'Сейчас: PHP %s (%s).\n' "${OLD}" "${OLD_SERVICE}"
    if find_target; then
        php_repo_present && REPO_NOTE="репозиториев ОС или deb.sury.org / ppa:ondrej/php"
        if [[ "${OLD}" == "${TARGET}" ]] || { [[ "${EXPLICIT}" != "1" ]] && version_ge "${OLD}" "${TARGET}"; }; then printf 'Переход не нужен: портал уже на PHP %s.\n' "${OLD}"; exit 0; fi
        printf 'Будет: PHP %s из %s.\n' "${TARGET}" "${REPO_NOTE}"
    else
        printf 'Будет: PHP %s из deb.sury.org / ppa:ondrej/php (в репозиториях ОС его нет; репозиторий подключится).\n' "${TARGET:-${PHP_RECOMMENDED_VERSION}}"
    fi
    printf 'Запустить переход: sudo %s php-upgrade\n' "${APP_ID}"
    exit 0
fi

acquire_lock 0
apt_update_once
if ! find_target; then
    info "В репозиториях ОС нет PHP ${TARGET:-${PHP_RECOMMENDED_VERSION}+} — подключается deb.sury.org / ppa:ondrej/php…"
    add_php_repo || die "Не удалось подключить deb.sury.org / ppa:ondrej/php (нет доступа к репозиторию?). Портал продолжает работать на PHP ${OLD}."
    REPO_NOTE="deb.sury.org / ppa:ondrej/php"
    if ! find_target; then
        # Репозиторий недоступен (списки пакетов не загрузились) или в нём нет нужной версии — не оставляем его.
        [[ "${ADDED_PHP_REPO:-0}" == "1" ]] && remove_php_repo
        die "PHP ${TARGET:-${PHP_RECOMMENDED_VERSION}+} нет и в стороннем репозитории для «${PRETTY_NAME:-$OS_ID $OS_VERSION}» (или к нему нет доступа). Портал продолжает работать на PHP ${OLD}."
    fi
fi
if [[ "${OLD}" == "${TARGET}" ]] || { [[ "${EXPLICIT}" != "1" ]] && version_ge "${OLD}" "${TARGET}"; }; then
    ok "Портал уже работает на PHP ${OLD} — переход не нужен."
    exit 0
fi
NEW_SOCK="/run/php/php${TARGET}-fpm.sock"
NEW_SERVICE="php${TARGET}-fpm"

printf '\nПереход портала с PHP %s на PHP %s (пакеты — из %s): настройки PHP и пула, кэш, nginx, проверка.\n' "${OLD}" "${TARGET}" "${REPO_NOTE}"
printf 'Сайт переключается без остановки; если после переключения портал не ответит — всё вернётся на PHP %s.\n\n' "${OLD}"
confirm "Продолжить?" || die "Отменено."

# --- 1. Пакеты и настройки новой версии -----------------------------------------------------------
# Работал ли PHP-FPM новой версии до перехода (им могут пользоваться другие сайты): при откате его не останавливать.
# Проверка — до установки пакетов: apt сам запускает только что установленную службу.
NEW_WAS_ACTIVE="0"
svc status "${NEW_SERVICE}" && NEW_WAS_ACTIVE="1"
step "PHP ${TARGET}: пакеты"
install_php_packages "${TARGET}"
NEW_BIN="/usr/bin/php${TARGET}"
php_version_ok "${NEW_BIN}" || die "PHP ${TARGET} не запускается (${NEW_BIN})."
missing_ext=$(php_missing_extensions "${NEW_BIN}" | tr "\n" " ")
[[ -z "${missing_ext// /}" ]] || die "У PHP ${TARGET} не хватает расширений: ${missing_ext}— портал остаётся на PHP ${OLD}."

step "PHP ${TARGET}: настройки"
for kind in fpm cli; do
    src="/etc/php/${OLD}/${kind}/conf.d/90-${APP_ID}.ini"
    dst_dir="/etc/php/${TARGET}/${kind}/conf.d"
    if [[ -f "${src}" && -d "${dst_dir}" ]]; then
        sed "s/^; Настройки портала.*/; Настройки портала «${APP_TITLE}» (перенесены с PHP ${OLD} командой php-upgrade)/" "${src}" > "${dst_dir}/90-${APP_ID}.ini"
    fi
done
PHP_VERSION="${TARGET}" configure_fpm_pool "/etc/php/${TARGET}/fpm/pool.d"
svc enable "${NEW_SERVICE}" || die "Не удалось запустить ${NEW_SERVICE}."
svc restart "${NEW_SERVICE}" || die "Не удалось перезапустить ${NEW_SERVICE}."
for _ in $(seq 1 20); do [[ -S "${NEW_SOCK}" ]] && break; sleep 1; done
[[ -S "${NEW_SOCK}" ]] || die "${NEW_SERVICE} запущен, но нет сокета ${NEW_SOCK} — портал остаётся на PHP ${OLD}."
ok "PHP-FPM ${TARGET} работает (${NEW_SOCK})."

step "Проверка портала на PHP ${TARGET}"
console_new() { runuser -u "${SERVICE_USER}" -- "${NEW_BIN}" -d memory_limit=512M "${APP_DIR}/current/bin/console" "$@" --env=prod --no-interaction; }
# Кэш новой версией PHP: на PHP 8.4+ у контейнера Symfony свой класс — прежний PHP-FPM продолжает работать со своим.
console_new cache:warmup >>"${LOG_FILE}" 2>&1 || die "Портал не запускается на PHP ${TARGET} (cache:warmup — см. ${LOG_FILE}). Он остаётся на PHP ${OLD}."
console_new app:check >>"${LOG_FILE}" 2>&1 || warn "app:check на PHP ${TARGET} сообщил о проблемах (см. ${LOG_FILE})."

# --- 2. Переключение nginx -----------------------------------------------------------------------------
step "Переключение nginx на PHP ${TARGET}"
BACKUP_DIR_NGINX="/var/lib/${APP_ID}/php-upgrade-$(date '+%Y%m%d%H%M%S')"
mkdir -p "${BACKUP_DIR_NGINX}"
# Только файлы портала (сайт, сниппеты «Видео», Kerberos): другие сайты сервера на прежнем PHP-FPM не трогаются.
NGINX_FILES=()
while IFS= read -r f; do
    [[ -L "${f}" || "${f}" != *"${APP_ID}"* ]] || NGINX_FILES+=("${f}")
done < <(grep -rlF "${OLD_SOCK}" /etc/nginx 2>/dev/null | sort -u)
[[ ${#NGINX_FILES[@]} -gt 0 ]] || die "В настройках nginx не найден сокет ${OLD_SOCK} — переключать нечего. Портал остаётся на PHP ${OLD}."
for f in "${NGINX_FILES[@]}"; do
    { mkdir -p "${BACKUP_DIR_NGINX}$(dirname "${f}")" && cp -a "${f}" "${BACKUP_DIR_NGINX}${f}"; } \
        || die "Не удалось сохранить копию ${f} — переключение отменено, портал остаётся на PHP ${OLD}."
done
restore_nginx() {
    local f
    for f in "${NGINX_FILES[@]}"; do cp -a "${BACKUP_DIR_NGINX}${f}" "${f}"; done
    nginx -t >>"${LOG_FILE}" 2>&1 && svc reload nginx >>"${LOG_FILE}" 2>&1 || true
}
rollback_switch() {
    restore_nginx
    PHP_VERSION="${OLD}"; PHP_FPM_SERVICE="${OLD_SERVICE}"; PHP_FPM_SOCK="${OLD_SOCK}"
    save_conf
    update-alternatives --set php "/usr/bin/php${OLD}" >>"${LOG_FILE}" 2>&1 || true
}
# Прерывание (Ctrl+C) посреди переключения — возврат на прежнюю версию, а не наполовину переключённый сайт.
trap 'trap - INT TERM; rollback_switch; die "Переход прерван — портал работает на PHP ${OLD}."' INT TERM
for f in "${NGINX_FILES[@]}"; do
    sed -i "s|${OLD_SOCK}|${NEW_SOCK}|g" "${f}"
done
if ! nginx -t >>"${LOG_FILE}" 2>&1; then
    trap - INT TERM
    restore_nginx
    die "Конфигурация nginx с PHP ${TARGET} не прошла проверку (nginx -t) — возвращена прежняя. Портал остаётся на PHP ${OLD}."
fi
svc reload nginx || { trap - INT TERM; restore_nginx; die "nginx не перезагрузился — возвращена прежняя конфигурация (PHP ${OLD})."; }

PHP_VERSION="${TARGET}"; PHP_FPM_SERVICE="${NEW_SERVICE}"; PHP_FPM_SOCK="${NEW_SOCK}"
save_conf
update-alternatives --set php "${NEW_BIN}" >>"${LOG_FILE}" 2>&1 || true

if ! body=$(wait_for_health 20 3); then
    error "Портал не отвечает на PHP ${TARGET} (${body:-нет ответа}) — возврат на PHP ${OLD}."
    trap - INT TERM
    rollback_switch
    if [[ "${NEW_WAS_ACTIVE}" != "1" ]]; then
        svc stop "${NEW_SERVICE}" >>"${LOG_FILE}" 2>&1 || true
        svc disable "${NEW_SERVICE}" >>"${LOG_FILE}" 2>&1 || true
    fi
    die "Переход не удался, портал работает на PHP ${OLD}. Подробности — ${LOG_FILE}."
fi
trap - INT TERM
ok "Портал работает на PHP ${TARGET}: ${body}"

# --- 3. Прежняя версия -------------------------------------------------------------------------------
# Команда управления и задания cron берут версию PHP из ${CONF_FILE} — они уже на новой.
install_cli_wrapper || true
if [[ "${KEEP_OLD}" != "1" ]] && ! grep -rqF "${OLD_SOCK}" /etc/nginx 2>/dev/null; then
    svc stop "${OLD_SERVICE}" >>"${LOG_FILE}" 2>&1 || true
    svc disable "${OLD_SERVICE}" >>"${LOG_FILE}" 2>&1 || true
    info "${OLD_SERVICE} остановлен; пакеты PHP ${OLD} оставлены (удалить: apt purge 'php${OLD}-*')."
else
    info "${OLD_SERVICE} не останавливался (--keep-old или им пользуются другие сайты nginx)."
fi
info "Возврат на PHP ${OLD} при необходимости: sudo ${APP_ID} php-upgrade --to ${OLD} (копия прежних настроек nginx — ${BACKUP_DIR_NGINX})."
printf '\n%sГотово: портал работает на PHP %s.%s Журнал: %s\n' "${C_GREEN}${C_BOLD}" "${TARGET}" "${C_RESET}" "${LOG_FILE}"
print_warnings_summary
exit 0
