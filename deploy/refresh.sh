#!/usr/bin/env bash
# =============================================================================
#  Обновление служебных файлов установки из текущего релиза:
#   - команда управления /usr/local/bin/docportal;
#   - задания cron (проверка сроков, резервные копии, описания через LLM);
#   - недостающие системные пакеты (poppler-utils для извлечения текста из PDF);
#   - по запросу — LibreOffice (--with-preview) и tesseract (--with-ocr).
#
#  Нужен, если обновление выполнялось скриптами предыдущей версии (переход с 1.2.0 и старше
#  командой «docportal update»): код уже новый, а команда управления и cron остались прежними.
#
#  Запуск: sudo /opt/docportal/current/deploy/refresh.sh [--with-preview] [--with-ocr] [--with-extras]
# =============================================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/common.sh
. "${SCRIPT_DIR}/common.sh"

WITH_PREVIEW="0"
WITH_OCR="0"
for arg in "$@"; do
    case "${arg}" in
        --with-preview) WITH_PREVIEW="1" ;;
        --with-ocr) WITH_OCR="1" ;;
        --with-extras) WITH_PREVIEW="1"; WITH_OCR="1" ;;
    esac
done

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
    if [[ "${WITH_PREVIEW}" == "1" ]]; then
        if install_preview_tools; then ok "Установлен LibreOffice: просмотр офисных файлов прямо в браузере."
        else warn "Не удалось установить LibreOffice — предпросмотр docx/xlsx/pptx будет недоступен."; fi
    elif ! command -v soffice >/dev/null 2>&1; then
        info "Предпросмотр офисных файлов выключен (нет LibreOffice). Включить: ${SCRIPT_DIR}/refresh.sh --with-preview"
    fi
    if [[ "${WITH_OCR}" == "1" ]]; then
        if install_ocr_tools; then ok "Установлен tesseract: распознавание сканов (включается в панели администратора → Настройки)."
        else warn "Не удалось установить tesseract — распознавание сканов будет недоступно."; fi
    elif ! command -v tesseract >/dev/null 2>&1; then
        info "Распознавание сканов выключено (нет tesseract). Включить: ${SCRIPT_DIR}/refresh.sh --with-ocr"
    fi
fi

printf '\nГотово. Проверить состояние портала: %s status\n' "${APP_ID}"
