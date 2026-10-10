#!/usr/bin/env bash
# =============================================================================
#  Автоматическое обновление портала по расписанию.
#
#  Использование: docportal auto-update <команда>   (или: sudo ./deploy/auto-update.sh <команда>)
#    on [--time ЧЧ:ММ] [--github ВЛАДЕЛЕЦ/РЕПО [--token-file ФАЙЛ] | --url АДРЕС | --local]
#                        включить: каждую ночь (по умолчанию в 04:30) проверять источник обновлений и, если
#                        там есть версия новее установленной, обновляться (update.sh --auto: резервная копия,
#                        режим обслуживания, откат при ошибке). Установка портала включает это сама.
#    off                 выключить
#    status              состояние: расписание, источник и последняя версия на нём, архивы, итог запуска
#    run                 проверить источник и обновиться сейчас (как ночью)
#
#  Источник обновлений: каталог /var/lib/docportal/updates (архив docportal-<версия>.tar.gz и .sha256 кладут
#  туда вручную) и, если задан, — релизы GitHub (--github) или каталог на веб-сервере (--url: latest.txt и
#  архивы рядом); оттуда новая версия скачивается сама. Если обновление не удалось, портал возвращается к прежней версии, а эта
#  версия повторно автоматически не ставится (файл .failed-<версия> в каталоге обновлений; удалить его —
#  разрешить повтор). Итог каждого запуска — в панели администратора → «Мониторинг».
# =============================================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/common.sh
. "${SCRIPT_DIR}/common.sh"

UPDATES_DIR="/var/lib/${APP_ID}/updates"
CRON_FILE="/etc/cron.d/${APP_ID}"
CRON_LOG="${LOG_DIR}/auto-update-cron.log"

usage() {
    cat <<EOF
Автоматическое обновление портала «${APP_TITLE}» по расписанию.

Использование: ${APP_ID} auto-update <команда>
  on [параметры]      Включить: ежедневно обновляться, если появилась версия новее установленной.
     --time ЧЧ:ММ           время проверки (по умолчанию 04:30)
     --github ВЛАДЕЛЕЦ/РЕПО скачивать новые версии из релизов GitHub (архив и latest.txt выкладывает сборка релиза)
     --token-file ФАЙЛ      токен GitHub для закрытого репозитория (право чтения содержимого); хранится в ${UPDATE_TOKEN_FILE}
     --url АДРЕС            скачивать из каталога на веб-сервере (АДРЕС/latest.txt и АДРЕС/${APP_ID}-<версия>.tar.gz)
     --local                не скачивать: только архивы, положенные в ${UPDATES_DIR}
  off                 Выключить.
  status              Расписание, источник и последняя версия на нём, архивы, итог последнего запуска.
  run                 Проверить источник и обновиться сейчас.
Архив ${APP_ID}-<версия>.tar.gz (и .sha256), положенный в ${UPDATES_DIR}, ставится при любом источнике.
EOF
}

# Строка расписания (минуты, часы) → строка cron.
cron_line() { printf '%s %s * * * root /usr/local/bin/%s update --auto >> %s 2>&1\n' "$1" "$2" "${APP_ID}" "${CRON_LOG}"; }

# Записать файл заданий cron без строки автообновления (и со строкой, если передана).
write_auto_line() {
    local line=${1:-} tmp="/etc/cron.d/.${APP_ID}.auto.tmp"
    if [[ -f "${CRON_FILE}" ]]; then
        grep -v -E "/${APP_ID} update --auto( |$)" "${CRON_FILE}" > "${tmp}" || true
    else
        printf '%s\n' "${CRON_ENV_LINES}" > "${tmp}"
    fi
    if ! grep -q '^PATH=' "${tmp}"; then
        { printf '%s\n' "${CRON_ENV_LINES}"; cat "${tmp}"; } > "${tmp}.2" && mv -f "${tmp}.2" "${tmp}"
    fi
    if [[ -n "${line}" ]]; then
        [[ -s "${tmp}" && -n "$(tail -c1 "${tmp}")" ]] && printf '\n' >> "${tmp}"
        printf '%s\n' "${line}" >> "${tmp}"
    fi
    chmod 644 "${tmp}"
    mv -f "${tmp}" "${CRON_FILE}"
}

