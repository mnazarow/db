#!/usr/bin/env bash
# =============================================================================
#  Вход через Windows (Kerberos, SPNEGO) для портала, установленного на сервер (не в Docker).
#
#  Использование:
#    sudo docportal kerberos on --keytab /root/http.keytab --service HTTP/portal.corp.local [--realm CORP.LOCAL] [--auto]
#    sudo docportal kerberos off
#    sudo docportal kerberos status
#
#  «on» ставит модуль nginx для SPNEGO (Debian, Ubuntu, Astra — пакет libnginx-mod-http-auth-spnego),
#  копирует keytab в /etc/nginx/<портал>-kerberos/http.keytab (читает только nginx), защищает адрес
#  /login/kerberos проверкой Kerberos и включает вход через Windows в настройках портала. При ошибке
#  nginx -t прежняя конфигурация возвращается.
#
#  keytab для службы HTTP создаётся на контроллере домена, например:
#    ktpass -princ HTTP/portal.corp.local@CORP.LOCAL -mapuser CORP\svc-portal -pass * -crypto AES256-SHA1 \
#           -ptype KRB5_NT_PRINCIPAL -out http.keytab
#  или на Linux в домене (Samba, sssd): net ads keytab add HTTP / adcli update --service-name=HTTP.
#
#  В Docker встроенный nginx модуля SPNEGO не содержит: используйте прокси перед порталом (Apache с
#  mod_auth_gssapi, IIS), передающий учётную запись заголовком, — «Панель администратора → Вход и безопасность».
# =============================================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/common.sh
. "${SCRIPT_DIR}/common.sh"

usage() {
    cat <<EOF
Вход через Windows (Kerberos) для портала «${APP_TITLE}».

Использование: sudo ${APP_ID} kerberos <действие> [параметры]
  on                       Настроить nginx и включить вход через Windows.
    --keytab ФАЙЛ          keytab службы HTTP (обязательно при первом включении).
    --service ИМЯ          Имя службы: HTTP/имя.сервера.портала (по умолчанию HTTP/<домен портала>).
    --realm ОБЛАСТЬ        Область Kerberos (домен), например CORP.LOCAL — необязательно.
    --auto                 Входить сразу при открытии страницы входа, без нажатия кнопки.
  off                      Выключить вход через Windows и убрать правило nginx.
  status                   Показать состояние.
EOF
}

ACTION="${1:-status}"
[[ $# -gt 0 ]] && shift
KEYTAB=""; SERVICE=""; REALM=""; AUTO=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --keytab) KEYTAB="${2:-}"; shift 2 ;;
        --service) SERVICE="${2:-}"; shift 2 ;;
        --realm) REALM="${2:-}"; shift 2 ;;
        --auto) AUTO="1"; shift ;;
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
if [[ "${ACTION}" == "status" ]]; then LOG_FILE="/dev/null"; else log_init kerberos "${ACTION}" "$@"; fi
load_conf || die "Портал не установлен: не найден ${CONF_FILE}."

KRB_DIR="/etc/nginx/${APP_ID}-kerberos"
KRB_CONF="${KRB_DIR}/locations.conf"
KRB_KEYTAB="${KRB_DIR}/http.keytab"

krb_site_conf() {
    local f
    for f in "/etc/nginx/sites-available/${APP_ID}.conf" "/etc/nginx/conf.d/${APP_ID}.conf"; do
        [[ -f "${f}" ]] && { printf '%s' "${f}"; return 0; }
    done
    return 1
}

# Пользователь, от которого работают процессы nginx (ему нужно читать keytab).
krb_nginx_user() {
    local u
    u=$(awk '$1 == "user" {gsub(";", "", $2); print $2; exit}' /etc/nginx/nginx.conf 2>/dev/null)
    [[ -n "${u}" ]] || { id www-data >/dev/null 2>&1 && u="www-data"; }
    [[ -n "${u}" ]] || u="nginx"
    printf '%s' "${u}"
}

# Модуль SPNEGO загружен в nginx (пакетом или вручную)?
krb_module_present() {
    nginx -V 2>&1 | grep -q 'spnego' && return 0
    grep -rqs 'ngx_http_auth_spnego_module' /etc/nginx/modules-enabled /usr/share/nginx/modules-available /etc/nginx/nginx.conf /usr/share/nginx/modules /etc/nginx/modules 2>/dev/null
}

