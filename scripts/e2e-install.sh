#!/usr/bin/env bash
# =============================================================================
#  Сквозная проверка скриптов развёртывания на чистом сервере (CI: .github/workflows/e2e.yml).
#  Запускается от root на сервере (или в контейнере с systemd), где портала ещё нет:
#
#    sudo ./scripts/e2e-install.sh АРХИВ АРХИВ_НОВОЙ_ВЕРСИИ [native|docker] [video|no-video]
#
#  Сценарий: установка из архива → проверки (сайт, вход администратора, docportal status, cron,
#  автообновление, раздел «Видео») → резервная копия и её проверка → автоматическое обновление на новую
#  версию из каталога обновлений → повторный запуск (новой версии нет) → восстановление из копии →
#  удаление с --purge (ничего не осталось). Любая неудача — код 1 и журналы скриптов на экран.
# =============================================================================
set -Eeuo pipefail

ARCHIVE=${1:?архив дистрибутива}
NEXT_ARCHIVE=${2:?архив новой версии}
MODE=${3:-native}
VIDEO=${4:-no-video}
PORT=${E2E_PORT:-8080}
ADMIN_PASSWORD='E2e-Admin-2026!'
APP=docportal
BASE="http://127.0.0.1:${PORT}"
WORK=$(mktemp -d)
STEP=""

