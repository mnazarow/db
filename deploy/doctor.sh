#!/usr/bin/env bash
# =============================================================================
#  Диагностика портала документации: службы, база данных, место на диске, ответ HTTP.
#  Использование: sudo ./deploy/doctor.sh   (или: docportal status)
# =============================================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/common.sh
. "${SCRIPT_DIR}/common.sh"

require_root "$@"
LOG_FILE="/dev/null"
load_conf || die "Портал не установлен: не найден ${CONF_FILE}."
banner "диагностика"
PROBLEMS=0
check() { # check "описание" команда…
    local title=$1; shift
    if "$@" >/dev/null 2>&1; then ok "${title}"; else error "${title}"; PROBLEMS=$((PROBLEMS + 1)); fi
}

printf 'Версия: %s   Режим: %s   Каталог: %s   Установлен: %s\n\n' "${APP_VERSION}" "${INSTALL_MODE}" "${APP_DIR}" "${INSTALLED_AT}"

if [[ "${INSTALL_MODE}" == "docker" ]]; then
    check "Docker запущен" docker info
    cd "${APP_DIR}/docker" 2>/dev/null || die "Нет каталога ${APP_DIR}/docker"
    for s in db app cron web; do
        check "Контейнер ${s} работает" bash -c "docker compose ps --status running ${s} | grep ${s} >/dev/null"
    done
    info "Состояние контейнеров:"; docker compose ps 2>/dev/null | sed 's/^/   /'
else
    check "Служба ${PHP_FPM_SERVICE} работает" svc status "${PHP_FPM_SERVICE}"
    check "Служба nginx работает" svc status nginx
    [[ "${DB_LOCAL}" == "1" ]] && check "Служба ${DB_SERVICE} работает" svc status "${DB_SERVICE}"
    check "Конфигурация nginx корректна (nginx -t)" nginx -t
    check "Каталог текущего релиза существует" test -d "${APP_DIR}/current/public"
    check "Хранилище документов доступно для записи пользователю ${SERVICE_USER}" runuser -u "${SERVICE_USER}" -- test -w "${APP_DIR}/shared/storage"
    check "Подключение к базе данных" mysql_exec "${DB_HOST}" "${DB_PORT}" "${DB_USER}" "${DB_PASSWORD}" "SELECT 1"
    info "Проверка приложения (app:check):"
    runuser -u "${SERVICE_USER}" -- php "${APP_DIR}/current/bin/console" app:check --env=prod --no-interaction 2>&1 | sed 's/^/   /' || PROBLEMS=$((PROBLEMS + 1))
fi

url=$(health_url)
if body=$(health_probe); then ok "Ответ ${url}: ${body}"; else error "Ответ ${url}: ${body:-нет ответа}"; PROBLEMS=$((PROBLEMS + 1)); fi

if [[ "${SSL_ENABLED:-0}" == "1" && -n "${DOMAIN:-}" ]]; then
    # Сертификат проверяется по-настоящему (без -k): доверие, срок действия, соответствие домену.
    if curl -sSf -o /dev/null -m 10 --noproxy '*' --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/health" 2>/dev/null; then
        exp=""
        if command -v openssl >/dev/null 2>&1; then
            exp=$(echo | openssl s_client -servername "${DOMAIN}" -connect 127.0.0.1:443 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2-)
        fi
        if [[ -n "${exp}" ]] && exp_ts=$(date -d "${exp}" +%s 2>/dev/null); then
            days=$(( (exp_ts - $(date +%s)) / 86400 ))
            if [[ ${days} -lt 7 ]]; then warn "Сертификат HTTPS истекает через ${days} дн. (${exp}) — проверьте: certbot renew --dry-run"; PROBLEMS=$((PROBLEMS + 1)); else ok "Сертификат HTTPS действителен ещё ${days} дн."; fi
        else
            ok "Сертификат HTTPS для ${DOMAIN} проходит проверку"
        fi
    else
        warn "Сертификат HTTPS для ${DOMAIN} не проходит проверку (истёк, самоподписанный или не для этого домена)"; PROBLEMS=$((PROBLEMS + 1))
    fi
    if command -v certbot >/dev/null 2>&1 && [[ -d /etc/letsencrypt/renewal ]]; then
        if systemctl list-timers certbot.timer 2>/dev/null | grep_has certbot; then ok "Автопродление сертификата: systemd-таймер certbot.timer активен"
        elif [[ -f /etc/cron.d/certbot ]]; then ok "Автопродление сертификата: задание cron /etc/cron.d/certbot"
        else warn "Не найден таймер/cron автопродления certbot — проверьте: systemctl status certbot.timer"; fi
    fi
fi

free_mb=$(free_space_mb "${APP_DIR}")
if [[ -n "${free_mb}" ]]; then
    if [[ ${free_mb} -lt 1024 ]]; then warn "Свободно на диске: ${free_mb} МБ (мало!)"; PROBLEMS=$((PROBLEMS + 1)); else ok "Свободно на диске: ${free_mb} МБ"; fi
fi
if [[ -d "${BACKUP_DIR}" ]]; then
    last=$(ls -1t "${BACKUP_DIR}"/${APP_ID}-backup-*.tar.gz 2>/dev/null | head -n1)
    if [[ -n "${last}" ]]; then ok "Последняя резервная копия: $(basename "${last}") ($(date -r "${last}" '+%d.%m.%Y %H:%M'))"; else warn "Резервных копий пока нет (${BACKUP_DIR})"; fi
fi

if [[ "${INSTALL_MODE}" == "native" ]]; then
    logf=$(ls -t "${APP_DIR}"/shared/log/prod*.log 2>/dev/null | head -n1)
    if [[ -n "${logf}" && -f "${logf}" ]]; then
        errs=$(grep -c 'CRITICAL\|ERROR' "${logf}" 2>/dev/null || echo 0)
        info "Ошибок в ${logf}: ${errs}. Последние записи:"
        tail -n 5 "${logf}" | cut -c1-200 | sed 's/^/   /'
    fi
fi

echo
if [[ ${PROBLEMS} -eq 0 ]]; then ok "Проблем не обнаружено."; exit 0; else error "Обнаружено проблем: ${PROBLEMS}."; exit 1; fi
