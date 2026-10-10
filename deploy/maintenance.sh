#!/usr/bin/env bash
# =============================================================================
#  Режим обслуживания портала: посетители видят страницу «Идёт обновление портала» (ответ 503),
#  а проверки /health, /health/details и /metrics работают как обычно.
#
#  Использование: sudo docportal maintenance on [--message "текст"] [--allow IP,IP] | off | status
#  update.sh и restore.sh включают режим сами — на время изменения базы данных и файлов.
# =============================================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/common.sh
. "${SCRIPT_DIR}/common.sh"

usage() {
    cat <<EOF
Режим обслуживания портала «${APP_TITLE}».

Использование: sudo ${APP_ID} maintenance <действие> [параметры]
  on                  Включить: посетители видят страницу «Идёт обновление портала».
    --message "текст" Пояснение на странице (например: «Плановые работы до 18:00»).
    --allow IP,IP     Адреса компьютеров, с которых портал остаётся доступен (например, администратора).
  off                 Выключить.
    --force           Выключить, даже если режим включило ещё не закончившееся обновление или восстановление.
  status              Показать, включён ли режим (код возврата 0 — включён).
EOF
}

ACTION="${1:-status}"
[[ $# -gt 0 ]] && shift
MESSAGE=""
ALLOW=""
FORCE="0"
while [[ $# -gt 0 ]]; do
    case "$1" in
        --message) MESSAGE="${2:-}"; shift 2 ;;
        --allow) ALLOW="${2:-}"; shift 2 ;;
        --force) FORCE="1"; shift ;;
        --help|-h) usage; exit 0 ;;
        *) usage; die "Неизвестный параметр: $1" ;;
    esac
done

case "${ACTION}" in
    on|off|status) ;;
    --help|-h|help) usage; exit 0 ;;
    *) usage; die "Неизвестное действие: ${ACTION}" ;;
esac

require_root "$@"
if [[ "${ACTION}" == "status" ]]; then LOG_FILE="/dev/null"; else log_init maintenance "${ACTION}" "$@"; fi
load_conf || die "Портал не установлен: не найден ${CONF_FILE}."

# Режим, который включили update.sh или restore.sh, они же и выключат: вмешательство посреди их работы
# открыло бы портал с наполовину обновлёнными данными (или оставило бы его закрытым после успеха).
OWNER=$(maintenance_owner)
if [[ "${ACTION}" != "status" ]] && maintenance_is_on && [[ "${OWNER}" == "update" || "${OWNER}" == "restore" ]] && [[ "${FORCE}" != "1" ]]; then
    case "${OWNER}" in
        update) what="обновление портала" ;;
        *) what="восстановление из резервной копии" ;;
    esac
    if [[ "${ACTION}" == "on" ]]; then
        die "Режим обслуживания уже включён: идёт (или было прервано) ${what}. Он выключится по окончании; принудительно: ${APP_ID} maintenance off --force"
    fi
    die "Режим обслуживания включило ${what} — оно выключит его само по окончании. Если операция прервалась и портал можно открывать, выполните: ${APP_ID} maintenance off --force"
fi

case "${ACTION}" in
    on)
        if ! maintenance_wired; then
            if [[ "${INSTALL_MODE}" == "native" ]]; then
                ensure_nginx_maintenance || die "Режим обслуживания не подключён к nginx — посетители его не увидят (см. сообщение выше)."
            else
                die "Конфигурация nginx контейнера не знает о режиме обслуживания (портал установлен версией до 1.7.0). Обновите портал: ${APP_ID} update."
            fi
        fi
        maintenance_on "${MESSAGE}" "${ALLOW}" admin || die "Режим обслуживания не включён."
        ok "Режим обслуживания включён: посетители видят страницу «Идёт обновление портала»."
        [[ -n "${ALLOW}" ]] && info "Портал по-прежнему доступен с адресов: ${ALLOW//,/, }."
        info "Выключить: ${APP_ID} maintenance off"
        ;;
    off)
        if maintenance_is_on; then
            maintenance_off
            ok "Режим обслуживания выключен: портал снова доступен всем."
        else
            info "Режим обслуживания уже выключен."
        fi
        ;;
    status)
        if maintenance_describe; then
            if ! maintenance_wired; then
                if [[ "${INSTALL_MODE}" == "native" ]]; then
                    warn "Правило режима не подключено к nginx — посетители страницу обслуживания не видят. Выполните: sudo ${APP_DIR}/current/deploy/refresh.sh"
                else
                    warn "Конфигурация nginx контейнера не знает о режиме обслуживания — обновите портал: ${APP_ID} update."
                fi
            fi
            exit 0
        fi
        exit 1
        ;;
esac
exit 0
