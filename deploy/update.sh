#!/usr/bin/env bash
# =============================================================================
#  Обновление портала документации до новой версии одной командой.
#
#  Запускается из каталога НОВОЙ версии:   sudo ./deploy/update.sh
#  или с указанием источника:              sudo docportal update --archive docportal-1.1.0.tar.gz
#
#  Порядок: резервная копия → новый релиз рядом с текущим → миграции → переключение →
#  проверка работоспособности; при ошибке — автоматический откат на предыдущий релиз.
# =============================================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/common.sh
. "${SCRIPT_DIR}/common.sh"

SOURCE_DIR=""
ARCHIVE=""
KEEP_RELEASES=3
ASSUME_YES="0"
NO_BACKUP="0"
NO_DB_ROLLBACK="0"
CREATED_TMP=""
NEW_RELEASE=""
PREV_RELEASE=""
BACKUP_FILE=""
SWITCHED="0"
MIGRATED="0"

usage() {
    cat <<EOF
Обновление портала «${APP_TITLE}».

Использование: sudo $0 [параметры]
  --source DIR       Каталог с новой версией (по умолчанию — каталог, где лежит скрипт).
  --archive FILE     Архив новой версии (.tar.gz/.zip).
  --keep N           Сколько предыдущих релизов хранить (по умолчанию ${KEEP_RELEASES}).
  --no-backup        Не делать резервную копию перед обновлением (не рекомендуется).
  --no-db-rollback   При откате не восстанавливать базу данных из резервной копии.
  --yes, -y          Не спрашивать подтверждения.
  --help             Справка.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --source) SOURCE_DIR="${2:-}"; shift 2 ;;
        --archive) ARCHIVE="${2:-}"; shift 2 ;;
        --keep) KEEP_RELEASES="${2:-}"; shift 2 ;;
        --no-backup) NO_BACKUP="1"; shift ;;
        --no-db-rollback) NO_DB_ROLLBACK="1"; shift ;;
        --yes|-y) ASSUME_YES="1"; shift ;;
        --help|-h) usage; exit 0 ;;
        *) usage; die "Неизвестный параметр: $1" ;;
    esac
done

require_root "$@"
log_init update "$@"
load_conf || die "Портал не установлен: не найден ${CONF_FILE}. Для первой установки используйте install.sh."
[[ "${KEEP_RELEASES}" =~ ^[0-9]+$ ]] || die "--keep: укажите число."
banner "обновление"

# --- Источник новой версии --------------------------------------------------------
if [[ -n "${ARCHIVE}" ]]; then
    [[ -f "${ARCHIVE}" ]] || die "Архив не найден: ${ARCHIVE}"
    CREATED_TMP=$(mktemp -d "/tmp/${APP_ID}-upd.XXXXXX")
    case "${ARCHIVE}" in
        *.tar.gz|*.tgz) tar -xzf "${ARCHIVE}" -C "${CREATED_TMP}" || die "Не удалось распаковать архив." ;;
        *.zip) require_cmd unzip; unzip -q "${ARCHIVE}" -d "${CREATED_TMP}" || die "Не удалось распаковать архив." ;;
        *) die "Неизвестный формат архива." ;;
    esac
    if is_project_dir "${CREATED_TMP}"; then SOURCE_DIR="${CREATED_TMP}"; else
        sub=$(find "${CREATED_TMP}" -maxdepth 2 -name composer.json -printf '%h\n' | head -n1)
        [[ -n "${sub}" ]] && is_project_dir "${sub}" || die "В архиве не найден проект."
        SOURCE_DIR="${sub}"
    fi
elif [[ -z "${SOURCE_DIR}" ]]; then
    SOURCE_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
