#!/usr/bin/env bash
# =============================================================================
#  Общие функции скриптов развёртывания портала «Портал документации».
#  Подключается из install.sh / update.sh / uninstall.sh / backup.sh / restore.sh.
#  Не запускается напрямую.
# =============================================================================

# --- Константы ---------------------------------------------------------------
APP_ID="docportal"
APP_TITLE="Портал документации"
CONF_DIR="/etc/${APP_ID}"
CONF_FILE="${CONF_DIR}/install.conf"
LOG_DIR="/var/log/${APP_ID}"
DEFAULT_APP_DIR="/opt/${APP_ID}"
DEFAULT_BACKUP_DIR="/var/backups/${APP_ID}"
DEFAULT_DB_NAME="docportal"
DEFAULT_DB_USER="docportal"
DEFAULT_HTTP_PORT="80"
PHP_MIN_VERSION="8.2"
PHP_PREFERRED_VERSIONS=("8.3" "8.4" "8.5" "8.2" "8.6" "8.7")
REQUIRED_PHP_EXTENSIONS=(pdo_mysql mbstring ctype iconv xml dom zlib fileinfo json intl curl opcache ldap)

# --- Цвета и вывод -------------------------------------------------------------
if [[ -t 1 ]] && [[ "${NO_COLOR:-}" == "" ]]; then
    C_RESET=$'\e[0m'; C_RED=$'\e[31m'; C_GREEN=$'\e[32m'; C_YELLOW=$'\e[33m'; C_BLUE=$'\e[34m'; C_BOLD=$'\e[1m'; C_DIM=$'\e[2m'
else
    C_RESET=""; C_RED=""; C_GREEN=""; C_YELLOW=""; C_BLUE=""; C_BOLD=""; C_DIM=""
fi

LOG_FILE=""

_ts() { date '+%Y-%m-%d %H:%M:%S'; }

log_init() {
    # $1 — имя скрипта (install/update/...)
    mkdir -p "${LOG_DIR}" 2>/dev/null || true
    LOG_FILE="${LOG_DIR}/${1}-$(date '+%Y%m%d-%H%M%S').log"
    if ! touch "${LOG_FILE}" 2>/dev/null; then
        LOG_FILE="/tmp/${APP_ID}-${1}-$(date '+%Y%m%d-%H%M%S').log"
        touch "${LOG_FILE}"
    fi
    chmod 600 "${LOG_FILE}" 2>/dev/null || true
    _log_raw "===== $(_ts) запуск: $0 $* (пользователь: $(id -un), хост: $(hostname)) ====="
}

_log_raw() { [[ -n "${LOG_FILE}" ]] && printf '%s\n' "$*" >> "${LOG_FILE}" || true; }

info()    { printf '%s[%s]%s %s\n' "${C_BLUE}" "$(_ts)" "${C_RESET}" "$*"; _log_raw "[INFO ] $*"; }
ok()      { printf '%s[%s] ✔%s %s\n' "${C_GREEN}" "$(_ts)" "${C_RESET}" "$*"; _log_raw "[ OK  ] $*"; }
warn()    { printf '%s[%s] ⚠ %s%s\n' "${C_YELLOW}" "$(_ts)" "$*" "${C_RESET}" >&2; _log_raw "[WARN ] $*"; }
error()   { printf '%s[%s] ✖ %s%s\n' "${C_RED}" "$(_ts)" "$*" "${C_RESET}" >&2; _log_raw "[ERROR] $*"; }
step()    { printf '\n%s%s▶ %s%s\n' "${C_BOLD}" "${C_BLUE}" "$*" "${C_RESET}"; _log_raw "[STEP ] $*"; }
debug()   { _log_raw "[DEBUG] $*"; [[ "${VERBOSE:-0}" == "1" ]] && printf '%s   %s%s\n' "${C_DIM}" "$*" "${C_RESET}" || true; }

