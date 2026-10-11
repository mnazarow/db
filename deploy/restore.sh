#!/usr/bin/env bash
# =============================================================================
#  Восстановление портала документации из резервной копии.
#  Использование: sudo ./deploy/restore.sh АРХИВ.tar.gz [--db-only|--files-only] [--no-video] [--yes]
#  ВНИМАНИЕ: текущие данные базы и файлы документов будут заменены содержимым архива (при полном
#  восстановлении — и данные раздела «Видео», если они есть в архиве и раздел включён).
#  Перед восстановлением создаётся страховочная копия текущего состояния.
# =============================================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/common.sh
. "${SCRIPT_DIR}/common.sh"
# shellcheck source=deploy/video.sh
. "${SCRIPT_DIR}/video.sh"

ARCHIVE=""
DB_ONLY="0"
FILES_ONLY="0"
ASSUME_YES="0"
SAFETY_BACKUP="1"
SAFETY=""
NO_VIDEO="0"
# Режим обслуживания на время замены данных: включил ли его этот запуск, начались ли изменения, завершено ли.
MAINT_SET="0"
DATA_CHANGED="0"
RESTORE_DONE="0"

usage() {
    cat <<EOF
Восстановление портала «${APP_TITLE}» из резервной копии.

Использование: sudo $0 АРХИВ.tar.gz [параметры]
  --db-only             Восстановить только базу данных.
  --files-only          Восстановить только файлы документов.
  --no-video            Не восстанавливать раздел «Видео» (база видеопортала и видеофайлы остаются текущими).
                        С --db-only и --files-only раздел «Видео» не восстанавливается.
  --no-safety-backup    Не создавать страховочную копию текущего состояния перед восстановлением.
  --yes, -y             Не спрашивать подтверждения.
  --help                Справка.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --db-only) DB_ONLY="1"; shift ;;
        --files-only) FILES_ONLY="1"; shift ;;
        --no-video) NO_VIDEO="1"; shift ;;
        --no-safety-backup) SAFETY_BACKUP="0"; shift ;;
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
ARCHIVE="$(cd "$(dirname "${ARCHIVE}")" && pwd)/$(basename "${ARCHIVE}")"
[[ "${DB_ONLY}" == "1" && "${FILES_ONLY}" == "1" ]] && die "--db-only и --files-only нельзя указывать вместе."
acquire_lock 0

banner "восстановление"
if [[ -f "${ARCHIVE}.sha256" ]]; then
    # Сравнивается только сумма: в файлах копий до 1.6.1 записан полный путь архива, который после
    # переноса на другой сервер или в другой каталог уже не существует.
    expected=$(awk 'NR==1 {print $1}' "${ARCHIVE}.sha256")
    actual=$(sha256sum "${ARCHIVE}" | awk '{print $1}')
    [[ -n "${expected}" && "${expected}" == "${actual}" ]] || die "Контрольная сумма архива не совпадает — файл повреждён."
    ok "Контрольная сумма архива проверена"
fi

# Хранилище документов в native: каталог shared/storage, символическая ссылка на другой диск или точка
# монтирования. Во втором и третьем случае архив распаковывается прямо на диск хранилища (в служебный
# подкаталог), чтобы файлы затем переносились переименованием и не требовали места на системном диске.
STORAGE_REAL=""
STORAGE_SEPARATE="0"
if [[ "${INSTALL_MODE}" == "native" ]]; then
    mkdir -p "${APP_DIR}/shared/storage"
    STORAGE_REAL=$(readlink -f "${APP_DIR}/shared/storage")
    if [[ -L "${APP_DIR}/shared/storage" ]] || mountpoint -q "${STORAGE_REAL}" 2>/dev/null \
        || [[ "$(stat -c %d "${STORAGE_REAL}")" != "$(stat -c %d "${APP_DIR}/shared")" ]]; then
        STORAGE_SEPARATE="1"
    fi
fi

# Архив распаковывается рядом с данными, а не в /tmp (там бывает мало места): в native — в каталог shared
# или на диск хранилища (файлы затем переносятся в хранилище без копирования), в Docker — в каталог
# резервных копий.
if [[ "${INSTALL_MODE}" == "native" ]]; then
    WORK_PARENT="${APP_DIR}/shared"
    [[ "${STORAGE_SEPARATE}" == "1" && "${DB_ONLY}" != "1" ]] && WORK_PARENT="${STORAGE_REAL}"
else
    WORK_PARENT="${BACKUP_DIR:-${DEFAULT_BACKUP_DIR}}"
