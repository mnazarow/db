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
# Версия PHP для новых установок и перехода (docportal php-upgrade): Symfony 8, на которую портал перейдёт
# после выхода Symfony 8.4 LTS, требует PHP 8.4+ (РУКОВОДСТВО, 8.4). Ниже — портал работает, но переходить придётся.
PHP_RECOMMENDED_VERSION="8.4"
PHP_PREFERRED_VERSIONS=("8.4" "8.5" "8.6" "8.7" "8.3" "8.2")
REQUIRED_PHP_EXTENSIONS=(pdo_mysql mbstring ctype iconv xml dom zlib fileinfo json intl curl opcache ldap)

# --- Цвета и вывод -------------------------------------------------------------
if [[ -t 1 ]] && [[ "${NO_COLOR:-}" == "" ]]; then
    C_RESET=$'\e[0m'; C_RED=$'\e[31m'; C_GREEN=$'\e[32m'; C_YELLOW=$'\e[33m'; C_BLUE=$'\e[34m'; C_BOLD=$'\e[1m'; C_DIM=$'\e[2m'
else
    C_RESET=""; C_RED=""; C_GREEN=""; C_YELLOW=""; C_BLUE=""; C_BOLD=""; C_DIM=""
fi

LOG_FILE=""

_ts() { date '+%Y-%m-%d %H:%M:%S'; }

# Аргументы командной строки для журнала: значения паролей, ключей и DSN заменяются на ***.
mask_args() {
    local out=() hide="0" a
    for a in "$@"; do
        if [[ "${hide}" == "1" ]]; then out+=("***"); hide="0"; continue; fi
        case "${a}" in
            --*password*=*|--*secret*=*|--*dsn*=*|--*token*=*) out+=("${a%%=*}=***") ;;
            --*password*|--*secret*|--*dsn*|--*token*) out+=("${a}"); hide="1" ;;
            *) out+=("${a}") ;;
        esac
    done
    printf '%s' "${out[*]:-}"
}

log_init() {
    # $1 — имя скрипта (install/update/...), далее — аргументы запуска (пароли в журнал не попадают)
    local name=$1; shift
    mkdir -p "${LOG_DIR}" 2>/dev/null || true
    # Журналы содержат параметры установки — читать их могут только root и группа adm.
    chmod 750 "${LOG_DIR}" 2>/dev/null || true
    LOG_FILE="${LOG_DIR}/${name}-$(date '+%Y%m%d-%H%M%S').log"
    if ! touch "${LOG_FILE}" 2>/dev/null; then
        LOG_FILE="$(mktemp "/tmp/${APP_ID}-${name}-XXXXXX.log")"
    fi
    chmod 600 "${LOG_FILE}" 2>/dev/null || true
    # Журналы разовых запусков (ночная копия — один в день) хранятся полгода, журналы установки — всегда.
    find "${LOG_DIR}" -maxdepth 1 -type f -name '*-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9][0-9][0-9].log*' \
        ! -name 'install-*' -mtime +180 -delete 2>/dev/null || true
    _log_raw "===== $(_ts) запуск: $0 $(mask_args "$@") (пользователь: $(id -un), хост: $(hostname)) ====="
}

_log_raw() { [[ -n "${LOG_FILE}" ]] && printf '%s\n' "$*" >> "${LOG_FILE}" || true; }

info()    { printf '%s[%s]%s %s\n' "${C_BLUE}" "$(_ts)" "${C_RESET}" "$*"; _log_raw "[INFO ] $*"; }
ok()      { printf '%s[%s] ✔%s %s\n' "${C_GREEN}" "$(_ts)" "${C_RESET}" "$*"; _log_raw "[ OK  ] $*"; }
# Предупреждения за запуск собираются для итоговой сводки (print_warnings_summary); промежуточные
# сообщения о повторах (warn_transient) в сводку не попадают.
WARNINGS=()
warn()    { WARNINGS+=("$*"); printf '%s[%s] ⚠ %s%s\n' "${C_YELLOW}" "$(_ts)" "$*" "${C_RESET}" >&2; _log_raw "[WARN ] $*"; }
warn_transient() { printf '%s[%s] … %s%s\n' "${C_YELLOW}" "$(_ts)" "$*" "${C_RESET}" >&2; _log_raw "[RETRY] $*"; }
# Текст последней ошибки — для отметки о неудачном задании в мониторинге (record_job).
LAST_ERROR=""
error()   { LAST_ERROR="$*"; printf '%s[%s] ✖ %s%s\n' "${C_RED}" "$(_ts)" "$*" "${C_RESET}" >&2; _log_raw "[ERROR] $*"; }
# Строка журнала, с которой начался текущий шаг: при ошибке подсказки ищутся только в выводе этого шага.
_STEP_LOG_LINE=0
CURRENT_STEP=""
step()    {
    CURRENT_STEP="$*"
    printf '\n%s%s▶ %s%s\n' "${C_BOLD}" "${C_BLUE}" "$*" "${C_RESET}"
    _log_raw "[STEP ] $*"
    if [[ -n "${LOG_FILE}" && -r "${LOG_FILE}" ]]; then _STEP_LOG_LINE=$(wc -l < "${LOG_FILE}" 2>/dev/null || echo 0); fi
    return 0
}
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
    if [[ -n "${CURRENT_STEP}" ]]; then
        _log_raw "[ERROR] шаг: ${CURRENT_STEP}"
        show_step_log_tail 10 || true
    fi
    explain_failure || true
    maybe_rollback
    [[ -n "${LOG_FILE}" && "${LOG_FILE}" != "/dev/null" ]] && error "Подробности в журнале: ${LOG_FILE}"
    # Для отметки в мониторинге и файла .failed-<версия> — сама причина, а не строка про журнал.
    LAST_ERROR="$*"
    exit 1
}

# Вызывается ловушкой ERR: печатает место ошибки (строка, команда, цепочка функций) и завершает работу.
on_error() {
    local exit_code=$? line=$1 cmd=$2
    error "Ошибка (код ${exit_code}) в строке ${line}: ${cmd}"
    _log_raw "[ERROR] функции: ${FUNCNAME[*]:1}; шаг: ${CURRENT_STEP:-—}"
    show_step_log_tail 10 || true
    explain_failure || true
    maybe_rollback
    [[ -n "${LOG_FILE}" && "${LOG_FILE}" != "/dev/null" ]] && error "Подробности в журнале: ${LOG_FILE}"
    LAST_ERROR="Ошибка (код ${exit_code}) в строке ${line}: ${cmd}"
    exit "${exit_code}"
}

setup_traps() {
    set -Eeuo pipefail
    trap 'on_error ${LINENO} "${BASH_COMMAND}"' ERR
    trap 'echo; die "Прервано пользователем."' INT TERM HUP
}

# Одновременно может выполняться только одна операция обслуживания (install/update/backup/restore/uninstall):
# иначе, например, ночная резервная копия может начаться посреди обновления. Вложенные вызовы
# (update → backup.sh, update → restore.sh) наследуют блокировку. acquire_lock [ожидание в секундах]
acquire_lock() {
    [[ "${DOCPORTAL_LOCK_HELD:-}" == "1" ]] && return 0
    command -v flock >/dev/null 2>&1 || return 0
    local wait=${1:-0} lock="/run/${APP_ID}.lock"
    [[ -d /run && -w /run ]] || lock="/var/lock/${APP_ID}.lock"
    # Файл блокировки доступен только root: иначе любой пользователь сервера мог бы открыть его и,
    # удерживая блокировку, остановить резервное копирование и обновления.
    ( umask 077; : >>"${lock}" ) 2>/dev/null || true
    chmod 600 "${lock}" 2>/dev/null || true
    exec 9>>"${lock}" || return 0
    if ! flock -w "${wait}" 9; then
        local holders="" name services="" others=""
        holders=$(lock_holders "${lock}" 2>/dev/null || true)
        for name in ${holders}; do
            case "${name}" in
                php-fpm*|nginx|mysqld|mariadbd|cron|crond) services+="${name} " ;;
                *) others+="${name} " ;;
            esac
        done
        if [[ -n "${services}" && -z "${others}" ]]; then
            # Скрипты до 1.6.1 без systemd запускали службы с открытым файлом блокировки — службы держат её,
            # пока работают. Операции обслуживания при этом не идут: достаточно перезапустить службы.
            die "Блокировку операций обслуживания удерживают службы (${services% }), запущенные скриптами прежней версии. Перезапустите их, например: service ${PHP_FPM_SERVICE:-php-fpm} restart && service nginx restart — и повторите."
        fi
        die "Уже выполняется другая операция обслуживания портала (установка, обновление, резервное копирование или восстановление). Дождитесь её завершения и повторите."
    fi
    export DOCPORTAL_LOCK_HELD=1
    # Блокировку взял именно этот процесс (а не унаследовал от вызвавшего скрипта) — он может её отпустить.
    LOCK_OWNED="1"
}

# Отпустить блокировку раньше выхода (например, перед долгой отправкой копии во внешнее хранилище),
# если её взял этот процесс. Унаследованную от update.sh блокировку не трогаем.
release_lock() {
    [[ "${LOCK_OWNED:-0}" == "1" ]] || return 0
    command -v flock >/dev/null 2>&1 && flock -u 9 2>/dev/null || true
    exec 9>&- 2>/dev/null || true
    LOCK_OWNED="0"
    unset DOCPORTAL_LOCK_HELD
}