fi
SOURCE_DIR="$(cd "${SOURCE_DIR}" && pwd)"
is_project_dir "${SOURCE_DIR}" || die "Каталог «${SOURCE_DIR}» не похож на проект портала."
NEW_VERSION=$(project_version "${SOURCE_DIR}")
info "Текущая версия: ${APP_VERSION}; новая версия: ${NEW_VERSION}; источник: ${SOURCE_DIR}"
if [[ "${INSTALL_MODE}" == "native" && "${SOURCE_DIR}" == "${APP_DIR}"/* ]]; then
    die "Источник обновления находится внутри каталога установки. Распакуйте новую версию в другой каталог (например, /tmp) и запустите update.sh оттуда."
fi
if [[ "${ASSUME_YES}" != "1" ]]; then
    confirm "Обновить портал ${APP_VERSION} → ${NEW_VERSION}?" || die "Обновление отменено."
fi

# --- Откат ------------------------------------------------------------------------
rollback() {
    warn "Откат обновления…"
    if [[ "${INSTALL_MODE}" == "native" ]]; then
        if [[ "${SWITCHED}" == "1" && -n "${PREV_RELEASE}" && -d "${PREV_RELEASE}" ]]; then
            ln -sfn "${PREV_RELEASE}" "${APP_DIR}/current"
            svc reload "${PHP_FPM_SERVICE}" || svc restart "${PHP_FPM_SERVICE}" || true
            svc reload nginx || true
            ok "Возвращён предыдущий релиз: ${PREV_RELEASE}"
        fi
        if [[ "${MIGRATED}" == "1" && "${NO_DB_ROLLBACK}" != "1" && -n "${BACKUP_FILE}" && -f "${BACKUP_FILE}" ]]; then
            warn "Восстановление базы данных из ${BACKUP_FILE}…"
            "${SCRIPT_DIR}/restore.sh" "${BACKUP_FILE}" --db-only --yes || warn "Не удалось восстановить базу данных автоматически. Восстановите вручную: ${APP_ID} restore ${BACKUP_FILE} --db-only"
        fi
        [[ -n "${NEW_RELEASE}" && -d "${NEW_RELEASE}" ]] && rm -rf "${NEW_RELEASE}"
    else
        if docker image inspect "${APP_ID}:previous" >/dev/null 2>&1; then
            warn "Возврат предыдущего образа контейнера…"
            (cd "${APP_DIR}/docker" && docker tag "${APP_ID}:previous" "${APP_ID}:latest" && docker compose up -d >>"${LOG_FILE}" 2>&1) || warn "Не удалось вернуть предыдущий образ."
        fi
        if [[ -n "${BACKUP_FILE}" && -f "${BACKUP_FILE}" && -d "${APP_DIR}.prev" ]]; then
            rsync -a --delete --exclude '/docker/.env' "${APP_DIR}.prev/" "${APP_DIR}/" >>"${LOG_FILE}" 2>&1 || true
        fi
        if [[ "${MIGRATED}" == "1" && "${NO_DB_ROLLBACK}" != "1" && -n "${BACKUP_FILE}" && -f "${BACKUP_FILE}" ]]; then
            "${SCRIPT_DIR}/restore.sh" "${BACKUP_FILE}" --db-only --yes || warn "Не удалось восстановить базу данных автоматически."
        fi
    fi
    [[ -n "${CREATED_TMP}" ]] && rm -rf "${CREATED_TMP}" || true
    warn "Откат завершён. Портал работает на версии ${APP_VERSION}."
}

setup_traps
ROLLBACK_ENABLED="1"

# --- Резервная копия --------------------------------------------------------------
if [[ "${NO_BACKUP}" != "1" ]]; then
    step "Резервная копия перед обновлением"
    BACKUP_FILE=$("${SCRIPT_DIR}/backup.sh" --tag before-update-"${NEW_VERSION}" --keep 0 --quiet) || die "Не удалось создать резервную копию. Обновление прервано."
    ok "Резервная копия: ${BACKUP_FILE}"
fi

# Проверка /health (health_probe из common.sh: при включённом HTTPS — https://ДОМЕН/health,
# перенаправления HTTP→HTTPS отслеживаются).
health() {
    local body
    if body=$(wait_for_health 40 3); then
        ok "Портал отвечает: ${body}"
    else
        error "Портал не отвечает после обновления на $(health_url) (${body:-нет ответа})."
        return 1
    fi
}

if [[ "${INSTALL_MODE}" == "native" ]]; then
    PHP_BIN=$(php_bin_for "${PHP_VERSION}")
    [[ -n "${PHP_BIN}" ]] || die "Не найден php${PHP_VERSION}."
    PREV_RELEASE=$(readlink -f "${APP_DIR}/current" 2>/dev/null || true)
    NEW_RELEASE="${APP_DIR}/releases/${NEW_VERSION}-$(date '+%Y%m%d%H%M%S')"

    step "Развёртывание релиза ${NEW_RELEASE}"
    mkdir -p "${NEW_RELEASE}"
    rsync -a --exclude '/var/' --exclude '/.env.local' --exclude '/.env.*.local' --exclude '/.git/' \
        --exclude '/docker/.env' --exclude '/backups/' "${SOURCE_DIR}/" "${NEW_RELEASE}/" >>"${LOG_FILE}" 2>&1 || die "Не удалось скопировать файлы новой версии."
    if [[ ! -d "${NEW_RELEASE}/vendor" ]]; then
        if [[ -d "${PREV_RELEASE}/vendor" ]] && cmp -s "${PREV_RELEASE}/composer.lock" "${NEW_RELEASE}/composer.lock"; then
            info "composer.lock не изменился — каталог vendor/ копируется из предыдущего релиза."
            rsync -a "${PREV_RELEASE}/vendor/" "${NEW_RELEASE}/vendor/" >>"${LOG_FILE}" 2>&1 || die "Не удалось скопировать vendor/."
        else
            command -v composer >/dev/null 2>&1 || die "Composer не найден, а в поставке нет vendor/. Установите composer или используйте архив с vendor/."
            (cd "${NEW_RELEASE}" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-progress >>"${LOG_FILE}" 2>&1) || die "composer install завершился с ошибкой."
        fi
    fi
    mkdir -p "${NEW_RELEASE}/var/cache" "${APP_DIR}/shared/storage" "${APP_DIR}/shared/log" "${APP_DIR}/shared/import"
    rm -rf "${NEW_RELEASE}/var/storage" "${NEW_RELEASE}/var/log" "${NEW_RELEASE}/var/import"
    ln -sfn "${APP_DIR}/shared/storage" "${NEW_RELEASE}/var/storage"
    ln -sfn "${APP_DIR}/shared/log" "${NEW_RELEASE}/var/log"
    ln -sfn "${APP_DIR}/shared/import" "${NEW_RELEASE}/var/import"
    ln -sfn "${APP_DIR}/shared/.env.local" "${NEW_RELEASE}/.env.local"
    # Каталог импорта появился в 1.1.0: у старых установок добавляем параметр и права.
    if ! grep -q '^IMPORT_DIR=' "${APP_DIR}/shared/.env.local" 2>/dev/null; then
        printf '\n# Каталог импорта: положите сюда папки с документами и запустите импорт в панели администратора.\nIMPORT_DIR=%s/shared/import\n' "${APP_DIR}" >> "${APP_DIR}/shared/.env.local"
    fi
    chown "${SERVICE_USER}:${SERVICE_USER}" "${APP_DIR}/shared/import" && chmod 2770 "${APP_DIR}/shared/import"
    chown -R root:"${SERVICE_USER}" "${NEW_RELEASE}"
    chown -R "${SERVICE_USER}:${SERVICE_USER}" "${NEW_RELEASE}/var"
    chmod -R u+rwX,g+rX,o-rwx "${NEW_RELEASE}" 2>/dev/null || true
    chmod 751 "${NEW_RELEASE}"; chmod -R o+rX "${NEW_RELEASE}/public"
    chmod +x "${NEW_RELEASE}/bin/console" "${NEW_RELEASE}"/deploy/*.sh 2>/dev/null || true
    if command -v restorecon >/dev/null 2>&1; then restorecon -R "${NEW_RELEASE}" >>"${LOG_FILE}" 2>&1 || true; fi

    console() { runuser -u "${SERVICE_USER}" -- "${PHP_BIN}" -d memory_limit=512M "${NEW_RELEASE}/bin/console" "$@" --no-interaction --env=prod >>"${LOG_FILE}" 2>&1; }

    step "Прогрев кэша и миграции"
    console cache:clear || die "Ошибка очистки кэша новой версии."
    console cache:warmup || die "Ошибка прогрева кэша новой версии."
    MIGRATED="1"
    console doctrine:migrations:migrate --allow-no-migration || die "Ошибка применения миграций."
    console app:check || die "Проверка новой версии (app:check) не пройдена."

    step "Переключение на новый релиз"
    ln -sfn "${NEW_RELEASE}" "${APP_DIR}/current"; SWITCHED="1"
    svc reload "${PHP_FPM_SERVICE}" || svc restart "${PHP_FPM_SERVICE}" || die "Не удалось перезапустить ${PHP_FPM_SERVICE}."
    nginx -t >>"${LOG_FILE}" 2>&1 && svc reload nginx || true
    health || die "Новая версия не отвечает."
    install_cli_wrapper && info "Команда управления ${APP_ID} обновлена."

    # Удаление старых релизов
    mapfile -t old < <(ls -1dt "${APP_DIR}"/releases/*/ 2>/dev/null | tail -n +$((KEEP_RELEASES + 2)))
    for d in "${old[@]:-}"; do
        [[ -n "${d}" && "$(readlink -f "${d}")" != "${NEW_RELEASE}" && "$(readlink -f "${d}")" != "${PREV_RELEASE}" ]] || continue
        rm -rf "${d}" && info "Удалён старый релиз: ${d}"
    done
else
    step "Обновление Docker-развёртывания"
    cd "${APP_DIR}/docker" || die "Каталог ${APP_DIR}/docker не найден."
    docker image inspect "${APP_ID}:latest" >/dev/null 2>&1 && docker tag "${APP_ID}:latest" "${APP_ID}:previous" >>"${LOG_FILE}" 2>&1 || true
    rm -rf "${APP_DIR}.prev"; rsync -a --exclude '/docker/.env' "${APP_DIR}/" "${APP_DIR}.prev/" >>"${LOG_FILE}" 2>&1 || warn "Не удалось сохранить копию текущего кода."
    rsync -a --delete --exclude '/var/' --exclude '/.env.local' --exclude '/.git/' --exclude '/docker/.env' --exclude '/docker/mysql-data/' --exclude '/backups/' \
        "${SOURCE_DIR}/" "${APP_DIR}/" >>"${LOG_FILE}" 2>&1 || die "Не удалось скопировать файлы новой версии."
    chmod +x "${APP_DIR}"/deploy/*.sh "${APP_DIR}/docker/entrypoint.sh" "${APP_DIR}/bin/console" 2>/dev/null || true
    docker compose build --pull >>"${LOG_FILE}" 2>&1 || die "Сборка образа новой версии не удалась."
    # Каталог импорта документов появился в 1.1.0: подключается в контейнеры как /var/www/html/var/import.
    if ! grep -q '^IMPORT_HOST_DIR=' "${APP_DIR}/docker/.env" 2>/dev/null; then
        printf '\n# Каталог импорта документов: путь на сервере или имя тома Docker.\nIMPORT_HOST_DIR=%s/import\n' "${APP_DIR}" >> "${APP_DIR}/docker/.env"
    fi
    mkdir -p "${APP_DIR}/import" && chown 82:82 "${APP_DIR}/import" 2>/dev/null && chmod 2775 "${APP_DIR}/import" || true
    MIGRATED="1"
    docker compose up -d >>"${LOG_FILE}" 2>&1 || die "Не удалось запустить обновлённые контейнеры."
    health || die "Новая версия не отвечает."
    install_cli_wrapper && info "Команда управления ${APP_ID} обновлена."
    rm -rf "${APP_DIR}.prev"
    docker image prune -f >>"${LOG_FILE}" 2>&1 || true
fi

APP_VERSION="${NEW_VERSION}"
save_conf
ROLLBACK_ENABLED="0"
[[ -n "${CREATED_TMP}" ]] && rm -rf "${CREATED_TMP}" || true
trap - ERR
printf '\n%sОбновление завершено: версия %s.%s Журнал: %s\n' "${C_GREEN}${C_BOLD}" "${NEW_VERSION}" "${C_RESET}" "${LOG_FILE}"
[[ -n "${BACKUP_FILE}" ]] && printf 'Резервная копия перед обновлением: %s\n' "${BACKUP_FILE}"
exit 0