fi
mkdir -p "${WORK_PARENT}"
WORK=$(mktemp -d "${WORK_PARENT}/.restore-work.XXXXXX")

# Файлы, подготовленные внутри хранилища (.restore-new), убираются, если восстановление прервалось до
# замены файлов; если замена уже началась — остаются для ручного разбора (о них говорит сообщение об ошибке).
STORAGE_MODE=""
STAGED="0"
SWAP_STARTED="0"
cleanup() {
    rm -rf "${WORK}"
    if [[ "${STAGED}" == "1" && "${SWAP_STARTED}" != "1" ]]; then
        case "${STORAGE_MODE}" in
            inplace) rm -rf "${STORAGE_REAL}/.restore-new" ;;
            docker) (cd "${APP_DIR}/docker" && app_sh 'rm -rf /var/www/html/var/storage/.restore-new') >>"${LOG_FILE}" 2>&1 || true ;;
        esac
    fi
    if [[ "${MAINT_SET}" == "1" ]]; then
        if [[ "${DATA_CHANGED}" == "1" && "${RESTORE_DONE}" != "1" ]]; then
            # Восстановление прервалось посреди замены данных: открывать портал с наполовину заменёнными
            # данными нельзя — посетители по-прежнему видят страницу обслуживания.
            warn "Режим обслуживания оставлен включённым: восстановление не завершено. Устраните причину и повторите восстановление; выключить режим: ${APP_ID} maintenance off"
        else
            maintenance_off
        fi
    fi
}
trap cleanup EXIT

MEMBERS=()
[[ "${DB_ONLY}" == "1" ]] && MEMBERS=(--wildcards '*/MANIFEST.txt' '*/db.sql.gz')
[[ "${FILES_ONLY}" == "1" ]] && MEMBERS=(--wildcards '*/MANIFEST.txt' '*/storage')
if ! tar -xzf "${ARCHIVE}" -C "${WORK}" "${MEMBERS[@]}" 2>>"${LOG_FILE}"; then
    die "Не удалось распаковать архив в ${WORK_PARENT} (свободно $(free_space_mb "${WORK_PARENT}") МБ; см. журнал) — данные не изменены."
fi
SRC=$(find "${WORK}" -maxdepth 1 -mindepth 1 -type d | head -n1)
[[ -n "${SRC}" && -f "${SRC}/MANIFEST.txt" ]] || die "Архив не похож на резервную копию портала (нет MANIFEST.txt)."
info "Содержимое архива:"; sed 's/^/   /' "${SRC}/MANIFEST.txt"
[[ "${FILES_ONLY}" == "1" || -s "${SRC}/db.sql.gz" ]] || die "В архиве нет db.sql.gz."
if [[ "${FILES_ONLY}" != "1" ]]; then
    # Таблицы базы удаляются перед загрузкой дампа, поэтому дамп проверяется заранее.
    gzip -t "${SRC}/db.sql.gz" 2>>"${LOG_FILE}" || die "Файл db.sql.gz в архиве повреждён — данные не изменены."
    zgrep -q -m1 'CREATE TABLE' "${SRC}/db.sql.gz" || die "В db.sql.gz нет таблиц — это не дамп базы портала; данные не изменены."
fi
if [[ "${DB_ONLY}" != "1" ]]; then
    # Копии до 1.6.1 при хранилище-ссылке содержат саму ссылку, а не файлы: «восстановить» такие файлы
    # значило бы заменить документы ссылкой (а в худшем случае — удалить их).
    if [[ -L "${SRC}/storage" ]]; then
        die "В архиве вместо файлов документов — символическая ссылка (копия сделана версией до 1.6.1, когда хранилище было ссылкой на другой диск): файлов в ней нет. Восстановите только базу (--db-only), файлы документов — с того диска."
    fi
    # В копии портала без документов каталога storage может не быть.
    mkdir -p "${SRC}/storage"
    # Служебные каталоги прежних восстановлений и lost+found файловой системы документами не являются.
    rm -rf "${SRC}/storage/.restore-new" "${SRC}/storage/.restore-old" "${SRC}/storage/lost+found"
    find "${SRC}/storage" -mindepth 1 -maxdepth 1 \( -name '.restore-old-*' -o -name '.restore-work.*' \) -exec rm -rf {} + 2>/dev/null || true
fi

if [[ "${ASSUME_YES}" != "1" ]]; then
    confirm "Текущие данные будут ЗАМЕНЕНЫ содержимым архива. Продолжить?" || die "Восстановление отменено."