# Имена процессов, которые держат открытым файл блокировки, без повторов. Сам скрипт и его дочерние
# процессы (в том числе конвейер этой проверки) не учитываются.
# Процессы появляются и завершаются во время просмотра /proc — каждая ошибка чтения допустима.
lock_holders() {
    local lock=$1 fd pid p
    for fd in $(find /proc/[0-9]*/fd -maxdepth 1 -lname "${lock}" 2>/dev/null || true); do
        pid=${fd#/proc/}; pid=${pid%%/*}
        p=${pid}
        while [[ -n "${p}" && "${p}" != "0" && "${p}" != "1" && "${p}" != "$$" ]]; do
            p=$( (sed 's/^.*) //' "/proc/${p}/stat" 2>/dev/null || true) | cut -d' ' -f2)
        done
        [[ "${p}" == "$$" ]] && continue
        cat "/proc/${pid}/comm" 2>/dev/null || true
    done | sort -u
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
        warn_transient "Попытка ${n}/${attempts} не удалась, повтор через ${delay} с: $*"
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
        local attempt out failed=""
        out=$(mktemp)
        for attempt in 1 2 3; do
            apt_wait_lock
            local rc=0
            apt_get update -q >"${out}" 2>&1 9>&- || rc=$?
            cat "${out}" >> "${LOG_FILE:-/dev/null}"
            # apt-get update завершается успешно, даже когда репозитории недоступны («W: Failed to fetch»).
            failed=$(grep -E '^(W|E): (Failed to fetch|The repository .* (does not have|is not signed)|Some index files failed)' "${out}" | head -n1 || true)
            [[ ${rc} -eq 0 && -z "${failed}" ]] && break
            [[ -n "${failed}" ]] || failed="apt-get update: код ${rc}"
            [[ ${attempt} -lt 3 ]] || break
            warn_transient "Списки пакетов обновились не полностью (попытка ${attempt}/3) — повтор через $((attempt * 10)) с"
            apt_recover_lists || true
            sleep $((attempt * 10))
            failed=""
        done
        rm -f "${out}"
        if [[ -n "${failed}" ]]; then
            # Часто недоступен один сторонний репозиторий — продолжаем с теми индексами, что удалось получить.
            warn "Списки пакетов обновились не полностью: ${failed:0:200} — продолжаем; если пакеты не установятся, проверьте доступ к репозиториям (прокси, брандмауэр, /etc/apt/sources.list)."
        fi
        _APT_UPDATED=1
    fi
}

# Очистка повреждённых списков пакетов (Hash Sum mismatch, незавершённая загрузка).
apt_recover_lists() {
    local tail
    tail=$(tail -n 60 "${LOG_FILE:-/dev/null}" 2>/dev/null || true)
    rm -rf /var/lib/apt/lists/partial/* 2>/dev/null || true
    if grep_has -Ei 'Hash Sum mismatch|File has unexpected size|Clearsigned file isn.t valid' <<<"${tail}"; then
        rm -rf /var/lib/apt/lists/* 2>/dev/null || true
        apt_get clean >>"${LOG_FILE:-/dev/null}" 2>&1 9>&- || true
    fi
    return 0
}

pkg_install() {
    # pkg_install пакет1 пакет2 … — до трёх попыток; между ними — разбор причины и восстановление
    # (прерванный dpkg, повреждённые списки пакетов, занятая блокировка, неполные зависимости).
    [[ $# -eq 0 ]] && return 0
    info "Установка пакетов: $*"
    local n
    case "${PKG}" in
        apt)
            apt_update_once
            for n in 1 2 3; do
                apt_wait_lock
                apt_get install -y -q --no-install-recommends "$@" >>"${LOG_FILE}" 2>&1 9>&- && return 0
                [[ ${n} -lt 3 ]] || break
                warn_transient "Установка пакетов не удалась (попытка ${n}/3) — восстановление и повтор"
                apt_recover || true
                sleep $((n * 10))
            done
            die "Не удалось установить пакеты: $*."
            ;;
        dnf)
            for n in 1 2 3; do
                timeout 3600 dnf install -y -q --setopt=retries=10 "$@" >>"${LOG_FILE}" 2>&1 9>&- && return 0
                [[ ${n} -lt 3 ]] || break
                warn_transient "Установка пакетов не удалась (попытка ${n}/3) — очистка кэша dnf и повтор"
                dnf clean metadata >>"${LOG_FILE}" 2>&1 9>&- || true
                sleep $((n * 10))
            done
            die "Не удалось установить пакеты: $*."
            ;;
    esac
}

# Определяет пакетный менеджер, ничего не прерывая: неизвестная ОС — просто код возврата 1.
detect_pkg_quiet() {
    [[ -n "${PKG}" ]] && return 0
    [[ -r /etc/os-release ]] || return 1
    local ID="" ID_LIKE=""
    # shellcheck disable=SC1091
    . /etc/os-release
    case "${ID:-}" in
        ubuntu|debian|linuxmint|pop) PKG="apt" ;;
        almalinux|rocky|centos|rhel|ol|fedora) PKG="dnf" ;;
        *)
            case "${ID_LIKE:-}" in
                *debian*) PKG="apt" ;;
                *rhel*|*fedora*) PKG="dnf" ;;
                *) return 1 ;;
            esac ;;
    esac
    return 0
}

# Необязательные пакеты: при неудаче — предупреждение, а не остановка (никогда не вызывает die).
pkg_install_optional() {
    [[ $# -eq 0 ]] && return 0
    detect_pkg_quiet || return 1
    info "Установка пакетов (необязательных): $*"
    case "${PKG}" in
        apt)
            apt_update_once
            apt_wait_lock
            apt_get install -y -q --no-install-recommends "$@" >>"${LOG_FILE}" 2>&1 9>&- && return 0
            # Одна повторная попытка после восстановления (прерванный dpkg, устаревшие списки пакетов).
            apt_recover || true
            apt_wait_lock
            apt_get install -y -q --no-install-recommends "$@" >>"${LOG_FILE}" 2>&1 9>&-
            ;;
        dnf) timeout 3600 dnf install -y -q --setopt=retries=10 "$@" >>"${LOG_FILE}" 2>&1 9>&- ;;
        *) return 1 ;;
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
    # Дескриптор 9 (блокировка acquire_lock) службам не передаётся: без systemd демоны запускаются
    # потомками скрипта и иначе держали бы блокировку, пока работают.
    local action=$1 name=$2
    if has_systemd; then
        case "${action}" in
            enable) systemctl enable --now "${name}" >>"${LOG_FILE}" 2>&1 9>&- ;;
            status) systemctl is-active --quiet "${name}" 9>&- ;;
            *) systemctl "${action}" "${name}" >>"${LOG_FILE}" 2>&1 9>&- ;;
        esac
    else
        case "${action}" in
            enable) service "${name}" start >>"${LOG_FILE}" 2>&1 9>&- || true ;;
            status) service "${name}" status >/dev/null 2>&1 9>&- ;;
            *) service "${name}" "${action}" >>"${LOG_FILE}" 2>&1 9>&- ;;
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
    local tmp var
    tmp=$(umask 077; mktemp "${CONF_DIR}/.install.conf.XXXXXX")
    {
        printf '# Параметры установки портала «%s». Файл создан скриптом install.sh, обновлён %s.\n' "${APP_TITLE}" "$(date '+%Y-%m-%d %H:%M')"
        printf '# Используется скриптами update.sh / backup.sh / restore.sh / uninstall.sh. Не удаляйте.\n'
        # Значения экранируются (printf %q): пароли с кавычками, $ и пробелами не ломают файл.
        for var in INSTALL_MODE APP_DIR APP_VERSION BACKUP_DIR DOMAIN HTTP_PORT SERVICE_USER PHP_VERSION PHP_FPM_SERVICE \
                   PHP_FPM_SOCK DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD DB_SERVER_VERSION DB_LOCAL DB_SERVICE \
                   DB_CREATED_BY_INSTALL DB_USER_CREATED_BY_INSTALL SSL_ENABLED INSTALLED_AT BACKUP_REMOTE BACKUP_REMOTE_KEEP \
                   VIDEO_ENABLED VIDEO_LIVE VIDEO_PATH VIDEO_PORT VIDEO_INTERNAL_PORT VIDEO_DB_PORT VIDEO_DATA_DIR VIDEO_BACKUP_MEDIA \
                   AUTO_UPDATE UPDATE_URL UPDATE_GITHUB; do
            printf '%s=%q\n' "${var}" "${!var:-}"
        done
    } > "${tmp}"
    chmod 600 "${tmp}"
    mv -f "${tmp}" "${CONF_FILE}"
}

# --- MySQL ---------------------------------------------------------------------
# Файл с учётными данными для клиента mysql (пароль не попадает в аргументы процесса).
mysql_defaults_file() {
    # mysql_defaults_file host port user password → путь к временному файлу
    local f
    f=$(mktemp)
    chmod 600 "${f}"
    # Значения в кавычках, \ и " экранируются: иначе символ # в пароле начинает комментарий.
    local pw=${4//\\/\\\\}
    pw=${pw//\"/\\\"}
    printf '[client]\nhost=%s\nport=%s\nuser="%s"\npassword="%s"\n' "$1" "$2" "$3" "${pw}" > "${f}"
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
# Версия $1 строго новее $2.
version_gt() { [[ "$1" != "$2" ]] && version_ge "$1" "$2"; }

# --- Пакеты PHP (Debian, Ubuntu) -------------------------------------------------------
# Есть ли в apt устанавливаемый пакет php<версия>-fpm
apt_has_php() { apt-cache policy "php${1}-fpm" 2>/dev/null | grep_has 'Candidate: [0-9]'; }

# Версия PHP, которую ОС считает основной (по зависимостям метапакета php-fpm), например 8.5
apt_default_php_version() {
    apt-cache depends php-fpm 2>/dev/null | grep -oE 'php[0-9]+\.[0-9]+-fpm' | head -n1 | grep -oE '[0-9]+\.[0-9]+' || true
}

# Версия PHP не ниже МИН среди пакетов apt: сначала основная для ОС, затем предпочтительные.
# detect_apt_php_version [МИН=PHP_MIN_VERSION]
detect_apt_php_version() {
    local min=${1:-${PHP_MIN_VERSION}} v
    # Основная версия ОС — только без стороннего репозитория: с ним метапакет php-fpm указывает на самую
    # новую версию репозитория, а нужна предпочтительная (PHP_PREFERRED_VERSIONS).
    if ! php_repo_present; then
        v=$(apt_default_php_version)
        if [[ -n "${v}" ]] && version_ge "${v}" "${min}" && apt_has_php "${v}"; then
            echo "${v}"; return 0
        fi
    fi
    for v in "${PHP_PREFERRED_VERSIONS[@]}"; do
        if version_ge "${v}" "${min}" && apt_has_php "${v}"; then echo "${v}"; return 0; fi
    done
    return 1
}

# Подключён ли сторонний репозиторий PHP (deb.sury.org / ppa:ondrej/php).
php_repo_present() {
    compgen -G '/etc/apt/sources.list.d/ondrej-ubuntu-php-*' >/dev/null 2>&1 || [[ -f /etc/apt/sources.list.d/php-sury.list ]]
}

# Подключает репозиторий с новыми версиями PHP: ppa:ondrej/php (Ubuntu) или deb.sury.org (Debian).
# Код 1 — не удалось (нет доступа к репозиторию): частично созданные файлы убираются, установка может
# продолжиться с PHP из ОС. ADDED_PHP_REPO=1 — репозиторий подключён этим вызовом.
add_php_repo() {
    php_repo_present && return 0
    pkg_install ca-certificates curl gnupg lsb-release apt-transport-https || return 1
    if [[ "${OS_ID}" == "ubuntu" ]]; then
        pkg_install software-properties-common || return 1
        if ! add-apt-repository -y ppa:ondrej/php >>"${LOG_FILE:-/dev/null}" 2>&1; then
            remove_php_repo
            return 1
        fi
    else
        if ! download https://packages.sury.org/php/apt.gpg /usr/share/keyrings/deb.sury.org-php.gpg; then
            remove_php_repo
            return 1
        fi
        echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php-sury.list
    fi
    ADDED_PHP_REPO="1"
    _APT_UPDATED=0
    if ! apt_update_once; then
        remove_php_repo
        ADDED_PHP_REPO="0"
        return 1
    fi
    return 0
}

remove_php_repo() {
    if [[ "${OS_ID}" == "ubuntu" ]]; then
        command -v add-apt-repository >/dev/null 2>&1 && add-apt-repository -y --remove ppa:ondrej/php >>"${LOG_FILE:-/dev/null}" 2>&1 || true
        rm -f /etc/apt/sources.list.d/ondrej-ubuntu-php-*.list /etc/apt/sources.list.d/ondrej-ubuntu-php-*.sources
    else
        rm -f /etc/apt/sources.list.d/php-sury.list /usr/share/keyrings/deb.sury.org-php.gpg
    fi
    _APT_UPDATED=0
}

# Пакеты PHP для портала: install_php_packages ВЕРСИЯ
install_php_packages() {
    local v=$1
    pkg_install "php${v}-fpm" "php${v}-cli" "php${v}-mysql" "php${v}-xml" "php${v}-mbstring" "php${v}-intl" \
        "php${v}-zip" "php${v}-gd" "php${v}-curl" "php${v}-ldap"
}

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
    loaded=$("${phpbin}" -m 2>/dev/null | tr '[:upper:]' '[:lower:]' || true)
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

# Окружение для заданий cron: в cron PATH=/usr/bin:/bin, а runuser, php-fpm и docker лежат и в /usr/sbin, /usr/local/bin.
CRON_ENV_LINES="SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

# Задания приложения для native-установки (в Docker их выполняет контейнер cron).
cron_app_jobs() {
    cat <<EOF
0 8 * * * root /usr/local/bin/${APP_ID} console app:documents:expiry --no-interaction >> ${LOG_DIR}/expiry-cron.log 2>&1
20 * * * * root /usr/local/bin/${APP_ID} console app:documents:describe --missing --limit=50 --quiet-if-disabled --no-interaction >> ${LOG_DIR}/describe-cron.log 2>&1
40 * * * * root /usr/local/bin/${APP_ID} console app:search:reindex --limit=500 --no-interaction >> ${LOG_DIR}/reindex-cron.log 2>&1
50 * * * * root /usr/local/bin/${APP_ID} console app:ai:index --limit=200 --quiet-if-disabled --no-interaction >> ${LOG_DIR}/ai-index-cron.log 2>&1
30 8 * * * root /usr/local/bin/${APP_ID} console app:documents:acknowledge-remind --no-interaction >> ${LOG_DIR}/acknowledge-cron.log 2>&1
* * * * * root /usr/local/bin/${APP_ID} console app:telegram:poll --no-interaction >> ${LOG_DIR}/telegram-cron.log 2>&1
10 9 * * 1 root /usr/local/bin/${APP_ID} console app:materials:remind --no-interaction >> ${LOG_DIR}/materials-remind-cron.log 2>&1
40 8 * * 1 root /usr/local/bin/${APP_ID} console app:team:digest --no-interaction >> ${LOG_DIR}/team-digest-cron.log 2>&1
5 0 * * * root /usr/local/bin/${APP_ID} console app:stats:aggregate --no-interaction >> ${LOG_DIR}/stats-cron.log 2>&1
* * * * * root /usr/local/bin/${APP_ID} console messenger:consume async --time-limit=55 --memory-limit=256M --quiet --no-interaction >> ${LOG_DIR}/worker-cron.log 2>&1
EOF
}

# Резервная копия (ежедневно в 03:15) и пробное восстановление последней копии во временную базу
# (по воскресеньям в 05:30) — задания сервера в обоих режимах установки.
cron_backup_line() { printf '15 3 * * * root /usr/local/bin/%s backup --quiet >> %s/backup-cron.log 2>&1\n' "${APP_ID}" "${LOG_DIR}"; }
cron_verify_line() { printf '30 5 * * 0 root /usr/local/bin/%s verify-backup --quiet >> %s/verify-backup-cron.log 2>&1\n' "${APP_ID}" "${LOG_DIR}"; }

# Записывает /etc/cron.d/<app>: write_cron_file РЕЗЕРВНОЕ_КОПИРОВАНИЕ(0|1)
write_cron_file() {
    local with_backup=$1 cron_file="/etc/cron.d/${APP_ID}"
    if [[ "${INSTALL_MODE}" == "docker" && "${with_backup}" != "1" ]]; then
        rm -f "${cron_file}"
        return 0
    fi
    {
        printf '# Портал «%s» (создано скриптами установки %s).\n' "${APP_TITLE}" "$(date '+%Y-%m-%d %H:%M')"
        if [[ "${INSTALL_MODE}" == "docker" ]]; then
            printf '# Задания приложения выполняет контейнер cron; здесь — резервная копия (03:15) и её проверка (вс 05:30).\n'
        else
            printf '# Проверка сроков актуальности (08:00), описания через LLM и индексация содержимого (ежечасно),\n'
            printf '# напоминания об ознакомлении (08:30), разбор сообщений Telegram и фоновые задачи (каждую минуту), сводки\n'
            printf '# по таблицам материалов (пн 09:10) и руководителям (пн 08:40), итоги статистики (00:05)%s.\n' "$([[ "${with_backup}" == "1" ]] && echo ', резервная копия (03:15) и её проверка (вс 05:30)')"
            printf '# Задания LLM, Telegram и напоминаний ничего не делают, пока они выключены в панели администратора.\n'
        fi
        printf '%s\n' "${CRON_ENV_LINES}"
        if [[ "${with_backup}" == "1" ]]; then cron_backup_line; cron_verify_line; fi
        [[ "${INSTALL_MODE}" == "docker" ]] || cron_app_jobs
    } > "/etc/cron.d/.${APP_ID}.tmp"
    chmod 644 "/etc/cron.d/.${APP_ID}.tmp"
    mv -f "/etc/cron.d/.${APP_ID}.tmp" "${cron_file}"
    if has_systemd; then svc enable cron >/dev/null 2>&1 || svc enable crond >/dev/null 2>&1 || true; fi
}

# Приводит /etc/cron.d/<app> к текущей версии при обновлении, не переписывая файл целиком:
# добавляет SHELL и PATH (без PATH задания не находили runuser и не выполнялись) и недостающие задания,
# а в Docker-режиме убирает задания приложения (их выполняет контейнер cron — на сервере они дублировались
# и запускались от root). Задания, закомментированные администратором, и его собственные строки остаются.
ensure_cron_entries() {
    local cron_file="/etc/cron.d/${APP_ID}" with_backup="0" tmp line cmd
    [[ -f "${cron_file}" ]] || { [[ "${INSTALL_MODE}" == "docker" ]] && return 0; }
    if [[ ! -f "${cron_file}" ]]; then
        # Файл удалён вручную — восстанавливаем задания приложения (резервную копию — нет: её могли убрать намеренно).
        write_cron_file "${with_backup}"
        info "Восстановлен файл заданий ${cron_file}."
        return 0
    fi
    # Временный файл начинается с точки: такие файлы в /etc/cron.d не читают ни cron (Debian), ни cronie.
    tmp="/etc/cron.d/.${APP_ID}.tmp"
    cp "${cron_file}" "${tmp}"
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        # Убираются только стандартные задания приложения; свои строки администратора остаются.
        while IFS= read -r line; do
            cmd=$(awk '{print $9}' <<<"${line}")
            grep -vE "console ${cmd}( |$)" "${tmp}" > "${tmp}.new" || true
            mv -f "${tmp}.new" "${tmp}"
        done < <(cron_app_jobs)
    fi
    # Переменные окружения — перед первой строкой, которая не комментарий (в cron они действуют на задания ниже).
    local need_shell="1" need_path="1"
    grep -q '^SHELL=' "${tmp}" && need_shell="0"
    grep -q '^PATH=' "${tmp}" && need_path="0"
    if [[ "${need_shell}" == "1" || "${need_path}" == "1" ]]; then
        awk -v need_shell="${need_shell}" -v need_path="${need_path}" \
            -v shell_line="$(grep '^SHELL=' <<<"${CRON_ENV_LINES}")" -v path_line="$(grep '^PATH=' <<<"${CRON_ENV_LINES}")" '
            function put() {
                if (need_shell == "1") print shell_line
                if (need_path == "1") print path_line
                done = 1
            }
            !done && $0 !~ /^[[:space:]]*(#|$)/ { put() }
            { print }
            END { if (!done) put() }' "${tmp}" > "${tmp}.env" && mv -f "${tmp}.env" "${tmp}"
    fi
    if [[ "${INSTALL_MODE}" != "docker" ]]; then
        while IFS= read -r line; do
            cmd=$(awk '{print $9}' <<<"${line}")
            # Задание уже есть — включённое или закомментированное администратором.
            if ! grep -qE "console ${cmd}( |$)" "${tmp}"; then
                # Последняя строка файла могла остаться без перевода строки — задание не должно к ней приклеиться.
                [[ -s "${tmp}" && -n "$(tail -c1 "${tmp}")" ]] && printf '\n' >> "${tmp}"
                printf '%s\n' "${line}" >> "${tmp}"
            fi
        done < <(cron_app_jobs)
    fi
    # Проверка восстановления (1.7.0) — рядом с включённой резервной копией; если копию отключил
    # администратор (строка закомментирована или удалена), проверять нечего.
    if grep -qE "^[[:space:]]*[^#[:space:]].*/${APP_ID} backup( |$)" "${tmp}" && ! grep -qE "${APP_ID} verify-backup( |$)" "${tmp}"; then
        [[ -s "${tmp}" && -n "$(tail -c1 "${tmp}")" ]] && printf '\n' >> "${tmp}"
        cron_verify_line >> "${tmp}"
    fi
    if cmp -s "${tmp}" "${cron_file}"; then
        rm -f "${tmp}"
    else
        chmod 644 "${tmp}"
        mv -f "${tmp}" "${cron_file}"
        info "Задания cron обновлены: ${cron_file}."
    fi
}

# --- Необязательные инструменты: предпросмотр офисных файлов и распознавание сканов -----------
# LibreOffice весит сотни мегабайт, tesseract нужен не всем — поэтому ставятся по отдельному запросу
# (--with-preview / --with-ocr при установке либо вручную, команда показана в панели администратора).
install_preview_tools() {
    case "${OS_FAMILY:-}" in
        debian)
            pkg_install_optional libreoffice-nogui && return 0
            pkg_install_optional libreoffice-writer libreoffice-calc libreoffice-impress && return 0
            ;;
        rhel)
            pkg_install_optional libreoffice-headless && return 0
            pkg_install_optional libreoffice-writer libreoffice-calc libreoffice-impress && return 0
            ;;
    esac
    return 1
}

