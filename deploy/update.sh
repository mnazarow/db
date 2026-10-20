#!/usr/bin/env bash
# =============================================================================
#  Обновление портала документации до новой версии одной командой.
#
#  Запускается из каталога НОВОЙ версии:   sudo ./deploy/update.sh
#  или с указанием источника:              sudo docportal update --archive docportal-1.8.0.tar.gz
#  или без параметров:                     sudo docportal update
#      — архив новой версии ищется сам: /var/lib/docportal/updates, текущий каталог, /root, /tmp, /home/*.
#  По расписанию (ночью, если в /var/lib/docportal/updates появился архив новее): docportal auto-update on
#
#  Полностью автоматически: без вопросов; проверки до каких-либо изменений (архив и его контрольная сумма,
#  версия, место, база, nginx, Docker); режим обслуживания → резервная копия → новый релиз рядом с текущим →
#  миграции → переключение → проверка работоспособности → раздел «Видео» (обновляется, а при переходе
#  на 1.8.0 — ставится, если хватает ресурсов); при ошибке — автоматический откат на предыдущий релиз.
# =============================================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/common.sh
. "${SCRIPT_DIR}/common.sh"
# shellcheck source=deploy/video.sh
. "${SCRIPT_DIR}/video.sh"

SOURCE_DIR=""
ARCHIVE=""
KEEP_RELEASES=3
# Обновление полностью автоматическое: подтверждение — только с --confirm.
ASSUME_YES="1"
NO_BACKUP="0"
NO_DB_ROLLBACK="0"
NO_MAINT="0"
# auto — раздел «Видео» включается при обновлении, если ещё не был настроен и серверу хватает ресурсов.
WITH_VIDEO="auto"
# --auto: запуск по расписанию — новая версия только из каталога обновлений; нет новой — тихо выходим.
AUTO_MODE="0"
ALLOW_DOWNGRADE="0"
NO_SWAP="0"
UPDATES_DIR="/var/lib/${APP_ID}/updates"
UPDATE_DONE="0"
# Восстановлена ли база при откате (0 — миграции изменили базу, а восстановить её не удалось).
DB_RESTORED="1"
# Включил ли этот запуск режим обслуживания (выключается только такой — включённый администратором остаётся).
MAINT_SET="0"
CREATED_TMP=""
NEW_RELEASE=""
PREV_RELEASE=""
BACKUP_FILE=""
SWITCHED="0"
MIGRATED="0"
# Docker: создал ли этот запуск тег :previous и копию кода .prev (откатываться можно только на них —
# тег и копия, оставшиеся от прошлых обновлений, вернули бы позапрошлую версию поверх новой схемы базы).
TAGGED_PREVIOUS="0"
SAVED_PREV="0"
APP_IMAGE_NAME=""
# Что не переносится между версиями кода в Docker-режиме (данные и настройки этой установки).
# docker/nginx/video/ и docker/video/ — файлы раздела «Видео», созданные командой «docportal video enable».
PREV_EXCLUDES=(--exclude '/docker/.env' --exclude '/import/' --exclude '/backups/' --exclude '/var/' --exclude '/.env.local' --exclude '/.git/' --exclude '/docker/mysql-data/' --exclude '/maintenance/'
               --exclude '/docker/nginx/video/' --exclude '/docker/video/')

