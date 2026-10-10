#!/usr/bin/env bash
# =============================================================================
#  Удаление портала документации.
#  Использование: sudo ./deploy/uninstall.sh [--purge] [--no-backup] [--yes]
#    без --purge  — удаляются код, конфигурация nginx, cron и CLI; база данных и резервные копии сохраняются
#                   (раздел «Видео»: службы удаляются, база видеопортала и видеофайлы сохраняются);
#    --purge      — дополнительно удаляются база данных, пользователь БД, тома Docker и файлы документов
#                   (и база видеопортала, видеофайлы, пользователь службы видеопортала).
#  Системные пакеты (nginx, PHP, MariaDB, Docker) не удаляются.
# =============================================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/common.sh
. "${SCRIPT_DIR}/common.sh"
# shellcheck source=deploy/video.sh
. "${SCRIPT_DIR}/video.sh"

PURGE="0"
DO_BACKUP="1"
ASSUME_YES="0"

usage() {
    cat <<EOF
Удаление портала «${APP_TITLE}».

Использование: sudo $0 [параметры]
  --purge        Удалить также базу данных, пользователя БД, файлы документов и тома Docker.
  --no-backup    Не делать финальную резервную копию перед удалением.
  --yes, -y      Не спрашивать подтверждения.
  --help         Справка.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --purge) PURGE="1"; shift ;;
        --no-backup) DO_BACKUP="0"; shift ;;
        --yes|-y) ASSUME_YES="1"; shift ;;
        --help|-h) usage; exit 0 ;;
        *) usage; die "Неизвестный параметр: $1" ;;
    esac
done

require_root "$@"
log_init uninstall "$@"
load_conf || die "Портал не установлен: не найден ${CONF_FILE}."
acquire_lock 0

banner "удаление"
info "Режим: ${INSTALL_MODE}; каталог: ${APP_DIR}; база: ${DB_NAME}; purge: ${PURGE}"
if [[ "${ASSUME_YES}" != "1" ]]; then
    if [[ "${PURGE}" == "1" ]]; then
        confirm "Будут удалены код, база данных «${DB_NAME}» и все файлы документов. Продолжить?" || die "Удаление отменено."
    else
        confirm "Будут удалены код и настройки веб-сервера (база данных сохранится). Продолжить?" || die "Удаление отменено."
    fi
fi

if [[ "${DO_BACKUP}" == "1" ]]; then
    step "Финальная резервная копия"
    if "${SCRIPT_DIR}/backup.sh" --tag before-uninstall --keep 0; then
        ok "Резервная копия сохранена в ${BACKUP_DIR}"
    else
        warn "Не удалось создать резервную копию."
        if [[ "${PURGE}" == "1" && "${ASSUME_YES}" != "1" ]]; then
            confirm "Продолжить удаление БЕЗ резервной копии?" || die "Удаление отменено."
        fi
    fi
fi

set +e
if [[ "${INSTALL_MODE}" == "docker" ]]; then
    step "Остановка контейнеров"
    if [[ -f "${APP_DIR}/docker/docker-compose.yml" ]]; then
        cd "${APP_DIR}/docker" || true
        if [[ "${PURGE}" == "1" ]]; then
            docker compose down -v --remove-orphans >>"${LOG_FILE}" 2>&1 && ok "Контейнеры и тома удалены" || warn "docker compose down завершился с ошибкой."
        else
            docker compose down --remove-orphans >>"${LOG_FILE}" 2>&1 && ok "Контейнеры остановлены (тома с базой и файлами сохранены)" || warn "docker compose down завершился с ошибкой."
        fi
        docker image rm "${APP_ID}:latest" "${APP_ID}:previous" "${APP_ID}-video:latest" >>"${LOG_FILE}" 2>&1 || true
        cd / || true
    fi