install_ocr_tools() {
    case "${OS_FAMILY:-}" in
        debian) pkg_install_optional tesseract-ocr tesseract-ocr-rus poppler-utils && return 0 ;;
        rhel)   pkg_install_optional tesseract tesseract-langpack-rus poppler-utils && return 0 ;;
    esac
    return 1
}

install_cli_wrapper() {
    cat > "/usr/local/bin/${APP_ID}.tmp" <<EOF
#!/usr/bin/env bash
# Управление порталом «${APP_TITLE}»: ${APP_ID} <команда> [параметры]
set -euo pipefail
# Из cron команда запускается с PATH=/usr/bin:/bin, а runuser и docker могут лежать в /usr/sbin и /usr/local/bin.
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\${PATH:+:\${PATH}}"
CONF="${CONF_FILE}"
[[ -r "\${CONF}" ]] || { echo "Портал не установлен (нет \${CONF})." >&2; exit 1; }
. "\${CONF}"
if [[ "\${INSTALL_MODE}" == "docker" ]]; then DEPLOY="\${APP_DIR}/deploy"; else DEPLOY="\${APP_DIR}/current/deploy"; fi
# Консоль в контейнере запускается от www-data (иначе кэш Symfony создаётся от root и портал перестаёт его обновлять);
# без терминала (cron, конвейер) — без псевдотерминала.
DEXEC=(docker compose exec -u www-data)
[[ -t 0 && -t 1 ]] || DEXEC+=(-T)
# Консоль — той же версией PHP, что и PHP-FPM (php8.3, php8.4…), а не той, на которую указывает «php»:
# на сервере может стоять и другая версия.
PHPX=\$(command -v "php\${PHP_VERSION:-}" 2>/dev/null || command -v php 2>/dev/null || echo php)
cmd="\${1:-help}"; shift || true
case "\${cmd}" in
    update|backup|restore|uninstall|status|doctor|maintenance|verify-backup|remote-backup|video|auto-update|kerberos|php-upgrade)
        [[ "\${cmd}" == "status" ]] && cmd="doctor"
        exec "\${DEPLOY}/\${cmd}.sh" "\$@" ;;
    console)
        if [[ "\${INSTALL_MODE}" == "docker" ]]; then
            cd "\${APP_DIR}/docker" && exec "\${DEXEC[@]}" app php bin/console "\$@"
        else
            exec runuser -u "\${SERVICE_USER}" -- "\${PHPX}" "\${APP_DIR}/current/bin/console" "\$@"
        fi ;;
    import)
        # import [КАТАЛОГ] [параметры app:import:directory]: папки → разделы, файлы → документы.
        SRC=""; if [[ \$# -gt 0 && "\${1}" != -* ]]; then SRC="\${1}"; shift; fi
        if [[ "\${INSTALL_MODE}" == "docker" ]]; then
            cd "\${APP_DIR}/docker"
            if [[ -z "\${SRC}" ]]; then exec "\${DEXEC[@]}" app php bin/console app:import:directory "\$@"; fi
            [[ -d "\${SRC}" ]] || { echo "Каталог не найден: \${SRC}" >&2; exit 1; }
            NAME=\$(basename "\${SRC}")
            # Каталог копируется в том импорта контейнера, импортируется оттуда и удаляется после успеха.
            docker compose cp "\${SRC}" "app:/var/www/html/var/import/\${NAME}" >/dev/null || exit 1
            docker compose exec -T app chown -R www-data:www-data "/var/www/html/var/import/\${NAME}" >/dev/null 2>&1 || true
            "\${DEXEC[@]}" app php bin/console app:import:directory "/var/www/html/var/import/\${NAME}" --root-as-section "\$@" || exit \$?
            exec docker compose exec -T app rm -rf "/var/www/html/var/import/\${NAME}"
        fi
        if [[ -z "\${SRC}" ]]; then exec runuser -u "\${SERVICE_USER}" -- "\${PHPX}" "\${APP_DIR}/current/bin/console" app:import:directory "\$@"; fi
        [[ -d "\${SRC}" ]] || { echo "Каталог не найден: \${SRC}" >&2; exit 1; }
        SRC=\$(cd "\${SRC}" && pwd)
        # Пользователь службы должен читать каталог: при необходимости скопируйте его в \${APP_DIR}/shared/import.
        exec runuser -u "\${SERVICE_USER}" -- "\${PHPX}" "\${APP_DIR}/current/bin/console" app:import:directory "\${SRC}" --root-as-section "\$@" ;;
    org-import)
        # org-import ФАЙЛ [параметры app:org:import]: оргструктура из выгрузки 1С:ЗУП (CSV или Excel).
        # Файл копируется туда, где его прочитает пользователь службы (в Docker — в контейнер), и после загрузки удаляется.
        [[ \$# -gt 0 && -f "\${1}" ]] || { echo "Использование: ${APP_ID} org-import ФАЙЛ.csv|ФАЙЛ.xlsx [--dry-run] [--create] [--no-deactivate] [--names]" >&2; exit 1; }
        SRC="\${1}"; shift
        EXT="\${SRC##*.}"; TMPNAME="org-import-\$\$.\${EXT}"
        rc=0
        if [[ "\${INSTALL_MODE}" == "docker" ]]; then
            cd "\${APP_DIR}/docker"
            docker compose cp "\${SRC}" "app:/tmp/\${TMPNAME}" >/dev/null || exit 1
            docker compose exec -T app chmod 644 "/tmp/\${TMPNAME}" >/dev/null 2>&1 || true
            "\${DEXEC[@]}" app php bin/console app:org:import "/tmp/\${TMPNAME}" "\$@" || rc=\$?
            docker compose exec -T app rm -f "/tmp/\${TMPNAME}" >/dev/null 2>&1 || true
            exit "\${rc}"
        fi
        TMPDIR_ORG=\$(mktemp -d) || exit 1
        install -m 0644 "\${SRC}" "\${TMPDIR_ORG}/\${TMPNAME}" && chmod 0755 "\${TMPDIR_ORG}" || { rm -rf "\${TMPDIR_ORG}"; exit 1; }
        runuser -u "\${SERVICE_USER}" -- "\${PHPX}" "\${APP_DIR}/current/bin/console" app:org:import "\${TMPDIR_ORG}/\${TMPNAME}" "\$@" || rc=\$?
        rm -rf "\${TMPDIR_ORG}"
        exit "\${rc}" ;;
    api-key|describe|reindex|acknowledge-remind|telegram|ai-index|team-digest)
        # api-key — ключи REST API; describe — описания через LLM; reindex — индекс поиска по содержимому;
        # ai-index — смысловой индекс для ответов ИИ (поиск по смыслу) и очистка журнала вопросов к ИИ;
        # acknowledge-remind — напоминания сотрудникам о неподтверждённом ознакомлении;
        # team-digest — еженедельная сводка руководителям (кто из сотрудников не ознакомился);
        # telegram — разбор сообщений бота и проверка связи.
        SUB="app:api-key"
        [[ "\${cmd}" == "describe" ]] && SUB="app:documents:describe"
        [[ "\${cmd}" == "reindex" ]] && SUB="app:search:reindex"
        [[ "\${cmd}" == "acknowledge-remind" ]] && SUB="app:documents:acknowledge-remind"
        [[ "\${cmd}" == "telegram" ]] && SUB="app:telegram:poll"
        [[ "\${cmd}" == "ai-index" ]] && SUB="app:ai:index"
        [[ "\${cmd}" == "team-digest" ]] && SUB="app:team:digest"
        if [[ "\${INSTALL_MODE}" == "docker" ]]; then
            cd "\${APP_DIR}/docker" && exec "\${DEXEC[@]}" app php bin/console "\${SUB}" "\$@"
        else
            exec runuser -u "\${SERVICE_USER}" -- "\${PHPX}" "\${APP_DIR}/current/bin/console" "\${SUB}" "\$@"
        fi ;;
    logs)
        if [[ "\${INSTALL_MODE}" == "docker" ]]; then cd "\${APP_DIR}/docker" && exec docker compose logs -f --tail=200 "\$@";
        else
            LOGF=\$( (ls -t "\${APP_DIR}"/shared/log/prod*.log 2>/dev/null || true) | head -n1)
            [[ -n "\${LOGF}" ]] || { echo "Журнал приложения пока пуст (\${APP_DIR}/shared/log)." >&2; exit 0; }
            exec tail -n 200 -f "\${LOGF}" "\$@"
        fi ;;
    help|--help|-h|*)
        cat <<HELP
