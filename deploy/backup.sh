#!/usr/bin/env bash
# =============================================================================
#  Резервная копия портала документации: база данных + файлы документов + настройки.
#  Использование: sudo ./deploy/backup.sh [--dir КАТАЛОГ] [--keep N] [--quiet] [--no-remote] [--with-video-media]
#  Раздел «Видео» (если включён): база видеопортала — всегда, видеофайлы — по VIDEO_BACKUP_MEDIA=1 или --with-video-media.
#  Результат: КАТАЛОГ/docportal-backup-ГГГГММДД-ЧЧММСС.tar.gz; если настроено внешнее хранилище
#  (docportal remote-backup set …), ночная копия отправляется и туда.
# =============================================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/common.sh
. "${SCRIPT_DIR}/common.sh"
# shellcheck source=deploy/video.sh
. "${SCRIPT_DIR}/video.sh"

KEEP=14
QUIET="0"
OUT_DIR=""
TAG=""
# Отправка во внешнее хранилище: auto — только обычные (без метки) копии, yes — всегда, no — никогда.
REMOTE_MODE="auto"
BACKUP_STARTED="0"
BACKUP_OK="0"

usage() {
    cat <<EOF
Резервное копирование портала «${APP_TITLE}».

Использование: sudo $0 [параметры]
  --dir DIR      Каталог для архивов (по умолчанию из install.conf: BACKUP_DIR).
  --keep N       Сколько последних архивов хранить (по умолчанию ${KEEP}; 0 — не удалять).
  --tag TEXT     Добавить метку к имени файла (например, before-update).
  --quiet        Минимум вывода (для cron).
  --no-remote    Не отправлять копию во внешнее хранилище (docportal remote-backup).
  --remote       Отправить во внешнее хранилище и копию с меткой (--tag).
  --with-video-media  Включить в копию видеофайлы раздела «Видео» (база видеопортала входит всегда).
  --help         Справка.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dir) OUT_DIR="${2:-}"; shift 2 ;;
        --keep) KEEP="${2:-}"; shift 2 ;;
        --tag) TAG="${2:-}"; shift 2 ;;
        --quiet) QUIET="1"; shift ;;
        --no-remote) REMOTE_MODE="no"; shift ;;
        --remote) REMOTE_MODE="yes"; shift ;;
        --with-video-media) BACKUP_VIDEO_MEDIA="1"; shift ;;
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
[[ -z "${TAG}" || "${TAG}" =~ ^[A-Za-z0-9._-]+$ ]] || die "--tag: допустимы латинские буквы, цифры и символы . _ -"
# Ночная копия дожидается окончания обновления или восстановления (до 30 минут), а не пересекается с ним.
acquire_lock 1800

OUT_DIR="${OUT_DIR:-${BACKUP_DIR:-${DEFAULT_BACKUP_DIR}}}"
mkdir -p "${OUT_DIR}" || die "Не удалось создать каталог ${OUT_DIR}."
chmod 700 "${OUT_DIR}"

STAMP=$(date '+%Y%m%d-%H%M%S')
NAME="${APP_ID}-backup-${STAMP}${TAG:+-${TAG}}"
# Рабочий каталог — рядом с архивами, а не в /tmp: /tmp бывает в памяти (tmpfs) и маленьким.
WORK=$(mktemp -d "${OUT_DIR}/.work-${STAMP}.XXXXXX")
ARCHIVE="${OUT_DIR}/${NAME}.tar.gz"

# При неудаче мониторинг (панель администратора → Мониторинг) получает отметку с текстом ошибки.
cleanup() {
    rm -rf "${WORK}" "${ARCHIVE}.part"
    if [[ "${BACKUP_STARTED}" == "1" && "${BACKUP_OK}" != "1" ]]; then
        record_job backup error "${LAST_ERROR:-Резервная копия не создана (подробности в журнале ${LOG_FILE})}" "${SECONDS}"
    fi
}
trap cleanup EXIT
setup_traps
BACKUP_STARTED="1"
record_job backup running

banner "резервная копия"
info "Режим: ${INSTALL_MODE}; каталог архивов: ${OUT_DIR}"

if [[ "${INSTALL_MODE}" == "docker" ]]; then
    cd "${APP_DIR}/docker" || die "Каталог ${APP_DIR}/docker не найден."
fi

# Команда в контейнере приложения: в работающем — exec, в остановленном — во временном контейнере с теми же
# томами (без зависимостей и entrypoint): копия файлов делается и тогда, когда портал не запускается.
app_run() {
    if docker compose ps --status running app 2>/dev/null | grep_has app; then
        docker compose exec -T app "$@"
    else
        docker compose run --rm --no-deps -T --entrypoint "$1" app "${@:2}"
    fi
}