step() { STEP="$*"; printf '\n::group::%s\n' "$*"; }
endstep() { printf '::endgroup::\n'; }
fail() {
    printf '\n::error::%s: %s\n' "${STEP}" "$*"
    printf '%s\n' "----- Журналы скриптов -----"
    for f in $(ls -1t /var/log/${APP}/*.log 2>/dev/null | head -n 4); do printf '== %s\n' "${f}"; tail -n 80 "${f}"; done
    if [[ "${MODE}" == "docker" && -d /opt/${APP}/docker ]]; then (cd /opt/${APP}/docker && docker compose ps && docker compose logs --tail=60) || true; fi
    if command -v journalctl >/dev/null 2>&1; then journalctl -n 60 --no-pager 2>/dev/null || true; fi
    exit 1
}
trap 'fail "команда завершилась с ошибкой (строка ${LINENO}): ${BASH_COMMAND}"' ERR
expect_health() {
    local body n
    for n in $(seq 1 60); do
        body=$(curl -fsS -m 5 "${BASE}/health" 2>/dev/null || true)
        [[ "${body}" == *'"status":"ok"'* ]] && { printf 'Портал отвечает: %s\n' "${body}"; return 0; }
        sleep 2
    done
    fail "портал не отвечает на ${BASE}/health (последний ответ: ${body:-нет})"
}
installed_version() { sed -n "s/^APP_VERSION=//p" "/etc/${APP}/install.conf" | tr -d "'\""; }
archive_version() { local v; v=$(basename "$1"); v=${v#"${APP}"-}; printf '%s' "${v%.tar.gz}"; }

[[ ${EUID} -eq 0 ]] || { echo "Запускайте от root." >&2; exit 1; }
[[ ! -e /etc/${APP}/install.conf ]] || { echo "Портал уже установлен — сценарий только для чистого сервера." >&2; exit 1; }

step "Установка (${MODE}, ${VIDEO})"
tar -xzf "${ARCHIVE}" -C "${WORK}"
install_args=(--mode "${MODE}" --port "${PORT}" --admin-password "${ADMIN_PASSWORD}")
[[ "${VIDEO}" == "video" ]] && install_args+=(--with-video --video-no-live) || install_args+=(--no-video)
# E2E_PHP=from-os: PHP из репозиториев ОС, затем переход на 8.4 командой php-upgrade.
[[ "${E2E_PHP:-}" == "from-os" ]] && install_args+=(--php-from-os)
"${WORK}/${APP}/deploy/install.sh" "${install_args[@]}" || fail "install.sh завершился с ошибкой"
endstep

step "Проверки после установки"
expect_health
[[ "$(installed_version)" == "$(archive_version "${ARCHIVE}")" ]] || fail "установлена версия $(installed_version), ожидалась $(archive_version "${ARCHIVE}")"
curl -fsS -m 10 -o /dev/null "${BASE}/" || fail "главная страница не открывается"
# Вход администратора: форма входа с CSRF-токеном → перенаправление на главную.
jar="${WORK}/cookies"
token=$(curl -fsS -m 10 -c "${jar}" "${BASE}/login" | grep -o 'name="_csrf_token" value="[^"]*"' | sed 's/.*value="//; s/"$//')
[[ -n "${token}" ]] || fail "на странице входа нет CSRF-токена"
code=$(curl -sS -m 10 -b "${jar}" -c "${jar}" -o /dev/null -w '%{http_code}' --data-urlencode "_username=admin" --data-urlencode "_password=${ADMIN_PASSWORD}" --data-urlencode "_csrf_token=${token}" "${BASE}/login")
[[ "${code}" == "302" ]] || fail "вход администратора: ответ ${code}, ожидалось перенаправление"
curl -fsS -m 10 -b "${jar}" "${BASE}/admin/monitoring" | grep -q 'Мониторинг' || fail "администратор не видит «Мониторинг» после входа"
${APP} status || fail "docportal status сообщил о проблемах"
if [[ "${MODE}" == "native" ]]; then
    grep -q "console messenger:consume" /etc/cron.d/${APP} || fail "нет задания cron обработчика фоновых задач"
    php_version() { sed -n "s/^PHP_VERSION=//p" /etc/${APP}/install.conf | tr -d "'\""; }
    if [[ "${E2E_PHP:-}" == "from-os" ]]; then
        printf 'PHP из репозиториев ОС: %s\n' "$(php_version)"
        ${APP} php-upgrade --check
        ${APP} php-upgrade --yes || fail "php-upgrade завершился с ошибкой"
        expect_health
        ${APP} status || fail "docportal status после php-upgrade сообщил о проблемах"
    fi
    [[ "$(printf '%s\n%s\n' 8.4 "$(php_version)" | sort -V | head -n1)" == "8.4" ]] || fail "PHP $(php_version) — ожидался 8.4 или новее"
    printf 'PHP портала: %s\n' "$(php_version)"
fi
grep -q "/${APP} update --auto" /etc/cron.d/${APP} || fail "автообновление не включено при установке"
grep -q '^AUTO_UPDATE=1' /etc/${APP}/install.conf || fail "AUTO_UPDATE=1 не записан в install.conf"
${APP} auto-update status
if [[ "${VIDEO}" == "video" ]]; then
    curl -fsS -m 10 "${BASE}/video/api/health" | grep -q '"status":"ok"' || fail "раздел «Видео» не отвечает через портал"
    [[ "$(curl -s -m 10 -o /dev/null -w '%{http_code}' "${BASE}/video/api/internal/status")" == "404" ]] || fail "внутренние адреса видеопортала доступны снаружи"
    ${APP} video status || fail "docportal video status сообщил о проблемах"
    ${APP} video sync-users || fail "сотрудники не перенесены в раздел «Видео»"
fi
endstep

step "Резервная копия и её проверка"
${APP} backup || fail "резервная копия не создана"
BACKUP=$(ls -1t /var/backups/${APP}/${APP}-backup-*.tar.gz | head -n1)
[[ -s "${BACKUP}" ]] || fail "нет файла резервной копии"
${APP} verify-backup "${BACKUP}" || fail "проверка резервной копии не пройдена"
endstep

step "Автоматическое обновление на $(archive_version "${NEXT_ARCHIVE}")"
install -d -m 700 /var/lib/${APP}/updates
cp "${NEXT_ARCHIVE}" /var/lib/${APP}/updates/
(cd /var/lib/${APP}/updates && sha256sum "$(basename "${NEXT_ARCHIVE}")" > "$(basename "${NEXT_ARCHIVE}").sha256")
${APP} auto-update run || fail "автоматическое обновление завершилось с ошибкой"
expect_health
[[ "$(installed_version)" == "$(archive_version "${NEXT_ARCHIVE}")" ]] || fail "после обновления версия $(installed_version), ожидалась $(archive_version "${NEXT_ARCHIVE}")"
out=$(${APP} auto-update run 2>&1) || fail "повторный запуск автообновления завершился с ошибкой: ${out}"
grep -q 'Новой версии нет' <<<"${out}" || fail "повторный запуск автообновления должен сообщить, что новой версии нет: ${out}"
${APP} status || fail "docportal status после обновления сообщил о проблемах"
endstep

step "Восстановление из копии"
${APP} restore "${BACKUP}" --yes || fail "восстановление завершилось с ошибкой"
expect_health
endstep

step "Удаление с --purge"
${APP} uninstall --purge --yes --no-backup || fail "удаление завершилось с ошибкой"
for p in /opt/${APP} /etc/${APP}/install.conf /etc/cron.d/${APP} /usr/local/bin/${APP} /var/lib/${APP}/updates; do
    [[ ! -e "${p}" ]] || fail "после удаления остался ${p}"
done
if curl -fsS -m 5 "${BASE}/health" >/dev/null 2>&1; then fail "после удаления портал всё ещё отвечает"; fi
endstep

rm -rf "${WORK}"
trap - ERR
printf '\nСквозная проверка (%s, %s) пройдена.\n' "${MODE}" "${VIDEO}"
