#!/usr/bin/env bash
# =============================================================================
#  Проверка резервной копии пробным восстановлением. Архив проверяется целиком (контрольная сумма,
#  чтение всех файлов), база данных загружается во ВРЕМЕННУЮ базу, в ней проверяются таблицы портала
#  и то, что на каждый файл версии документа, упомянутый в базе, в архиве есть файл. Текущие данные
#  портала не затрагиваются; временная база удаляется. Итог виден в панели администратора → Мониторинг.
#
#  Использование: sudo docportal verify-backup [АРХИВ] [--quiet]
#  По умолчанию проверяется последняя резервная копия. В cron — еженедельно (воскресенье, 05:30).
# =============================================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/common.sh
. "${SCRIPT_DIR}/common.sh"

# Без set -e: каждый шаг проверяется явно, а итог (в том числе неудача) записывается в мониторинг.
set -o pipefail
ARCHIVE=""
QUIET="0"

usage() {
    cat <<EOF
Проверка резервной копии портала «${APP_TITLE}» пробным восстановлением во временную базу.

Использование: sudo ${APP_ID} verify-backup [АРХИВ] [параметры]
  АРХИВ          Архив резервной копии (по умолчанию — последняя копия в каталоге резервных копий).
  --quiet        Минимум вывода (для cron).
  --help         Справка.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --quiet) QUIET="1"; shift ;;
        --help|-h) usage; exit 0 ;;
        -*) usage; die "Неизвестный параметр: $1" ;;
        *) ARCHIVE="$1"; shift ;;
    esac
done

[[ "${QUIET}" == "1" ]] && exec 1>/dev/null
require_root
log_init verify-backup "$@"
load_conf || die "Портал не установлен: не найден ${CONF_FILE}."
BACKUP_DIR="${BACKUP_DIR:-${DEFAULT_BACKUP_DIR}}"
# Не пересекается с ночной копией, обновлением и восстановлением (ждёт их окончания до 30 минут).
acquire_lock 1800

if [[ -z "${ARCHIVE}" ]]; then
    # Последняя обычная копия; если их нет — последняя любая (например, перед обновлением).
    ARCHIVE=$( (ls -1t "${BACKUP_DIR}"/${APP_ID}-backup-[0-9]*-[0-9]*.tar.gz 2>/dev/null || true) | grep -E -- '-[0-9]{8}-[0-9]{6}\.tar\.gz$' | head -n1 || true)
    [[ -n "${ARCHIVE}" ]] || ARCHIVE=$( (ls -1t "${BACKUP_DIR}"/${APP_ID}-backup-*.tar.gz 2>/dev/null || true) | head -n1)
fi
NAME=$(basename "${ARCHIVE:-нет архива}")
# В мониторинг попадает проверка копий из каталога резервных копий (то, из чего будут восстанавливать);
# проверка произвольного файла только выводит результат.
RECORD="1"
if [[ -n "${ARCHIVE}" && "$(dirname "$(readlink -f "${ARCHIVE}" 2>/dev/null || echo "${ARCHIVE}")")" != "$(readlink -f "${BACKUP_DIR}" 2>/dev/null || echo "${BACKUP_DIR}")" ]]; then
    RECORD="0"
fi
STATUS="ok"          # итог: ok | warning | error
NOTES=()             # пояснения для отметки в мониторинге
DONE="0"
TMP_DB=""
WORK=""

note() { NOTES+=("$*"); }
fail() { STATUS="error"; error "$*"; note "$*"; finish; }
soft() { [[ "${STATUS}" == "ok" ]] && STATUS="warning"; warn "$*"; note "$*"; }

