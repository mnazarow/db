#!/usr/bin/env bash
# =============================================================================
#  Восстановление портала документации из резервной копии.
#  Использование: sudo ./deploy/restore.sh АРХИВ.tar.gz [--db-only|--files-only] [--yes]
#  ВНИМАНИЕ: текущие данные базы и файлы документов будут заменены содержимым архива.
# =============================================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/common.sh
. "${SCRIPT_DIR}/common.sh"

ARCHIVE=""
DB_ONLY="0"
FILES_ONLY="0"
ASSUME_YES="0"

usage() {
    cat <<EOF
Восстановление портала «${APP_TITLE}» из резервной копии.

Использование: sudo $0 АРХИВ.tar.gz [параметры]
  --db-only      Восстановить только базу данных.
  --files-only   Восстановить только файлы документов.
  --yes, -y      Не спрашивать подтверждения.
  --help         Справка.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --db-only) DB_ONLY="1"; shift ;;
        --files-only) FILES_ONLY="1"; shift ;;
        --yes|-y) ASSUME_YES="1"; shift ;;
        --help|-h) usage; exit 0 ;;
        -*) usage; die "Неизвестный параметр: $1" ;;
        *) ARCHIVE="$1"; shift ;;
    esac
done

require_root "$@"
log_init restore "$@"
load_conf || die "Портал не установлен: не найден ${CONF_FILE}."
[[ -n "${ARCHIVE}" ]] || { usage; die "Укажите архив резервной копии."; }
[[ -f "${ARCHIVE}" ]] || die "Архив не найден: ${ARCHIVE}"
[[ "${DB_ONLY}" == "1" && "${FILES_ONLY}" == "1" ]] && die "--db-only и --files-only нельзя указывать вместе."

banner "восстановление"
if [[ -f "${ARCHIVE}.sha256" ]]; then
    (cd "$(dirname "${ARCHIVE}")" && sha256sum -c "$(basename "${ARCHIVE}").sha256" >>"${LOG_FILE}" 2>&1) || die "Контрольная сумма архива не совпадает — файл повреждён."
    ok "Контрольная сумма архива проверена"
fi

WORK=$(mktemp -d "/tmp/${APP_ID}-restore.XXXXXX")
cleanup() { rm -rf "${WORK}"; }
trap cleanup EXIT
tar -xzf "${ARCHIVE}" -C "${WORK}" || die "Не удалось распаковать архив."
SRC=$(find "${WORK}" -maxdepth 1 -mindepth 1 -type d | head -n1)
[[ -n "${SRC}" && -f "${SRC}/MANIFEST.txt" ]] || die "Архив не похож на резервную копию портала (нет MANIFEST.txt)."
info "Содержимое архива:"; sed 's/^/   /' "${SRC}/MANIFEST.txt"

if [[ "${ASSUME_YES}" != "1" ]]; then
    confirm "Текущие данные будут ЗАМЕНЕНЫ содержимым архива. Продолжить?" || die "Восстановление отменено."
fi
setup_traps

# Страховочная копия текущего состояния.
step "Страховочная копия текущего состояния"
"${SCRIPT_DIR}/backup.sh" --tag before-restore --keep 0 --quiet || warn "Не удалось создать страховочную копию — продолжаем."

if [[ "${FILES_ONLY}" != "1" ]]; then
    step "Восстановление базы данных ${DB_NAME}"
    [[ -s "${SRC}/db.sql.gz" ]] || die "В архиве нет db.sql.gz."
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        cd "${APP_DIR}/docker" || die "Каталог ${APP_DIR}/docker не найден."
        gunzip -c "${SRC}/db.sql.gz" | docker compose exec -T -e MYSQL_PWD="${DB_PASSWORD}" db mysql -u"${DB_USER}" "${DB_NAME}" 2>>"${LOG_FILE}" \
            || die "Ошибка импорта SQL в контейнер базы данных."
    else
        CLI=$(mysql_cli) || die "Клиент mysql не найден."
        DEFAULTS=$(mysql_defaults_file "${DB_HOST}" "${DB_PORT}" "${DB_USER}" "${DB_PASSWORD}")
        if ! gunzip -c "${SRC}/db.sql.gz" | "${CLI}" --defaults-extra-file="${DEFAULTS}" "${DB_NAME}" 2>>"${LOG_FILE}"; then
            rm -f "${DEFAULTS}"; die "Ошибка импорта SQL (см. журнал)."
        fi
        rm -f "${DEFAULTS}"
    fi
    ok "База данных восстановлена"
fi

if [[ "${DB_ONLY}" != "1" ]]; then
    step "Восстановление файлов документов"
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        cd "${APP_DIR}/docker"
        docker compose exec -T app sh -c 'find /var/www/html/var/storage -mindepth 1 -delete' >>"${LOG_FILE}" 2>&1 || true
        if [[ -n "$(ls -A "${SRC}/storage" 2>/dev/null)" ]]; then
            docker compose cp "${SRC}/storage/." app:/var/www/html/var/storage/ >>"${LOG_FILE}" 2>&1 || die "Не удалось скопировать файлы в контейнер."
        fi
        docker compose exec -T app chown -R www-data:www-data /var/www/html/var/storage >>"${LOG_FILE}" 2>&1 || true
    else
        rsync -a --delete "${SRC}/storage/" "${APP_DIR}/shared/storage/" >>"${LOG_FILE}" 2>&1 || die "Не удалось восстановить хранилище документов."
        chown -R "${SERVICE_USER}:${SERVICE_USER}" "${APP_DIR}/shared/storage"
    fi
    ok "Файлы восстановлены ($(find "${SRC}/storage" -type f | wc -l) шт.)"
fi

if [[ "${INSTALL_MODE}" == "native" ]]; then
    runuser -u "${SERVICE_USER}" -- php "${APP_DIR}/current/bin/console" cache:clear --env=prod --no-interaction >>"${LOG_FILE}" 2>&1 || true
fi
ok "Восстановление завершено. Проверьте портал в браузере."