# Откат (функция rollback определяется в конкретном скрипте) выполняется один раз и только
# когда ROLLBACK_ENABLED=1 — т.е. после того, как скрипт начал изменять систему.
ROLLBACK_ENABLED="0"
_ROLLBACK_DONE="0"
maybe_rollback() {
    if [[ "${ROLLBACK_ENABLED}" == "1" && "${_ROLLBACK_DONE}" == "0" ]] && declare -F rollback >/dev/null 2>&1; then
        _ROLLBACK_DONE="1"
        set +e
        rollback || true
    fi
}

die() {
    error "$*"
    maybe_rollback
    [[ -n "${LOG_FILE}" ]] && error "Подробности в журнале: ${LOG_FILE}"
    exit 1
}

# Вызывается ловушкой ERR: печатает место ошибки и завершает работу.
on_error() {
    local exit_code=$? line=$1 cmd=$2
    error "Ошибка (код ${exit_code}) в строке ${line}: ${cmd}"
    maybe_rollback
    [[ -n "${LOG_FILE}" ]] && error "Подробности в журнале: ${LOG_FILE}"
    exit "${exit_code}"
}

setup_traps() {
    set -Eeuo pipefail
    trap 'on_error ${LINENO} "${BASH_COMMAND}"' ERR
    trap 'echo; die "Прервано пользователем."' INT TERM
}

# Проверка наличия строки в потоке БЕЗ раннего выхода (grep_has в конвейере при pipefail
# приводит к SIGPIPE у левой команды и ложному «не найдено»). Флаги те же, что у grep.
grep_has() { grep "$@" >/dev/null 2>&1; }

# Выполняет команду, пишет её и вывод в журнал. Возвращает код возврата команды.
run() {
    debug "\$ $*"
    local out rc
    set +e
    out=$("$@" 2>&1)
    rc=$?
    set -e
    if [[ -n "${out}" ]]; then
        _log_raw "${out}"
    fi
    if [[ ${rc} -ne 0 ]]; then
        printf '%s\n' "${out}" | tail -n 25 >&2
    fi
    return ${rc}
}

# Повтор команды до N раз с паузой (сетевые операции, ожидание сервисов).
retry() {
    local attempts=$1 delay=$2; shift 2
    local n=1
    until "$@"; do
        if [[ ${n} -ge ${attempts} ]]; then
            return 1
        fi
        warn "Попытка ${n}/${attempts} не удалась, повтор через ${delay} с: $*"
        sleep "${delay}"
        n=$((n + 1))
    done
}

require_root() {
    if [[ "$(id -u)" -ne 0 ]]; then
        die "Скрипт нужно запускать от root: sudo $0 $*"
    fi
}

require_cmd() {
    local c
    for c in "$@"; do
        command -v "${c}" >/dev/null 2>&1 || die "Не найдена команда «${c}». Установите её и повторите."
    done
}

confirm() {
    # confirm "Вопрос" — возвращает 0 при ответе «да» или при --yes
    if [[ "${ASSUME_YES:-0}" == "1" ]]; then
        return 0
    fi
    local answer
    read -r -p "$1 [y/N]: " answer
    [[ "${answer}" =~ ^([yYдД]|yes|да)$ ]]
}

gen_password() {
    # Случайный пароль без неоднозначных символов (только буквы и цифры) длиной $1 (по умолчанию 24).
    local len=${1:-24}
    LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c "${len}" || true
    echo
}

gen_secret_hex() { od -An -N32 -tx1 /dev/urandom | tr -d ' \n'; echo; }

# --- Определение ОС ------------------------------------------------------------
OS_ID=""; OS_VERSION=""; OS_FAMILY=""; PKG=""
detect_os() {
    if [[ ! -r /etc/os-release ]]; then
        die "Не удалось определить операционную систему (нет /etc/os-release)."
    fi
    # shellcheck disable=SC1091
    . /etc/os-release
    OS_ID="${ID:-unknown}"
    OS_VERSION="${VERSION_ID:-}"
    case "${OS_ID}" in
        ubuntu|debian|linuxmint|pop) OS_FAMILY="debian"; PKG="apt" ;;
        almalinux|rocky|centos|rhel|ol|fedora) OS_FAMILY="rhel"; PKG="dnf" ;;
        *)
            if [[ "${ID_LIKE:-}" == *debian* ]]; then OS_FAMILY="debian"; PKG="apt";
            elif [[ "${ID_LIKE:-}" == *rhel* || "${ID_LIKE:-}" == *fedora* ]]; then OS_FAMILY="rhel"; PKG="dnf";
            else die "Операционная система «${PRETTY_NAME:-$OS_ID}» не поддерживается. Поддерживаются Debian 11/12/13, Ubuntu 22.04/24.04/26.04, AlmaLinux/Rocky 9."; fi ;;
    esac
    info "ОС: ${PRETTY_NAME:-$OS_ID $OS_VERSION} (семейство: ${OS_FAMILY})"
}