usage() {
    cat <<EOF
Обновление портала «${APP_TITLE}».

Использование: sudo $0 [параметры]
  --source DIR       Каталог с новой версией (по умолчанию — каталог, где лежит скрипт).
  --archive FILE     Архив новой версии (.tar.gz/.zip).
                     Без --source и --archive при запуске установленной копии (docportal update) архив
                     новее установленной версии ищется в ${UPDATES_DIR}, текущем каталоге, /root, /tmp, /home/*.
  --keep N           Сколько предыдущих релизов хранить (по умолчанию ${KEEP_RELEASES}).
  --no-backup        Не делать резервную копию перед обновлением (не рекомендуется).
  --no-db-rollback   При откате не восстанавливать базу данных из резервной копии.
  --no-maintenance   Не включать режим обслуживания (страницу «Идёт обновление портала») на время миграций.
  --no-video         Не включать раздел «Видео» (по умолчанию включается при переходе на 1.8.0, если хватает ресурсов).
  --with-video       Включить раздел «Видео», даже если он был выключен или ресурсов мало.
  --allow-downgrade  Разрешить установку версии старше установленной (по умолчанию — отказ).
  --auto             Режим расписания: архив только из ${UPDATES_DIR}; нет новой версии — выход без ошибки;
                     версия, обновление на которую не удалось, повторно автоматически не ставится.
  --no-swap          Не создавать файл подкачки при малой памяти.
  --confirm          Спросить подтверждение (по умолчанию обновление идёт без вопросов).
  --yes, -y          Не спрашивать подтверждения (так и по умолчанию).
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
        --no-maintenance) NO_MAINT="1"; shift ;;
        --yes|-y) ASSUME_YES="1"; shift ;;
        --confirm) ASSUME_YES="0"; shift ;;
        --no-video) WITH_VIDEO="0"; shift ;;
        --with-video) WITH_VIDEO="1"; shift ;;
        --allow-downgrade) ALLOW_DOWNGRADE="1"; shift ;;
        --auto) AUTO_MODE="1"; shift ;;
        --no-swap) NO_SWAP="1"; shift ;;
        --help|-h) usage; exit 0 ;;
        *) usage; die "Неизвестный параметр: $1" ;;
    esac
done

require_root "$@"
log_init update "$@"
load_conf || die "Портал не установлен: не найден ${CONF_FILE}. Для первой установки используйте install.sh."
[[ "${KEEP_RELEASES}" =~ ^[0-9]+$ ]] || die "--keep: укажите число."
acquire_lock 0
banner "обновление"

# При любом завершении: удалить распакованный архив; в режиме расписания — отметить неудачу в мониторинге
# и запомнить версию, чтобы не повторять её каждую ночь. При перезапуске из новой версии (exec ниже)
# ловушка не срабатывает — это сделает перезапущенный скрипт.
update_exit() {
    local rc=$?
    [[ -n "${CREATED_TMP:-}" ]] && rm -rf "${CREATED_TMP}"
    if [[ "${AUTO_MODE}" == "1" && "${UPDATE_DONE}" != "1" && ${rc} -ne 0 ]]; then
        if [[ -n "${NEW_VERSION:-}" ]]; then
            mkdir -p "${UPDATES_DIR}" 2>/dev/null && printf '%s\n' "$(date '+%Y-%m-%d %H:%M') ${LAST_ERROR:-ошибка}" > "${UPDATES_DIR}/.failed-${NEW_VERSION}" 2>/dev/null || true
        fi
        record_job auto-update error "Обновление до ${NEW_VERSION:-новой версии} не выполнено: ${LAST_ERROR:-см. журнал ${LOG_FILE}}" "${SECONDS}" || true
    fi
    return 0
}
trap update_exit EXIT

# Архив новее установленной версии: docportal-<версия>.tar.gz (при равных версиях полный архив важнее -src).
find_update_archive() {
    local dirs=("${UPDATES_DIR}") d f v best="" best_v="${APP_VERSION:-0}"
    if [[ "${AUTO_MODE}" != "1" ]]; then
        dirs+=("${PWD}" /root /tmp /var/tmp)
        for d in /home/*; do [[ -d "${d}" ]] && dirs+=("${d}"); done
    fi
    for d in "${dirs[@]}"; do
        for f in "${d}"/"${APP_ID}"-[0-9]*.tar.gz; do
            [[ -f "${f}" ]] || continue
            v=$(basename "${f}"); v=${v#"${APP_ID}"-}; v=${v%.tar.gz}; v=${v%-src}
            [[ "${v}" =~ ^[0-9]+(\.[0-9]+)*$ ]] || continue
            if [[ "${AUTO_MODE}" == "1" && -e "${UPDATES_DIR}/.failed-${v}" ]]; then
                # Сообщение — в stderr: stdout функции — путь к найденному архиву.
                info "Пропускается ${f}: обновление на ${v} уже не удалось ($(head -c 200 "${UPDATES_DIR}/.failed-${v}" | tr -d '\n')). Разрешить повтор: rm ${UPDATES_DIR}/.failed-${v}" >&2
                continue
            fi
            if version_gt "${v}" "${best_v}" || { [[ "${v}" == "${best_v}" && -n "${best}" && "${best}" == *-src.tar.gz && "${f}" != *-src.tar.gz ]]; }; then
                best="${f}"; best_v="${v}"
            fi
        done
    done
    [[ -n "${best}" ]] && printf '%s' "${best}"
}

# --- Источник новой версии --------------------------------------------------------
if [[ -z "${ARCHIVE}" && -z "${SOURCE_DIR}" ]]; then
    DEFAULT_SOURCE="$(cd "${SCRIPT_DIR}/.." && pwd)"
    # Запущена установленная копия (docportal update) или режим расписания — ищем архив новой версии.
    if [[ "${AUTO_MODE}" == "1" || "${DEFAULT_SOURCE}" == "${APP_DIR}" || "${DEFAULT_SOURCE}" == "${APP_DIR}"/* ]]; then
        mkdir -p "${UPDATES_DIR}" && chmod 700 "${UPDATES_DIR}" 2>/dev/null || true
        # Источник обновлений (docportal auto-update on --url | --github): новая версия сначала скачивается
        # в каталог обновлений — дальше всё как с архивом, положенным туда вручную.
        REMOTE_FAILED=""
        if [[ -n "${UPDATE_URL:-}${UPDATE_GITHUB:-}" ]]; then
            remote_rc=0
            remote_fetch_update "${UPDATES_DIR}" "${APP_VERSION}" "$([[ "${AUTO_MODE}" == "1" ]] && echo auto)" || remote_rc=$?
            if [[ ${remote_rc} -eq 1 ]]; then
                REMOTE_FAILED="${REMOTE_ERROR}"
                warn "Источник обновлений ($(update_source_label)): ${REMOTE_ERROR}"
            fi
        fi
        if ! ARCHIVE=$(find_update_archive) || [[ -z "${ARCHIVE}" ]]; then
            ARCHIVE=""
            if [[ "${AUTO_MODE}" == "1" ]]; then
                UPDATE_DONE="1"
                # Новая версия есть, но обновление на неё уже не удалось — в «Мониторинге» остаётся предупреждение,
                # пока администратор не устранит причину (удалит отметку или обновится вручную).
                skipped=""
                for marker in "${UPDATES_DIR}"/.failed-*; do
                    [[ -e "${marker}" ]] || continue
                    if version_gt "${marker##*/.failed-}" "${APP_VERSION:-0}"; then skipped+="${skipped:+, }${marker##*/.failed-}"; fi
                done
                if [[ -n "${REMOTE_FAILED}" ]]; then
                    record_job auto-update warning "Источник обновлений недоступен: ${REMOTE_FAILED}" "${SECONDS}" || true
                elif [[ -n "${skipped}" ]]; then
                    warn "Обновление на ${skipped} уже не удалось — повторно автоматически не выполняется. Исправьте причину и удалите отметку: rm ${UPDATES_DIR}/.failed-<версия> (или обновитесь вручную: ${APP_ID} update)."
                    record_job auto-update warning "Обновление на ${skipped} не удалось ранее и повторно не выполняется — исправьте причину и удалите ${UPDATES_DIR}/.failed-<версия>" "${SECONDS}" || true
                else
                    info "Новой версии нет (установлена ${APP_VERSION}; каталог обновлений ${UPDATES_DIR})."
                    record_job auto-update ok "Новой версии нет (установлена ${APP_VERSION})" "${SECONDS}" || true
                fi
                exit 0
            fi
            [[ -n "${REMOTE_FAILED}" ]] && die "Новая версия не скачана: ${REMOTE_FAILED}. Можно положить архив ${APP_ID}-<версия>.tar.gz в ${UPDATES_DIR} вручную и повторить, либо указать явно: ${APP_ID} update --archive ФАЙЛ"
            die "Архив новой версии не найден (нужен ${APP_ID}-<версия>.tar.gz новее ${APP_VERSION}). Положите его в ${UPDATES_DIR} (или в текущий каталог, /root, /tmp) и повторите, либо укажите явно: ${APP_ID} update --archive ФАЙЛ$([[ -z "${UPDATE_URL:-}${UPDATE_GITHUB:-}" ]] && printf '. Скачивать новые версии автоматически: %s auto-update on --github ВЛАДЕЛЕЦ/РЕПОЗИТОРИЙ (или --url АДРЕС)' "${APP_ID}")"
        fi
        info "Найден архив новой версии: ${ARCHIVE}"
    fi
fi
if [[ -n "${ARCHIVE}" ]]; then
    [[ -f "${ARCHIVE}" ]] || die "Архив не найден: ${ARCHIVE}"
    ARCHIVE="$(cd "$(dirname "${ARCHIVE}")" && pwd)/$(basename "${ARCHIVE}")"
    # Контрольная сумма (файл .sha256 рядом с архивом): повреждённый при передаче архив не ставится.
    if [[ -f "${ARCHIVE}.sha256" ]]; then
        expected_sum=$(awk 'NR==1 {print $1}' "${ARCHIVE}.sha256")
        actual_sum=$(sha256sum "${ARCHIVE}" | awk '{print $1}')
        [[ -n "${expected_sum}" && "${expected_sum}" == "${actual_sum}" ]] || die "Контрольная сумма архива ${ARCHIVE} не совпадает с ${ARCHIVE}.sha256 — архив повреждён при передаче. Скопируйте его заново."
        ok "Контрольная сумма архива проверена"
    fi
    case "${ARCHIVE}" in
        *.tar.gz|*.tgz) gzip -t "${ARCHIVE}" 2>>"${LOG_FILE}" || die "Архив ${ARCHIVE} повреждён (gzip -t) — скопируйте его заново." ;;
    esac
    # Распаковка — туда, где хватает места (архив с vendor/ и видеопорталом занимает в распакованном виде
    # примерно втрое больше).
    need_mb=$(( $(stat -c %s "${ARCHIVE}") / 1048576 * 4 + 300 ))
    tmp_parent=""
    for d in /tmp /var/tmp "$(dirname "${APP_DIR}")"; do
        free_mb=$(free_space_mb "${d}" 2>/dev/null || echo 0)
        if [[ -d "${d}" && ${free_mb:-0} -ge ${need_mb} ]]; then tmp_parent="${d}"; break; fi
    done
    [[ -n "${tmp_parent}" ]] || die "Недостаточно места для распаковки архива: нужно около ${need_mb} МБ в /tmp, /var/tmp или $(dirname "${APP_DIR}")."
    CREATED_TMP=$(mktemp -d "${tmp_parent}/${APP_ID}-upd.XXXXXX")
    case "${ARCHIVE}" in
        *.tar.gz|*.tgz) tar -xzf "${ARCHIVE}" -C "${CREATED_TMP}" 2>>"${LOG_FILE}" || die "Не удалось распаковать архив (место: $(free_space_mb "${tmp_parent}") МБ в ${tmp_parent})." ;;
        *.zip) require_cmd unzip; unzip -q "${ARCHIVE}" -d "${CREATED_TMP}" >>"${LOG_FILE}" 2>&1 || die "Не удалось распаковать архив." ;;
        *) die "Неизвестный формат архива (ожидается .tar.gz или .zip)." ;;
    esac
    if is_project_dir "${CREATED_TMP}"; then SOURCE_DIR="${CREATED_TMP}"; else
        sub=$(find "${CREATED_TMP}" -maxdepth 2 -name composer.json -printf '%h\n' 2>/dev/null | head -n1 || true)
        [[ -n "${sub}" ]] && is_project_dir "${sub}" || die "В архиве не найден проект."
        SOURCE_DIR="${sub}"
    fi