fi
setup_traps

if [[ "${SAFETY_BACKUP}" == "1" ]]; then
    step "Страховочная копия текущего состояния"
    SAFETY=$("${SCRIPT_DIR}/backup.sh" --tag before-restore --keep 0 --quiet) \
        || die "Не удалось создать страховочную копию текущего состояния — восстановление остановлено, данные не изменены. Освободите место или повторите с --no-safety-backup."
    ok "Страховочная копия: ${SAFETY}"
fi

# Посетители не должны работать с порталом, пока база и файлы заменяются (изменения пропали бы, а страницы
# показывали бы ошибки). Режим, включённый администратором или update.sh (откат), здесь не трогается.
RESTORE_MAINT_MESSAGE="Восстановление данных из резервной копии."
if maintenance_is_on && [[ "$(maintenance_owner)" == "restore" ]]; then
    # Режим остался от прерванного восстановления — после успешного повтора он выключится.
    MAINT_SET="1"
    info "Режим обслуживания остался включённым после прерванного восстановления — выключится, когда это восстановление завершится."
elif maintenance_wired && ! maintenance_is_on; then
    if maintenance_on "${RESTORE_MAINT_MESSAGE}" "" restore; then
        MAINT_SET="1"
        info "Включён режим обслуживания: посетители видят страницу «Идёт обновление портала»."
    fi
fi

# Команда в контейнере приложения: в работающем — exec, в остановленном — во временном контейнере с теми же
# томами (без зависимостей и entrypoint): файлы восстанавливаются, даже если портал не запускается.
app_sh() {
    if docker compose ps --status running app 2>/dev/null | grep_has app; then
        docker compose exec -T app sh -c "$1"
    else
        docker compose run --rm --no-deps -T --entrypoint sh app -c "$1"
    fi
}