# --- Пакеты --------------------------------------------------------------------
apt_update_once() {
    if [[ "${_APT_UPDATED:-0}" != "1" ]]; then
        info "Обновление списка пакетов…"
        if ! retry 2 5 env DEBIAN_FRONTEND=noninteractive apt-get update -q >>"${LOG_FILE}" 2>&1; then
            # Часто причина — недоступный сторонний репозиторий; пробуем продолжить с теми индексами, что удалось получить.
            warn "apt-get update завершился с ошибкой (см. журнал). Возможно, недоступен один из сторонних репозиториев — продолжаем."
        fi
        _APT_UPDATED=1
    fi
}

pkg_install() {
    # pkg_install пакет1 пакет2 …
    [[ $# -eq 0 ]] && return 0
    info "Установка пакетов: $*"
    case "${PKG}" in
        apt)
            apt_update_once
            retry 3 10 env DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends "$@" >>"${LOG_FILE}" 2>&1 \
                || die "Не удалось установить пакеты: $*. См. журнал ${LOG_FILE}"
            ;;
        dnf)
            retry 3 10 dnf install -y -q "$@" >>"${LOG_FILE}" 2>&1 || die "Не удалось установить пакеты: $*. См. журнал ${LOG_FILE}"
            ;;
    esac
}

pkg_installed() {
    case "${PKG}" in
        apt) dpkg -s "$1" >/dev/null 2>&1 ;;
        dnf) rpm -q "$1" >/dev/null 2>&1 ;;
    esac
}

# --- Службы --------------------------------------------------------------------
has_systemd() { [[ -d /run/systemd/system ]] && command -v systemctl >/dev/null 2>&1; }

svc() {
    # svc start|stop|restart|reload|enable|disable|status имя
    local action=$1 name=$2
    if has_systemd; then
        case "${action}" in
            enable) systemctl enable --now "${name}" >>"${LOG_FILE}" 2>&1 ;;
            status) systemctl is-active --quiet "${name}" ;;
            *) systemctl "${action}" "${name}" >>"${LOG_FILE}" 2>&1 ;;
        esac
    else
        case "${action}" in
            enable) service "${name}" start >>"${LOG_FILE}" 2>&1 || true ;;
            status) service "${name}" status >/dev/null 2>&1 ;;
            *) service "${name}" "${action}" >>"${LOG_FILE}" 2>&1 ;;
        esac
    fi
}

# --- Конфигурация установки ----------------------------------------------------
load_conf() {
    if [[ -r "${CONF_FILE}" ]]; then
        # shellcheck disable=SC1090
        . "${CONF_FILE}"
        return 0
    fi
    return 1
}

save_conf() {
    mkdir -p "${CONF_DIR}"
    umask 077
    cat > "${CONF_FILE}" <<EOF
# Параметры установки портала «${APP_TITLE}». Файл создан скриптом install.sh $(date '+%Y-%m-%d %H:%M').
# Используется скриптами update.sh / backup.sh / restore.sh / uninstall.sh. Не удаляйте.
INSTALL_MODE="${INSTALL_MODE}"
APP_DIR="${APP_DIR}"
APP_VERSION="${APP_VERSION}"
BACKUP_DIR="${BACKUP_DIR}"
DOMAIN="${DOMAIN}"
HTTP_PORT="${HTTP_PORT}"
SERVICE_USER="${SERVICE_USER}"
PHP_VERSION="${PHP_VERSION}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE}"
PHP_FPM_SOCK="${PHP_FPM_SOCK}"
DB_HOST="${DB_HOST}"
DB_PORT="${DB_PORT}"
DB_NAME="${DB_NAME}"
DB_USER="${DB_USER}"
DB_PASSWORD="${DB_PASSWORD}"
DB_SERVER_VERSION="${DB_SERVER_VERSION}"
DB_LOCAL="${DB_LOCAL}"
DB_SERVICE="${DB_SERVICE}"
SSL_ENABLED="${SSL_ENABLED}"
INSTALLED_AT="${INSTALLED_AT}"
EOF
    umask 022
    chmod 600 "${CONF_FILE}"
}