krb_install_module() {
    krb_module_present && return 0
    detect_os
    case "${OS_FAMILY}" in
        debian) pkg_install_optional libnginx-mod-http-auth-spnego || true ;;
        rhel) pkg_install_optional nginx-mod-http-auth-spnego || true ;;
    esac
    krb_module_present && return 0
    if [[ "${OS_FAMILY}" == "rhel" ]]; then
        die "Модуль nginx для Kerberos (SPNEGO) в репозиториях этой системы не найден. Варианты: собрать модуль spnego-http-auth-nginx-module под установленный nginx или поставить перед порталом Apache с mod_auth_gssapi и выбрать в «Панели администратора → Вход и безопасность» учётную запись из заголовка."
    fi
    die "Не удалось установить пакет libnginx-mod-http-auth-spnego (модуль nginx для Kerberos). Проверьте доступ к репозиториям и повторите."
}

krb_status() {
    local site
    echo "Вход через Windows (Kerberos), портал «${APP_TITLE}»:"
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        echo "  установка в Docker: проверка Kerberos — на прокси перед порталом (учётная запись из заголовка)."
    else
        if krb_module_present; then echo "  модуль nginx SPNEGO: установлен"; else echo "  модуль nginx SPNEGO: не установлен"; fi
        if [[ -f "${KRB_CONF}" ]]; then
            echo "  правило nginx: ${KRB_CONF}"
            awk '/auth_gss_service_name|auth_gss_realm/ {gsub(";", ""); print "    " $1 " " $2}' "${KRB_CONF}"
        else
            echo "  правило nginx: нет"
        fi
        if site=$(krb_site_conf) && grep_has "${KRB_DIR}/" "${site}"; then echo "  подключено в ${site}"; else echo "  в сайт nginx не подключено"; fi
        [[ -f "${KRB_KEYTAB}" ]] && echo "  keytab: ${KRB_KEYTAB} ($(stat -c '%U:%G %a' "${KRB_KEYTAB}"))"
    fi
    app_console_quiet app:kerberos || true
}

# Консоль портала (вывод — на экран).
app_console_quiet() {
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        (cd "${APP_DIR}/docker" && docker compose exec -T -u www-data app php bin/console "$@" --no-interaction)
    else
        local php_bin
        php_bin=$(php_bin_for "${PHP_VERSION:-}") || php_bin="php"
        runuser -u "${SERVICE_USER}" -- "${php_bin}" "${APP_DIR}/current/bin/console" "$@" --env=prod --no-interaction
    fi
}