elif [[ -z "${SOURCE_DIR}" ]]; then
    SOURCE_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
fi
SOURCE_DIR="$(cd "${SOURCE_DIR}" && pwd)"
is_project_dir "${SOURCE_DIR}" || die "Каталог «${SOURCE_DIR}» не похож на проект портала."

# Скрипты обновления берём из НОВОЙ версии: при запуске «docportal update --archive …» этот файл
# принадлежит установленной (старой) версии, а новая может содержать изменения в самих скриптах
# (команда управления, задания cron, шаги обновления). Один раз перезапускаемся из нового каталога.
if [[ -z "${DOCPORTAL_UPDATE_REEXEC:-}" && -f "${SOURCE_DIR}/deploy/update.sh" && -f "${SOURCE_DIR}/deploy/common.sh" \
      && "$(cd "${SOURCE_DIR}/deploy" && pwd)" != "${SCRIPT_DIR}" ]]; then
    REEXEC_ARGS=(--source "${SOURCE_DIR}" --keep "${KEEP_RELEASES}")
    [[ "${ASSUME_YES}" == "1" ]] && REEXEC_ARGS+=(--yes)
    [[ "${NO_BACKUP}" == "1" ]] && REEXEC_ARGS+=(--no-backup)
    [[ "${NO_DB_ROLLBACK}" == "1" ]] && REEXEC_ARGS+=(--no-db-rollback)
    # Параметр появился в 1.7.0: новой версии передаётся, только если она его знает.
    [[ "${NO_MAINT}" == "1" ]] && grep -q -- '--no-maintenance' "${SOURCE_DIR}/deploy/update.sh" && REEXEC_ARGS+=(--no-maintenance)
    # Параметры 1.8.0 — тоже только той версии, что их знает.
    if grep -q -- '--allow-downgrade' "${SOURCE_DIR}/deploy/update.sh"; then
        [[ "${ASSUME_YES}" == "1" ]] || REEXEC_ARGS+=(--confirm)
        [[ "${WITH_VIDEO}" == "0" ]] && REEXEC_ARGS+=(--no-video)
        [[ "${WITH_VIDEO}" == "1" ]] && REEXEC_ARGS+=(--with-video)
        [[ "${ALLOW_DOWNGRADE}" == "1" ]] && REEXEC_ARGS+=(--allow-downgrade)
        [[ "${AUTO_MODE}" == "1" ]] && REEXEC_ARGS+=(--auto)
        [[ "${NO_SWAP}" == "1" ]] && REEXEC_ARGS+=(--no-swap)
    fi
    export DOCPORTAL_UPDATE_REEXEC=1 DOCPORTAL_UPDATE_TMP="${CREATED_TMP}"
    info "Обновление продолжится скриптами новой версии: ${SOURCE_DIR}/deploy/update.sh"
    exec bash "${SOURCE_DIR}/deploy/update.sh" "${REEXEC_ARGS[@]}"
