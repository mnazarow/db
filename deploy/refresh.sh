#!/usr/bin/env bash
# =============================================================================
#  Обновление служебных файлов установки из текущего релиза:
#   - команда управления /usr/local/bin/docportal;
#   - задания cron (проверка сроков, резервные копии, описания через LLM);
#   - недостающие системные пакеты (poppler-utils для извлечения текста из PDF).
#
#  Нужен, если обновление выполнялось скриптами предыдущей версии (переход с 1.2.0 и старше
#  командой «docportal update»): код уже новый, а команда управления и cron остались прежними.
#
#  Запуск: sudo /opt/docportal/current/deploy/refresh.sh
# =============================================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/common.sh
. "${SCRIPT_DIR}/common.sh"

require_root "$@"
log_init refresh "$@"
load_conf || die "Портал не установлен: не найден ${CONF_FILE}."
banner "обновление служебных файлов"

step "Команда управления и задания cron"
install_cli_wrapper && ok "Команда ${APP_ID} обновлена: /usr/local/bin/${APP_ID}"
if [[ "${INSTALL_MODE}" == "native" ]]; then
    ensure_cron_entries
    ok "Задания cron проверены: /etc/cron.d/${APP_ID}"
    if ! command -v pdftotext >/dev/null 2>&1; then
        if pkg_install_optional poppler-utils && command -v pdftotext >/dev/null 2>&1; then
            ok "Установлен пакет poppler-utils (pdftotext) — извлечение текста из PDF для API и LLM."
        else
            warn "Не удалось установить poppler-utils — установите пакет вручную, иначе текст из PDF не извлекается."
        fi
    else
        ok "pdftotext доступен."
    fi
fi

printf '\nГотово. Проверить состояние портала: %s status\n' "${APP_ID}"