krb_on() {
    [[ "${INSTALL_MODE}" == "docker" ]] && die "Портал установлен в Docker: встроенный nginx не проверяет Kerberos. Поставьте перед порталом прокси с проверкой Kerberos (Apache mod_auth_gssapi, IIS) и выберите в «Панели администратора → Вход и безопасность» учётную запись из заголовка."
    require_cmd nginx
    local site user group realm_line=""
    site=$(krb_site_conf) || die "Не найден файл сайта nginx портала (/etc/nginx/sites-available/${APP_ID}.conf)."
    if [[ -z "${KEYTAB}" && ! -f "${KRB_KEYTAB}" ]]; then
        die "Укажите keytab службы HTTP: ${APP_ID} kerberos on --keytab /путь/http.keytab --service HTTP/имя.портала"
    fi
    if [[ -n "${KEYTAB}" ]]; then
        [[ -r "${KEYTAB}" ]] || die "Файл keytab не найден или не читается: ${KEYTAB}"
        [[ -s "${KEYTAB}" ]] || die "Файл keytab пустой: ${KEYTAB}"
        # Файл keytab начинается с байта 0x05 (формат MIT Kerberos).
        [[ "$(head -c1 "${KEYTAB}" | od -An -tx1 | tr -d ' ')" == "05" ]] || die "${KEYTAB} не похож на keytab (ожидается двоичный файл формата MIT Kerberos)."
    fi
    if [[ -z "${SERVICE}" ]]; then
        [[ -n "${DOMAIN:-}" ]] || die "Укажите имя службы: --service HTTP/имя.сервера.портала (полное имя, по которому сотрудники открывают портал)."
        SERVICE="HTTP/${DOMAIN}"
    fi
    [[ "${SERVICE}" =~ ^HTTP/[A-Za-z0-9.-]+(@[A-Za-z0-9.-]+)?$ ]] || die "Имя службы должно быть вида HTTP/portal.corp.local, получено: ${SERVICE}"
    if [[ -n "${REALM}" ]]; then
        [[ "${REALM}" =~ ^[A-Za-z0-9.-]+$ ]] || die "Некорректная область Kerberos: ${REALM}"
        REALM="${REALM^^}"
        realm_line="    auth_gss_realm ${REALM};
"
    fi
    if command -v klist >/dev/null 2>&1 && [[ -n "${KEYTAB}" ]]; then
        if ! klist -k "${KEYTAB}" 2>/dev/null | grep -qi "${SERVICE%%@*}"; then
            warn "В keytab нет ключа для ${SERVICE} (klist -k ${KEYTAB}) — проверьте имя службы."
        fi
    fi

    step "Модуль nginx для Kerberos (SPNEGO)"
    krb_install_module
    ok "Модуль SPNEGO для nginx установлен"

    step "Правило nginx для /login/kerberos"
    user=$(krb_nginx_user)
    group=$(id -gn "${user}" 2>/dev/null || printf '%s' "${user}")
    install -d -m 750 -o root -g "${group}" "${KRB_DIR}"
    local f
    for f in "${KRB_CONF}" "${KRB_KEYTAB}" "${site}"; do
        [[ -f "${f}" ]] && cp -f "${f}" "${f}.krb-prev"
    done
    if [[ -n "${KEYTAB}" ]]; then
        install -m 640 -o root -g "${group}" "${KEYTAB}" "${KRB_KEYTAB}" || die "Не удалось скопировать keytab в ${KRB_KEYTAB}."
    fi
    cat > "${KRB_CONF}" <<EOF
# Вход через Windows (Kerberos, SPNEGO) — создано «${APP_ID} kerberos on» $(date '+%Y-%m-%d %H:%M').
# Учётную запись проверяет nginx и передаёт порталу в REMOTE_USER; подделать её из браузера нельзя.
location = /login/kerberos {
    auth_gss on;
${realm_line}    auth_gss_keytab ${KRB_KEYTAB};
    auth_gss_service_name ${SERVICE};
    auth_gss_allow_basic_fallback off;
    # Браузер не смог войти через Windows — страница, которая сразу открывает форму входа по паролю.
    error_page 401 /kerberos-401.html;
    fastcgi_pass unix:${PHP_FPM_SOCK};
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME \$realpath_root/index.php;
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_param DOCUMENT_ROOT \$realpath_root;
    fastcgi_param REMOTE_USER \$remote_user;
}
EOF
    chmod 644 "${KRB_CONF}"
    if ! grep_has "${KRB_DIR}/" "${site}"; then
        if grep_has "include ${MAINTENANCE_SNIPPET};" "${site}"; then
            sed -i "s|^\([[:space:]]*\)include ${MAINTENANCE_SNIPPET};|&\n\1include ${KRB_DIR}/*.conf;|" "${site}"
        else
            sed -i "0,/^[[:space:]]*root .*;/s||&\n    include ${KRB_DIR}/*.conf;|" "${site}"
        fi
    fi
    grep_has "${KRB_DIR}/" "${site}" || die "Не удалось подключить правило Kerberos в ${site}."
    local out
    if ! out=$(nginx -t 2>&1); then
        _log_raw "${out}"
        for f in "${KRB_CONF}" "${KRB_KEYTAB}" "${site}"; do
            if [[ -f "${f}.krb-prev" ]]; then mv -f "${f}.krb-prev" "${f}"; elif [[ "${f}" != "${site}" ]]; then rm -f "${f}"; fi
        done
        die "Конфигурация nginx с Kerberos не прошла проверку (nginx -t): ${out##*$'\n'} — прежняя конфигурация возвращена."
    fi
    rm -f "${KRB_CONF}.krb-prev" "${KRB_KEYTAB}.krb-prev" "${site}.krb-prev"
    svc reload nginx || svc restart nginx || die "Не удалось перезагрузить nginx."
    ok "nginx проверяет Kerberos на /login/kerberos (служба ${SERVICE})"

    step "Настройки портала"
    local args=(app:kerberos --enable)
    [[ -n "${REALM}" ]] && args+=("--realm=${REALM}")
    [[ -n "${AUTO}" ]] && args+=("--auto=1")
    app_console_quiet "${args[@]}" || die "nginx настроен, но включить вход через Windows в портале не удалось — включите его в «Панели администратора → Вход и безопасность»."
    ok "Вход через Windows включён. Адрес портала добавьте в зону «Местная интрасеть» браузеров (групповой политикой)."
}

krb_off() {
    local site
    if [[ "${INSTALL_MODE}" != "docker" ]]; then
        rm -rf "${KRB_DIR}"
        if site=$(krb_site_conf); then
            sed -i "\|include ${KRB_DIR}/\*\.conf;|d" "${site}"
        fi
        if command -v nginx >/dev/null 2>&1; then
            nginx -t >>"${LOG_FILE}" 2>&1 || die "Конфигурация nginx не прошла проверку после удаления правила Kerberos (nginx -t) — проверьте ${site:-сайт портала}."
            svc reload nginx || svc restart nginx || warn "Не удалось перезагрузить nginx."
        fi
        ok "Правило nginx для Kerberos удалено"
    fi
    app_console_quiet app:kerberos --disable >/dev/null || die "Не удалось выключить вход через Windows в настройках портала."
    ok "Вход через Windows выключен"
}

case "${ACTION}" in
    status) krb_status ;;
    on) krb_on ;;
    off) krb_off ;;
esac