# Хранилище может быть символической ссылкой на отдельный диск — копируется содержимое настоящего каталога.
STORAGE_REAL=""
if [[ "${INSTALL_MODE}" == "native" ]]; then
    STORAGE_REAL=$(readlink -f "${APP_DIR}/shared/storage" 2>/dev/null || true)
fi

# Проверка свободного места (примерно: размер файлов документов + 200 МБ; в Docker файлы копируются
# во временный каталог, поэтому нужно вдвое больше).
need_mb=200
if [[ "${INSTALL_MODE}" == "native" && -n "${STORAGE_REAL}" && -d "${STORAGE_REAL}" ]]; then
    need_mb=$(( $(du -sm --exclude='.restore-*' "${STORAGE_REAL}" | awk '{print $1}') + 200 ))
elif [[ "${INSTALL_MODE}" == "docker" ]]; then
    storage_mb=$(app_run du -sm /var/www/html/var/storage 2>/dev/null | awk '{print $1}' || true)
    [[ "${storage_mb}" =~ ^[0-9]+$ ]] && need_mb=$(( storage_mb * 2 + 200 ))
fi
free_mb=$(free_space_mb "${OUT_DIR}")
if [[ -n "${free_mb}" && ${free_mb} -lt ${need_mb} ]]; then
    die "Недостаточно места в ${OUT_DIR}: свободно ${free_mb} МБ, нужно около ${need_mb} МБ."
fi

mkdir -p "${WORK}/${NAME}"
cp "${CONF_FILE}" "${WORK}/${NAME}/install.conf"

step "Выгрузка базы данных"
if [[ "${INSTALL_MODE}" == "docker" ]]; then
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
if [[ "${INSTALL_MODE}" == "docker" ]]; then
    mkdir -p "${WORK}/${NAME}/storage"
    # Ошибка копирования — ошибка резервной копии: архив без файлов документов бесполезен. Код 1 у tar
    # в контейнере — файлы менялись во время чтения (идёт загрузка): копия годна, это предупреждение.
    copy_rc=(0 0)
    app_run tar --exclude='./.restore-*' --exclude='./lost+found' -C /var/www/html/var/storage -cf - . 2>>"${LOG_FILE}" | tar -C "${WORK}/${NAME}/storage" -xf - 2>>"${LOG_FILE}" \
        || copy_rc=("${PIPESTATUS[@]}")
    if [[ "${copy_rc[1]:-1}" != "0" || ( "${copy_rc[0]:-1}" != "0" && "${copy_rc[0]:-1}" != "1" ) ]]; then
        die "Не удалось скопировать файлы документов из контейнера (см. журнал)."
    fi
    [[ "${copy_rc[0]}" == "1" ]] && warn "Файлы документов менялись во время копирования (загрузка или импорт): в архив попало их состояние на момент чтения."
    cp "${APP_DIR}/docker/.env" "${WORK}/${NAME}/docker.env"
    files_count=$(find "${WORK}/${NAME}/storage" -type f | wc -l)
    STORAGE_ARGS=()
else
    [[ -n "${STORAGE_REAL}" && -d "${STORAGE_REAL}" ]] || die "Не найдено хранилище документов ${APP_DIR}/shared/storage."
    cp "${APP_DIR}/shared/.env.local" "${WORK}/${NAME}/env.local"
    files_count=$(find "${STORAGE_REAL}" -type f ! -path "${STORAGE_REAL}/.restore-*" ! -path "${STORAGE_REAL}/lost+found/*" | wc -l)
    # Файлы документов не копируются во временный каталог, а сразу упаковываются в архив под именем
    # ${NAME}/storage (tar --transform) — из настоящего каталога хранилища, а не через ссылку на него.
    STORAGE_ARGS=(-C "${STORAGE_REAL}" --transform "s,^\.,${NAME}/storage,S" .)
fi
ok "Файлов документов: ${files_count}"

