#!/usr/bin/env bash
# =============================================================================
#  Резервная копия портала документации: база данных + файлы документов + настройки.
#  Использование: sudo ./deploy/backup.sh [--dir КАТАЛОГ] [--keep N] [--quiet]
#  Результат: КАТАЛОГ/docportal-backup-ГГГГММДД-ЧЧММСС.tar.gz
# =============================================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/common.sh
. "${SCRIPT_DIR}/common.sh"

KEEP=14
QUIET="0"
OUT_DIR=""
TAG=""

usage() {
    cat <<EOF
Резервное копирование портала «${APP_TITLE}».

Использование: sudo $0 [параметры]
  --dir DIR      Каталог для архивов (по умолчанию из install.conf: BACKUP_DIR).
  --keep N       Сколько последних архивов хранить (по умолчанию ${KEEP}; 0 — не удалять).
  --tag TEXT     Добавить метку к имени файла (например, before-update).
  --quiet        Минимум вывода (для cron).
  --help         Справка.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dir) OUT_DIR="${2:-}"; shift 2 ;;
        --keep) KEEP="${2:-}"; shift 2 ;;
        --tag) TAG="${2:-}"; shift 2 ;;
        --quiet) QUIET="1"; shift ;;
        --help|-h) usage; exit 0 ;;
        *) usage; die "Неизвестный параметр: $1" ;;
    esac
done

exec 3>&1
[[ "${QUIET}" == "1" ]] && exec 1>/dev/null
require_root "$@"
log_init backup "$@"
load_conf || die "Портал не установлен: не найден ${CONF_FILE}."
[[ "${KEEP}" =~ ^[0-9]+$ ]] || die "--keep: укажите число."

OUT_DIR="${OUT_DIR:-${BACKUP_DIR:-${DEFAULT_BACKUP_DIR}}}"
mkdir -p "${OUT_DIR}" || die "Не удалось создать каталог ${OUT_DIR}."
chmod 700 "${OUT_DIR}"

STAMP=$(date '+%Y%m%d-%H%M%S')
NAME="${APP_ID}-backup-${STAMP}${TAG:+-${TAG}}"
WORK=$(mktemp -d "/tmp/${APP_ID}-backup.XXXXXX")
ARCHIVE="${OUT_DIR}/${NAME}.tar.gz"

cleanup() { rm -rf "${WORK}"; }
trap cleanup EXIT
setup_traps

banner "резервная копия"
info "Режим: ${INSTALL_MODE}; каталог архивов: ${OUT_DIR}"

# Проверка свободного места (примерно: размер загрузок + 200 МБ).
need_mb=200
if [[ "${INSTALL_MODE}" == "native" && -d "${APP_DIR}/shared/storage" ]]; then
    need_mb=$(( $(du -sm "${APP_DIR}/shared/storage" | awk '{print $1}') + 200 ))
fi
free_mb=$(free_space_mb "${OUT_DIR}")
if [[ -n "${free_mb}" && ${free_mb} -lt ${need_mb} ]]; then
    die "Недостаточно места в ${OUT_DIR}: свободно ${free_mb} МБ, нужно около ${need_mb} МБ."
fi

mkdir -p "${WORK}/${NAME}"
cp "${CONF_FILE}" "${WORK}/${NAME}/install.conf"

step "Выгрузка базы данных"
if [[ "${INSTALL_MODE}" == "docker" ]]; then
    cd "${APP_DIR}/docker" || die "Каталог ${APP_DIR}/docker не найден."
    docker compose ps --status running db 2>/dev/null | grep_has db || die "Контейнер базы данных не запущен (docker compose ps)."
    docker compose exec -T -e MYSQL_PWD="${DB_PASSWORD}" db mysqldump --single-transaction --quick --routines --triggers --default-character-set=utf8mb4 -u"${DB_USER}" "${DB_NAME}" 2>>"${LOG_FILE}" | gzip > "${WORK}/${NAME}/db.sql.gz" \
        || die "mysqldump в контейнере завершился с ошибкой."
else
    DUMP=$(mysqldump_cli) || die "Не найден mysqldump/mariadb-dump."
    DEFAULTS=$(mysql_defaults_file "${DB_HOST}" "${DB_PORT}" "${DB_USER}" "${DB_PASSWORD}")
    if ! "${DUMP}" --defaults-extra-file="${DEFAULTS}" --single-transaction --quick --routines --triggers --default-character-set=utf8mb4 "${DB_NAME}" 2>>"${LOG_FILE}" | gzip > "${WORK}/${NAME}/db.sql.gz"; then
        rm -f "${DEFAULTS}"; die "Выгрузка базы данных завершилась с ошибкой (см. журнал)."
    fi
    rm -f "${DEFAULTS}"
fi
[[ -s "${WORK}/${NAME}/db.sql.gz" ]] || die "Файл выгрузки базы пуст."
ok "База данных выгружена ($(human_size "$(stat -c %s "${WORK}/${NAME}/db.sql.gz")"))"

step "Копирование файлов документов и настроек"
mkdir -p "${WORK}/${NAME}/storage"
if [[ "${INSTALL_MODE}" == "docker" ]]; then
    cd "${APP_DIR}/docker"
    docker compose cp app:/var/www/html/var/storage/. "${WORK}/${NAME}/storage/" >>"${LOG_FILE}" 2>&1 || warn "Не удалось скопировать файлы документов из контейнера (возможно, файлов ещё нет)."
    cp "${APP_DIR}/docker/.env" "${WORK}/${NAME}/docker.env"
else
    rsync -a "${APP_DIR}/shared/storage/" "${WORK}/${NAME}/storage/" >>"${LOG_FILE}" 2>&1 || die "Не удалось скопировать хранилище документов."
    cp "${APP_DIR}/shared/.env.local" "${WORK}/${NAME}/env.local"
fi
files_count=$(find "${WORK}/${NAME}/storage" -type f | wc -l)
ok "Файлов документов: ${files_count}"

cat > "${WORK}/${NAME}/MANIFEST.txt" <<EOF
Портал: ${APP_TITLE}
Версия приложения: ${APP_VERSION}
Режим: ${INSTALL_MODE}
Дата: $(date '+%Y-%m-%d %H:%M:%S')
Хост: $(hostname)
База данных: ${DB_NAME}
Файлов документов: ${files_count}
Содержимое: db.sql.gz (SQL-дамп), storage/ (файлы версий документов), install.conf, $( [[ "${INSTALL_MODE}" == "docker" ]] && echo docker.env || echo env.local )
Восстановление: sudo ${APP_ID} restore ${ARCHIVE}
EOF

step "Упаковка архива"
tar -C "${WORK}" -czf "${ARCHIVE}.part" "${NAME}" || die "Не удалось создать архив."
mv "${ARCHIVE}.part" "${ARCHIVE}"
chmod 600 "${ARCHIVE}"
sha256sum "${ARCHIVE}" > "${ARCHIVE}.sha256"
ok "Архив создан: ${ARCHIVE} ($(human_size "$(stat -c %s "${ARCHIVE}")"))"

if [[ ${KEEP} -gt 0 ]]; then
    mapfile -t old < <(ls -1t "${OUT_DIR}"/${APP_ID}-backup-*.tar.gz 2>/dev/null | tail -n +$((KEEP + 1)))
    for f in "${old[@]:-}"; do
        [[ -n "${f}" ]] || continue
        rm -f "${f}" "${f}.sha256"
        info "Удалён старый архив: $(basename "${f}")"
    done
fi

# Путь к архиву печатается в исходный stdout даже в режиме --quiet (его читают update.sh и cron).
printf '%s\n' "${ARCHIVE}" >&3
