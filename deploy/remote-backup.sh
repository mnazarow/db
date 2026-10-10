#!/usr/bin/env bash
# =============================================================================
#  Копии во внешнем хранилище: после каждой ночной резервной копии архив отправляется за пределы
#  сервера — в облачное хранилище (через rclone: S3, Яндекс Диск, WebDAV, SFTP…) или в каталог
#  смонтированного сетевого диска. Без этого при потере сервера копии пропадут вместе с ним.
#
#  Использование: sudo docportal remote-backup status | set НАЗНАЧЕНИЕ [--keep N] | off | test | push [АРХИВ]
# =============================================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/common.sh
. "${SCRIPT_DIR}/common.sh"

usage() {
    cat <<EOF
Копии портала «${APP_TITLE}» во внешнем хранилище.

Использование: sudo ${APP_ID} remote-backup <действие>
  status                      Настройка и копии, которые лежат во внешнем хранилище.
  set НАЗНАЧЕНИЕ [--keep N]   Отправлять каждую ночную копию в НАЗНАЧЕНИЕ и хранить там N последних (по умолчанию 14):
                                имя:путь — хранилище rclone (настраивается заранее: rclone config, от root),
                                           например yandex:backups/docportal или s3:bucket/docportal;
                                /каталог — каталог на сервере, например смонтированный NFS/SMB: /mnt/nas/docportal.
  off                         Больше не отправлять копии (уже отправленные остаются).
  test                        Проверить запись в хранилище.
  push [АРХИВ]                Отправить архив сейчас (по умолчанию — последнюю резервную копию).
EOF
}

ACTION="${1:-status}"
[[ $# -gt 0 ]] && shift
DEST=""
KEEP=""
ARCHIVE=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --keep) KEEP="${2:-}"; shift 2 ;;
        --help|-h) usage; exit 0 ;;
        -*) usage; die "Неизвестный параметр: $1" ;;
        *)
            if [[ "${ACTION}" == "set" && -z "${DEST}" ]]; then DEST="$1"
            elif [[ "${ACTION}" == "push" && -z "${ARCHIVE}" ]]; then ARCHIVE="$1"
            else usage; die "Лишний аргумент: $1"; fi
            shift ;;
    esac
done
case "${ACTION}" in
    status|set|off|test|push) ;;
    help|--help|-h) usage; exit 0 ;;
    *) usage; die "Неизвестное действие: ${ACTION}" ;;
esac

require_root
if [[ "${ACTION}" == "status" ]]; then LOG_FILE="/dev/null"; else log_init remote-backup "${ACTION}"; fi
load_conf || die "Портал не установлен: не найден ${CONF_FILE}."
BACKUP_DIR="${BACKUP_DIR:-${DEFAULT_BACKUP_DIR}}"