# --- Временная база данных ------------------------------------------------------------------------------
DOCKER_ROOT_PW=""
vdb_ready() {
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        DOCKER_ROOT_PW=$( (sed -n 's/^MYSQL_ROOT_PASSWORD=//p' "${APP_DIR}/docker/.env" 2>/dev/null || true) | tail -n1 | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/")
        [[ -n "${DOCKER_ROOT_PW}" ]] || return 1
        (cd "${APP_DIR}/docker" && docker compose ps --status running db 2>/dev/null | grep_has db) || return 1
        vdb_sql "SELECT 1" >/dev/null
    else
        [[ "${DB_LOCAL:-1}" == "1" ]] || return 1
        mysql_root_exec "SELECT 1" >/dev/null 2>&1
    fi
}
# vdb_sql "SQL" [БАЗА] — от имени администратора сервера БД (root), вывод без заголовков.
vdb_sql() {
    local sql=$1 db=${2:-}
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        # Пароль root берётся из переменной окружения контейнера db, а не передаётся в командной строке
        # (её видят все пользователи сервера в списке процессов).
        (cd "${APP_DIR}/docker" && docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 -N -B -e "$1" ${2:+"$2"}' sh "${sql}" "${db}" </dev/null) 2>>"${LOG_FILE}"
    else
        local cli
        cli=$(mysql_cli) || return 1
        "${cli}" --default-character-set=utf8mb4 -N -B -e "${sql}" ${db:+"${db}"} 2>>"${LOG_FILE}"
    fi
}
# vdb_load ДАМП.sql.gz БАЗА
vdb_load() {
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        gunzip -c "$1" | (cd "${APP_DIR}/docker" && docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 "$1"' sh "$2") 2>>"${LOG_FILE}"
    else
        local cli
        cli=$(mysql_cli) || return 1
        gunzip -c "$1" | "${cli}" --default-character-set=utf8mb4 "$2" 2>>"${LOG_FILE}"
    fi
}
drop_tmp_db() {
    [[ -n "${TMP_DB}" ]] || return 0
    vdb_sql "DROP DATABASE IF EXISTS \`${TMP_DB}\`" >/dev/null 2>&1 || warn "Не удалось удалить временную базу ${TMP_DB} — удалите её вручную."
    TMP_DB=""
}

finish() {
    [[ "${DONE}" == "1" ]] && return 0
    DONE="1"
    drop_tmp_db
    [[ -n "${WORK}" ]] && rm -rf "${WORK}"
    local summary="" n
    for n in "${NOTES[@]}"; do summary+="${summary:+; }${n}"; done
    summary="${NAME}: ${summary:-проверка не выполнена}"
    [[ "${RECORD}" == "1" ]] && record_job backup-verify "${STATUS}" "${summary}" "${SECONDS}"
    case "${STATUS}" in
        ok) ok "Копия ${NAME} пригодна для восстановления." ;;
        warning) warn "Копия ${NAME} проверена с замечаниями (см. выше)." ;;
        *) error "Копия ${NAME} НЕ прошла проверку — восстановление из неё может не удаться."
           [[ "${QUIET}" == "1" ]] && printf 'Проверка %s не пройдена: %s\n' "${NAME}" "${summary}" >&2 ;;
    esac
    [[ "${STATUS}" == "error" ]] && exit 1
    exit 0
}
trap 'finish' EXIT
trap 'STATUS="error"; note "проверка прервана"; finish' INT TERM HUP

banner "проверка резервной копии"
[[ -n "${ARCHIVE}" ]] || fail "резервных копий нет в ${BACKUP_DIR} — создайте: ${APP_ID} backup"
[[ -f "${ARCHIVE}" ]] || fail "архив не найден: ${ARCHIVE}"
info "Архив: ${ARCHIVE} ($(human_size "$(stat -c %s "${ARCHIVE}")"), $(date -r "${ARCHIVE}" '+%d.%m.%Y %H:%M'))"

# --- 1. Целостность архива --------------------------------------------------------------------------------
step "Целостность архива"
if [[ -f "${ARCHIVE}.sha256" ]]; then
    expected=$(awk 'NR==1 {print $1}' "${ARCHIVE}.sha256")
    actual=$(sha256sum "${ARCHIVE}" | awk '{print $1}')
    [[ -n "${expected}" && "${expected}" == "${actual}" ]] || fail "контрольная сумма архива не совпадает — файл повреждён"
    ok "Контрольная сумма совпадает"
else
    soft "нет файла контрольной суммы ${NAME}.sha256"
fi
WORK=$(mktemp -d "${BACKUP_DIR}/.verify-work.XXXXXX")
# Полное чтение архива: проверяет сжатие и структуру каждого файла.
tar -tzf "${ARCHIVE}" > "${WORK}/list.txt" 2>>"${LOG_FILE}" || fail "архив не читается (повреждён или обрезан)"
ROOT=$(awk -F/ 'NF {print $1; exit}' "${WORK}/list.txt")
[[ -n "${ROOT}" ]] && grep -qxF "${ROOT}/MANIFEST.txt" "${WORK}/list.txt" || fail "в архиве нет MANIFEST.txt — это не резервная копия портала"
grep -qxF "${ROOT}/db.sql.gz" "${WORK}/list.txt" || fail "в архиве нет выгрузки базы данных db.sql.gz"
# Файлы документов в архиве (пути относительно хранилища).
sed -n "s|^${ROOT}/storage/||p" "${WORK}/list.txt" | grep -v '/$' | grep -v '^$' | sort -u > "${WORK}/files.txt" || true
files_in_archive=$(wc -l < "${WORK}/files.txt")
tar -xzf "${ARCHIVE}" -C "${WORK}" "${ROOT}/MANIFEST.txt" "${ROOT}/db.sql.gz" 2>>"${LOG_FILE}" || fail "не удалось извлечь выгрузку базы из архива"
manifest_files=$(sed -n 's/^Файлов документов: *\([0-9]*\).*/\1/p' "${WORK}/${ROOT}/MANIFEST.txt" | head -n1)
backup_version=$(sed -n 's/^Версия приложения: *//p' "${WORK}/${ROOT}/MANIFEST.txt" | head -n1)
ok "Архив читается полностью: файлов документов ${files_in_archive}, версия портала в копии ${backup_version:-?}"
if [[ -n "${manifest_files}" && "${manifest_files}" != "${files_in_archive}" ]]; then
    # Файлы менялись во время копирования (загрузка или импорт ночью) — копия годна, но стоит знать.
    soft "в архиве ${files_in_archive} файлов документов, при копировании насчитано ${manifest_files}"