Использование: ${APP_ID} <команда>
  status              Состояние портала и диагностика
  update [--archive FILE|--source DIR]   Обновление до новой версии без вопросов (без параметров — архив
                      новее установленного ищется в /var/lib/${APP_ID}/updates, текущем каталоге, /root, /tmp)
  auto-update on [--time ЧЧ:ММ] [--github ВЛ/РЕПО | --url АДРЕС] | off | status | run
                                        Обновление по расписанию (каталог /var/lib/${APP_ID}/updates, релизы GitHub, веб-сервер)
  backup              Резервная копия (база данных + файлы; --no-remote — без отправки во внешнее хранилище)
  restore ARCHIVE     Восстановление из резервной копии
  verify-backup [ARCHIVE]   Пробное восстановление копии во временную базу (по умолчанию — последней)
  remote-backup …     Копии во внешнем хранилище (rclone или каталог): status | set НАЗНАЧЕНИЕ [--keep N] | off | test | push
  php-upgrade [--to 8.4] [--check]   Переход на новую версию PHP (Debian, Ubuntu): пакеты, PHP-FPM, nginx,
                      проверка; при неудаче — возврат на прежнюю. Нужен PHP ${PHP_RECOMMENDED_VERSION}+ для будущей Symfony 8
  maintenance on|off|status   Режим обслуживания: посетители видят страницу «Идёт обновление портала»
                      (on --message "текст" --allow IP,IP — портал остаётся доступен с указанных адресов)
  console <команда>   Консоль Symfony (например: console app:user:create ivanov --admin, console app:documents:expiry, console app:ldap:test)
  import [КАТАЛОГ]    Импорт документов из каталога: папки → разделы, файлы → документы
                      (без каталога — импорт каталога IMPORT_DIR; параметры: --section=ID --dry-run --draft --delete-source --describe …)
  api-key <действие>  Ключи REST API для внешних систем (RAG): list | create "Название" [--internal] | disable ID | enable ID | delete ID
  describe            Сформировать описания документов через LLM (параметры: --missing --regenerate --force --limit=N --dry-run)
  reindex             Индекс полнотекстового поиска по содержимому файлов (--all — всё заново, --status — состояние)
  ai-index            Смысловой индекс для ответов ИИ (поиск по смыслу; --all — заново, --status — состояние)
  acknowledge-remind  Напоминания о неподтверждённом ознакомлении (--days=N, --dry-run)
  team-digest         Сводка руководителям о сотрудниках (--dry-run, --force, --manager=ЛОГИН — письмо одному)
  telegram            Уведомления в Telegram: разбор сообщений бота (--status, --check, --send "текст" --user ЛОГИН)
  kerberos on|off|status   Вход через Windows без пароля (Kerberos/SPNEGO в nginx):
                      on --keytab ФАЙЛ --service HTTP/имя.портала [--realm ОБЛАСТЬ] [--auto]
  org-import ФАЙЛ     Оргструктура из 1С:ЗУП (CSV или Excel): подразделения, должности, руководители, уволенные
                      (--dry-run — только показать изменения; --create — создать недостающих; --no-deactivate; --names)
  video <команда>     Раздел «Видео» (видеопортал по адресу /video, общий вход и роли с порталом):
                      enable [--no-live] [--backup-media] | status | restart | sync-users | logs [api|worker|mediamtx] | disable
  logs                Журнал приложения
  uninstall           Удаление портала
HELP
        [[ "\${cmd}" == help || "\${cmd}" == --help || "\${cmd}" == -h ]] || { echo "Неизвестная команда: \${cmd}" >&2; exit 2; }
        ;;
esac
EOF
    chmod 755 "/usr/local/bin/${APP_ID}.tmp"
    mv -f "/usr/local/bin/${APP_ID}.tmp" "/usr/local/bin/${APP_ID}"
}

# Значение для .env.local (Symfony Dotenv): в одинарных кавычках — буквально, без подстановки $ и
# экранирования. Одинарная кавычка внутри значения записывается склейкой частей: it's → 'it'"'"'s'
# (Dotenv соединяет соседние части; в двойных кавычках — только сама кавычка, подставлять там нечего).
env_quote() {
    local v=$1 q="'"
    printf "'%s'" "${v//${q}/${q}\"${q}\"${q}}"
}