else
    step "Удаление конфигурации nginx"
    rm -f "/etc/nginx/sites-enabled/${APP_ID}.conf" "/etc/nginx/sites-available/${APP_ID}.conf" "/etc/nginx/conf.d/${APP_ID}.conf"
    # Правило режима обслуживания и его страница — после сайта, который их подключал.
    rm -f "${MAINTENANCE_SNIPPET}" "${MAINTENANCE_SNIPPET}.prev"
    if command -v semanage >/dev/null 2>&1; then semanage fcontext -d "$(maintenance_dir)(/.*)?" >/dev/null 2>&1 || true; fi
    rm -rf "$(maintenance_dir)"
    rmdir "/var/lib/${APP_ID}" 2>/dev/null || true
    # Раздел «Видео»: службы, правила nginx; с --purge — база видеопортала и видеофайлы.
    if [[ -f "/etc/systemd/system/${VIDEO_UNIT_API}.service" || -d "${VIDEO_NGINX_DIR}" || "${PURGE}" == "1" ]]; then
        step "Удаление раздела «Видео»"
        video_uninstall "${PURGE}"
    fi
    if nginx -t >>"${LOG_FILE}" 2>&1; then svc reload nginx && ok "nginx перезагружен"; else warn "nginx -t сообщил об ошибке — проверьте конфигурацию вручную."; fi
    if [[ -n "${PHP_VERSION}" ]]; then
        rm -f "/etc/php/${PHP_VERSION}/fpm/conf.d/90-${APP_ID}.ini" "/etc/php/${PHP_VERSION}/cli/conf.d/90-${APP_ID}.ini" "/etc/php.d/90-${APP_ID}.ini" \
              "/etc/php/${PHP_VERSION}/fpm/pool.d/zz-${APP_ID}.conf" "/etc/php-fpm.d/zz-${APP_ID}.conf"
        svc restart "${PHP_FPM_SERVICE}" >/dev/null 2>&1 || true
    fi
    if [[ "${PURGE}" == "1" && "${DB_LOCAL}" == "1" ]]; then
        # Базу и пользователя удаляем, только если их создала установка (в install.conf до 1.6.1 признаков
        # нет — тогда считается, что создала). Существовавшая до установки база сохраняется.
        if [[ "${DB_CREATED_BY_INSTALL:-1}" != "0" ]]; then
            step "Удаление базы данных ${DB_NAME}"
            mysql_root_exec "DROP DATABASE IF EXISTS \`${DB_NAME}\`;" && ok "База данных удалена" || warn "Не удалось удалить базу данных."
        else
            warn "База данных «${DB_NAME}» существовала до установки портала — не удаляется."
        fi
        if [[ "${DB_USER_CREATED_BY_INSTALL:-1}" != "0" ]]; then
            mysql_root_exec "DROP USER IF EXISTS '${DB_USER}'@'localhost'; DROP USER IF EXISTS '${DB_USER}'@'127.0.0.1'; FLUSH PRIVILEGES;" || warn "Не удалось удалить пользователя БД."
        else
            warn "Пользователь БД «${DB_USER}» существовал до установки портала — не удаляется."
        fi
    elif [[ "${PURGE}" == "1" ]]; then
        warn "База данных на внешнем сервере ${DB_HOST} не удаляется автоматически."
    fi
fi

step "Удаление файлов"
rm -f "/etc/cron.d/${APP_ID}" "/usr/local/bin/${APP_ID}" "/etc/logrotate.d/${APP_ID}"
if [[ "${PURGE}" == "1" ]]; then
    rm -rf "${APP_DIR}" && ok "Каталог ${APP_DIR} удалён"
else
    # Файлы документов и неимпортированные файлы переносятся в каталог резервных копий. Если перенести
    # не удалось (например, не хватило места), каталог установки НЕ удаляется — иначе файлы пропали бы.
    KEEP_DIR="${BACKUP_DIR:-${DEFAULT_BACKUP_DIR}}/storage-kept-$(date '+%Y%m%d-%H%M%S')"
    keep_failed="0"
    keep_copy() { # keep_copy ИСТОЧНИК НАЗНАЧЕНИЕ описание
        [[ -d "$1" && -n "$(ls -A "$1" 2>/dev/null)" ]] || return 0
        if mkdir -p "$2" && cp -a "$1/." "$2/"; then ok "$3 сохранены в $2"; else error "Не удалось сохранить $3 в $2."; keep_failed="1"; fi
    }
    if [[ "${INSTALL_MODE}" == "native" ]]; then
        keep_copy "${APP_DIR}/shared/storage" "${KEEP_DIR}" "Файлы документов"
        keep_copy "${APP_DIR}/shared/import" "${KEEP_DIR}-import" "Неимпортированные файлы из каталога импорта"
    else
        keep_copy "${APP_DIR}/import" "${KEEP_DIR}-import" "Неимпортированные файлы из каталога импорта"
    fi
    if [[ "${keep_failed}" == "1" ]]; then
        error "Каталог ${APP_DIR} НЕ удалён, чтобы не потерять файлы. Освободите место в ${BACKUP_DIR:-${DEFAULT_BACKUP_DIR}} и удалите его вручную после копирования."
    else
        rm -rf "${APP_DIR}" && ok "Каталог ${APP_DIR} удалён (база данных сохранена)"
    fi
fi
rm -f "${CONF_DIR}/admin-credentials.txt" "${CONF_FILE}"
rmdir "${CONF_DIR}" 2>/dev/null || true

printf '\n%sПортал удалён.%s Резервные копии: %s; журналы: %s\n' "${C_GREEN}" "${C_RESET}" "${BACKUP_DIR:-${DEFAULT_BACKUP_DIR}}" "${LOG_DIR}"
[[ "${PURGE}" != "1" ]] && printf 'База данных «%s» сохранена. Для полного удаления запустите с --purge.\n' "${DB_NAME}"
exit 0