fi
gzip -t "${WORK}/${ROOT}/db.sql.gz" 2>>"${LOG_FILE}" || fail "выгрузка базы db.sql.gz повреждена"
zgrep -q -m1 'CREATE TABLE' "${WORK}/${ROOT}/db.sql.gz" || fail "в выгрузке базы нет таблиц"
note "архив цел (${files_in_archive} файлов)"

# --- 1a. Раздел «Видео»: выгрузка базы видеопортала ------------------------------------------------------------
# Формат pg_dump -Fc проверяется оглавлением (pg_restore --list): читается ли выгрузка целиком и есть ли в ней
# таблицы видеопортала. Временную базу PostgreSQL для неё не создаём — оглавление читается без сервера.
if grep -qxF "${ROOT}/video/db.dump" "${WORK}/list.txt"; then
    step "Выгрузка базы раздела «Видео»"
    tar -xzf "${ARCHIVE}" -C "${WORK}" "${ROOT}/video/db.dump" 2>>"${LOG_FILE}" || fail "не удалось извлечь выгрузку базы видеопортала"
    vdump="${WORK}/${ROOT}/video/db.dump"
    [[ "$(head -c 5 "${vdump}" 2>/dev/null)" == "PGDMP" ]] || fail "выгрузка базы видеопортала (video/db.dump) повреждена: это не выгрузка pg_dump"
    vtoc=""
    if [[ "${INSTALL_MODE}" == "docker" ]] && (cd "${APP_DIR}/docker" 2>/dev/null && docker compose ps --status running video-db 2>/dev/null | grep -q video-db); then
        vtoc=$(cd "${APP_DIR}/docker" && docker compose exec -T video-db pg_restore --list < "${vdump}" 2>>"${LOG_FILE}") || fail "выгрузка базы видеопортала не читается (pg_restore --list) — подробности в ${LOG_FILE}"
    elif command -v pg_restore >/dev/null 2>&1; then
        vtoc=$(pg_restore --list "${vdump}" 2>>"${LOG_FILE}") || fail "выгрузка базы видеопортала не читается (pg_restore --list) — подробности в ${LOG_FILE}"
    fi
    if [[ -n "${vtoc}" ]]; then
        vmissing=()
        for t in users videos schema_migrations; do grep -qE "TABLE public ${t} " <<<"${vtoc}" || vmissing+=("${t}"); done
        [[ ${#vmissing[@]} -eq 0 ]] || fail "в выгрузке базы видеопортала нет таблиц: ${vmissing[*]}"
        ok "Выгрузка базы видеопортала читается: $(grep -c ' TABLE DATA ' <<<"${vtoc}") таблиц с данными, $(human_size "$(stat -c %s "${vdump}")")"
        note "база видеопортала читается"
    else
        soft "pg_restore не найден — выгрузка базы видеопортала проверена только по заголовку"
    fi
    if grep -q "^${ROOT}/video/media/" "${WORK}/list.txt"; then
        ok "Видеофайлы в копии: $(grep -c "^${ROOT}/video/media/.*[^/]$" "${WORK}/list.txt" || true)"
    fi
    rm -f "${vdump}"
elif [[ "${VIDEO_ENABLED:-0}" == "1" ]]; then
    soft "раздел «Видео» включён, а в копии нет выгрузки его базы (video/db.dump) — копия сделана до его включения или скриптом старой версии"
fi

# --- 2. Пробное восстановление базы ---------------------------------------------------------------------------
step "Пробное восстановление базы данных"
if ! vdb_ready; then
    if [[ "${INSTALL_MODE}" == "native" && "${DB_LOCAL:-1}" != "1" ]]; then
        soft "база на внешнем сервере ${DB_HOST} — пробная загрузка не выполнялась (проверены архив и выгрузка)"
    else
        soft "нет доступа администратора к серверу баз данных — пробная загрузка не выполнялась"
    fi
    finish
fi
# Временные базы прежних прерванных проверок.
while IFS= read -r stale; do
    [[ "${stale}" =~ ^${APP_ID}_verify_[0-9]+$ ]] || continue
    vdb_sql "DROP DATABASE IF EXISTS \`${stale}\`" >/dev/null 2>&1 && info "Удалена временная база прерванной проверки: ${stale}"
done < <(vdb_sql "SHOW DATABASES LIKE '${APP_ID}\\_verify\\_%'" || true)

# Место: таблицы с индексами занимают примерно вдвое больше несжатой выгрузки.
dump_bytes=$(gunzip -c "${WORK}/${ROOT}/db.sql.gz" | wc -c)
need_mb=$(( dump_bytes * 2 / 1048576 + 100 ))
if [[ "${INSTALL_MODE}" == "docker" ]]; then
    data_dir=$(docker info --format '{{.DockerRootDir}}' 2>/dev/null || echo /var/lib/docker)
else
    data_dir=$(vdb_sql "SELECT @@datadir" || echo /var/lib/mysql)
fi
free_mb=$(free_space_mb "${data_dir:-/var/lib/mysql}")
if [[ -n "${free_mb}" && ${free_mb} -lt ${need_mb} ]]; then
    soft "мало места для пробной загрузки (свободно ${free_mb} МБ в ${data_dir}, нужно около ${need_mb} МБ) — загрузка не выполнялась"
    finish
fi

TMP_DB="${APP_ID}_verify_$(date '+%Y%m%d%H%M%S')"
vdb_sql "CREATE DATABASE \`${TMP_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" >/dev/null || fail "не удалось создать временную базу ${TMP_DB}"
load_started=${SECONDS}
vdb_load "${WORK}/${ROOT}/db.sql.gz" "${TMP_DB}" || fail "выгрузка базы не загружается (ошибка SQL — подробности в журнале ${LOG_FILE})"
ok "База загружена во временную ${TMP_DB} за $((SECONDS - load_started)) с"

tables=$(vdb_sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()" "${TMP_DB}" || echo 0)
missing_tables=()
for t in user section document document_version doctrine_migration_versions; do
    [[ "$(vdb_sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '${t}'" "${TMP_DB}" || echo 0)" == "1" ]] || missing_tables+=("${t}")
done
[[ ${#missing_tables[@]} -eq 0 ]] || fail "в восстановленной базе нет таблиц портала: ${missing_tables[*]}"
documents=$(vdb_sql "SELECT COUNT(*) FROM document" "${TMP_DB}" || echo "?")
versions=$(vdb_sql "SELECT COUNT(*) FROM document_version" "${TMP_DB}" || echo "?")
users=$(vdb_sql "SELECT COUNT(*) FROM \`user\`" "${TMP_DB}" || echo "?")
migration=$(vdb_sql "SELECT MAX(version) FROM doctrine_migration_versions" "${TMP_DB}" | sed 's/.*Version//' || true)
ok "Таблиц: ${tables}; документов: ${documents}, версий: ${versions}, пользователей: ${users}; последняя миграция: ${migration:-?}"
note "база восстановлена (документов ${documents}, пользователей ${users})"

# --- 3. Файлы документов ----------------------------------------------------------------------------------------
step "Файлы документов"
vdb_sql "SELECT stored_path FROM document_version WHERE stored_path IS NOT NULL AND stored_path <> ''" "${TMP_DB}" | sort -u > "${WORK}/refs.txt" \
    || fail "не удалось прочитать список файлов из восстановленной базы"
refs=$(wc -l < "${WORK}/refs.txt")
comm -23 "${WORK}/refs.txt" "${WORK}/files.txt" > "${WORK}/missing.txt"
missing_files=$(wc -l < "${WORK}/missing.txt")
if [[ ${missing_files} -gt 0 ]]; then
    error "В архиве нет ${missing_files} из ${refs} файлов, на которые ссылается база, например:"
    head -n 5 "${WORK}/missing.txt" | sed 's/^/   /' >&2
    fail "нет ${missing_files} из ${refs} файлов документов, например $(head -n1 "${WORK}/missing.txt")"
fi
ok "Все ${refs} файлов версий, упомянутых в базе, есть в архиве"
note "все ${refs} файлов на месте"
finish