# Версия из имени архива: docportal-1.8.0.tar.gz → 1.8.0
archive_version() { local v; v=$(basename "$1"); v=${v#"${APP_ID}"-}; v=${v%.tar.gz}; printf '%s' "${v%-src}"; }

cmd="${1:-status}"
shift || true
case "${cmd}" in
    on)
        hh=""; mm=""; src=""; src_value=""; token_file=""
        while [[ $# -gt 0 ]]; do
            case "$1" in
                --time)
                    [[ "${2:-}" =~ ^([01]?[0-9]|2[0-3]):([0-5][0-9])$ ]] || die "--time: укажите время в формате ЧЧ:ММ (например, 03:40)."
                    hh=$((10#${BASH_REMATCH[1]})); mm=$((10#${BASH_REMATCH[2]})); shift 2 ;;
                --github)
                    [[ "${2:-}" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || die "--github: укажите репозиторий как ВЛАДЕЛЕЦ/РЕПОЗИТОРИЙ (например, example/docportal)."
                    src="github"; src_value="$2"; shift 2 ;;
                --url)
                    [[ "${2:-}" =~ ^https?://[^[:space:]]+$ ]] || die "--url: укажите адрес каталога обновлений (http:// или https://)."
                    src="url"; src_value="${2%/}"; shift 2 ;;
                --token-file)
                    [[ -n "${2:-}" && -s "${2}" ]] || die "--token-file: файл с токеном не найден или пуст: ${2:-}"
                    token_file="$2"; shift 2 ;;
                --local) src="local"; shift ;;
                *) usage; die "Неизвестный параметр: $1" ;;
            esac
        done
        require_root "$@"
        log_init auto-update-config "${cmd}"
        load_conf || die "Портал не установлен: не найден ${CONF_FILE}."
        # Время: заданное, иначе прежнее из расписания, иначе 04:30.
        if [[ -z "${hh}" ]]; then
            prev=$(grep -E "/${APP_ID} update --auto( |$)" "${CRON_FILE}" 2>/dev/null | grep -v '^[[:space:]]*#' | head -n1 || true)
            if [[ -n "${prev}" ]]; then mm=$(awk '{print $1}' <<<"${prev}"); hh=$(awk '{print $2}' <<<"${prev}"); else hh="4"; mm="30"; fi
        fi
        case "${src}" in
            github) UPDATE_GITHUB="${src_value}"; UPDATE_URL="" ;;
            url) UPDATE_URL="${src_value}"; UPDATE_GITHUB="" ;;
            local) UPDATE_URL=""; UPDATE_GITHUB=""; rm -f "${UPDATE_TOKEN_FILE}" ;;
        esac
        if [[ -n "${token_file}" ]]; then
            [[ -n "${UPDATE_GITHUB:-}" ]] || die "--token-file нужен только вместе с --github."
            install -m 600 -o root -g root "${token_file}" "${UPDATE_TOKEN_FILE}"
            info "Токен GitHub сохранён в ${UPDATE_TOKEN_FILE} (права 600). Исходный файл можно удалить."
        fi
        install -d -m 700 "${UPDATES_DIR}"
        write_auto_line "$(cron_line "${mm}" "${hh}")"
        AUTO_UPDATE="1"
        save_conf
        ok "Автоматическое обновление включено: ежедневно в $(printf '%02d:%02d' "${hh}" "${mm}"); источник — $(update_source_label)."
        if [[ -n "${UPDATE_URL:-}${UPDATE_GITHUB:-}" ]]; then
            rc=0; remote_latest || rc=$?
            if [[ ${rc} -eq 0 ]]; then
                ok "Источник отвечает: последняя версия ${REMOTE_VERSION} (установлена ${APP_VERSION:-?})$(version_gt "${REMOTE_VERSION}" "${APP_VERSION:-0}" && echo ' — будет установлена в назначенное время')."
            else
                warn "Источник обновлений сейчас недоступен: ${REMOTE_ERROR}. Расписание включено — проверьте доступ: ${APP_ID} auto-update status"
            fi
        else
            printf 'Положите архив новой версии (%s-<версия>.tar.gz и, желательно, .sha256) в %s —\n' "${APP_ID}" "${UPDATES_DIR}"
            printf 'портал обновится в назначенное время. Скачивать новые версии сам: %s auto-update on --github ВЛАДЕЛЕЦ/РЕПО\n' "${APP_ID}"
        fi
        printf 'Обновление: резервная копия, режим обслуживания, при ошибке — откат. Итог — в панели администратора →\n'
        printf '«Мониторинг» и в %s. Выключить: %s auto-update off\n' "${CRON_LOG}" "${APP_ID}"
        print_warnings_summary
        ;;
    off)
        require_root "$@"
        log_init auto-update-config "${cmd}"
        load_conf || die "Портал не установлен: не найден ${CONF_FILE}."
        [[ -f "${CRON_FILE}" ]] && write_auto_line ""
        AUTO_UPDATE="0"
        save_conf
        ok "Автоматическое обновление выключено. Обновиться вручную: ${APP_ID} update"
        ;;
    status)
        require_root "$@"
        LOG_FILE="/dev/null"
        load_conf || die "Портал не установлен: не найден ${CONF_FILE}."
        line=$(grep -E "/${APP_ID} update --auto( |$)" "${CRON_FILE}" 2>/dev/null | grep -v '^[[:space:]]*#' | head -n1 || true)
        if [[ -n "${line}" ]]; then
            ok "Автоматическое обновление включено: ежедневно в $(awk '{printf "%02d:%02d", $2, $1}' <<<"${line}")"
        else
            info "Автоматическое обновление выключено (включить: ${APP_ID} auto-update on)."
        fi
        printf 'Установленная версия: %s\nКаталог обновлений: %s\nИсточник: %s\n' "${APP_VERSION:-?}" "${UPDATES_DIR}" "$(update_source_label)"
        if [[ -n "${UPDATE_URL:-}${UPDATE_GITHUB:-}" ]]; then
            rc=0; remote_latest || rc=$?
            if [[ ${rc} -eq 0 ]]; then
                printf '  последняя версия на источнике: %s%s\n' "${REMOTE_VERSION}" "$(version_gt "${REMOTE_VERSION}" "${APP_VERSION:-0}" && echo ' — новее установленной, будет установлена' || echo ' — новее установленной нет')"
            else
                warn "Источник обновлений недоступен: ${REMOTE_ERROR}"
            fi
        fi
        found="0"
        for f in "${UPDATES_DIR}"/"${APP_ID}"-[0-9]*.tar.gz; do
            [[ -f "${f}" ]] || continue
            found="1"; v=$(archive_version "${f}")
            state="старше или та же версия — не ставится"
            if [[ "${v}" != "${APP_VERSION:-}" ]] && version_ge "${v}" "${APP_VERSION:-0}"; then state="будет установлен"; fi
            [[ -e "${UPDATES_DIR}/.failed-${v}" ]] && state="обновление не удалось: $(head -c 160 "${UPDATES_DIR}/.failed-${v}") — повтор: rm ${UPDATES_DIR}/.failed-${v}"
            printf '  %s (%s) — %s\n' "$(basename "${f}")" "$(human_size "$(stat -c %s "${f}")")" "${state}"
        done
        [[ "${found}" == "1" ]] || printf '  архивов нет\n'
        if [[ -s "${CRON_LOG}" ]]; then
            printf 'Последний запуск (%s):\n' "${CRON_LOG}"
            grep -E '✔|✖|⚠|Новой версии нет|Обновление завершено' "${CRON_LOG}" | tail -n 5 | sed 's/^/  /' || true
        fi
        ;;
    run)
        exec "${SCRIPT_DIR}/update.sh" --auto "$@"
        ;;
    help|--help|-h) usage ;;
    *) usage; die "Неизвестная команда: ${cmd}" ;;
esac