# --- MySQL ---------------------------------------------------------------------
# Файл с учётными данными для клиента mysql (пароль не попадает в аргументы процесса).
mysql_defaults_file() {
    # mysql_defaults_file host port user password → путь к временному файлу
    local f
    f=$(mktemp)
    chmod 600 "${f}"
    printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' "$1" "$2" "$3" "$4" > "${f}"
    echo "${f}"
}

mysql_cli() {
    if command -v mariadb >/dev/null 2>&1; then echo mariadb; elif command -v mysql >/dev/null 2>&1; then echo mysql; else return 1; fi
}

mysqldump_cli() {
    if command -v mariadb-dump >/dev/null 2>&1; then echo mariadb-dump; elif command -v mysqldump >/dev/null 2>&1; then echo mysqldump; else return 1; fi
}

# Выполняет SQL от имени пользователя. mysql_exec host port user password "SQL"
mysql_exec() {
    local f cli
    cli=$(mysql_cli) || die "Клиент mysql/mariadb не найден."
    f=$(mysql_defaults_file "$1" "$2" "$3" "$4")
    local rc=0
    "${cli}" --defaults-extra-file="${f}" --batch --skip-column-names -e "$5" 2>>"${LOG_FILE}" || rc=$?
    rm -f "${f}"
    return ${rc}
}

# Root-доступ к локальному серверу (unix_socket / без пароля) — выполняет SQL.
mysql_root_exec() {
    local cli
    cli=$(mysql_cli) || die "Клиент mysql/mariadb не найден."
    "${cli}" --batch --skip-column-names -e "$1" 2>>"${LOG_FILE}"
}

wait_for_mysql() {
    # wait_for_mysql host port user password [попытки]
    local attempts=${5:-30} n=1
    while ! mysql_exec "$1" "$2" "$3" "$4" "SELECT 1" >/dev/null 2>&1; do
        if [[ ${n} -ge ${attempts} ]]; then
            return 1
        fi
        sleep 2; n=$((n + 1))
    done
    return 0
}

# --- PHP --------------------------------------------------------------------------
php_bin_for() { command -v "php${1}" 2>/dev/null || command -v php 2>/dev/null; }