# Раздел «Видео»: база видеопортала (и видеофайлы, если включено) — в каталог video/ архива.
video_backup "${WORK}/${NAME}" "${NAME}"
# (Без «$( [[ … ]] && echo … )» в присваивании: при ложном условии присваивание вернуло бы 1 и set -e
# прервал бы копию — так было в 1.8.0 у всех копий с включённым разделом «Видео» без видеофайлов.)
VIDEO_NOTE=""
if video_enabled; then
    VIDEO_NOTE=", video/ (раздел «Видео»: db.dump"
    if [[ -d "${WORK}/${NAME}/video/media" || ${#VIDEO_TAR_ARGS[@]} -gt 0 ]]; then
        VIDEO_NOTE+=", media/ — видеофайлы"
    fi
    VIDEO_NOTE+=")"
fi

cat > "${WORK}/${NAME}/MANIFEST.txt" <<EOF
Портал: ${APP_TITLE}
Версия приложения: ${APP_VERSION}
Режим: ${INSTALL_MODE}
Дата: $(date '+%Y-%m-%d %H:%M:%S')
Хост: $(hostname)
База данных: ${DB_NAME}
Файлов документов: ${files_count}
Содержимое: db.sql.gz (SQL-дамп), storage/ (файлы версий документов), install.conf, $( [[ "${INSTALL_MODE}" == "docker" ]] && echo docker.env || echo env.local )${VIDEO_NOTE}
Восстановление: sudo ${APP_ID} restore ${ARCHIVE}
EOF

step "Упаковка архива"
# Код 1 у GNU tar — файлы менялись во время чтения (в хранилище идёт загрузка или импорт). Такая копия
# годна: база выгружена раньше и на файлы, появившиеся позже, не ссылается.
tar_rc=0
# Служебные каталоги незавершённого восстановления (.restore-*) и lost+found в копию не входят.
tar --warning=no-file-changed --warning=no-file-removed --warning=no-file-shrank \
    --exclude='./.restore-*' --exclude='./lost+found' \
    -czf "${ARCHIVE}.part" -C "${WORK}" "${NAME}" "${STORAGE_ARGS[@]}" "${VIDEO_TAR_ARGS[@]}" 2>>"${LOG_FILE}" || tar_rc=$?
if [[ ${tar_rc} -eq 1 ]]; then
    warn "Файлы документов менялись во время копирования (загрузка или импорт): в архив попало их состояние на момент чтения."
elif [[ ${tar_rc} -ne 0 ]]; then
    die "Не удалось создать архив (см. журнал)."
fi
mv "${ARCHIVE}.part" "${ARCHIVE}"
chmod 600 "${ARCHIVE}"
# Контрольная сумма с именем файла без пути: архив можно перенести в другой каталог или на другой сервер.
(cd "${OUT_DIR}" && sha256sum "${NAME}.tar.gz" > "${NAME}.tar.gz.sha256")
ok "Архив создан: ${ARCHIVE} ($(human_size "$(stat -c %s "${ARCHIVE}")"))"

if [[ ${KEEP} -gt 0 ]]; then
    mapfile -t old < <( (ls -1t "${OUT_DIR}"/${APP_ID}-backup-*.tar.gz 2>/dev/null || true) | tail -n +$((KEEP + 1)))
    for f in "${old[@]:-}"; do
        [[ -n "${f}" ]] || continue
        rm -f "${f}" "${f}.sha256"
        info "Удалён старый архив: $(basename "${f}")"
    done
fi
BACKUP_OK="1"
record_job backup ok "$(basename "${ARCHIVE}"), $(human_size "$(stat -c %s "${ARCHIVE}")"), файлов документов: ${files_count}" "${SECONDS}"

# Копия во внешнем хранилище. Её неудача не отменяет готовую резервную копию: она отмечается в мониторинге
# и выводится предупреждением (в журнал cron).
if [[ -n "${BACKUP_REMOTE:-}" && ( "${REMOTE_MODE}" == "yes" || ( "${REMOTE_MODE}" == "auto" && -z "${TAG}" ) ) ]]; then
    step "Отправка во внешнее хранилище ${BACKUP_REMOTE}"
    # Копия готова — блокировка больше не нужна: долгая выгрузка не должна задерживать обновление,
    # восстановление и проверку копий.
    release_lock
    remote_started=${SECONDS}
    if remote_backup_push "${ARCHIVE}"; then
        record_job backup-remote ok "${REMOTE_RESULT}" "$((SECONDS - remote_started))"
        ok "Копия во внешнем хранилище: ${REMOTE_RESULT}"
    else
        record_job backup-remote error "${REMOTE_RESULT}" "$((SECONDS - remote_started))"
        warn "Копия не отправлена во внешнее хранилище: ${REMOTE_RESULT}. Проверьте: ${APP_ID} remote-backup test"
    fi
fi

# Путь к архиву печатается в исходный stdout даже в режиме --quiet (его читают update.sh и cron).
printf '%s\n' "${ARCHIVE}" >&3