case "${ACTION}" in
    status)
        if [[ -z "${BACKUP_REMOTE:-}" ]]; then
            info "Копии во внешнее хранилище не отправляются. Включить: ${APP_ID} remote-backup set НАЗНАЧЕНИЕ (подробнее: ${APP_ID} remote-backup --help)."
            exit 0
        fi
        info "Назначение: ${BACKUP_REMOTE}; хранится последних копий: ${BACKUP_REMOTE_KEEP:-14} (0 — все)."
        if remote_is_dir; then
            list=$( (find "${BACKUP_REMOTE%/}" -maxdepth 1 -type f -name "${APP_ID}-backup-*.tar.gz" -printf '%f\n' 2>/dev/null || true) | sort -r)
        elif command -v rclone >/dev/null 2>&1; then
            list=$( (rclone lsf "${BACKUP_REMOTE%/}" --files-only --include "${APP_ID}-backup-*.tar.gz" 2>/dev/null || true) | sort -r)
        else
            warn "rclone не установлен — копии в ${BACKUP_REMOTE} не отправляются. Установите rclone (https://rclone.org/install/)."
            exit 1
        fi
        if [[ -n "${list}" ]]; then
            info "Копии во внешнем хранилище ($(printf '%s\n' "${list}" | wc -l)):"
            printf '%s\n' "${list}" | head -n 20 | sed 's/^/   /'
        else
            warn "Во внешнем хранилище пока нет копий (или оно недоступно — проверьте: ${APP_ID} remote-backup test)."
        fi
        ;;
    set)
        [[ -n "${DEST}" ]] || { usage; die "Укажите назначение: имя:путь (rclone) или /каталог."; }
        # Настройки записывает и update.sh: одновременно с ним менять их нельзя.
        acquire_lock 60
        load_conf
        if [[ "${DEST}" == /* ]]; then
            [[ "${DEST}" != *..* ]] || die "Путь не должен содержать «..»."
            case "${DEST%/}" in
                "${APP_DIR}"|"${APP_DIR}"/*|"${BACKUP_DIR%/}"|"${BACKUP_DIR%/}"/*) die "Внешнее хранилище должно быть вне каталога портала и каталога резервных копий: ${DEST}" ;;
            esac
        elif [[ "${DEST}" =~ ^([A-Za-z0-9_][A-Za-z0-9_.-]*):(.*)$ ]]; then
            remote_name="${BASH_REMATCH[1]}"
            if ! command -v rclone >/dev/null 2>&1; then
                info "Устанавливается rclone…"
                pkg_install_optional rclone || true
                command -v rclone >/dev/null 2>&1 || die "Не удалось установить rclone из репозитория ОС. Установите его (https://rclone.org/install/), настройте хранилище (rclone config) и повторите."
            fi
            if ! rclone listremotes 2>/dev/null | grep -qx "${remote_name}:"; then
                die "Хранилище rclone «${remote_name}» не настроено. Выполните от root: rclone config — и повторите. Настроенные хранилища: $(rclone listremotes 2>/dev/null | tr '\n' ' ')"
            fi
        else
            die "Назначение — имя:путь хранилища rclone (например, yandex:backups/docportal) или абсолютный путь каталога."
        fi
        KEEP="${KEEP:-${BACKUP_REMOTE_KEEP:-14}}"
        [[ "${KEEP}" =~ ^[0-9]+$ ]] || die "--keep: укажите число."
        BACKUP_REMOTE="${DEST}"
        BACKUP_REMOTE_KEEP="${KEEP}"
        remote_backup_test || die "Проверка записи не пройдена: ${REMOTE_RESULT}. Настройка не сохранена."
        save_conf
        ok "Копии будут отправляться в ${BACKUP_REMOTE} после каждой ночной резервной копии (хранится последних: ${BACKUP_REMOTE_KEEP})."
        info "Отправить последнюю копию сейчас: ${APP_ID} remote-backup push"
        ;;
    off)
        if [[ -z "${BACKUP_REMOTE:-}" ]]; then info "Копии во внешнее хранилище и так не отправляются."; exit 0; fi
        acquire_lock 60
        load_conf
        old="${BACKUP_REMOTE}"
        BACKUP_REMOTE=""
        save_conf
        ok "Копии больше не отправляются в ${old}; отправленные ранее остались там."
        ;;
    test)
        [[ -n "${BACKUP_REMOTE:-}" ]] || die "Внешнее хранилище не настроено: ${APP_ID} remote-backup set НАЗНАЧЕНИЕ"
        if remote_backup_test; then ok "Проверка пройдена: ${REMOTE_RESULT}."; else die "Проверка не пройдена: ${REMOTE_RESULT}."; fi
        ;;
    push)
        [[ -n "${BACKUP_REMOTE:-}" ]] || die "Внешнее хранилище не настроено: ${APP_ID} remote-backup set НАЗНАЧЕНИЕ"
        if [[ -z "${ARCHIVE}" ]]; then
            ARCHIVE=$( (ls -1t "${BACKUP_DIR}"/${APP_ID}-backup-*.tar.gz 2>/dev/null || true) | head -n1)
            [[ -n "${ARCHIVE}" ]] || die "В ${BACKUP_DIR} нет резервных копий — создайте: ${APP_ID} backup"
        fi
        [[ -f "${ARCHIVE}" ]] || die "Архив не найден: ${ARCHIVE}"
        acquire_lock 1800
        info "Отправка $(basename "${ARCHIVE}") ($(human_size "$(stat -c %s "${ARCHIVE}")")) в ${BACKUP_REMOTE}…"
        started=${SECONDS}
        if remote_backup_push "${ARCHIVE}"; then
            record_job backup-remote ok "${REMOTE_RESULT}" "$((SECONDS - started))"
            ok "Отправлено: ${REMOTE_RESULT}"
        else
            record_job backup-remote error "${REMOTE_RESULT}" "$((SECONDS - started))"
            die "Копия не отправлена: ${REMOTE_RESULT}"
        fi
        ;;
esac
exit 0