# version_ge 8.5 8.2 → 0, если первая версия не меньше второй
version_ge() { [[ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n1)" == "$2" ]]; }

php_version_ok() {
    # php_version_ok /usr/bin/php8.3 → 0 если >= PHP_MIN_VERSION
    local v
    v=$("$1" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null) || return 1
    [[ "$(printf '%s\n%s\n' "${PHP_MIN_VERSION}" "${v}" | sort -V | head -n1)" == "${PHP_MIN_VERSION}" ]]
}

php_missing_extensions() {
    # php_missing_extensions /usr/bin/php → список отсутствующих расширений
    local phpbin=$1 ext missing=()
    local loaded
    loaded=$("${phpbin}" -m 2>/dev/null | tr '[:upper:]' '[:lower:]')
    for ext in "${REQUIRED_PHP_EXTENSIONS[@]}"; do
        if ! grep_has -x "${ext}" <<<"${loaded}"; then
            if [[ "${ext}" == "opcache" ]] && grep_has -x "zend opcache" <<<"${loaded}"; then continue; fi
            missing+=("${ext}")
        fi
    done
    printf '%s\n' "${missing[@]:-}"
}

# --- Проверка работоспособности по HTTP -----------------------------------------------
# Адрес проверки: после включения HTTPS (SSL_ENABLED=1) — https://ДОМЕН/health,
# иначе — http://127.0.0.1:ПОРТ/health.
health_url() {
    if [[ "${SSL_ENABLED:-0}" == "1" && -n "${DOMAIN:-}" ]]; then
        printf 'https://%s/health' "${DOMAIN}"
    else
        printf 'http://127.0.0.1:%s/health' "${HTTP_PORT:-80}"
    fi
}

# Один запрос к /health. Печатает тело ответа (или описание ошибки), код возврата 0 —
# если получен HTTP 200 с "status":"ok".
# Запрос всегда уходит на этот же сервер: имя домена направляется на 127.0.0.1 (--resolve),
# поэтому проверка не зависит от DNS и внешнего брандмауэра. Перенаправление HTTP→HTTPS,
# которое добавляет certbot, отслеживается (-L); сертификат не проверяется (-k) — это проверка
# работоспособности приложения, а не сертификата (сертификат проверяет doctor.sh).
health_probe() {
    local host="${DOMAIN:-localhost}" url out code body
    local resolve=(--resolve "${host}:80:127.0.0.1" --resolve "${host}:443:127.0.0.1")
    if [[ "${HTTP_PORT:-80}" != "80" && "${HTTP_PORT:-80}" != "443" ]]; then
        resolve+=(--resolve "${host}:${HTTP_PORT}:127.0.0.1")
    fi
    url=$(health_url)
    # --noproxy '*': запрос к собственному серверу не должен уходить через прокси из окружения (https_proxy).
    if ! out=$(curl -sS -L -k -m 10 --max-redirs 3 --noproxy '*' "${resolve[@]}" -H "Host: ${host}" -w '\n%{http_code}' "${url}" 2>>"${LOG_FILE:-/dev/null}"); then
        printf 'нет соединения с %s' "${url}"
        return 1
    fi
    code=${out##*$'\n'}
    body=${out%$'\n'*}
    if [[ "${code}" == "200" && "${body}" == *'"status":"ok"'* ]]; then
        printf '%s' "${body}"
        return 0
    fi
    printf 'HTTP %s: %s' "${code:-?}" "$(printf '%s' "${body}" | tr -d '\r' | tr '\n' ' ' | sed 's/  */ /g' | cut -c1-200)"
    return 1
}

# Ожидание корректного ответа /health. wait_for_health [попытки=40] [пауза=3]
# Печатает тело последнего ответа; код 0 — портал отвечает.
wait_for_health() {
    local attempts=${1:-40} delay=${2:-3} n=0 body=""
    until body=$(health_probe); do
        n=$((n + 1))
        if [[ ${n} -ge ${attempts} ]]; then
            printf '%s' "${body}"
            return 1
        fi
        sleep "${delay}"
    done
    printf '%s' "${body}"
}

# --- Разное ---------------------------------------------------------------------------
human_size() { numfmt --to=iec --suffix=B "$1" 2>/dev/null || echo "$1 B"; }

free_space_mb() { df -Pm "$1" 2>/dev/null | awk 'NR==2 {print $4}'; }

# Пишет/обновляет переменную в файле вида KEY=VALUE. set_env_var файл KEY значение
set_env_var() {
    local file=$1 key=$2 value=$3
    local escaped
    escaped=$(printf '%s' "${value}" | sed 's/[\/&|]/\\&/g')
    if grep_has -E "^${key}=" "${file}" 2>/dev/null; then
        sed -i "s|^${key}=.*|${key}=\"${escaped}\"|" "${file}"
    else
        printf '%s="%s"\n' "${key}" "${value}" >> "${file}"
    fi
}

project_version() {
    # project_version DIR → содержимое файла VERSION или "unknown"
    if [[ -r "$1/VERSION" ]]; then tr -d ' \n\r' < "$1/VERSION"; else echo "unknown"; fi
}

is_project_dir() {
    [[ -f "$1/composer.json" && -f "$1/bin/console" && -d "$1/public" && -d "$1/src" ]]
}

banner() {
    printf '%s%s\n' "${C_BOLD}" "=============================================================="
    printf ' %s — %s\n' "${APP_TITLE}" "$1"
    printf '%s%s\n' "==============================================================" "${C_RESET}"
}