fi
# Временный каталог распакованного архива (из первого запуска) удаляем по завершении.
if [[ -n "${DOCPORTAL_UPDATE_TMP:-}" && -z "${CREATED_TMP}" ]]; then
    CREATED_TMP="${DOCPORTAL_UPDATE_TMP}"
fi
NEW_VERSION=$(project_version "${SOURCE_DIR}")
info "Текущая версия: ${APP_VERSION}; новая версия: ${NEW_VERSION}; источник: ${SOURCE_DIR}"
if [[ "${INSTALL_MODE}" == "native" && "${SOURCE_DIR}" == "${APP_DIR}"/* ]]; then
    die "Источник обновления находится внутри каталога установки. Распакуйте новую версию в другой каталог (например, /tmp) и запустите update.sh оттуда."
fi
# Версия: обновление на более старую — по ошибке выбранный архив (откат версии — восстановлением из копии).
if [[ "${NEW_VERSION}" != "unknown" && "${APP_VERSION:-}" != "" ]] && version_gt "${APP_VERSION}" "${NEW_VERSION}"; then
    [[ "${ALLOW_DOWNGRADE}" == "1" ]] || die "Версия ${NEW_VERSION} старше установленной ${APP_VERSION}. Вернуть прежнюю версию надёжнее восстановлением из резервной копии (${APP_ID} restore); если нужна именно установка старой версии — --allow-downgrade."
    warn "Устанавливается более старая версия ${NEW_VERSION} (установлена ${APP_VERSION}) — по --allow-downgrade."
fi
if [[ "${AUTO_MODE}" == "1" && "${NEW_VERSION}" == "${APP_VERSION}" ]]; then
    info "Версия ${NEW_VERSION} уже установлена — обновлять нечего."
    UPDATE_DONE="1"
    record_job auto-update ok "Новой версии нет (установлена ${APP_VERSION})" "${SECONDS}"
    exit 0
fi
[[ "${NEW_VERSION}" == "${APP_VERSION}" ]] && info "Версия ${NEW_VERSION} уже установлена — выполняется повторная установка той же версии (исправление файлов, настроек, служб)."
if [[ "${ASSUME_YES}" != "1" ]]; then
    confirm "Обновить портал ${APP_VERSION} → ${NEW_VERSION}?" || die "Обновление отменено."
fi
[[ "${AUTO_MODE}" == "1" ]] && record_job auto-update running

# --- Проверки до каких-либо изменений -----------------------------------------------
# Всё, что можно проверить заранее, проверяется до режима обслуживания и резервной копии: ошибка здесь
# ничего не меняет на сервере.
update_preflight() {
    step "Проверки перед обновлением"
    detect_pkg_quiet || true
    pkg_preflight
    check_clock || true
    local free need
    if [[ "${INSTALL_MODE}" == "native" ]]; then
        need=$(( $(du -sm "${SOURCE_DIR}" 2>/dev/null | awk '{print $1}' || true) + 512 ))
        free=$(free_space_mb "${APP_DIR}" 2>/dev/null || true)
        if [[ ! "${free}" =~ ^[0-9]+$ ]]; then
            warn "Не удалось определить свободное место в ${APP_DIR} (df) — проверка места пропущена."
        elif [[ ${free} -lt ${need} ]]; then
            die "Недостаточно места в ${APP_DIR}: свободно ${free} МБ, для нового релиза нужно около ${need} МБ. Удалите старые релизы (${APP_DIR}/releases) или резервные копии и повторите."
        fi
        if command -v nginx >/dev/null 2>&1 && ! nginx -t >>"${LOG_FILE}" 2>&1; then
            die "Текущая конфигурация nginx содержит ошибку (nginx -t) — обновление не начато, чтобы не оставить сайт неработающим. Исправьте конфигурацию и повторите."
        fi
        mysql_cli >/dev/null || die "Не найден клиент mysql/mariadb (пакет mariadb-client) — обновление не начато."
        mysql_exec "${DB_HOST}" "${DB_PORT}" "${DB_USER}" "${DB_PASSWORD}" "SELECT 1" >/dev/null 2>&1 \
            || die "Нет подключения к базе данных ${DB_USER}@${DB_HOST}:${DB_PORT} (systemctl status ${DB_SERVICE:-mariadb}) — обновление не начато."
    else
        command -v docker >/dev/null 2>&1 || die "Docker не найден — обновление не начато."
        if ! docker info >/dev/null 2>&1; then
            warn_transient "Служба Docker не отвечает — запуск…"
            svc enable docker || true
            retry 15 2 docker info >/dev/null 2>&1 || die "Служба Docker не отвечает (systemctl status docker) — обновление не начато."
        fi
        free=$(free_space_mb /var/lib/docker 2>/dev/null || free_space_mb / 2>/dev/null || true)
        if [[ ! "${free}" =~ ^[0-9]+$ ]]; then
            warn "Не удалось определить свободное место для образов Docker (df) — проверка места пропущена."
        elif [[ ${free} -lt 3072 ]]; then
            die "Недостаточно места для образов Docker: свободно ${free} МБ, нужно не меньше 3 ГБ (docker system prune освободит место от старых образов)."
        fi
    fi
    ensure_swap 2048 2048
    if body=$(health_probe); then
        ok "Портал работает: ${body}"
    else
        warn "Портал сейчас не отвечает (${body}) — обновление продолжается: возможно, оно и исправит проблему."
    fi
    ok "Проверки пройдены"
}
update_preflight

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
        if [[ "${MIGRATED}" == "1" ]]; then
            DB_RESTORED="0"
            if [[ "${NO_DB_ROLLBACK}" != "1" && -n "${BACKUP_FILE}" && -f "${BACKUP_FILE}" ]]; then
                warn "Восстановление базы данных из ${BACKUP_FILE}…"
                if "${SCRIPT_DIR}/restore.sh" "${BACKUP_FILE}" --db-only --yes --no-safety-backup; then
                    DB_RESTORED="1"
                else
                    warn "Не удалось восстановить базу данных автоматически. Восстановите вручную: ${APP_ID} restore ${BACKUP_FILE} --db-only"
                fi
            fi
        fi
        [[ -n "${NEW_RELEASE}" && -d "${NEW_RELEASE}" ]] && rm -rf "${NEW_RELEASE}"
    else
        # Сначала прежний код (в том числе docker-compose.yml), затем прежний образ: контейнеры пересоздаются
        # по прежнему описанию, а не по новому с прежним образом.
        if [[ "${SAVED_PREV}" == "1" && -d "${APP_DIR}.prev" ]]; then
            rsync -a --delete "${PREV_EXCLUDES[@]}" "${APP_DIR}.prev/" "${APP_DIR}/" >>"${LOG_FILE}" 2>&1 || true
            rm -rf "${APP_DIR}.prev"
        fi
        if [[ "${TAGGED_PREVIOUS}" == "1" ]] && docker image inspect "${APP_ID}:previous" >/dev/null 2>&1; then
            warn "Возврат предыдущего образа контейнера…"
            (cd "${APP_DIR}/docker" && docker tag "${APP_ID}:previous" "${APP_IMAGE_NAME}" && docker compose up -d >>"${LOG_FILE}" 2>&1) || warn "Не удалось вернуть предыдущий образ."
        fi
        if [[ "${MIGRATED}" == "1" ]]; then
            DB_RESTORED="0"
            if [[ "${NO_DB_ROLLBACK}" != "1" && -n "${BACKUP_FILE}" && -f "${BACKUP_FILE}" ]]; then
                if "${SCRIPT_DIR}/restore.sh" "${BACKUP_FILE}" --db-only --yes --no-safety-backup; then
                    DB_RESTORED="1"
                else
                    warn "Не удалось восстановить базу данных автоматически."
                fi
            fi
        fi
    fi
    [[ -n "${CREATED_TMP}" ]] && rm -rf "${CREATED_TMP}" || true
    if [[ "${DB_RESTORED}" == "0" ]]; then
        # Миграции новой версии уже изменили базу, а прежняя версия с ней может работать неправильно:
        # портал остаётся закрытым, пока администратор не восстановит базу или не повторит обновление.
        if [[ "${MAINT_SET}" == "1" ]]; then
            printf 'admin\n' > "$(maintenance_dir)/owner" 2>/dev/null || true
            MAINT_SET="0"
            warn "Режим обслуживания оставлен включённым: база данных изменена миграциями новой версии и не восстановлена."
        fi
        warn "Восстановите базу (${APP_ID} restore ${BACKUP_FILE:-АРХИВ} --db-only) или повторите обновление; затем: ${APP_ID} maintenance off"
        warn "Откат кода завершён (версия ${APP_VERSION}), база данных НЕ восстановлена."
        return 0
    fi
    maintenance_end
    warn "Откат завершён. Портал работает на версии ${APP_VERSION}."
}

# Режим обслуживания на время миграций и переключения версии: посетители видят страницу «Идёт обновление
# портала», а не ошибки прежнего кода на новой схеме базы. Режим, включённый администратором до обновления,
# после обновления остаётся включённым.
maintenance_begin() {
    [[ "${NO_MAINT}" == "1" ]] && return 0
    if ! maintenance_wired; then
        warn "Режим обслуживания не подключён к веб-серверу — во время миграций портал будет открыт посетителям."
        return 0
    fi
    if maintenance_is_on; then
        if [[ "$(maintenance_owner)" == "update" ]]; then
            # Режим остался от прерванного обновления — после успешного обновления он выключится.
            MAINT_SET="1"
            info "Режим обслуживания остался включённым после прерванного обновления — выключится после этого обновления."
        else
            info "Режим обслуживания уже включён администратором — после обновления он останется включённым."
        fi
        return 0
    fi
    if maintenance_on "Обновление до версии ${NEW_VERSION}." "" update; then
        MAINT_SET="1"
        info "Включён режим обслуживания: посетители видят страницу «Идёт обновление портала»."
    else
        warn "Не удалось включить режим обслуживания — обновление продолжается без него."
    fi
}
maintenance_end() {
    if [[ "${MAINT_SET}" == "1" ]]; then
        maintenance_off
        MAINT_SET="0"
        ok "Режим обслуживания выключен — портал снова открыт посетителям."
    fi
    return 0
}

setup_traps
ROLLBACK_ENABLED="1"

# Режим обслуживания включается до резервной копии: всё, что сотрудники успели бы изменить между копией и
# миграциями, пропало бы при откате (а загруженные файлы остались бы без записей в базе). Портал закрыт на
# время копии, установки новой версии и миграций; --no-maintenance — оставить его открытым.
if [[ "${NO_MAINT}" != "1" ]]; then
    if [[ "${INSTALL_MODE}" == "native" ]]; then
        ensure_nginx_maintenance || warn "Обновление продолжится без страницы обслуживания."
    fi
    maintenance_begin
fi

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
        collect_diagnostics
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
            retry 3 20 bash -c "cd '${NEW_RELEASE}' && COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_PROCESS_TIMEOUT=900 timeout -k 60 3600 composer install --no-dev --optimize-autoloader --no-interaction --no-progress </dev/null >>'${LOG_FILE}' 2>&1" \
                || die "composer install завершился с ошибкой (нужен доступ к github.com/packagist.org; надёжнее обновляться архивом дистрибутива — в нём vendor/ уже есть)."
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
    # serverVersion=8.0 Doctrine читает как «MySQL младше 8.0.0» (особенности сравнения версий) и
    # работает с MySQL 8 по правилам 5.7; полная версия 8.0.0 выбирает правильный диалект.
    if grep -q 'serverVersion=8\.0&' "${APP_DIR}/shared/.env.local" 2>/dev/null; then
        sed -i 's/serverVersion=8\.0&/serverVersion=8.0.0\&/' "${APP_DIR}/shared/.env.local" \
            && info "DATABASE_URL: serverVersion=8.0 заменён на 8.0.0." || warn "Не удалось исправить serverVersion в ${APP_DIR}/shared/.env.local (укажите serverVersion=8.0.0 вручную)."
    fi
    # С 1.8.0 предупреждения об устаревании пишутся по дням (неделя хранения); прежний общий файл рос
    # без ограничения и больше не нужен.
    rm -f "${APP_DIR}/shared/log/deprecations.log" 2>/dev/null || true
    chown "${SERVICE_USER}:${SERVICE_USER}" "${APP_DIR}/shared/import" && chmod 2770 "${APP_DIR}/shared/import"
    chown -R root:"${SERVICE_USER}" "${NEW_RELEASE}"
    chown -R "${SERVICE_USER}:${SERVICE_USER}" "${NEW_RELEASE}/var"
    chmod -R u+rwX,g+rX,o-rwx "${NEW_RELEASE}" 2>/dev/null || true
    chmod 751 "${NEW_RELEASE}"; chmod -R o+rX "${NEW_RELEASE}/public"
    chmod +x "${NEW_RELEASE}/bin/console" "${NEW_RELEASE}"/deploy/*.sh 2>/dev/null || true
    [[ -d "${NEW_RELEASE}/video" ]] && chmod -R o+rX "${NEW_RELEASE}/video"
    if command -v restorecon >/dev/null 2>&1; then restorecon -R "${NEW_RELEASE}" >>"${LOG_FILE}" 2>&1 || true; fi
    # Раздел «Видео»: зависимости и веб-интерфейс видеопортала в новом релизе — до переключения, пока
    # прежняя версия продолжает работать (без них новая версия видеопортала не запустится).
    video_update_prepare "${NEW_RELEASE}" "${PREV_RELEASE}"

    console() { runuser -u "${SERVICE_USER}" -- "${PHP_BIN}" -d memory_limit=512M "${NEW_RELEASE}/bin/console" "$@" --no-interaction --env=prod >>"${LOG_FILE}" 2>&1; }


    step "Прогрев кэша и миграции"
    console cache:clear || die "Ошибка очистки кэша новой версии."
    console cache:warmup || die "Ошибка прогрева кэша новой версии."
    # База меняется, только если у новой версии есть миграции: иначе при откате её восстанавливать не нужно.
    if console doctrine:migrations:up-to-date; then
        info "Новых миграций базы данных нет."
    else
        MIGRATED="1"
        console doctrine:migrations:migrate --allow-no-migration || die "Ошибка применения миграций."
    fi
    console app:check || die "Проверка новой версии (app:check) не пройдена."

    step "Переключение на новый релиз"
    ln -sfn "${NEW_RELEASE}" "${APP_DIR}/current"; SWITCHED="1"
    svc reload "${PHP_FPM_SERVICE}" || svc restart "${PHP_FPM_SERVICE}" || die "Не удалось перезапустить ${PHP_FPM_SERVICE}."
    nginx -t >>"${LOG_FILE}" 2>&1 && svc reload nginx || true
    health || die "Новая версия не отвечает."
    maintenance_end
    install_cli_wrapper && info "Команда управления ${APP_ID} обновлена."
    ensure_cron_entries
    install_logrotate
    upgrade_native_system_config "${NEW_RELEASE}"
    # Видеопортал перезапускается на новой версии; его ошибка — предупреждение (портал документов уже обновлён).
    # Внутри — без отката обновления: портал к этому моменту обновлён и проверен.
    ( ROLLBACK_ENABLED="0"; video_update_finish ) || warn "Раздел «Видео» не перезапущен — проверьте: ${APP_ID} video status"
    # Извлечение текста из PDF (API, LLM) появилось в 1.3.0 — пакет poppler-utils ставим при обновлении, если его нет.
    # Обновление к этому моменту уже выполнено и проверено, поэтому любая неудача здесь — только предупреждение.
    if ! command -v pdftotext >/dev/null 2>&1; then
        if pkg_install_optional poppler-utils && command -v pdftotext >/dev/null 2>&1; then
            info "Установлен пакет poppler-utils (pdftotext)."
        else
            warn "Не удалось установить poppler-utils — текст из PDF для API и описаний через LLM извлекаться не будет (установите пакет вручную)."
        fi
    fi
    # Водяной знак на PDF (1.8.0) накладывает qpdf — небольшой пакет, ставим при обновлении, если его нет.
    if ! command -v qpdf >/dev/null 2>&1; then
        if pkg_install_optional qpdf && command -v qpdf >/dev/null 2>&1; then
            info "Установлен пакет qpdf (водяной знак на PDF)."
        else
            warn "Не удалось установить qpdf — водяной знак на PDF работать не будет (установите пакет qpdf вручную)."
        fi
    fi

    # Удаление старых релизов
    mapfile -t old < <(ls -1dt "${APP_DIR}"/releases/*/ 2>/dev/null | tail -n +$((KEEP_RELEASES + 2)))
    for d in "${old[@]:-}"; do
        [[ -n "${d}" && "$(readlink -f "${d}")" != "${NEW_RELEASE}" && "$(readlink -f "${d}")" != "${PREV_RELEASE}" ]] || continue
        rm -rf "${d}" && info "Удалён старый релиз: ${d}"
    done
else
    step "Обновление Docker-развёртывания"
    cd "${APP_DIR}/docker" || die "Каталог ${APP_DIR}/docker не найден."
    # Образ приложения — как в docker-compose.yml (APP_IMAGE в docker/.env, по умолчанию docportal:latest).
    APP_IMAGE_NAME=$(docker compose config --format json 2>/dev/null | python3 -c 'import json,sys; print(json.load(sys.stdin)["services"]["app"]["image"])' 2>/dev/null || true)
    [[ -n "${APP_IMAGE_NAME}" ]] || APP_IMAGE_NAME=$( (sed -n 's/^APP_IMAGE=//p' .env 2>/dev/null || true) | tail -n1 | tr -d "\"'")
    APP_IMAGE_NAME=${APP_IMAGE_NAME:-${APP_ID}:latest}
    if docker image inspect "${APP_IMAGE_NAME}" >/dev/null 2>&1 && docker tag "${APP_IMAGE_NAME}" "${APP_ID}:previous" >>"${LOG_FILE}" 2>&1; then
        TAGGED_PREVIOUS="1"
    fi
    rm -rf "${APP_DIR}.prev"
    if rsync -a "${PREV_EXCLUDES[@]}" "${APP_DIR}/" "${APP_DIR}.prev/" >>"${LOG_FILE}" 2>&1; then
        SAVED_PREV="1"
    else
        warn "Не удалось сохранить копию текущего кода."
    fi
    # /import/ — каталог импорта на сервере (IMPORT_HOST_DIR): его содержимое при обновлении не трогаем.
    # /maintenance/ — состояние режима обслуживания (каталог подключён в контейнер web).
    rsync -a --delete --exclude '/var/' --exclude '/.env.local' --exclude '/.git/' --exclude '/docker/.env' --exclude '/docker/mysql-data/' --exclude '/backups/' --exclude '/import/' \
        --exclude '/maintenance/' --exclude '/docker/nginx/video/' --exclude '/docker/video/' "${SOURCE_DIR}/" "${APP_DIR}/" >>"${LOG_FILE}" 2>&1 || die "Не удалось скопировать файлы новой версии."
    chmod +x "${APP_DIR}"/deploy/*.sh "${APP_DIR}/docker/entrypoint.sh" "${APP_DIR}/bin/console" 2>/dev/null || true
    # Загрузка базовых образов прерывается при сбоях сети и ограничениях Docker Hub — до трёх попыток.
    retry 3 30 bash -c "docker compose build --pull app >>'${LOG_FILE}' 2>&1" || die "Сборка образа новой версии не удалась (Docker Hub недоступен? зеркало — в /etc/docker/daemon.json, registry-mirrors)."
    # Раздел «Видео»: образ видеопортала собирается отдельно — его ошибка не мешает обновить портал
    # (контейнеры видеопортала продолжат работать на прежнем образе).
    video_update_prepare "${APP_DIR}"
    # Каталог импорта документов появился в 1.1.0: подключается в контейнеры как /var/www/html/var/import.
    if ! grep -q '^IMPORT_HOST_DIR=' "${APP_DIR}/docker/.env" 2>/dev/null; then
        printf '\n# Каталог импорта документов: путь на сервере или имя тома Docker.\nIMPORT_HOST_DIR=%s/import\n' "${APP_DIR}" >> "${APP_DIR}/docker/.env"
    fi
    mkdir -p "${APP_DIR}/import" && chown 82:82 "${APP_DIR}/import" 2>/dev/null && chmod 2775 "${APP_DIR}/import" || true
    # До 1.6.1 установщик доверял заголовкам X-Forwarded-* от всех частных сетей — клиенты локальной сети
    # могли подменить свой IP. nginx передаёт настоящий адрес по FastCGI, поэтому прокси не нужны.
    if grep -q '^TRUSTED_PROXIES=172.16.0.0/12,10.0.0.0/8,192.168.0.0/16$' "${APP_DIR}/docker/.env" 2>/dev/null; then
        sed -i 's|^TRUSTED_PROXIES=172.16.0.0/12,10.0.0.0/8,192.168.0.0/16$|TRUSTED_PROXIES=|' "${APP_DIR}/docker/.env" || true
        warn "TRUSTED_PROXIES в docker/.env очищен: заголовкам X-Forwarded-* от всех частных сетей портал больше не доверяет. Если перед порталом стоит обратный прокси (TLS), укажите его адрес: TRUSTED_PROXIES=<IP прокси> в ${APP_DIR}/docker/.env и выполните: cd ${APP_DIR}/docker && docker compose up -d"
    fi
    # Страница обслуживания показывается, пока контейнер приложения применяет миграции (с 1.7.0 каталог
    # ${APP_DIR}/maintenance подключён в контейнер web; при переходе с прежних версий web пересоздаётся
    # командой up -d ниже и сразу начинает показывать страницу; режим включён в начале обновления).
    MIGRATED="1"
    retry 3 15 bash -c "docker compose up -d >>'${LOG_FILE}' 2>&1" || die "Не удалось запустить обновлённые контейнеры."
    # Каталог docker/nginx подключён в web целиком: изменённая конфигурация применяется без пересоздания.
    docker compose exec -T web nginx -s reload >>"${LOG_FILE}" 2>&1 || true
    health || die "Новая версия не отвечает."
    maintenance_end
    # Прежний общий файл предупреждений об устаревании (с 1.8.0 — по дням, неделя хранения).
    docker compose exec -T app rm -f var/log/deprecations.log >>"${LOG_FILE}" 2>&1 || true
    install_cli_wrapper && info "Команда управления ${APP_ID} обновлена."
    # Задания приложения выполняет контейнер cron; на сервере остаётся только резервное копирование.
    ensure_cron_entries
    install_logrotate
    # Внутри — без отката обновления: портал к этому моменту обновлён и проверен.
    ( ROLLBACK_ENABLED="0"; video_update_finish ) || warn "Раздел «Видео» не перезапущен — проверьте: ${APP_ID} video status"
fi

APP_VERSION="${NEW_VERSION}"
save_conf
ROLLBACK_ENABLED="0"
if [[ "${INSTALL_MODE}" == "docker" ]]; then
    # Прежние код и образ убираются только теперь, когда откат уже не понадобится (и не должны достаться
    # откату следующего обновления).
    rm -rf "${APP_DIR}.prev"
    [[ "${TAGGED_PREVIOUS}" == "1" ]] && docker image rm "${APP_ID}:previous" >>"${LOG_FILE}" 2>&1 || true
    docker image prune -f >>"${LOG_FILE}" 2>&1 || true
fi
[[ -n "${CREATED_TMP}" ]] && rm -rf "${CREATED_TMP}" || true
CREATED_TMP=""
trap - ERR
set +e
UPDATE_DONE="1"
# Эта версия установлена — отметка о прошлой неудаче (если была) больше не нужна. Архивы более старых
# версий в каталоге обновлений тоже (архив установленной версии остаётся — для повторной установки).
rm -f "${UPDATES_DIR}/.failed-${NEW_VERSION}" 2>/dev/null
for old_archive in "${UPDATES_DIR}"/"${APP_ID}"-[0-9]*.tar.gz; do
    [[ -f "${old_archive}" ]] || continue
    old_v=$(basename "${old_archive}"); old_v=${old_v#"${APP_ID}"-}; old_v=${old_v%.tar.gz}; old_v=${old_v%-src}
    [[ "${old_v}" =~ ^[0-9]+(\.[0-9]+)*$ ]] && version_gt "${NEW_VERSION}" "${old_v}" && rm -f "${old_archive}" "${old_archive}.sha256"
done

# Раздел «Видео»: если он ещё не был настроен (переход с версии до 1.8.0 или установка без него), он
# включается сам, когда серверу хватает ресурсов. Выключенный администратором (docportal video disable,
# install.sh --no-video) не включается. Ошибка — предупреждение: портал уже обновлён.
maybe_enable_video() {
    local deploy_dir code
    if [[ "${INSTALL_MODE}" == "docker" ]]; then deploy_dir="${APP_DIR}/deploy"; code="${APP_DIR}/video"; else deploy_dir="${APP_DIR}/current/deploy"; code="${APP_DIR}/current/video"; fi
    [[ "${WITH_VIDEO}" == "0" || "${VIDEO_ENABLED:-}" == "1" ]] && return 0
    [[ -f "${code}/server/package.json" && -f "${deploy_dir}/video.sh" ]] || return 0
    if [[ "${VIDEO_ENABLED:-}" == "0" && "${WITH_VIDEO}" != "1" ]]; then
        info "Раздел «Видео» выключен администратором — не включается (включить: ${APP_ID} video enable)."
        return 0
    fi
    if [[ "${INSTALL_MODE}" == "native" ]]; then
        detect_pkg_quiet || true
        if [[ "${PKG:-}" != "apt" ]]; then
            info "Раздел «Видео» на сервере ставится только на Debian и Ubuntu — пропускается."
            return 0
        fi
    fi
    if [[ "${WITH_VIDEO}" == "auto" ]] && ! video_resources_ok; then
        warn "Раздел «Видео» не включён автоматически: ${VIDEO_REASON}. Включить вручную: ${APP_ID} video enable"
        return 0
    fi
    step "Раздел «Видео»: установка"
    local attempt
    for attempt in 1 2; do
        if NO_SWAP="${NO_SWAP}" bash "${deploy_dir}/video.sh" enable; then
            VIDEO_ENABLED="1"
            return 0
        fi
        [[ ${attempt} -lt 2 ]] && { warn_transient "Раздел «Видео» не включился — повтор через 60 с"; sleep 60; }
    done
    warn "Портал обновлён, но раздел «Видео» включить не удалось (журнал — ${LOG_DIR}/video-enable-*.log). Исправьте причину и выполните: ${APP_ID} video enable"
    return 1
}
maybe_enable_video || true

# Автоматическое обновление: включено при установке 1.8.0+; при переходе с более старой версии (о нём ещё не
# решали — AUTO_UPDATE пусто) включается так же. Выключенное администратором (auto-update off) не трогается.
if [[ -z "${AUTO_UPDATE:-}" && "${AUTO_MODE}" != "1" ]]; then
    au_script="${APP_DIR}/current/deploy/auto-update.sh"
    [[ "${INSTALL_MODE}" == "docker" ]] && au_script="${APP_DIR}/deploy/auto-update.sh"
    if [[ -f "${au_script}" ]] && bash "${au_script}" on >>"${LOG_FILE}" 2>&1; then
        AUTO_UPDATE="1"
        ok "Автоматическое обновление включено: ежедневно в 04:30 из /var/lib/${APP_ID}/updates (${APP_ID} auto-update status; выключить — auto-update off)"
    else
        warn "Автоматическое обновление не включено (см. ${LOG_FILE}). Включить: ${APP_ID} auto-update on"
    fi
fi

[[ "${AUTO_MODE}" == "1" ]] && record_job auto-update ok "Обновлено до ${NEW_VERSION}" "${SECONDS}"
printf '\n%sОбновление завершено: версия %s.%s Журнал: %s\n' "${C_GREEN}${C_BOLD}" "${NEW_VERSION}" "${C_RESET}" "${LOG_FILE}"
[[ -n "${BACKUP_FILE}" ]] && printf 'Резервная копия перед обновлением: %s\n' "${BACKUP_FILE}"
[[ "${VIDEO_ENABLED:-}" == "1" ]] && printf 'Раздел «Видео»: включён (%s video status — проверка).\n' "${APP_ID}"
# Помощник (1.8.0): пока языковая модель не подключена, кнопки «Помощник» у сотрудников нет — подсказка, как подключить.
AI_READY="0"
if [[ "${INSTALL_MODE}" == "docker" ]]; then
    (cd "${APP_DIR}/docker" && docker compose exec -T -u www-data app php bin/console app:ai:configure --ready --no-interaction) >/dev/null 2>&1 && AI_READY="1"
else
    runuser -u "${SERVICE_USER}" -- "$(php_bin_for "${PHP_VERSION:-}" || echo php)" "${APP_DIR}/current/bin/console" app:ai:configure --ready --env=prod --no-interaction >/dev/null 2>&1 && AI_READY="1"
fi
if [[ "${AI_READY}" != "1" ]]; then
    printf 'Помощник (чат по документам и прайс-листам): языковая модель не подключена, сотрудники кнопку «Помощник» не видят.\n'
    printf '  Подключить: sudo %s ai enable — модель на этом сервере; sudo %s ai connect --url … --model … — на другом (РУКОВОДСТВО, 7.7).\n' "${APP_ID}" "${APP_ID}"
fi
# PHP ниже рекомендуемого: портал работает, но будущей Symfony 8 нужен PHP 8.4+ — переход отдельной командой.
if [[ "${INSTALL_MODE}" == "native" && -n "${PHP_VERSION:-}" ]] && ! version_ge "${PHP_VERSION}" "${PHP_RECOMMENDED_VERSION}"; then
    printf 'PHP %s: рекомендуется перейти на PHP %s (понадобится для перехода портала на Symfony 8) — sudo %s php-upgrade.\n' "${PHP_VERSION}" "${PHP_RECOMMENDED_VERSION}" "${APP_ID}"
fi
print_warnings_summary
exit 0