# Значение для docker/.env: простые значения — как есть (их одинаково читают все версии docker compose),
# значения с $, #, кавычками, обратной косой чертой или пробелами по краям — в двойных кавычках
# с экранированием \\, \" и \$. Одинарные кавычки здесь не годятся: docker compose читает \' в конце
# значения как экранированную кавычку и не находит конца значения.
compose_env_value() {
    local v=$1
    if [[ "${v}" =~ ^[^\$\#\'\"\\]*$ && "${v}" == "${v#[[:space:]]}" && "${v}" == "${v%[[:space:]]}" ]]; then
        printf '%s' "${v}"
    else
        v=${v//\\/\\\\}; v=${v//\"/\\\"}; v=${v//\$/\\\$}
        printf '"%s"' "${v}"
    fi
}

# Пул PHP-FPM портала: по умолчанию в Debian/Ubuntu пул www обслуживает всего 5 запросов одновременно
# (загрузка крупных файлов и предпросмотр быстро их занимают). configure_fpm_pool КАТАЛОГ_ПУЛОВ
configure_fpm_pool() {
    local pool_dir=$1 file mem children
    [[ -d "${pool_dir}" ]] || return 0
    file="${pool_dir}/zz-${APP_ID}.conf"
    [[ -f "${file}" ]] && return 0
    mem=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 1024)
    children=$(( (mem - 512) / 80 ))
    (( children < 5 )) && children=5
    (( children > 40 )) && children=40
    cat > "${file}" <<EOF
; Пул PHP-FPM портала «${APP_TITLE}» (создано скриптами установки; дополняет стандартный пул www).
[www]
pm = dynamic
pm.max_children = ${children}
pm.start_servers = $(( children / 4 > 2 ? children / 4 : 2 ))
pm.min_spare_servers = 2
pm.max_spare_servers = $(( children / 2 > 3 ? children / 2 : 3 ))
pm.max_requests = 500
request_terminate_timeout = 300s
EOF
    local fpm_bin
    fpm_bin=$(command -v "php-fpm${PHP_VERSION:-}" 2>/dev/null || command -v php-fpm 2>/dev/null || command -v "/usr/sbin/php-fpm${PHP_VERSION:-}" 2>/dev/null || true)
    if [[ -n "${fpm_bin}" ]] && ! "${fpm_bin}" -t >>"${LOG_FILE:-/dev/null}" 2>&1; then
        rm -f "${file}"
        warn "Настройки пула PHP-FPM не прошли проверку — оставлены стандартные."
        return 0
    fi
    info "Пул PHP-FPM: до ${children} одновременных запросов (${file})."
}

# Настройки системы, появившиеся в новых версиях скриптов, для native-установок предыдущих версий
# (вызывается из update.sh и refresh.sh; любая неудача — только предупреждение). upgrade_native_system_config РЕЛИЗ
upgrade_native_system_config() {
    local rel=${1:-${APP_DIR}/current} ini pool_dir="/etc/php/${PHP_VERSION}/fpm/pool.d" changed="0"
    [[ -d "${pool_dir}" ]] || pool_dir="/etc/php-fpm.d"
    # Время жизни сессий (1.6.1): без него phpsessionclean удалял сессии через 24 минуты простоя.
    for ini in "/etc/php/${PHP_VERSION}/fpm/conf.d/90-${APP_ID}.ini" "/etc/php/${PHP_VERSION}/cli/conf.d/90-${APP_ID}.ini" "/etc/php.d/90-${APP_ID}.ini"; do
        if [[ -f "${ini}" ]] && ! grep -q '^session.gc_maxlifetime' "${ini}"; then
            printf '; Время жизни сессии (12 ч) — для задания очистки сессий phpsessionclean.\nsession.gc_maxlifetime = 43200\n' >> "${ini}"
            changed="1"
        fi
    done
    if [[ ! -f "${pool_dir}/zz-${APP_ID}.conf" ]]; then
        configure_fpm_pool "${pool_dir}"
        [[ -f "${pool_dir}/zz-${APP_ID}.conf" ]] && changed="1"
    fi
    if [[ "${changed}" == "1" ]]; then
        svc reload "${PHP_FPM_SERVICE}" || svc restart "${PHP_FPM_SERVICE}" || warn "Не удалось перезапустить ${PHP_FPM_SERVICE} — выполните: systemctl restart ${PHP_FPM_SERVICE}"
    fi
    # Настройки приложения: владелец root, пользователь службы только читает.
    if [[ -f "${APP_DIR}/shared/.env.local" ]]; then
        chown "root:${SERVICE_USER}" "${APP_DIR}/shared/.env.local" 2>/dev/null && chmod 640 "${APP_DIR}/shared/.env.local" 2>/dev/null || true
    fi
    if command -v getenforce >/dev/null 2>&1 && [[ "$(getenforce 2>/dev/null)" == "Enforcing" ]]; then
        semanage fcontext -a -t httpd_sys_rw_content_t "${APP_DIR}/releases/[^/]+/var(/.*)?" >>"${LOG_FILE:-/dev/null}" 2>&1 || true
        # current — символическая ссылка: restorecon по ней не переходит, поэтому — настоящий каталог релиза.
        restorecon -R "$(readlink -f "${rel}")" >>"${LOG_FILE:-/dev/null}" 2>&1 || true
    fi
    return 0
}

# Ротация журналов заданий cron (/var/log/<app>/*-cron.log). Журналы разовых запусков скриптов
# (install-…, update-…, backup-…) не ротируются — старые удаляет log_init; журналы приложения
# (shared/log/prod-ГГГГ-ММ-ДД.log, audit-…) Symfony ведёт по дням и удаляет сам.
install_logrotate() {
    [[ -d /etc/logrotate.d ]] || return 0
    cat > "/etc/logrotate.d/${APP_ID}" <<EOF
# Портал «${APP_TITLE}» (создано скриптами установки).
${LOG_DIR}/*-cron.log {
    weekly
    rotate 8
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
EOF
    chmod 644 "/etc/logrotate.d/${APP_ID}"
}

# --- Отметки о заданиях для мониторинга ------------------------------------------------------------
# Резервную копию, копию во внешнем хранилище и проверку восстановления выполняют скрипты сервера, а не
# портал — об их запусках портал узнаёт отсюда (панель администратора → Мониторинг, /health/details).
# Неудачная отметка ни на что не влияет (например, при обновлении с версии, где такой команды ещё нет).
# record_job ИМЯ running|ok|warning|error [ПОЯСНЕНИЕ] [СЕКУНДЫ]
record_job() {
    local name=$1 status=$2 message=${3:-} duration=${4:-} php_bin
    local args=(app:system:job "${name}" "--status=${status}")
    [[ -n "${message}" ]] && args+=("--message=${message}")
    [[ -n "${duration}" ]] && args+=("--duration=${duration}")
    [[ -n "${APP_DIR:-}" ]] || return 0
    if [[ "${INSTALL_MODE:-}" == "docker" ]]; then
        [[ -f "${APP_DIR}/docker/docker-compose.yml" ]] || return 0
        (cd "${APP_DIR}/docker" && timeout 120 docker compose exec -T -u www-data app php bin/console "${args[@]}" --no-interaction) \
            >>"${LOG_FILE:-/dev/null}" 2>&1 9>&- || true
    else
        [[ -f "${APP_DIR}/current/bin/console" ]] || return 0
        php_bin=$(php_bin_for "${PHP_VERSION:-}") || return 0
        timeout 120 runuser -u "${SERVICE_USER}" -- "${php_bin}" "${APP_DIR}/current/bin/console" "${args[@]}" --env=prod --no-interaction \
            >>"${LOG_FILE:-/dev/null}" 2>&1 9>&- || true
    fi
    return 0
}

# --- Режим обслуживания ------------------------------------------------------------------------------
# Пока режим включён, посетители видят страницу «Идёт обновление портала» (ответ 503), а /health,
# /health/details и /metrics отвечают как обычно: по ним скрипты обновления проверяют новую версию,
# а мониторинг — сам портал. Включают режим update.sh (на время миграций) и restore.sh (на время замены
# данных); вручную — «docportal maintenance on|off».
# Native: состояние — в /var/lib/<app>/maintenance (только root: отсюда nginx подключает список
# разрешённых адресов), правило — в /etc/nginx/<app>-maintenance.conf, подключённом в сайт портала.
# Docker: состояние — в ${APP_DIR}/maintenance (каталог подключён в контейнер web только для чтения),
# правило — в docker/nginx/default.conf.
MAINTENANCE_SNIPPET="/etc/nginx/${APP_ID}-maintenance.conf"

maintenance_dir() {
    if [[ "${INSTALL_MODE:-native}" == "docker" ]]; then printf '%s/maintenance' "${APP_DIR}"; else printf '/var/lib/%s/maintenance' "${APP_ID}"; fi
}

maintenance_is_on() { [[ -f "$(maintenance_dir)/enabled" ]]; }

# Кто включил режим: update, restore или admin (по нему update.sh и restore.sh узнают режим, оставленный их
# же прерванным запуском, и не выключают режим, включённый администратором).
maintenance_owner() { head -n1 "$(maintenance_dir)/owner" 2>/dev/null | tr -cd 'a-z' || true; }

# SELinux (RHEL): каталог режима должен быть доступен nginx на чтение, иначе nginx «не видит» флаг и
# страницу — режим молча не работает.
maintenance_selinux_label() {
    [[ "${INSTALL_MODE:-native}" == "native" ]] || return 0
    command -v selinuxenabled >/dev/null 2>&1 && selinuxenabled 2>/dev/null || return 0
    local dir
    dir=$(maintenance_dir)
    if command -v semanage >/dev/null 2>&1; then
        semanage fcontext -a -t httpd_sys_content_t "${dir}(/.*)?" >>"${LOG_FILE:-/dev/null}" 2>&1 \
            || semanage fcontext -m -t httpd_sys_content_t "${dir}(/.*)?" >>"${LOG_FILE:-/dev/null}" 2>&1 || true
    fi
    if command -v restorecon >/dev/null 2>&1; then
        restorecon -R "${dir}" >>"${LOG_FILE:-/dev/null}" 2>&1 || true
    elif command -v chcon >/dev/null 2>&1; then
        chcon -R -t httpd_sys_content_t "${dir}" >>"${LOG_FILE:-/dev/null}" 2>&1 || true
    fi
}

# Проверка, что посетители действительно получают страницу обслуживания (ответ 503 на «/»).
# Код 1 — веб-сервер отвечает как обычно (например, nginx не может прочитать флаг).
maintenance_verify() {
    command -v curl >/dev/null 2>&1 || return 0
    local host="${DOMAIN:-localhost}" url code
    local resolve=(--resolve "${host}:80:127.0.0.1" --resolve "${host}:443:127.0.0.1")
    if [[ "${HTTP_PORT:-80}" != "80" && "${HTTP_PORT:-80}" != "443" ]]; then
        resolve+=(--resolve "${host}:${HTTP_PORT}:127.0.0.1")
    fi
    url=$(health_url)
    url="${url%/health}/"
    code=$(curl -s -L -k -m 10 --max-redirs 3 --noproxy '*' "${resolve[@]}" -H "Host: ${host}" -o /dev/null -w '%{http_code}' "${url}" 2>/dev/null || true)
    # Нет соединения — проверить нельзя (не считаем ошибкой режима).
    [[ "${code}" == "503" || -z "${code}" || "${code}" == "000" ]]
}

# Пояснение, с которым включён режим.
maintenance_message() { sed -n '2,$p' "$(maintenance_dir)/enabled" 2>/dev/null | tr '\n' ' ' | sed 's/ *$//' || true; }

# Страница обслуживания из шаблона deploy/maintenance.html; текст администратора экранируется.
# maintenance_write_page [ТЕКСТ]
maintenance_write_page() {
    local dir tpl note="" since msg=${1:-}
    dir=$(maintenance_dir)
    tpl="${SCRIPT_DIR}/maintenance.html"
    [[ -f "${tpl}" ]] || return 1
    install -d -m 755 "${dir}" || return 1
    if [[ -n "${msg}" ]]; then
        # Одна строка не длиннее ~300 символов; iconv -c убирает символ, разрезанный по границе байтов.
        msg=$(printf '%s' "${msg}" | tr '\r\n\t' '   ' | cut -c1-600 | iconv -f UTF-8 -t UTF-8 -c 2>/dev/null \
            | sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e 's/>/\&gt;/g' -e 's/"/\&quot;/g' || true)
        [[ -n "${msg}" ]] && note="<p class=\"note\">${msg}</p>"
    fi
    since="<p class=\"muted\">Начало работ: $(date '+%d.%m.%Y %H:%M').</p>"
    MAINT_NOTE="${note}" MAINT_SINCE="${since}" awk '
        index($0, "<!--MESSAGE-->") { print ENVIRON["MAINT_NOTE"]; next }
        index($0, "<!--SINCE-->") { print ENVIRON["MAINT_SINCE"]; next }
        { print }' "${tpl}" > "${dir}/.index.html.tmp" || return 1
    chmod 644 "${dir}/.index.html.tmp"
    mv -f "${dir}/.index.html.tmp" "${dir}/index.html"
}

# Файл сайта портала в nginx (native).
nginx_site_conf() {
    local f
    for f in "/etc/nginx/sites-available/${APP_ID}.conf" "/etc/nginx/conf.d/${APP_ID}.conf"; do
        if [[ -f "${f}" ]]; then printf '%s' "${f}"; return 0; fi
    done
    return 1
}

# Правило режима обслуживания для nginx (native). Подключается в server{} сайта портала.
# MAINTENANCE_SNIPPET_CHANGED=1 — содержимое изменилось (nginx нужно перечитать конфигурацию).
MAINTENANCE_SNIPPET_CHANGED="0"
write_maintenance_snippet() {
    local dir tmp
    dir=$(maintenance_dir)
    tmp=$(mktemp "/etc/nginx/.${APP_ID}-maintenance.XXXXXX") || return 1
    cat > "${tmp}" <<SNIPPET
# Режим обслуживания портала «${APP_TITLE}». Файл создают скрипты установки и обновления — правки
# будут перезаписаны. Включение и выключение: ${APP_ID} maintenance on|off.
set \$docportal_maintenance 0;
if (-f ${dir}/enabled) {
    set \$docportal_maintenance 1;
}
# Адреса, которым портал доступен и во время обслуживания (${APP_ID} maintenance on --allow …).
include ${dir}/allow*.conf;
# Проверки работоспособности отвечают всегда: по ним скрипты обновления проверяют новую версию. Сверяется
# исходный адрес запроса (\$request_uri): после внутреннего перехода на /index.php правила проверяются заново.
if (\$request_uri ~ "^/(health|health/details|metrics)(\\?|\$)") {
    set \$docportal_maintenance 0;
}
if (\$uri = /${APP_ID}-maintenance.html) {
    set \$docportal_maintenance 0;
}
if (\$docportal_maintenance) {
    return 503;
}
# Страница отдаётся по внутреннему адресу (а не через именованный location): так запрос любого метода,
# в том числе отправка формы, получает страницу, а не ошибку 405.
# Нет файла страницы — стандартный ответ 503 nginx (а не 404).
error_page 503 /${APP_ID}-maintenance.html;
location = /${APP_ID}-maintenance.html {
    internal;
    root ${dir};
    try_files /index.html =503;
    default_type text/html;
    charset utf-8;
    add_header Retry-After 120 always;
    add_header Cache-Control "no-store" always;
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
}
SNIPPET
    chmod 644 "${tmp}"
    if cmp -s "${tmp}" "${MAINTENANCE_SNIPPET}"; then
        rm -f "${tmp}"
        MAINTENANCE_SNIPPET_CHANGED="0"
    else
        # Прежнее правило сохраняется: если nginx не примет новое, оно возвращается (см. ensure_nginx_maintenance).
        if [[ -f "${MAINTENANCE_SNIPPET}" ]]; then cp -p "${MAINTENANCE_SNIPPET}" "${MAINTENANCE_SNIPPET}.prev"; else rm -f "${MAINTENANCE_SNIPPET}.prev"; fi
        mv -f "${tmp}" "${MAINTENANCE_SNIPPET}"
        MAINTENANCE_SNIPPET_CHANGED="1"
    fi
}

# Native: правило nginx и его подключение в сайт портала (сайты установок до 1.7.0 его не содержат).
# Код 1 — подключить не удалось (сайт изменён вручную или nginx не принял конфигурацию); портал при этом
# работает как прежде, только без страницы обслуживания.
ensure_nginx_maintenance() {
    [[ "${INSTALL_MODE:-native}" == "native" ]] || return 0
    command -v nginx >/dev/null 2>&1 || return 0
    local dir site bak include_line
    dir=$(maintenance_dir)
    install -d -m 755 "$(dirname "${dir}")" "${dir}" || return 1
    [[ -f "${dir}/index.html" ]] || maintenance_write_page "" || true
    maintenance_selinux_label
    write_maintenance_snippet || return 1
    site=$(nginx_site_conf) || return 0
    include_line="include ${MAINTENANCE_SNIPPET};"
    if grep -qF "${include_line}" "${site}"; then
        # Правило уже подключено; если обновление изменило его — nginx перечитывает конфигурацию.
        if [[ "${MAINTENANCE_SNIPPET_CHANGED}" == "1" ]]; then
            if nginx -t >>"${LOG_FILE:-/dev/null}" 2>&1; then
                rm -f "${MAINTENANCE_SNIPPET}.prev"
                svc reload nginx || true
            else
                # Новое правило не принято — возвращается прежнее: иначе следующий перезапуск nginx (продление
                # сертификата, перезагрузка сервера) остановил бы сайт.
                if [[ -f "${MAINTENANCE_SNIPPET}.prev" ]]; then mv -f "${MAINTENANCE_SNIPPET}.prev" "${MAINTENANCE_SNIPPET}"; fi
                warn "nginx не принял обновлённое правило режима обслуживания (nginx -t) — оставлено прежнее (${MAINTENANCE_SNIPPET})."
                return 1
            fi
        fi
        return 0
    fi
    if ! grep -qE "^[[:space:]]*root ${APP_DIR}/current/public;" "${site}"; then
        warn "В ${site} нет строки «root ${APP_DIR}/current/public;» — режим обслуживания не подключён. Добавьте в блок server{} строку: ${include_line}"
        return 1
    fi
    bak=$(mktemp) || return 1
    cp -p "${site}" "${bak}"
    # После каждой строки root сайта (в том числе в блоке HTTPS, который добавил certbot).
    sed -i "s|^\([[:space:]]*\)root ${APP_DIR}/current/public;|&\n\1${include_line}|" "${site}"
    if nginx -t >>"${LOG_FILE:-/dev/null}" 2>&1; then
        rm -f "${bak}"
        svc reload nginx || true
        info "Режим обслуживания подключён к сайту nginx (${site})."
        return 0
    fi
    cp -p "${bak}" "${site}"; rm -f "${bak}"
    warn "nginx не принял правило режима обслуживания — сайт возвращён в прежний вид (подробности: nginx -t)."
    return 1
}

# Подключено ли правило режима обслуживания (иначе включённый режим посетители не заметят).
maintenance_wired() {
    if [[ "${INSTALL_MODE:-native}" == "docker" ]]; then
        grep -q '/var/www/maintenance' "${APP_DIR}/docker/nginx/default.conf" 2>/dev/null
    else
        local site
        site=$(nginx_site_conf) && [[ -f "${MAINTENANCE_SNIPPET}" ]] && grep -qF "include ${MAINTENANCE_SNIPPET};" "${site}"
    fi
}

# Перечитать конфигурацию веб-сервера (после изменения списка разрешённых адресов).
maintenance_reload_web() {
    if [[ "${INSTALL_MODE:-native}" == "docker" ]]; then
        (cd "${APP_DIR}/docker" && docker compose exec -T web nginx -t && docker compose exec -T web nginx -s reload) >>"${LOG_FILE:-/dev/null}" 2>&1 9>&-
    else
        nginx -t >>"${LOG_FILE:-/dev/null}" 2>&1 && svc reload nginx
    fi
}

# Включение: maintenance_on [ТЕКСТ] [АДРЕСА через запятую или пробел] [КТО: admin|update|restore]. Код 1 —
# неверный адрес или nginx не принял список адресов (режим тогда не включается).
maintenance_on() {
    local dir msg=${1:-} allow=${2:-} owner=${3:-admin} ip lines=""
    dir=$(maintenance_dir)
    install -d -m 755 "${dir}" || return 1
    maintenance_selinux_label
    maintenance_write_page "${msg}" || warn "Не удалось подготовить страницу обслуживания — посетители увидят стандартный ответ 503."
    if [[ -n "${allow}" ]]; then
        for ip in ${allow//,/ }; do
            if [[ "${ip}" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || [[ "${ip}" =~ ^[0-9A-Fa-f:.]+$ && "${ip}" == *:* ]]; then
                lines+="if (\$remote_addr = \"${ip}\") { set \$docportal_maintenance 0; }"$'\n'
            else
                error "«${ip}» — не адрес IPv4 или IPv6 (подсети не поддерживаются: перечислите адреса через запятую)."
                return 1
            fi
        done
        printf '# Адреса, которым портал доступен во время обслуживания (%s).\n%s' "$(date '+%Y-%m-%d %H:%M')" "${lines}" > "${dir}/.allow.tmp"
        chmod 644 "${dir}/.allow.tmp"
        mv -f "${dir}/.allow.tmp" "${dir}/allow.conf"
        if ! maintenance_reload_web; then
            rm -f "${dir}/allow.conf"
            maintenance_reload_web || true
            error "Веб-сервер не принял список адресов — режим обслуживания не включён."
            return 1
        fi
        # Новые процессы nginx с обновлённым списком принимают запросы не мгновенно.
        sleep 1
    elif [[ -f "${dir}/allow.conf" ]]; then
        rm -f "${dir}/allow.conf"
        maintenance_reload_web || true
    fi
    printf '%s\n' "${owner}" > "${dir}/owner"
    chmod 644 "${dir}/owner"
    { date '+%Y-%m-%d %H:%M:%S'; printf '%s\n' "${msg}"; } > "${dir}/.enabled.tmp"
    chmod 644 "${dir}/.enabled.tmp"
    mv -f "${dir}/.enabled.tmp" "${dir}/enabled"
    maintenance_selinux_label
    # Флаг, недоступный веб-серверу (права, SELinux), — то же, что выключенный режим: проверяем по ответу.
    # Сразу после перечитывания конфигурации nginx старые процессы ещё отвечают — даём им несколько секунд.
    if [[ "${INSTALL_MODE:-native}" == "native" && -z "${allow}" ]] && maintenance_wired; then
        local attempt verified="0"
        for attempt in 1 2 3 4 5; do
            if maintenance_verify; then verified="1"; break; fi
            sleep 1
        done
        [[ "${verified}" == "1" ]] || warn "Режим обслуживания включён, но веб-сервер отвечает как обычно: проверьте, что nginx может читать ${dir} (права, SELinux: restorecon -R ${dir})."
    fi
    return 0
}

maintenance_off() {
    local dir
    dir=$(maintenance_dir)
    rm -f "${dir}/enabled" "${dir}/owner"
    if [[ -f "${dir}/allow.conf" ]]; then
        rm -f "${dir}/allow.conf"
        maintenance_reload_web || true
    fi
    return 0
}

# Сведения о режиме для docportal maintenance status и doctor.sh (печатает строки, код 0 — режим включён).
maintenance_describe() {
    local dir since msg ips
    dir=$(maintenance_dir)
    if ! maintenance_is_on; then
        printf 'Режим обслуживания выключен.\n'
        return 1
    fi
    since=$(sed -n 1p "${dir}/enabled" 2>/dev/null || true)
    msg=$(sed -n '2,$p' "${dir}/enabled" 2>/dev/null | tr '\n' ' ' | sed 's/ *$//' || true)
    printf 'Режим обслуживания ВКЛЮЧЁН с %s%s.\n' "${since:-?}" "${msg:+ («${msg}»)}"
    if [[ -f "${dir}/allow.conf" ]]; then
        ips=$( (grep -o 'remote_addr = "[^"]*"' "${dir}/allow.conf" || true) | cut -d'"' -f2 | paste -sd, - | sed 's/,/, /g')
        [[ -n "${ips}" ]] && printf 'Портал доступен с адресов: %s.\n' "${ips}"
    fi
    return 0
}

# --- Копии во внешнем хранилище (1.7.0) ------------------------------------------------------------
# BACKUP_REMOTE (install.conf): «хранилище:путь» rclone — например, yandex:backups/docportal или
# s3:bucket/docportal (хранилище настраивается командой rclone config от root), либо каталог на сервере —
# например, смонтированный ресурс NFS/SMB (/mnt/nas/docportal). BACKUP_REMOTE_KEEP — сколько последних
# копий хранить там (0 — не удалять). Для каждого портала нужен свой каталог: старые копии удаляются по имени.
remote_is_dir() { [[ "${BACKUP_REMOTE:-}" == /* ]]; }

# Сколько ждать отправки одной копии (секунд): зависшая выгрузка или «мёртвый» сетевой диск не должны
# держать операции обслуживания.
REMOTE_TIMEOUT="${REMOTE_TIMEOUT:-21600}"

# Каталог внешних копий должен существовать и быть на другом диске, чем локальные копии: если сетевой
# ресурс не смонтирован, его точка монтирования — обычный каталог на том же диске, и «внешняя» копия
# молча легла бы рядом с локальной. Код 0 — годится; иначе причина — в REMOTE_RESULT.
remote_dir_check() {
    local dest=$1 local_dev dest_dev
    if [[ ! -d "${dest}" ]]; then
        REMOTE_RESULT="каталог ${dest} не существует — смонтируйте сетевой диск (или создайте каталог на другом диске)"
        return 1
    fi
    dest_dev=$(stat -c %d "${dest}" 2>/dev/null || true)
    local_dev=$(stat -c %d "${BACKUP_DIR:-/}" 2>/dev/null || stat -c %d / 2>/dev/null || true)
    if [[ -n "${dest_dev}" && "${dest_dev}" == "${local_dev}" ]]; then
        REMOTE_RESULT="каталог ${dest} на том же диске, что и локальные копии (${BACKUP_DIR}) — сетевой диск не смонтирован? Внешняя копия должна быть на другом диске"
        return 1
    fi
    return 0
}

# Отправка архива и его контрольной суммы, удаление лишних старых копий. Итог — в REMOTE_RESULT.
# remote_backup_push АРХИВ → код 0 — успех.
REMOTE_RESULT=""
remote_backup_push() {
    local archive=$1 name dest keep=${BACKUP_REMOTE_KEEP:-14} old removed=0 log=${LOG_FILE:-/dev/null}
    name=$(basename "${archive}")
    REMOTE_RESULT=""
    [[ -n "${BACKUP_REMOTE:-}" ]] || { REMOTE_RESULT="внешнее хранилище не настроено"; return 1; }
    [[ "${keep}" =~ ^[0-9]+$ ]] || keep=14
    dest="${BACKUP_REMOTE%/}"
    if remote_is_dir; then
        remote_dir_check "${dest}" || return 1
        # Сначала во временный файл: оборванная копия не должна выглядеть готовой.
        if ! timeout "${REMOTE_TIMEOUT}" cp "${archive}" "${dest}/.${name}.part" 2>>"${log}"; then
            rm -f "${dest}/.${name}.part"
            REMOTE_RESULT="не удалось записать ${dest}/${name} (свободное место, права, доступность сетевого диска)"
            return 1
        fi
        mv -f "${dest}/.${name}.part" "${dest}/${name}"
        if [[ -f "${archive}.sha256" ]]; then
            cp "${archive}.sha256" "${dest}/${name}.sha256" 2>>"${log}" || true
            if ! (cd "${dest}" && sha256sum -c --quiet "${name}.sha256") >>"${log}" 2>&1; then
                REMOTE_RESULT="копия ${dest}/${name} не совпадает с архивом по контрольной сумме"
                return 1
            fi
        fi
        if [[ ${keep} -gt 0 ]]; then
            while IFS= read -r old; do
                [[ -n "${old}" ]] || continue
                rm -f "${dest:?}/${old}" "${dest:?}/${old}.sha256" && removed=$((removed + 1))
            done < <( (cd "${dest}" && find . -maxdepth 1 -type f -name "${APP_ID}-backup-*.tar.gz" -printf '%f\n' 2>/dev/null || true) | sort -r | tail -n +$((keep + 1)))
        fi
    else
        if ! command -v rclone >/dev/null 2>&1; then REMOTE_RESULT="не установлен rclone"; return 1; fi
        if ! timeout "${REMOTE_TIMEOUT}" rclone copyto "${archive}" "${dest}/${name}" --retries 3 --low-level-retries 10 --contimeout 30s --timeout 5m >>"${log}" 2>&1 9>&-; then
            REMOTE_RESULT="rclone не смог отправить ${name} в ${dest} (подробности в журнале ${log})"
            return 1
        fi
        if [[ -f "${archive}.sha256" ]]; then
            timeout 600 rclone copyto "${archive}.sha256" "${dest}/${name}.sha256" --contimeout 30s --timeout 5m >>"${log}" 2>&1 9>&- || true
        fi
        if [[ ${keep} -gt 0 ]]; then
            while IFS= read -r old; do
                [[ -n "${old}" ]] || continue
                if timeout 600 rclone deletefile "${dest}/${old}" --contimeout 30s --timeout 5m >>"${log}" 2>&1 9>&-; then removed=$((removed + 1)); fi
                timeout 600 rclone deletefile "${dest}/${old}.sha256" --contimeout 30s --timeout 5m >>"${log}" 2>&1 9>&- || true
            done < <( (timeout 600 rclone lsf "${dest}" --files-only --include "${APP_ID}-backup-*.tar.gz" --contimeout 30s --timeout 5m 2>>"${log}" 9>&- || true) | sort -r | tail -n +$((keep + 1)))
        fi
    fi
    REMOTE_RESULT="${name} → ${dest}"
    [[ ${removed} -gt 0 ]] && REMOTE_RESULT+=" (удалено старых копий: ${removed})"
    return 0
}

# Проверка записи во внешнее хранилище: небольшой файл записывается, читается и удаляется.
remote_backup_test() {
    local dest probe log=${LOG_FILE:-/dev/null} got=""
    probe="${APP_ID}-write-test-$(date '+%Y%m%d%H%M%S')"
    REMOTE_RESULT=""
    [[ -n "${BACKUP_REMOTE:-}" ]] || { REMOTE_RESULT="внешнее хранилище не настроено"; return 1; }
    dest="${BACKUP_REMOTE%/}"
    if remote_is_dir; then
        remote_dir_check "${dest}" || return 1
        timeout 120 sh -c 'printf "ok\n" > "$1"' sh "${dest}/.${probe}" 2>>"${log}" && got=$(timeout 60 cat "${dest}/.${probe}" 2>/dev/null) || true
        timeout 60 rm -f "${dest}/.${probe}" || true
    else
        if ! command -v rclone >/dev/null 2>&1; then REMOTE_RESULT="не установлен rclone"; return 1; fi
        if printf 'ok\n' | timeout 300 rclone rcat "${dest}/.${probe}" --contimeout 30s --timeout 2m >>"${log}" 2>&1 9>&-; then
            got=$(timeout 300 rclone cat "${dest}/.${probe}" --contimeout 30s --timeout 2m 2>>"${log}" 9>&- || true)
            timeout 300 rclone deletefile "${dest}/.${probe}" --contimeout 30s --timeout 2m >>"${log}" 2>&1 9>&- || true
        fi
    fi
    if [[ "${got}" == "ok" ]]; then REMOTE_RESULT="запись в ${dest} работает"; return 0; fi
    REMOTE_RESULT="не удалось записать файл в ${dest} (подробности в журнале ${log})"
    return 1
}

# =============================================================================
#  Надёжность (1.8.0): пакеты без вопросов, загрузки с повторами, порты, память,
#  разбор причин ошибок. Используется установкой, обновлением и разделом «Видео».
# =============================================================================

# apt без вопросов: конфигурационные файлы остаются прежними, needrestart (Ubuntu) не открывает диалог,
# блокировку dpkg (её держит, например, unattended-upgrades) apt ждёт до 10 минут; зависшая загрузка
# прерывается по таймауту.
apt_get() {
    env DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a NEEDRESTART_SUSPEND=1 UCF_FORCE_CONFFOLD=1 APT_LISTCHANGES_FRONTEND=none \
        timeout "${APT_TIMEOUT:-3600}" apt-get \
        -o DPkg::Lock::Timeout=600 -o Acquire::Retries=3 -o Acquire::http::Timeout=60 -o Acquire::https::Timeout=60 \
        -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold "$@"
}

# Процесс менеджера пакетов, который сейчас работает (пусто — свободен). Постоянно запущенный в Ubuntu
# unattended-upgrade-shutdown (ждёт выключения сервера) пакеты не ставит и не учитывается.
apt_busy_process() {
    command -v pgrep >/dev/null 2>&1 || return 0
    pgrep -a -f '(^|/)(apt|apt-get|aptitude|dpkg)( |$)|/unattended-upgrade( |$)|apt\.systemd\.daily' 2>/dev/null \
        | grep -v -e 'unattended-upgrade-shutdown' -e 'pgrep' | head -n1 || true
}

# Ожидание, пока закончит работу другой менеджер пакетов (автообновление ОС после загрузки сервера,
# установка из другой консоли) — до APT_LOCK_WAIT секунд (900).
apt_wait_lock() {
    [[ "${PKG:-}" == "apt" ]] || return 0
    local max=${APT_LOCK_WAIT:-900} waited=0 who
    while who=$(apt_busy_process) && [[ -n "${who}" ]]; do
        if [[ ${waited} -eq 0 ]]; then
            info "Менеджер пакетов занят другим процессом (${who:0:90}) — ожидание до $((max / 60)) мин…"
        fi
        if [[ ${waited} -ge ${max} ]]; then
            warn "Менеджер пакетов занят дольше $((max / 60)) мин (${who:0:90}) — продолжаем; при ошибке повторите позже."
            return 1
        fi
        sleep 10; waited=$((waited + 10))
    done
    return 0
}

# Восстановление после типичных сбоев apt по выводу последней команды в журнале:
#   прерванная установка (dpkg was interrupted) → dpkg --configure -a;
#   повреждённые или устаревшие списки (Hash Sum mismatch, …not valid yet) → очистка списков и apt-get update;
#   неполные зависимости → apt-get -f install; пакет не найден → обновление списков.
apt_recover() {
    [[ "${PKG:-}" == "apt" ]] || return 0
    local tail
    tail=$(tail -n 80 "${LOG_FILE:-/dev/null}" 2>/dev/null || true)
    if grep_has -Ei 'dpkg was interrupted|dpkg --configure -a|E: Sub-process /usr/bin/dpkg returned' <<<"${tail}" || [[ -n "$(dpkg --audit 2>/dev/null | head -n1)" ]]; then
        info "Восстановление прерванной установки пакетов (dpkg --configure -a)…"
        apt_wait_lock
        env DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a timeout 1800 dpkg --configure -a --force-confdef --force-confold >>"${LOG_FILE}" 2>&1 9>&- || true
        apt_get install -f -y -q >>"${LOG_FILE}" 2>&1 9>&- || true
    fi
    if grep_has -Ei 'Hash Sum mismatch|File has unexpected size|Release file .* is not valid yet|is not valid yet|Mirror sync in progress|Clearsigned file isn.t valid|NO_PUBKEY' <<<"${tail}"; then
        info "Очистка списков пакетов и повторная загрузка…"
        apt_recover_lists
        _APT_UPDATED=0
    fi
    if grep_has -Ei 'Unmet dependencies|held broken packages|apt --fix-broken install|apt-get -f install' <<<"${tail}"; then
        info "Исправление зависимостей пакетов (apt-get -f install)…"
        apt_get install -f -y -q >>"${LOG_FILE}" 2>&1 9>&- || true
    fi
    if grep_has -Ei 'Unable to locate package|has no installation candidate|Unable to fetch some archives|404  Not Found' <<<"${tail}"; then
        _APT_UPDATED=0
    fi
    [[ "${_APT_UPDATED:-0}" == "1" ]] || apt_update_once
    return 0
}

# Перед началом работы: менеджер пакетов в порядке (прерванная установка завершается).
pkg_preflight() {
    case "${PKG:-}" in
        apt)
            if [[ -n "$(dpkg --audit 2>/dev/null | head -n1)" ]]; then
                warn_transient "Менеджер пакетов в незавершённом состоянии — восстановление (dpkg --configure -a)…"
                apt_wait_lock
                env DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a timeout 1800 dpkg --configure -a --force-confdef --force-confold >>"${LOG_FILE}" 2>&1 9>&- || true
                apt_get install -f -y -q >>"${LOG_FILE}" 2>&1 9>&- || true
                [[ -z "$(dpkg --audit 2>/dev/null | head -n1)" ]] && ok "Менеджер пакетов восстановлен" \
                    || warn "dpkg сообщает о незавершённых пакетах (dpkg --audit) — установка пакетов может завершиться ошибкой."
            fi
            ;;
    esac
    return 0
}

# Загрузка файла с повторами и таймаутами. download URL ФАЙЛ — код 0, если файл получен и не пуст.
download() {
    local url=$1 dest=$2 tmp
    tmp="${dest}.part.$$"
    if curl -fsSL --retry 5 --retry-delay 5 --retry-connrefused --connect-timeout 20 --max-time "${DOWNLOAD_MAX_TIME:-900}" \
            -o "${tmp}" "${url}" 2>>"${LOG_FILE:-/dev/null}" 9>&- && [[ -s "${tmp}" ]]; then
        mv -f "${tmp}" "${dest}"
        return 0
    fi
    rm -f "${tmp}"
    _log_raw "Не удалось скачать ${url}"
    return 1
}

# --- Источник обновлений: каталог на веб-сервере или релизы GitHub (docportal auto-update on --url | --github) ---
# Каталог на веб-сервере (UPDATE_URL): файл latest.txt с номером последней версии и рядом архивы
# docportal-<версия>.tar.gz и .sha256 — так их выкладывает сборка релиза (scripts/build-release.sh, CI).
# Релизы GitHub (UPDATE_GITHUB=владелец/репозиторий): открытый репозиторий — те же файлы через
# https://github.com/…/releases/latest/download/; закрытый — через API GitHub с токеном (файл
# ${CONF_DIR}/update-token, права 600; токену достаточно права чтения содержимого репозитория).
UPDATE_TOKEN_FILE="${CONF_DIR}/update-token"
REMOTE_VERSION=""; REMOTE_ARCHIVE_URL=""; REMOTE_SHA_URL=""; REMOTE_ACCEPT=""; REMOTE_ERROR=""

# Описание источника для сообщений.
update_source_label() {
    if [[ -n "${UPDATE_GITHUB:-}" ]]; then printf 'релизы GitHub %s%s' "${UPDATE_GITHUB}" "$([[ -s "${UPDATE_TOKEN_FILE}" ]] && echo ' (с токеном)')"
    elif [[ -n "${UPDATE_URL:-}" ]]; then printf '%s' "${UPDATE_URL}"
    else printf 'только каталог /var/lib/%s/updates' "${APP_ID}"; fi
}

# GET с заголовками (токен — через файл заголовков, не в аргументах процесса): update_http_get URL ФАЙЛ [ACCEPT]
update_http_get() {
    local url=$1 dest=$2 accept=${3:-} hdr rc=0
    hdr=$(umask 077; mktemp)
    [[ -n "${accept}" ]] && printf 'Accept: %s\n' "${accept}" >> "${hdr}"
    if [[ "${url}" == https://api.github.com/* && -s "${UPDATE_TOKEN_FILE}" ]]; then
        printf 'Authorization: Bearer %s\n' "$(tr -d ' \r\n' < "${UPDATE_TOKEN_FILE}")" >> "${hdr}"
        printf 'X-GitHub-Api-Version: 2022-11-28\n' >> "${hdr}"
    fi
    curl -fsSL --retry 5 --retry-delay 5 --retry-connrefused --connect-timeout 20 --max-time "${DOWNLOAD_MAX_TIME:-1800}" \
        -H "@${hdr}" -o "${dest}" "${url}" 2>>"${LOG_FILE:-/dev/null}" 9>&- || rc=$?
    rm -f "${hdr}"
    return ${rc}
}

# Значение из JSON (php или python3): json_value ФАЙЛ ВЫРАЖЕНИЕ — выражение: tag_name | asset:ИМЯ (url вложения)
json_value() {
    local file=$1 expr=$2
    if command -v php >/dev/null 2>&1; then
        php -r '$d = json_decode((string) file_get_contents($argv[1]), true); $e = $argv[2];
            if (!is_array($d)) { exit(1); }
            if (str_starts_with($e, "asset:")) { foreach (($d["assets"] ?? []) as $a) { if (($a["name"] ?? "") === substr($e, 6)) { echo $a["url"] ?? ""; exit(0); } } exit(1); }
            echo is_scalar($d[$e] ?? null) ? $d[$e] : ""; ' "${file}" "${expr}"
    elif command -v python3 >/dev/null 2>&1; then
        python3 -c 'import json, sys
d = json.load(open(sys.argv[1], encoding="utf-8")); e = sys.argv[2]
if e.startswith("asset:"):
    m = [a.get("url", "") for a in d.get("assets", []) if a.get("name") == e[6:]]
    sys.exit(0 if m and print(m[0]) is None else 1)
v = d.get(e); print(v if isinstance(v, (str, int, float)) else "")' "${file}" "${expr}"
    else
        return 2
    fi
}

# Последняя версия на источнике: заполняет REMOTE_VERSION, REMOTE_ARCHIVE_URL, REMOTE_SHA_URL, REMOTE_ACCEPT
# (или REMOTE_ERROR и код 1). Источник не задан — код 2.
remote_latest() {
    REMOTE_VERSION=""; REMOTE_ARCHIVE_URL=""; REMOTE_SHA_URL=""; REMOTE_ACCEPT=""; REMOTE_ERROR=""
    local tmp base v
    tmp=$(mktemp)
    if [[ -n "${UPDATE_GITHUB:-}" && -s "${UPDATE_TOKEN_FILE}" ]]; then
        # Закрытый репозиторий: описание последнего релиза и вложения — через API.
        if ! update_http_get "https://api.github.com/repos/${UPDATE_GITHUB}/releases/latest" "${tmp}" "application/vnd.github+json"; then
            REMOTE_ERROR="API GitHub не ответил на запрос последнего релиза ${UPDATE_GITHUB} (нет доступа к api.github.com, неверный токен или в репозитории нет релизов)"
            rm -f "${tmp}"; return 1
        fi
        v=$(json_value "${tmp}" tag_name) || { [[ $? -eq 2 ]] && REMOTE_ERROR="для чтения ответа API GitHub нужен php или python3" || REMOTE_ERROR="непонятный ответ API GitHub"; rm -f "${tmp}"; return 1; }
        v=${v#v}
        REMOTE_ARCHIVE_URL=$(json_value "${tmp}" "asset:${APP_ID}-${v}.tar.gz" || true)
        REMOTE_SHA_URL=$(json_value "${tmp}" "asset:${APP_ID}-${v}.tar.gz.sha256" || true)
        REMOTE_ACCEPT="application/octet-stream"
        rm -f "${tmp}"
        [[ -n "${REMOTE_ARCHIVE_URL}" ]] || { REMOTE_ERROR="в последнем релизе ${UPDATE_GITHUB} (${v}) нет архива ${APP_ID}-${v}.tar.gz"; return 1; }
    else
        if [[ -n "${UPDATE_GITHUB:-}" ]]; then base="https://github.com/${UPDATE_GITHUB}/releases/latest/download"
        elif [[ -n "${UPDATE_URL:-}" ]]; then base="${UPDATE_URL%/}"
        else rm -f "${tmp}"; return 2; fi
        if ! update_http_get "${base}/latest.txt" "${tmp}"; then
            REMOTE_ERROR="не удалось получить ${base}/latest.txt (нет доступа к источнику обновлений$([[ -n "${UPDATE_GITHUB:-}" ]] && echo ', репозиторий закрыт — нужен токен (--token-file), или в релизе нет latest.txt'))"
            rm -f "${tmp}"; return 1
        fi
        v=$(head -n1 "${tmp}" | tr -d ' \r\nv')
        rm -f "${tmp}"
        REMOTE_ARCHIVE_URL="${base}/${APP_ID}-${v}.tar.gz"
        REMOTE_SHA_URL="${REMOTE_ARCHIVE_URL}.sha256"
    fi
    [[ "${v}" =~ ^[0-9]+(\.[0-9]+)*$ ]] || { REMOTE_ERROR="источник сообщил непонятный номер версии «${v:0:40}»"; return 1; }
    REMOTE_VERSION="${v}"
    return 0
}

# Скачать новую версию с источника в каталог обновлений (если она новее установленной и обновление на неё не
# проваливалось): remote_fetch_update КАТАЛОГ УСТАНОВЛЕННАЯ_ВЕРСИЯ [auto]. Код 0 — архив на месте или скачивать
# нечего; 1 — ошибка (описание в REMOTE_ERROR); 2 — источник не задан.
remote_fetch_update() {
    local dir=$1 installed=$2 mode=${3:-} rc=0
    remote_latest || rc=$?
    [[ ${rc} -eq 0 ]] || return ${rc}
    if ! version_gt "${REMOTE_VERSION}" "${installed:-0}"; then
        info "Источник обновлений ($(update_source_label)): последняя версия ${REMOTE_VERSION} — новее установленной ${installed} нет." >&2
        return 0
    fi
    if [[ "${mode}" == "auto" && -e "${dir}/.failed-${REMOTE_VERSION}" ]]; then
        info "Версия ${REMOTE_VERSION} не скачивается: обновление на неё уже не удалось (${dir}/.failed-${REMOTE_VERSION})." >&2
        return 0
    fi
    local dest="${dir}/${APP_ID}-${REMOTE_VERSION}.tar.gz" free
    mkdir -p "${dir}" && chmod 700 "${dir}" 2>/dev/null || true
    if [[ -s "${dest}" && -s "${dest}.sha256" ]] && (cd "${dir}" && sha256sum -c --status "$(basename "${dest}").sha256" 2>/dev/null); then
        info "Архив версии ${REMOTE_VERSION} уже скачан: ${dest}" >&2
        return 0
    fi
    free=$(free_space_mb "${dir}" 2>/dev/null || true)
    if [[ "${free}" =~ ^[0-9]+$ && ${free} -lt 1024 ]]; then
        REMOTE_ERROR="мало места в ${dir} для архива новой версии (свободно ${free} МБ)"; return 1
    fi
    info "Скачивается версия ${REMOTE_VERSION} ($(update_source_label))…" >&2
    rm -f "${dest}.sha256"
    if [[ -n "${REMOTE_SHA_URL}" ]] && update_http_get "${REMOTE_SHA_URL}" "${dest}.sha256.part" "${REMOTE_ACCEPT}"; then
        # В файле контрольной суммы — имя архива, каким его выложили; проверяем по нашему имени.
        printf '%s  %s\n' "$(awk 'NR==1 {print $1}' "${dest}.sha256.part")" "$(basename "${dest}")" > "${dest}.sha256"
        rm -f "${dest}.sha256.part"
    else
        rm -f "${dest}.sha256.part"
        warn "У версии ${REMOTE_VERSION} на источнике нет файла контрольной суммы (.sha256) — архив проверяется только распаковкой."
    fi
    if ! update_http_get "${REMOTE_ARCHIVE_URL}" "${dest}.part" "${REMOTE_ACCEPT}"; then
        rm -f "${dest}.part" "${dest}.sha256"
        REMOTE_ERROR="не удалось скачать архив версии ${REMOTE_VERSION} (${REMOTE_ARCHIVE_URL%%\?*})"; return 1
    fi
    if [[ -s "${dest}.sha256" ]] && [[ "$(sha256sum "${dest}.part" | awk '{print $1}')" != "$(awk 'NR==1 {print $1}' "${dest}.sha256")" ]]; then
        rm -f "${dest}.part" "${dest}.sha256"
        REMOTE_ERROR="скачанный архив версии ${REMOTE_VERSION} не совпал с контрольной суммой (загрузка повреждена) — повтор в следующий раз"; return 1
    fi
    chmod 600 "${dest}.part" && mv -f "${dest}.part" "${dest}"
    ok "Скачана версия ${REMOTE_VERSION}: ${dest} ($(human_size "$(stat -c %s "${dest}")"))" >&2
    return 0
}

# Доступен ли узел по TCP (без HTTP-запроса): net_reachable ХОСТ [ПОРТ=443]
net_reachable() {
    local host=$1 port=${2:-443}
    timeout 8 bash -c "exec 3<>/dev/tcp/${host}/${port}" 2>/dev/null
}

# Проверка доступа к узлам, нужным установке: check_hosts "описание|хост|порт" … — печатает недоступные
# (если задан прокси http(s)_proxy, проверка прямого доступа не показательна и пропускается).
UNREACHABLE_HOSTS=()
check_hosts() {
    UNREACHABLE_HOSTS=()
    if [[ -n "${https_proxy:-}${HTTPS_PROXY:-}${http_proxy:-}${HTTP_PROXY:-}" ]]; then
        info "Задан прокси (https_proxy/http_proxy) — прямой доступ к узлам не проверяется."
        return 0
    fi
    local item desc host port
    for item in "$@"; do
        IFS='|' read -r desc host port <<<"${item}"
        if net_reachable "${host}" "${port:-443}"; then
            debug "доступен ${host}:${port:-443} (${desc})"
        else
            UNREACHABLE_HOSTS+=("${desc} — ${host}")
        fi
    done
    [[ ${#UNREACHABLE_HOSTS[@]} -eq 0 ]]
}

# Свободен ли порт: port_busy ПОРТ [tcp|udp] — код 0, если порт уже кем-то прослушивается.
port_busy() {
    local port=$1 proto=${2:-tcp} flags=-ltn
    [[ "${proto}" == "udp" ]] && flags=-lun
    if command -v ss >/dev/null 2>&1; then
        ss "${flags}" "sport = :${port}" 2>/dev/null | awk 'NR > 1' | grep_has .
        return
    fi
    # Без ss (минимальная система): TCP — пробное подключение к локальному адресу; UDP проверить нечем.
    [[ "${proto}" == "tcp" ]] || return 1
    timeout 3 bash -c "exec 3<>/dev/tcp/127.0.0.1/${port}" 2>/dev/null
}

# Первый свободный TCP-порт, начиная с заданного: free_port 3100 → 3100 или 3101…
free_port() {
    local p=$1 last=$(( $1 + 100 ))
    while (( p <= last )); do
        port_busy "${p}" tcp || { printf '%s' "${p}"; return 0; }
        p=$((p + 1))
    done
    return 1
}

mem_total_mb() { awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 0; }
swap_total_mb() { awk '/SwapTotal/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 0; }

# Файл подкачки на серверах с малой памятью: без него сборка интерфейса, Composer, MariaDB и обработка
# видео завершаются ошибкой «Killed» (OOM). ensure_swap [нужно_памяти_с_подкачкой_МБ=2048] [размер_МБ=2048]
# Отключить: NO_SWAP=1 (параметр --no-swap). Ошибка создания — только предупреждение.
ensure_swap() {
    local need=${1:-2048} size=${2:-2048} mem swap file="/${APP_ID}.swap"
    [[ "${NO_SWAP:-0}" == "1" ]] && return 0
    mem=$(mem_total_mb); swap=$(swap_total_mb)
    (( mem > 0 && mem + swap < need )) || return 0
    if [[ -e "${file}" ]]; then
        swapon --show=NAME 2>/dev/null | grep_has -x "${file}" || swapon "${file}" >>"${LOG_FILE}" 2>&1 || true
        return 0
    fi
    local free
    free=$(free_space_mb / 2>/dev/null || echo 0)
    if (( free < size + 2048 )); then
        warn "Памяти ${mem} МБ, подкачки ${swap} МБ, а места для файла подкачки мало (${free} МБ) — при нехватке памяти сборка может прерваться."
        return 0
    fi
    info "Памяти ${mem} МБ, подкачки ${swap} МБ — создаётся файл подкачки ${file} (${size} МБ)…"
    if { fallocate -l "${size}M" "${file}" 2>>"${LOG_FILE}" || dd if=/dev/zero of="${file}" bs=1M count="${size}" status=none 2>>"${LOG_FILE}"; } \
        && chmod 600 "${file}" && mkswap "${file}" >>"${LOG_FILE}" 2>&1 && swapon "${file}" >>"${LOG_FILE}" 2>&1; then
        grep_has -E "^${file}[[:space:]]" /etc/fstab || printf '%s none swap sw 0 0\n' "${file}" >> /etc/fstab
        ok "Подключён файл подкачки ${file} (${size} МБ; запись в /etc/fstab)"
    else
        swapoff "${file}" >/dev/null 2>&1 || true
        rm -f "${file}"
        warn "Не удалось создать файл подкачки (в контейнере или на этой файловой системе это бывает невозможно) — при нехватке памяти сборка может прерваться."
    fi
}

# Проверка поддерживаемой версии ОС (после detect_os): слишком старые — остановка, незнакомые — предупреждение.
check_os_supported() {
    local major=${OS_VERSION%%.*}
    case "${OS_ID}" in
        debian)
            [[ "${major}" =~ ^[0-9]+$ && ${major} -lt 11 ]] && die "Debian ${OS_VERSION} слишком старый: нужен Debian 11 или новее."
            [[ "${major}" =~ ^[0-9]+$ && ${major} -gt 13 ]] && warn "Debian ${OS_VERSION} ещё не проверялся — установка продолжается."
            ;;
        ubuntu)
            [[ "${major}" =~ ^[0-9]+$ && ${major} -lt 20 ]] && die "Ubuntu ${OS_VERSION} слишком старая: нужна Ubuntu 22.04 или новее."
            [[ "${major}" =~ ^[0-9]+$ && ${major} -lt 22 ]] && warn "Ubuntu ${OS_VERSION} устарела (поддерживаются 22.04 и новее) — установка продолжается без гарантий."
            ;;
    esac
    case "$(uname -m)" in
        x86_64|amd64|aarch64|arm64) ;;
        *) warn "Архитектура $(uname -m) не проверялась — возможны ошибки установки пакетов." ;;
    esac
    return 0
}

# Часы сервера: при сильно неверной дате не проходят проверки сертификатов (HTTPS, репозитории).
check_clock() {
    local year
    year=$(date +%Y)
    if [[ ${year} -lt 2025 ]]; then
        warn "Дата на сервере — $(date '+%d.%m.%Y'): при неверных часах не проверяются сертификаты HTTPS. Настройте время (timedatectl set-ntp true)."
        return 1
    fi
    if command -v timedatectl >/dev/null 2>&1 && timedatectl show -p NTPSynchronized --value 2>/dev/null | grep_has -x no; then
        debug "Время не синхронизировано с NTP (timedatectl)."
    fi
    return 0
}

# Подсказки по типичным причинам ошибки — по выводу текущего шага в журнале (с начала шага).
explain_failure() {
    [[ -n "${LOG_FILE}" && "${LOG_FILE}" != "/dev/null" && -r "${LOG_FILE}" ]] || return 0
    local text hints=() from=$(( ${_STEP_LOG_LINE:-0} + 1 ))
    text=$(tail -n "+${from}" "${LOG_FILE}" 2>/dev/null | tail -n 400 || true)
    [[ -n "${text}" ]] || return 0
    _hint() { if grep_has -Ei "$1" <<<"${text}"; then hints+=("$2"); fi; }
    _hint 'Failed to fetch|Invalid response from proxy|Some index files failed to download|Could not connect to|Unable to connect to' \
        'Нет доступа к репозиториям пакетов (адреса — в строках «Failed to fetch» журнала): проверьте интернет, прокси (/etc/apt/apt.conf.d/, http_proxy) и брандмауэр; во внутренней сети укажите локальное зеркало в /etc/apt/sources.list.'
    _hint 'No space left on device|Not enough free space|You don.t have enough free space' \
        'Закончилось место на диске (df -h). Освободите место — старые резервные копии, journalctl --vacuum-size=200M, apt-get clean, docker system prune — и повторите.'
    _hint 'Could not resolve|Temporary failure (in name )?resolution|Name or service not known|getaddrinfo|ENOTFOUND|EAI_AGAIN' \
        'Сервер не может разрешить имена (DNS): проверьте /etc/resolv.conf и доступ в интернет; в сети с прокси задайте http_proxy/https_proxy.'
    _hint 'Connection timed out|Operation timed out|Failed to connect|Connection refused|Network is unreachable|No route to host|ETIMEDOUT|ECONNRESET|ECONNREFUSED|socket hang up' \
        'Нет связи с внешним узлом (таймаут или отказ соединения): проверьте брандмауэр и прокси; повторный запуск продолжит с того же места, ранее загруженное не скачивается заново.'
    _hint 'Could not get lock|Unable to acquire the dpkg frontend lock|is another process using it' \
        'Менеджер пакетов занят другим процессом (часто — автообновление ОС после загрузки). Подождите несколько минут и повторите.'
    _hint 'dpkg was interrupted' \
        'Предыдущая установка пакетов прервалась: выполните dpkg --configure -a и повторите.'
    _hint 'Hash Sum mismatch|is not valid yet|Release file .* is expired' \
        'Списки пакетов повреждены или часы сервера неверны: проверьте дату (date), выполните rm -rf /var/lib/apt/lists/* && apt-get update.'
    _hint 'certificate verify failed|SSL certificate problem|unable to get local issuer certificate|self.signed certificate|CERT_' \
        'Ошибка проверки сертификата HTTPS: проверьте дату и время сервера; если трафик проходит через прокси с подменой сертификатов — добавьте его корневой сертификат (update-ca-certificates).'
    _hint 'toomanyrequests|You have reached your pull rate limit' \
        'Docker Hub ограничил число загрузок: подождите или укажите зеркало — install.sh --docker-mirror https://mirror.gcr.io.'
    _hint 'Cannot connect to the Docker daemon|Is the docker daemon running' \
        'Служба Docker не запущена: systemctl start docker; журнал — journalctl -u docker.'
    _hint 'address already in use|bind\(\) to .* failed|port is already allocated|EADDRINUSE' \
        'Порт уже занят другой программой: ss -ltnp | grep ПОРТ — освободите его или укажите другой (--port).'
    _hint 'Killed|Out of memory|out of memory|ENOMEM|JavaScript heap out of memory|Cannot allocate memory' \
        'Не хватило оперативной памяти: добавьте памяти или файл подкачки (скрипт создаёт его сам, если не задан --no-swap) и повторите.'
    _hint 'Access denied for user' \
        'База данных отклонила пароль: проверьте параметры --db-* (или DB_* в /etc/docportal/install.conf).'
    _hint 'Permission denied' \
        'Отказано в доступе: запускайте от root (sudo), проверьте права на каталоги установки и режим SELinux/AppArmor.'
    _hint 'npm ERR!|npm error' \
        'npm не смог загрузить пакеты: нужен доступ к registry.npmjs.org (или ставьте из архива дистрибутива — в нём зависимости уже собраны).'
    _hint 'Unable to locate package|has no installation candidate' \
        'В подключённых репозиториях ОС нет нужного пакета: проверьте /etc/apt/sources.list (включены ли main/universe) и выполните apt-get update.'
    _hint 'nginx: \[emerg\]' \
        'nginx не принимает конфигурацию: nginx -t покажет файл и строку.'
    unset -f _hint
    [[ ${#hints[@]} -gt 0 ]] || return 0
    printf '%sВозможная причина и что сделать:%s\n' "${C_BOLD}" "${C_RESET}" >&2
    local h
    for h in "${hints[@]}"; do
        printf '  • %s\n' "${h}" >&2
        _log_raw "[HINT ] ${h}"
    done
    return 0
}

# Последние строки журнала текущего шага (для сообщения об ошибке на экране).
show_step_log_tail() {
    [[ -n "${LOG_FILE}" && "${LOG_FILE}" != "/dev/null" && -r "${LOG_FILE}" ]] || return 0
    local from=$(( ${_STEP_LOG_LINE:-0} + 1 )) lines
    lines=$(tail -n "+${from}" "${LOG_FILE}" 2>/dev/null | grep -v -e '^\[\(INFO\|STEP\| OK  \|DEBUG\|RETRY\|ERROR\|HINT \|WARN \)\]' -e '^=====' | tail -n "${1:-12}" || true)
    [[ -n "${lines}" ]] || return 0
    printf '%sПоследние строки журнала:%s\n' "${C_DIM}" "${C_RESET}" >&2
    printf '%s\n' "${lines}" | cut -c1-220 | sed 's/^/   | /' >&2
}

# Итоговая сводка предупреждений (в конце установки и обновления).
print_warnings_summary() {
    [[ ${#WARNINGS[@]} -gt 0 ]] || return 0
    local w seen="" unique=()
    for w in "${WARNINGS[@]}"; do
        # Одинаковые предупреждения — один раз.
        [[ "${seen}" == *"|${w}|"* ]] && continue
        seen+="|${w}|"
        unique+=("${w}")
    done
    printf '\n%sТребует внимания (%d):%s\n' "${C_YELLOW}${C_BOLD}" "${#unique[@]}" "${C_RESET}"
    for w in "${unique[@]}"; do printf '  • %s\n' "${w}"; done
}

# Состояние служб и последние ошибки — когда портал не ответил (на экран и в журнал).
collect_diagnostics() {
    # Диагностика не должна сама прерывать скрипт (set -e) — параметры оболочки здесь локальные.
    local -
    set +e
    local out=""
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        out+=$'Контейнеры:\n'"$(cd "${APP_DIR}/docker" 2>/dev/null && ${COMPOSE_CMD:-docker compose} ps 2>&1 | head -n 15)"$'\n'
        out+=$'Журнал app (последние строки):\n'"$(cd "${APP_DIR}/docker" 2>/dev/null && ${COMPOSE_CMD:-docker compose} logs --tail=15 app 2>&1)"$'\n'
        out+=$'Журнал web (последние строки):\n'"$(cd "${APP_DIR}/docker" 2>/dev/null && ${COMPOSE_CMD:-docker compose} logs --tail=8 web 2>&1)"
    else
        out+="nginx -t: $(nginx -t 2>&1 | tail -n 2 | tr '\n' ' ')"$'\n'
        out+="Службы: nginx — $(svc status nginx && echo работает || echo 'не работает'); ${PHP_FPM_SERVICE:-php-fpm} — $(svc status "${PHP_FPM_SERVICE:-php-fpm}" && echo работает || echo 'не работает'); ${DB_SERVICE:-mariadb} — $(svc status "${DB_SERVICE:-mariadb}" && echo работает || echo 'не работает/внешняя')"$'\n'
        out+=$'Ошибки nginx:\n'"$(tail -n 8 "/var/log/nginx/${APP_ID}.error.log" 2>/dev/null)"$'\n'
        out+=$'Журнал приложения:\n'"$(tail -n 12 "$(ls -t "${APP_DIR}"/shared/log/prod*.log 2>/dev/null | head -n1)" 2>/dev/null | cut -c1-300)"
    fi
    _log_raw "----- Диагностика -----"
    _log_raw "${out}"
    printf '%sДиагностика:%s\n' "${C_BOLD}" "${C_RESET}" >&2
    printf '%s\n' "${out}" | sed 's/^/   | /' | head -n 60 >&2
}