# Подмена содержимого каталога хранилища файлами из его подкаталога .restore-new (переименованием внутри
# одного раздела диска): так восстанавливается хранилище на отдельном диске — ссылка или точка монтирования
# остаётся на месте. replace_storage_contents КАТАЛОГ
# (Вызывается в условии — set -e там не действует, поэтому каждая ошибка проверяется явно.)
replace_storage_contents() (
    cd "$1" || exit 1
    mkdir -p .restore-old || exit 1
    shopt -s dotglob nullglob
    for f in *; do
        case "${f}" in .restore-new|.restore-old|.restore-old-*|.restore-work.*|lost+found) continue ;; esac
        mv -- "${f}" .restore-old/ || exit 1
    done
    for f in .restore-new/*; do
        mv -- "${f}" . || exit 1
    done
    rm -rf .restore-new .restore-old
)

# Каталог .restore-old остаётся после неудачной замены файлов и хранит прежние документы: при повторном
# восстановлении он не удаляется, а переименовывается. keep_restore_old КАТАЛОГ_ХРАНИЛИЩА
keep_restore_old() {
    local dir=$1 kept
    [[ -e "${dir}/.restore-old" ]] || return 0
    kept="${dir}/.restore-old-$(date '+%Y%m%d%H%M%S')"
    mv "${dir}/.restore-old" "${kept}" || die "Не удалось переименовать ${dir}/.restore-old — разберите его вручную и повторите."
    warn "Файлы прежнего незавершённого восстановления сохранены в ${kept} — удалите каталог, когда они не нужны."
}

# Файлы документов готовятся ДО замены базы: если не хватит места или контейнер недоступен, данные
# останутся прежними (иначе база была бы уже из архива, а файлы — старые).
if [[ "${DB_ONLY}" != "1" ]]; then
    step "Подготовка файлов документов"
    restored=$(find "${SRC}/storage" -type f | wc -l)
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        cd "${APP_DIR}/docker" || die "Каталог ${APP_DIR}/docker не найден."
        # Сначала файлы копируются во временный каталог внутри тома, текущие подменяются после базы.
        # shellcheck disable=SC2016
        app_sh '
            set -e
            cd /var/www/html/var/storage
            rm -rf .restore-new
            if [ -e .restore-old ]; then
                mv .restore-old ".restore-old-$(date +%Y%m%d%H%M%S)"
                echo "Файлы прежнего незавершённого восстановления переименованы: .restore-old-*" >&2
            fi
            mkdir -p .restore-new' >>"${LOG_FILE}" 2>&1 \
            || die "Контейнер приложения недоступен (docker compose ps) — данные не изменены."
        STORAGE_MODE="docker"; STAGED="1"
        tar -C "${SRC}/storage" -cf - . | app_sh 'tar -C /var/www/html/var/storage/.restore-new -xf -' >>"${LOG_FILE}" 2>&1 \
            || die "Не удалось скопировать файлы в контейнер — данные не изменены (см. журнал)."
        cd - >/dev/null
    else
        chown -R "${SERVICE_USER}:${SERVICE_USER}" "${SRC}/storage"
        chmod 750 "${SRC}/storage"
        if [[ "${STORAGE_SEPARATE}" != "1" ]]; then
            # Тот же раздел диска: каталоги меняются переименованием, без копирования.
            STORAGE_MODE="rename"
        else
            # Хранилище — ссылка на отдельный диск или точка монтирования: файлы, распакованные на тот же диск,
            # переносятся в его служебный подкаталог, а после базы подменяют текущие переименованием.
            keep_restore_old "${STORAGE_REAL}"
            rm -rf "${STORAGE_REAL}/.restore-new"
            STORAGE_MODE="inplace"; STAGED="1"
            if ! mv "${SRC}/storage" "${STORAGE_REAL}/.restore-new" 2>>"${LOG_FILE}" \
                && ! { mkdir -p "${STORAGE_REAL}/.restore-new" && cp -a "${SRC}/storage/." "${STORAGE_REAL}/.restore-new/"; } >>"${LOG_FILE}" 2>&1; then
                die "Не удалось подготовить файлы в хранилище ${STORAGE_REAL} (свободно $(free_space_mb "${STORAGE_REAL}") МБ) — данные не изменены."
            fi
        fi
    fi
    ok "Файлы документов подготовлены (${restored} шт.)"
fi

# Консоль приложения (после восстановления базы — миграции и очистка кэша).
app_console() {
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        (cd "${APP_DIR}/docker" && docker compose exec -T -u www-data app php bin/console "$@" --no-interaction)
    else
        runuser -u "${SERVICE_USER}" -- php "${APP_DIR}/current/bin/console" "$@" --env=prod --no-interaction
    fi
}

if [[ "${FILES_ONLY}" != "1" ]]; then
    step "Восстановление базы данных ${DB_NAME}"
    DATA_CHANGED="1"
    # Таблицы удаляются целиком: дамп пересоздаёт только свои таблицы, а таблицы, появившиеся позже
    # (например, после неудачного обновления), иначе остались бы и помешали повторным миграциям.
    LIST_SQL="SELECT CONCAT(IF(table_type='VIEW','DROP VIEW IF EXISTS ','DROP TABLE IF EXISTS '), '\`', REPLACE(table_name, '\`', '\`\`'), '\`;') FROM information_schema.tables WHERE table_schema = DATABASE()"
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        cd "${APP_DIR}/docker" || die "Каталог ${APP_DIR}/docker не найден."
        DROP_SQL=$(docker compose exec -T -e MYSQL_PWD="${DB_PASSWORD}" db mysql -u"${DB_USER}" -N -B -e "${LIST_SQL}" "${DB_NAME}" 2>>"${LOG_FILE}") \
            || die "Не удалось получить список таблиц базы данных."
        { printf 'SET FOREIGN_KEY_CHECKS=0;\n%s\n' "${DROP_SQL}"; gunzip -c "${SRC}/db.sql.gz"; printf '\nSET FOREIGN_KEY_CHECKS=1;\n'; } \
            | docker compose exec -T -e MYSQL_PWD="${DB_PASSWORD}" db mysql -u"${DB_USER}" "${DB_NAME}" 2>>"${LOG_FILE}" \
            || die "Ошибка импорта SQL в контейнер базы данных (см. журнал). Страховочная копия: ${SAFETY:-не создавалась}"
        cd - >/dev/null
    else
        CLI=$(mysql_cli) || die "Клиент mysql не найден."
        DEFAULTS=$(mysql_defaults_file "${DB_HOST}" "${DB_PORT}" "${DB_USER}" "${DB_PASSWORD}")
        if ! DROP_SQL=$("${CLI}" --defaults-extra-file="${DEFAULTS}" -N -B -e "${LIST_SQL}" "${DB_NAME}" 2>>"${LOG_FILE}"); then
            rm -f "${DEFAULTS}"; die "Не удалось получить список таблиц базы данных."
        fi
        if ! { printf 'SET FOREIGN_KEY_CHECKS=0;\n%s\n' "${DROP_SQL}"; gunzip -c "${SRC}/db.sql.gz"; printf '\nSET FOREIGN_KEY_CHECKS=1;\n'; } \
            | "${CLI}" --defaults-extra-file="${DEFAULTS}" "${DB_NAME}" 2>>"${LOG_FILE}"; then
            rm -f "${DEFAULTS}"; die "Ошибка импорта SQL (см. журнал). Страховочная копия: ${SAFETY:-не создавалась}"
        fi
        rm -f "${DEFAULTS}"
    fi
    ok "База данных восстановлена"
    # Копия могла быть сделана более старой версией портала — схема доводится до текущей версии кода.
    if app_console doctrine:migrations:migrate --allow-no-migration >>"${LOG_FILE}" 2>&1; then
        ok "Схема базы данных соответствует текущей версии кода"
    else
        warn "Миграции после восстановления завершились с ошибкой (см. журнал ${LOG_FILE}). Повторите: ${APP_ID} console doctrine:migrations:migrate"
    fi
fi

if [[ "${DB_ONLY}" != "1" ]]; then
    step "Восстановление файлов документов"
    SWAP_STARTED="1"
    DATA_CHANGED="1"
    case "${STORAGE_MODE}" in
        docker)
            cd "${APP_DIR}/docker" || die "Каталог ${APP_DIR}/docker не найден."
            # shellcheck disable=SC2016
            app_sh '
                set -e
                cd /var/www/html/var/storage
                mkdir -p .restore-old
                for f in * .[!.]* ..?*; do
                    [ -e "$f" ] || continue
                    case "$f" in .restore-new|.restore-old|.restore-old-*|.restore-work.*|lost+found) continue ;; esac
                    mv "$f" .restore-old/
                done
                for f in .restore-new/* .restore-new/.[!.]* .restore-new/..?*; do
                    [ -e "$f" ] || continue
                    mv "$f" .
                done
                rm -rf .restore-new .restore-old
                chown -R www-data:www-data /var/www/html/var/storage' >>"${LOG_FILE}" 2>&1 \
                || die "Не удалось заменить файлы документов в контейнере (см. журнал; прежние файлы — в var/storage/.restore-old, новые — в var/storage/.restore-new)."
            cd - >/dev/null
            ;;
        rename)
            # Замена каталогов переименованием (один и тот же раздел диска): быстро и без промежуточного состояния.
            OLD_STORAGE="${APP_DIR}/shared/.storage-before-restore-$(date '+%Y%m%d%H%M%S')"
            mv "${APP_DIR}/shared/storage" "${OLD_STORAGE}" || die "Не удалось подготовить замену хранилища."
            if ! mv "${SRC}/storage" "${APP_DIR}/shared/storage"; then
                mv "${OLD_STORAGE}" "${APP_DIR}/shared/storage"
                die "Не удалось восстановить хранилище документов — возвращены прежние файлы."
            fi
            rm -rf "${OLD_STORAGE}"
            ;;
        inplace)
            replace_storage_contents "${STORAGE_REAL}" >>"${LOG_FILE}" 2>&1 \
                || die "Не удалось заменить файлы документов в ${STORAGE_REAL} (см. журнал; прежние файлы — в ${STORAGE_REAL}/.restore-old, новые — в ${STORAGE_REAL}/.restore-new)."
            ;;
    esac
    if [[ "${INSTALL_MODE}" != "docker" ]] && command -v restorecon >/dev/null 2>&1; then
        restorecon -R "$(readlink -f "${APP_DIR}/shared/storage")" >>"${LOG_FILE}" 2>&1 || true
    fi
    ok "Файлы восстановлены (${restored} шт.)"
fi

# Раздел «Видео» — только при полном восстановлении. Его ошибка — предупреждение: данные портала уже восстановлены.
if [[ "${DB_ONLY}" != "1" && "${FILES_ONLY}" != "1" && "${NO_VIDEO}" != "1" && -f "${SRC}/video/db.dump" ]]; then
    ( video_restore "${SRC}" ) || warn "Раздел «Видео» не восстановлен (см. журнал ${LOG_FILE}). Повторить: ${APP_ID} restore ${ARCHIVE} — или вручную, по docs/РУКОВОДСТВО.md."
fi

app_console cache:clear >>"${LOG_FILE}" 2>&1 || warn "Не удалось очистить кэш приложения — выполните: ${APP_ID} console cache:clear"
RESTORE_DONE="1"
[[ "${MAINT_SET}" == "1" ]] && info "Режим обслуживания выключается — портал снова открыт посетителям."
ok "Восстановление завершено. Проверьте портал в браузере."
