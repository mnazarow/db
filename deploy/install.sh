#!/usr/bin/env bash
# =============================================================================
#  Установка портала документации на чистый сервер Linux одной командой.
#
#  Примеры:
#    sudo ./deploy/install.sh                                # локальная установка (nginx + PHP-FPM + MariaDB)
#    sudo ./deploy/install.sh --domain docs.example.ru --ssl-email admin@example.ru
#    sudo ./deploy/install.sh --domain docs.example.ru --ldap-host dc1.example.local --ldap-base-dn "DC=example,DC=local" --ldap-upn-suffix example.local
#    sudo ./deploy/install.sh --mode docker --port 8080      # установка в Docker
#    sudo ./deploy/install.sh --archive docportal-1.2.0.tar.gz --yes
#
#  Полный список параметров: ./deploy/install.sh --help
# =============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/common.sh
. "${SCRIPT_DIR}/common.sh"

usage() {
    cat <<EOF
Установка портала «${APP_TITLE}».

Использование: sudo $0 [параметры]

Режим:
  --mode native|docker      native — nginx + PHP-FPM + MariaDB на этом сервере (по умолчанию);
                            docker — контейнеры Docker Compose (nginx, php-fpm, MySQL).
  --source DIR              Каталог с распакованным проектом (по умолчанию — каталог, где лежит скрипт).
  --archive FILE            Архив проекта .tar.gz/.zip (будет распакован во временный каталог).

Размещение:
  --dir DIR                 Каталог установки (по умолчанию ${DEFAULT_APP_DIR}).
  --domain NAME             Доменное имя портала (server_name в nginx). По умолчанию — любой хост.
  --port N                  HTTP-порт (по умолчанию ${DEFAULT_HTTP_PORT}).
  --ssl-email EMAIL         Получить сертификат Let's Encrypt (certbot) для --domain и включить HTTPS.
  --timezone TZ             Часовой пояс приложения (по умолчанию Europe/Moscow).
  --app-name "Название"     Название портала в шапке (по умолчанию «Портал документации»).
  --company "Компания"      Название компании в шапке и подвале (по умолчанию «ВОДОКОМФОРТ»).

Администратор:
  --admin-user LOGIN        Логин первого администратора (по умолчанию admin).
  --admin-password PASS     Пароль администратора (по умолчанию генерируется и выводится в конце).

База данных (режим native):
  --db-host HOST            Внешний сервер MySQL/MariaDB (по умолчанию устанавливается локальный MariaDB).
  --db-port N               Порт (по умолчанию 3306).
  --db-name NAME            Имя базы (по умолчанию ${DEFAULT_DB_NAME}).
  --db-user USER            Пользователь БД (по умолчанию ${DEFAULT_DB_USER}).
  --db-password PASS        Пароль пользователя БД (по умолчанию генерируется).
                            Для внешнего сервера база и пользователь должны быть созданы заранее.

Active Directory / LDAP (вход по доменным учётным записям; без --ldap-host — только локальные учётные записи):
  --ldap-host HOST          Контроллер домена (включает LDAP_ENABLED=1).
  --ldap-port N             Порт (по умолчанию 389; для LDAPS — 636 и --ldap-encryption ssl).
  --ldap-encryption MODE    none | ssl | tls (по умолчанию none).
  --ldap-base-dn DN         Корень поиска пользователей, например "DC=example,DC=local".
  --ldap-bind-dn DN         Служебная учётная запись для поиска (DN или user@domain). Если не задана —
                            портал подключается к домену от имени входящего пользователя (см. --ldap-upn-suffix).
  --ldap-bind-password PASS Пароль служебной учётной записи.
  --ldap-upn-suffix DOMAIN  Суффикс UPN для входа без служебной учётной записи (например, example.local).
  --ldap-admin-group DN     Группа AD, членам которой выдаются права администратора портала.
  --ldap-user-group DN      Группа AD, обязательная для входа (пусто — все пользователи домена).

Почта (уведомления о сроках актуальности):
  --mail-dsn DSN            Транспорт Symfony Mailer, например smtp://user:pass@mail.example.ru:587 (по умолчанию письма не отправляются).
  --mail-from "Адрес"       Отправитель, например "Портал документации <noreply@example.ru>".

Docker:
  --docker-mirror URL       Зеркало Docker Hub (например, https://mirror.gcr.io) — записывается в /etc/docker/daemon.json.

Прочее:
  --backup-dir DIR          Каталог резервных копий (по умолчанию ${DEFAULT_BACKUP_DIR}).
  --no-backup-cron          Не добавлять ежедневное резервное копирование в cron.
  --yes, -y                 Не задавать вопросов (неинтерактивная установка).
  --verbose                 Подробный вывод.
  --help, -h                Эта справка.
EOF
}

# --- Параметры по умолчанию ------------------------------------------------------
INSTALL_MODE="native"
SOURCE_DIR=""
ARCHIVE=""
APP_DIR="${DEFAULT_APP_DIR}"
DOMAIN=""
HTTP_PORT="${DEFAULT_HTTP_PORT}"
SSL_EMAIL=""
SSL_ENABLED="0"
TIMEZONE="Europe/Moscow"
APP_NAME="Портал документации"
APP_COMPANY="ВОДОКОМФОРТ"
ADMIN_USER="admin"
LDAP_HOST=""
LDAP_PORT="389"
LDAP_ENCRYPTION="none"
LDAP_BASE_DN=""
LDAP_BIND_DN=""
LDAP_BIND_PASSWORD=""
LDAP_UPN_SUFFIX=""
LDAP_ADMIN_GROUP=""
LDAP_USER_GROUP=""
MAIL_DSN=""
MAIL_FROM=""
ADMIN_PASSWORD=""
DB_HOST="127.0.0.1"
DB_PORT="3306"
DB_NAME="${DEFAULT_DB_NAME}"
DB_USER="${DEFAULT_DB_USER}"
DB_PASSWORD=""
DB_LOCAL="1"
DB_SERVICE=""
DB_SERVER_VERSION="8.0"
DOCKER_MIRROR=""
BACKUP_DIR="${DEFAULT_BACKUP_DIR}"
BACKUP_CRON="1"
ASSUME_YES="0"
VERBOSE="0"
SERVICE_USER=""
PHP_VERSION=""
PHP_FPM_SERVICE=""
PHP_FPM_SOCK=""
APP_VERSION=""
INSTALLED_AT=""

# Что было создано в ходе установки (для отката при ошибке).
CREATED_APP_DIR="0"; CREATED_DB="0"; CREATED_DB_USER="0"; CREATED_NGINX_SITE="0"; CREATED_CONF="0"; CREATED_TMP=""; DOCKER_STARTED="0"; ADDED_PHP_REPO="0"

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --mode) INSTALL_MODE="${2:-}"; shift 2 ;;
            --source) SOURCE_DIR="${2:-}"; shift 2 ;;
            --archive) ARCHIVE="${2:-}"; shift 2 ;;
            --dir) APP_DIR="${2:-}"; shift 2 ;;
            --domain) DOMAIN="${2:-}"; shift 2 ;;
            --port) HTTP_PORT="${2:-}"; shift 2 ;;
            --ssl-email) SSL_EMAIL="${2:-}"; shift 2 ;;
            --timezone) TIMEZONE="${2:-}"; shift 2 ;;
            --app-name) APP_NAME="${2:-}"; shift 2 ;;
            --company) APP_COMPANY="${2:-}"; shift 2 ;;
            --admin-user) ADMIN_USER="${2:-}"; shift 2 ;;
            --admin-password) ADMIN_PASSWORD="${2:-}"; shift 2 ;;
            --db-host) DB_HOST="${2:-}"; shift 2 ;;
            --db-port) DB_PORT="${2:-}"; shift 2 ;;
            --db-name) DB_NAME="${2:-}"; shift 2 ;;
            --db-user) DB_USER="${2:-}"; shift 2 ;;
            --db-password) DB_PASSWORD="${2:-}"; shift 2 ;;
            --ldap-host) LDAP_HOST="${2:-}"; shift 2 ;;
            --ldap-port) LDAP_PORT="${2:-}"; shift 2 ;;
            --ldap-encryption) LDAP_ENCRYPTION="${2:-}"; shift 2 ;;
            --ldap-base-dn) LDAP_BASE_DN="${2:-}"; shift 2 ;;
            --ldap-bind-dn) LDAP_BIND_DN="${2:-}"; shift 2 ;;
            --ldap-bind-password) LDAP_BIND_PASSWORD="${2:-}"; shift 2 ;;
            --ldap-upn-suffix) LDAP_UPN_SUFFIX="${2:-}"; shift 2 ;;
            --ldap-admin-group) LDAP_ADMIN_GROUP="${2:-}"; shift 2 ;;
            --ldap-user-group) LDAP_USER_GROUP="${2:-}"; shift 2 ;;
            --mail-dsn) MAIL_DSN="${2:-}"; shift 2 ;;
            --mail-from) MAIL_FROM="${2:-}"; shift 2 ;;
            --docker-mirror) DOCKER_MIRROR="${2:-}"; shift 2 ;;
            --backup-dir) BACKUP_DIR="${2:-}"; shift 2 ;;
            --no-backup-cron) BACKUP_CRON="0"; shift ;;
            --yes|-y) ASSUME_YES="1"; shift ;;
            --verbose) VERBOSE="1"; shift ;;
            --help|-h) usage; exit 0 ;;
            *) usage; die "Неизвестный параметр: $1" ;;
        esac
    done
}

validate_args() {
    [[ "${INSTALL_MODE}" == "native" || "${INSTALL_MODE}" == "docker" ]] || die "--mode должен быть native или docker."
    [[ "${HTTP_PORT}" =~ ^[0-9]+$ && ${HTTP_PORT} -ge 1 && ${HTTP_PORT} -le 65535 ]] || die "--port: недопустимый номер порта «${HTTP_PORT}»."
    [[ "${DB_PORT}" =~ ^[0-9]+$ ]] || die "--db-port: недопустимый номер порта."
    [[ "${ADMIN_USER}" =~ ^[a-zA-Z0-9._@-]{3,64}$ ]] || die "--admin-user: логин может содержать латинские буквы, цифры и символы . _ - @ (3–64 символа)."
    [[ "${DB_NAME}" =~ ^[a-zA-Z0-9_]{1,64}$ ]] || die "--db-name: допустимы латинские буквы, цифры и подчёркивание."
    [[ "${DB_USER}" =~ ^[a-zA-Z0-9_]{1,32}$ ]] || die "--db-user: допустимы латинские буквы, цифры и подчёркивание (до 32 символов)."
    if [[ -n "${ADMIN_PASSWORD}" && ${#ADMIN_PASSWORD} -lt 8 ]]; then die "--admin-password: не менее 8 символов."; fi
    if [[ -n "${SSL_EMAIL}" && -z "${DOMAIN}" ]]; then die "--ssl-email требует --domain."; fi
    if [[ -n "${DOMAIN}" && ! "${DOMAIN}" =~ ^[a-zA-Z0-9.-]+$ ]]; then die "--domain: недопустимое доменное имя «${DOMAIN}»."; fi
    [[ "${APP_DIR}" == /* ]] || die "--dir: укажите абсолютный путь."
    if [[ -n "${LDAP_HOST}" ]]; then
        [[ -n "${LDAP_BASE_DN}" ]] || die "--ldap-host требует --ldap-base-dn (корень поиска, например \"DC=example,DC=local\")."
        [[ "${LDAP_PORT}" =~ ^[0-9]+$ ]] || die "--ldap-port: недопустимый номер порта."
        [[ "${LDAP_ENCRYPTION}" =~ ^(none|ssl|tls)$ ]] || die "--ldap-encryption: допустимы none, ssl или tls."
        if [[ -z "${LDAP_BIND_DN}" && -z "${LDAP_UPN_SUFFIX}" ]]; then
            LDAP_UPN_SUFFIX=$(printf '%s' "${LDAP_BASE_DN}" | grep -oi 'DC=[^,]*' | sed 's/^DC=//I' | paste -sd . -)
            warn "Не задан --ldap-upn-suffix — используется «${LDAP_UPN_SUFFIX}» (из base DN)."
        fi
    fi
    if [[ -n "${MAIL_DSN}" && -z "${MAIL_FROM}" ]]; then
        MAIL_FROM="${APP_NAME} <noreply@${DOMAIN:-localhost}>"
    fi
    if [[ "${DB_HOST}" != "127.0.0.1" && "${DB_HOST}" != "localhost" ]]; then
        DB_LOCAL="0"
        [[ -n "${DB_PASSWORD}" ]] || die "Для внешней базы данных укажите --db-password."
    fi
    if ! php -r 'exit(in_array($argv[1], timezone_identifiers_list(), true) ? 0 : 1);' "${TIMEZONE}" 2>/dev/null; then
        if ! [[ -f "/usr/share/zoneinfo/${TIMEZONE}" ]]; then
            die "--timezone: неизвестный часовой пояс «${TIMEZONE}»."
        fi
    fi
}

# --- Подготовка исходников ---------------------------------------------------------
prepare_source() {
    step "Подготовка исходных файлов"
    if [[ -n "${ARCHIVE}" ]]; then
        [[ -f "${ARCHIVE}" ]] || die "Архив не найден: ${ARCHIVE}"
        CREATED_TMP=$(mktemp -d "/tmp/${APP_ID}-src.XXXXXX")
        info "Распаковка ${ARCHIVE} → ${CREATED_TMP}"
        case "${ARCHIVE}" in
            *.tar.gz|*.tgz) tar -xzf "${ARCHIVE}" -C "${CREATED_TMP}" || die "Не удалось распаковать архив." ;;
            *.zip) require_cmd unzip; unzip -q "${ARCHIVE}" -d "${CREATED_TMP}" || die "Не удалось распаковать архив." ;;
            *) die "Неизвестный формат архива (ожидается .tar.gz или .zip)." ;;
        esac
        # Проект может лежать в корне архива или во вложенном каталоге.
        if is_project_dir "${CREATED_TMP}"; then
            SOURCE_DIR="${CREATED_TMP}"
        else
            local sub
            sub=$(find "${CREATED_TMP}" -maxdepth 2 -name composer.json -printf '%h\n' | head -n1)
            [[ -n "${sub}" ]] && is_project_dir "${sub}" || die "В архиве не найден проект (composer.json, bin/console, public/)."
            SOURCE_DIR="${sub}"
        fi
    elif [[ -z "${SOURCE_DIR}" ]]; then
        SOURCE_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
    fi
    SOURCE_DIR="$(cd "${SOURCE_DIR}" && pwd)"
    is_project_dir "${SOURCE_DIR}" || die "Каталог «${SOURCE_DIR}» не похож на проект портала (нет composer.json / bin/console / public)."
    APP_VERSION=$(project_version "${SOURCE_DIR}")
    ok "Исходники: ${SOURCE_DIR} (версия ${APP_VERSION})"
    if [[ ! -d "${SOURCE_DIR}/vendor" ]]; then
        warn "В исходниках нет каталога vendor/ (так бывает при копировании через git — vendor/ исключён в .gitignore)."
        warn "Зависимости будут загружены Composer с github.com. Надёжнее ставить из архива дистрибутива (.tar.gz) — там vendor/ уже есть."
    fi
}

preflight() {
    step "Предварительные проверки"
    require_root "$@"
    detect_os
    if [[ -f "${CONF_FILE}" ]]; then
        die "Портал уже установлен (найден ${CONF_FILE}). Для обновления используйте: sudo ./deploy/update.sh, для переустановки — сначала sudo ./deploy/uninstall.sh"
    fi
    local free
    free=$(free_space_mb "$(dirname "${APP_DIR}")" 2>/dev/null || free_space_mb /)
    if [[ -n "${free}" && ${free} -lt 2048 ]]; then
        die "Недостаточно места на диске: свободно ${free} МБ, требуется не менее 2 ГБ."
    fi
    local mem
    mem=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 0)
    if [[ ${mem} -gt 0 && ${mem} -lt 900 ]]; then
        warn "Оперативной памяти ${mem} МБ — рекомендуется не менее 1 ГБ."
    fi
    if command -v ss >/dev/null 2>&1 && ss -ltn 2>/dev/null | awk '{print $4}' | grep_has -E "[:.]${HTTP_PORT}$"; then
        if [[ "${INSTALL_MODE}" == "docker" ]] || ! command -v nginx >/dev/null 2>&1; then
            die "Порт ${HTTP_PORT} уже занят другой программой. Укажите другой порт: --port N"
        else
            warn "Порт ${HTTP_PORT} уже прослушивается (вероятно, nginx). Сайт по умолчанию будет отключён."
        fi
    fi
    if ! retry 2 3 bash -c 'timeout 8 bash -c "</dev/tcp/deb.debian.org/80" 2>/dev/null || timeout 8 bash -c "</dev/tcp/mirrors.rockylinux.org/80" 2>/dev/null || timeout 8 bash -c "</dev/tcp/archive.ubuntu.com/80" 2>/dev/null'; then
        warn "Не удалось проверить доступ в интернет — установка пакетов может завершиться ошибкой."
    fi
    ok "Проверки пройдены"
}

# --- Откат при ошибке -----------------------------------------------------------------
rollback() {
    warn "Откат незавершённой установки…"
    if [[ "${INSTALL_MODE}" == "docker" && "${DOCKER_STARTED}" == "1" && -f "${APP_DIR}/docker/docker-compose.yml" ]]; then
        (cd "${APP_DIR}/docker" && ${COMPOSE_CMD:-docker compose} down -v >>"${LOG_FILE}" 2>&1) || true
    fi
    if [[ "${CREATED_NGINX_SITE}" == "1" ]]; then
        rm -f "/etc/nginx/sites-enabled/${APP_ID}.conf" "/etc/nginx/sites-available/${APP_ID}.conf" "/etc/nginx/conf.d/${APP_ID}.conf"
        nginx -t >>"${LOG_FILE}" 2>&1 && svc reload nginx || true
    fi
    if [[ "${CREATED_DB}" == "1" || "${CREATED_DB_USER}" == "1" ]] && [[ "${DB_LOCAL}" == "1" ]]; then
        [[ "${CREATED_DB}" == "1" ]] && mysql_root_exec "DROP DATABASE IF EXISTS \`${DB_NAME}\`;" || true
        [[ "${CREATED_DB_USER}" == "1" ]] && mysql_root_exec "DROP USER IF EXISTS '${DB_USER}'@'localhost'; DROP USER IF EXISTS '${DB_USER}'@'127.0.0.1';" || true
    fi
    if [[ "${CREATED_APP_DIR}" == "1" && -d "${APP_DIR}" ]]; then
        rm -rf "${APP_DIR}"
    fi
    [[ "${CREATED_CONF}" == "1" ]] && rm -f "${CONF_FILE}" || true
    rm -f /etc/cron.d/"${APP_ID}" "/usr/local/bin/${APP_ID}"
    [[ -n "${CREATED_TMP}" ]] && rm -rf "${CREATED_TMP}" || true
    warn "Откат выполнен. Установленные системные пакеты сохранены."
}

# =============================================================================
#  РЕЖИМ NATIVE
# =============================================================================
# Есть ли в apt устанавливаемый пакет php<версия>-fpm
apt_has_php() { apt-cache policy "php${1}-fpm" 2>/dev/null | grep_has 'Candidate: [0-9]'; }

# Версия PHP, которую ОС считает основной (по зависимостям метапакета php-fpm), например 8.5
apt_default_php_version() {
    apt-cache depends php-fpm 2>/dev/null | grep -oE 'php[0-9]+\.[0-9]+-fpm' | head -n1 | grep -oE '[0-9]+\.[0-9]+' || true
}

# Ищет подходящую версию PHP среди пакетов ОС: сначала основную для ОС, затем предпочтительные.
detect_apt_php_version() {
    local v
    v=$(apt_default_php_version)
    if [[ -n "${v}" ]] && version_ge "${v}" "${PHP_MIN_VERSION}" && apt_has_php "${v}"; then
        echo "${v}"; return 0
    fi
    for v in "${PHP_PREFERRED_VERSIONS[@]}"; do
        if apt_has_php "${v}"; then echo "${v}"; return 0; fi
    done
    return 1
}

remove_php_repo() {
    if [[ "${OS_ID}" == "ubuntu" ]]; then
        add-apt-repository -y --remove ppa:ondrej/php >>"${LOG_FILE}" 2>&1 || true
        rm -f /etc/apt/sources.list.d/ondrej-ubuntu-php-*.list /etc/apt/sources.list.d/ondrej-ubuntu-php-*.sources
    else
        rm -f /etc/apt/sources.list.d/php-sury.list /usr/share/keyrings/deb.sury.org-php.gpg
    fi
    _APT_UPDATED=0
}

install_php_debian() {
    PHP_VERSION=$(detect_apt_php_version || true)
    if [[ -z "${PHP_VERSION}" ]]; then
        warn "В репозиториях ОС нет PHP ${PHP_MIN_VERSION}+, подключается репозиторий deb.sury.org / ppa:ondrej/php…"
        pkg_install ca-certificates curl gnupg lsb-release apt-transport-https
        if [[ "${OS_ID}" == "ubuntu" ]]; then
            pkg_install software-properties-common
            add-apt-repository -y ppa:ondrej/php >>"${LOG_FILE}" 2>&1 || die "Не удалось подключить ppa:ondrej/php (см. журнал). Установите PHP ${PHP_MIN_VERSION}+ вручную и повторите установку."
        else
            curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/deb.sury.org-php.gpg || die "Не удалось скачать ключ репозитория sury.org."
            echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php-sury.list
        fi
        ADDED_PHP_REPO="1"
        _APT_UPDATED=0
        apt_update_once
        PHP_VERSION=$(detect_apt_php_version || true)
        if [[ -z "${PHP_VERSION}" ]]; then
            remove_php_repo
            die "Ни в репозиториях ОС, ни в подключаемом репозитории нет пакетов PHP ${PHP_MIN_VERSION}+ для «${PRETTY_NAME:-$OS_ID $OS_VERSION}» (для новых выпусков ОС сторонний репозиторий появляется с задержкой). Установите PHP ${PHP_MIN_VERSION}+ с расширениями fpm, cli, mysql, xml, mbstring, intl, zip, gd, curl, ldap вручную и повторите установку."
        fi
    fi
    info "Выбрана версия PHP ${PHP_VERSION}"
    pkg_install "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-mysql" "php${PHP_VERSION}-xml" \
        "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-intl" "php${PHP_VERSION}-zip" "php${PHP_VERSION}-gd" \
        "php${PHP_VERSION}-curl" "php${PHP_VERSION}-ldap"
    PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"
    PHP_FPM_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"
    PHP_BIN="/usr/bin/php${PHP_VERSION}"
    PHP_INI_DIR_FPM="/etc/php/${PHP_VERSION}/fpm/conf.d"
    PHP_INI_DIR_CLI="/etc/php/${PHP_VERSION}/cli/conf.d"
    SERVICE_USER="www-data"
    # Убедимся, что php по умолчанию — нужной версии.
    update-alternatives --set php "/usr/bin/php${PHP_VERSION}" >>"${LOG_FILE}" 2>&1 || true
}

install_php_rhel() {
    warn "Семейство RHEL поддерживается в экспериментальном режиме."
    pkg_install epel-release || true
    local stream
    for stream in 8.3 8.2; do
        if dnf module list php 2>/dev/null | grep_has -E "^php\s+${stream}"; then
            dnf module reset -y php >>"${LOG_FILE}" 2>&1 || true
            dnf module enable -y "php:${stream}" >>"${LOG_FILE}" 2>&1 && PHP_VERSION="${stream}" && break
        fi
    done
    [[ -n "${PHP_VERSION}" ]] || die "Не найден модуль php 8.2/8.3 в dnf. Подключите remi-репозиторий и повторите."
    pkg_install php-fpm php-cli php-mysqlnd php-xml php-mbstring php-intl php-pecl-zip php-gd php-opcache php-process php-json php-ldap
    PHP_FPM_SERVICE="php-fpm"
    PHP_FPM_SOCK="/run/php-fpm/www.sock"
    PHP_BIN="/usr/bin/php"
    PHP_INI_DIR_FPM="/etc/php.d"
    PHP_INI_DIR_CLI="/etc/php.d"
    SERVICE_USER="apache"
    # PHP-FPM должен слушать unix-сокет, доступный nginx.
    sed -i 's|^listen = .*|listen = /run/php-fpm/www.sock|; s|^;listen.owner = .*|listen.owner = nginx|; s|^;listen.group = .*|listen.group = nginx|; s|^listen.acl_users = .*|listen.acl_users = apache,nginx|' /etc/php-fpm.d/www.conf
}

install_packages_native() {
    step "Установка системных пакетов"
    case "${OS_FAMILY}" in
        debian)
            pkg_install ca-certificates curl gnupg rsync tar gzip unzip cron acl lsb-release git
            install_php_debian
            pkg_install nginx
            if [[ "${DB_LOCAL}" == "1" ]]; then pkg_install mariadb-server mariadb-client; DB_SERVICE="mariadb"; else pkg_install mariadb-client; fi
            ;;
        rhel)
            pkg_install ca-certificates curl rsync tar gzip unzip cronie policycoreutils-python-utils git
            install_php_rhel
            pkg_install nginx
            if [[ "${DB_LOCAL}" == "1" ]]; then pkg_install mariadb-server mariadb; DB_SERVICE="mariadb"; else pkg_install mariadb; fi
            ;;
    esac
    # Проверка PHP
    php_version_ok "${PHP_BIN}" || die "Установлен PHP ниже ${PHP_MIN_VERSION}: $(${PHP_BIN} -v | head -n1)"
    local missing
    missing=$(php_missing_extensions "${PHP_BIN}" | tr '\n' ' ')
    [[ -z "${missing// /}" ]] || die "После установки не хватает расширений PHP: ${missing}"
    ok "PHP $(${PHP_BIN} -r 'echo PHP_VERSION;'), nginx $(nginx -v 2>&1 | sed 's/.*nginx\///')"
}

configure_php_native() {
    step "Настройка PHP"
    local ini_body
    ini_body="; Настройки портала «${APP_TITLE}» (создано install.sh)
upload_max_filesize = 128M
post_max_size = 130M
memory_limit = 512M
max_execution_time = 180
max_input_time = 180
date.timezone = ${TIMEZONE}
expose_php = Off
opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 192
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 60
realpath_cache_size = 4096K
realpath_cache_ttl = 600
"
    printf '%s' "${ini_body}" > "${PHP_INI_DIR_FPM}/90-${APP_ID}.ini"
    if [[ "${PHP_INI_DIR_CLI}" != "${PHP_INI_DIR_FPM}" ]]; then
        printf '%s' "${ini_body}" > "${PHP_INI_DIR_CLI}/90-${APP_ID}.ini"
    fi
    svc enable "${PHP_FPM_SERVICE}" || die "Не удалось запустить службу ${PHP_FPM_SERVICE}."
    svc restart "${PHP_FPM_SERVICE}" || die "Не удалось перезапустить ${PHP_FPM_SERVICE}."
    ok "PHP-FPM настроен (${PHP_FPM_SOCK})"
}

setup_database_native() {
    step "Настройка базы данных"
    if [[ -z "${DB_PASSWORD}" ]]; then DB_PASSWORD=$(gen_password 24); fi
    if [[ "${DB_LOCAL}" == "1" ]]; then
        svc enable "${DB_SERVICE}" || die "Не удалось запустить службу ${DB_SERVICE}."
        local n=0
        until mysql_root_exec "SELECT 1" >/dev/null 2>&1; do
            n=$((n + 1)); [[ ${n} -ge 30 ]] && die "Сервер MariaDB не отвечает (root через unix_socket). Проверьте: systemctl status ${DB_SERVICE}"
            sleep 2
        done
        if mysql_root_exec "SHOW DATABASES LIKE '${DB_NAME}'" | grep_has -x "${DB_NAME}"; then
            warn "База данных «${DB_NAME}» уже существует — будет использована как есть."
        else
            mysql_root_exec "CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || die "Не удалось создать базу данных."
            CREATED_DB="1"
        fi
        local pw_sql
        pw_sql=$(printf '%s' "${DB_PASSWORD}" | sed "s/'/''/g; s/\\\\/\\\\\\\\/g")
        if mysql_root_exec "SELECT 1 FROM mysql.user WHERE user='${DB_USER}' AND host='localhost'" | grep_has -x 1; then
            warn "Пользователь БД «${DB_USER}» уже существует — пароль будет обновлён."
            mysql_root_exec "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${pw_sql}';" || die "Не удалось обновить пароль пользователя БД."
        else
            mysql_root_exec "CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${pw_sql}';" || die "Не удалось создать пользователя БД."
            CREATED_DB_USER="1"
        fi
        mysql_root_exec "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;" || die "Не удалось выдать права пользователю БД."
        DB_HOST="127.0.0.1"
        # TCP-подключение по 127.0.0.1 требует пользователя с host=127.0.0.1 либо '%' на некоторых сборках; создадим и его.
        if ! mysql_root_exec "SELECT 1 FROM mysql.user WHERE user='${DB_USER}' AND host='127.0.0.1'" | grep_has -x 1; then
            mysql_root_exec "CREATE USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${pw_sql}'; GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1'; FLUSH PRIVILEGES;" || true
        else
            mysql_root_exec "ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${pw_sql}'; FLUSH PRIVILEGES;" || true
        fi
    fi
    wait_for_mysql "${DB_HOST}" "${DB_PORT}" "${DB_USER}" "${DB_PASSWORD}" 15 \
        || die "Не удаётся подключиться к базе данных ${DB_USER}@${DB_HOST}:${DB_PORT}/${DB_NAME}. Проверьте параметры --db-*."
    local raw
    raw=$(mysql_exec "${DB_HOST}" "${DB_PORT}" "${DB_USER}" "${DB_PASSWORD}" "SELECT VERSION()" | head -n1)
    if [[ "${raw}" == *MariaDB* ]]; then
        DB_SERVER_VERSION="$(echo "${raw}" | grep -oE '^[0-9]+\.[0-9]+\.[0-9]+')-MariaDB"
    else
        DB_SERVER_VERSION="$(echo "${raw}" | grep -oE '^[0-9]+\.[0-9]+\.[0-9]+' || echo '8.0')"
    fi
    ok "База данных доступна: ${DB_NAME} на ${DB_HOST}:${DB_PORT} (${raw})"
}

# Копирует проект в новый каталог релиза: install_release SRC DEST
install_release() {
    local src=$1 dest=$2
    mkdir -p "${dest}"
    rsync -a --delete \
        --exclude '/var/' --exclude '/.env.local' --exclude '/.env.*.local' --exclude '/.git/' \
        --exclude '/docker/.env' --exclude '/docker/mysql-data/' --exclude '/backups/' --exclude '/node_modules/' \
        "${src}/" "${dest}/" >>"${LOG_FILE}" 2>&1 || die "Не удалось скопировать файлы проекта в ${dest}."
    mkdir -p "${dest}/var/cache"
    if [[ ! -d "${dest}/vendor" ]]; then
        composer_install "${dest}"
    fi
}

composer_install() {
    local dir=$1
    step "Установка зависимостей Composer"
    if ! command -v composer >/dev/null 2>&1; then
        case "${PKG}" in
            apt) pkg_install composer ;;
            dnf) pkg_install composer ;;
        esac
    fi
    command -v composer >/dev/null 2>&1 || die "Composer не найден. Установите его: https://getcomposer.org/download/"
    info "Загрузка зависимостей (около 90 пакетов с github.com; может занять несколько минут)…"
    local composer_log
    composer_log=$(mktemp)
    if ! (cd "${dir}" && COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1 composer install --no-dev --optimize-autoloader --no-progress --prefer-dist --no-audit >"${composer_log}" 2>&1); then
        cat "${composer_log}" >>"${LOG_FILE}"
        error "Вывод Composer (последние строки):"
        tail -n 15 "${composer_log}" | sed 's/^/   | /' >&2
        rm -f "${composer_log}"
        die "composer install завершился с ошибкой. Каталог vendor/ можно перенести на сервер без Composer: он есть в архиве дистрибутива (sudo ./deploy/install.sh --archive docportal-<версия>.tar.gz) или скопируйте его с компьютера: scp -r docportal/vendor root@сервер:${SOURCE_DIR}/vendor — и запустите установку снова."
    fi
    cat "${composer_log}" >>"${LOG_FILE}"; rm -f "${composer_log}"
    ok "Зависимости установлены"
}

link_shared() {
    # link_shared RELEASE_DIR — подключает общие каталоги (загрузки, журналы, .env.local)
    local rel=$1
    mkdir -p "${APP_DIR}/shared/storage" "${APP_DIR}/shared/log" "${APP_DIR}/shared/import" "${rel}/var"
    rm -rf "${rel}/var/storage" "${rel}/var/log" "${rel}/var/import"
    ln -sfn "${APP_DIR}/shared/storage" "${rel}/var/storage"
    ln -sfn "${APP_DIR}/shared/log" "${rel}/var/log"
    ln -sfn "${APP_DIR}/shared/import" "${rel}/var/import"
    ln -sfn "${APP_DIR}/shared/.env.local" "${rel}/.env.local"
}

write_env_local_native() {
    step "Создание конфигурации приложения (.env.local)"
    local env_file="${APP_DIR}/shared/.env.local"
    mkdir -p "${APP_DIR}/shared"
    local secret url
    secret=$(gen_secret_hex)
    if [[ -n "${DOMAIN}" ]]; then url="http://${DOMAIN}"; [[ "${HTTP_PORT}" != "80" ]] && url="${url}:${HTTP_PORT}"; else url="http://localhost"; [[ "${HTTP_PORT}" != "80" ]] && url="${url}:${HTTP_PORT}"; fi
    local pw_url
    pw_url=$(php -r 'echo rawurlencode($argv[1]);' "${DB_PASSWORD}")
    umask 027
    cat > "${env_file}" <<EOF
# Локальные настройки портала «${APP_TITLE}» (создано install.sh $(date '+%Y-%m-%d %H:%M')).
# Описание всех параметров — в файле .env и в документации docs/РУКОВОДСТВО.md.
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=${secret}
APP_NAME="${APP_NAME}"
APP_COMPANY="${APP_COMPANY}"
APP_DEFAULT_URI=${url}
APP_TIMEZONE=${TIMEZONE}
DATABASE_URL="mysql://${DB_USER}:${pw_url}@${DB_HOST}:${DB_PORT}/${DB_NAME}?serverVersion=${DB_SERVER_VERSION}&charset=utf8mb4"
STORAGE_DIR=${APP_DIR}/shared/storage
# Каталог импорта: положите сюда папки с документами и запустите импорт в панели администратора.
IMPORT_DIR=${APP_DIR}/shared/import
UPLOAD_MAX_MB=100
TRUSTED_PROXIES=
MAILER_DSN=${MAIL_DSN:-null://null}
MAIL_FROM="${MAIL_FROM:-${APP_NAME} <noreply@${DOMAIN:-localhost}>}"
LDAP_ENABLED=$([[ -n "${LDAP_HOST}" ]] && echo 1 || echo 0)
LDAP_HOST=${LDAP_HOST:-dc.example.local}
LDAP_PORT=${LDAP_PORT}
LDAP_ENCRYPTION=${LDAP_ENCRYPTION}
LDAP_BASE_DN="${LDAP_BASE_DN:-DC=example,DC=local}"
LDAP_BIND_DN="${LDAP_BIND_DN}"
LDAP_BIND_PASSWORD="${LDAP_BIND_PASSWORD}"
LDAP_UPN_SUFFIX="${LDAP_UPN_SUFFIX}"
LDAP_ADMIN_GROUP="${LDAP_ADMIN_GROUP}"
LDAP_USER_GROUP="${LDAP_USER_GROUP}"
EOF
    umask 022
    chown "${SERVICE_USER}:${SERVICE_USER}" "${env_file}"
    chmod 640 "${env_file}"
    ok "Файл ${env_file} создан"
}

set_permissions() {
    local rel=$1
    chown -R "${SERVICE_USER}:${SERVICE_USER}" "${APP_DIR}/shared" "${rel}/var"
    chown -R root:"${SERVICE_USER}" "${rel}"
    chown -R "${SERVICE_USER}:${SERVICE_USER}" "${rel}/var"
    chmod -R u+rwX,g+rX,o-rwx "${rel}" 2>/dev/null || true
    chmod 751 "${rel}"
    chmod -R o+rX "${rel}/public"
    chmod 750 "${APP_DIR}/shared/storage" "${APP_DIR}/shared/log"
    # Каталог импорта: группа службы может записывать, новые файлы наследуют группу (setgid).
    chmod 2770 "${APP_DIR}/shared/import"
    chmod +x "${rel}/bin/console" "${rel}"/deploy/*.sh 2>/dev/null || true
    if command -v getenforce >/dev/null 2>&1 && [[ "$(getenforce 2>/dev/null)" == "Enforcing" ]]; then
        info "SELinux в режиме Enforcing — настройка контекстов"
        semanage fcontext -a -t httpd_sys_rw_content_t "${APP_DIR}/shared(/.*)?" >>"${LOG_FILE}" 2>&1 || true
        semanage fcontext -a -t httpd_sys_rw_content_t "${rel}/var(/.*)?" >>"${LOG_FILE}" 2>&1 || true
        restorecon -R "${APP_DIR}" >>"${LOG_FILE}" 2>&1 || true
        setsebool -P httpd_can_network_connect_db 1 >>"${LOG_FILE}" 2>&1 || true
        setsebool -P httpd_can_network_connect 1 >>"${LOG_FILE}" 2>&1 || true
    fi
}

# Запуск консольной команды Symfony от имени пользователя службы: app_console RELEASE_DIR аргументы…
app_console() {
    local rel=$1; shift
    runuser -u "${SERVICE_USER}" -- "${PHP_BIN}" -d memory_limit=512M "${rel}/bin/console" "$@" --no-interaction --env=prod
}

bootstrap_app_native() {
    local rel=$1
    step "Инициализация приложения"
    rm -rf "${rel}/var/cache/prod"
    app_console "${rel}" cache:clear >>"${LOG_FILE}" 2>&1 || die "Ошибка очистки кэша (проверьте .env.local и права на каталог var/)."
    app_console "${rel}" cache:warmup >>"${LOG_FILE}" 2>&1 || die "Ошибка прогрева кэша Symfony. См. журнал."
    info "Применение миграций базы данных…"
    app_console "${rel}" doctrine:migrations:migrate --allow-no-migration >>"${LOG_FILE}" 2>&1 || die "Ошибка применения миграций базы данных. См. журнал ${LOG_FILE}"
    if [[ -z "${ADMIN_PASSWORD}" ]]; then ADMIN_PASSWORD=$(gen_password 14); ADMIN_GENERATED="1"; fi
    ADMIN_PASSWORD="${ADMIN_PASSWORD}" app_console "${rel}" app:user:create "${ADMIN_USER}" --admin --name "Администратор" --if-not-exists >>"${LOG_FILE}" 2>&1 \
        || die "Не удалось создать администратора «${ADMIN_USER}»."
    app_console "${rel}" app:check >>"${LOG_FILE}" 2>&1 || die "Проверка установки (app:check) не пройдена. См. журнал."
    ok "Миграции применены, администратор «${ADMIN_USER}» создан"
}

configure_nginx_native() {
    step "Настройка nginx"
    local server_name="_" conf_path
    [[ -n "${DOMAIN}" ]] && server_name="${DOMAIN}"
    if [[ -d /etc/nginx/sites-available ]]; then
        conf_path="/etc/nginx/sites-available/${APP_ID}.conf"
    else
        conf_path="/etc/nginx/conf.d/${APP_ID}.conf"
    fi
    local LISTEN_V6=""
    [[ -f /proc/net/if_inet6 ]] && LISTEN_V6="    listen [::]:${HTTP_PORT};
"
    cat > "${conf_path}" <<EOF
# Портал «${APP_TITLE}» — создано install.sh $(date '+%Y-%m-%d %H:%M')
server {
    listen ${HTTP_PORT};
${LISTEN_V6}    server_name ${server_name};

    root ${APP_DIR}/current/public;
    index index.php;

    client_max_body_size 128M;
    server_tokens off;

    access_log /var/log/nginx/${APP_ID}.access.log;
    error_log  /var/log/nginx/${APP_ID}.error.log;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;

    location / {
        try_files \$uri /index.php\$is_args\$args;
    }

    location ~ ^/index\\.php(/|\$) {
        fastcgi_pass unix:${PHP_FPM_SOCK};
        fastcgi_split_path_info ^(.+\\.php)(/.*)\$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        fastcgi_read_timeout 180;
        fastcgi_buffers 16 32k;
        fastcgi_buffer_size 64k;
        internal;
    }

    # Любые другие .php-файлы недоступны.
    location ~ \\.php\$ {
        return 404;
    }

    location ~* \\.(css|js|woff2?|svg|png|jpg|ico)\$ {
        expires 30d;
        access_log off;
        add_header Cache-Control "public";
        try_files \$uri =404;
    }

    location ~ /\\. {
        deny all;
    }
}
EOF
    CREATED_NGINX_SITE="1"
    if [[ -d /etc/nginx/sites-enabled ]]; then
        ln -sfn "${conf_path}" "/etc/nginx/sites-enabled/${APP_ID}.conf"
        if [[ -L /etc/nginx/sites-enabled/default && "${HTTP_PORT}" == "80" && -z "${DOMAIN}" ]]; then
            warn "Отключается сайт nginx по умолчанию (/etc/nginx/sites-enabled/default), чтобы он не перехватывал порт 80."
            rm -f /etc/nginx/sites-enabled/default
        fi
    elif [[ "${OS_FAMILY}" == "rhel" && "${HTTP_PORT}" == "80" && -z "${DOMAIN}" ]] && grep_has 'listen       80' /etc/nginx/nginx.conf 2>/dev/null; then
        warn "В /etc/nginx/nginx.conf есть сервер по умолчанию на порту 80 — он отключается (создана копия nginx.conf.bak-${APP_ID})."
        cp /etc/nginx/nginx.conf "/etc/nginx/nginx.conf.bak-${APP_ID}"
        sed -i '/^    server {/,/^    }/ s/^/#/' /etc/nginx/nginx.conf
    fi
    local ngx_out
    if ! ngx_out=$(nginx -t 2>&1); then
        _log_raw "${ngx_out}"
        # Типичная причина на серверах без IPv6 — сайт nginx по умолчанию слушает [::]:80.
        if [[ "${ngx_out}" == *"socket() [::]"* && -L /etc/nginx/sites-enabled/default ]]; then
            warn "nginx не может открыть IPv6-сокет сайта по умолчанию — сайт по умолчанию отключается."
            rm -f /etc/nginx/sites-enabled/default
            ngx_out=$(nginx -t 2>&1) || { _log_raw "${ngx_out}"; die "Конфигурация nginx не прошла проверку (nginx -t): ${ngx_out##*$'\n'}"; }
        else
            die "Конфигурация nginx не прошла проверку (nginx -t): ${ngx_out##*$'\n'}"
        fi
    fi
    svc enable nginx || die "Не удалось запустить nginx."
    svc reload nginx || svc restart nginx || die "Не удалось перезагрузить nginx."
    ok "nginx: http://${DOMAIN:-<IP-сервера>}${HTTP_PORT:+:${HTTP_PORT}}"
}

configure_ssl_native() {
    [[ -n "${SSL_EMAIL}" ]] || return 0
    step "Получение сертификата Let's Encrypt для ${DOMAIN}"
    case "${PKG}" in
        apt) pkg_install certbot python3-certbot-nginx ;;
        dnf) pkg_install certbot python3-certbot-nginx ;;
    esac
    if certbot --nginx -d "${DOMAIN}" --non-interactive --agree-tos -m "${SSL_EMAIL}" --redirect >>"${LOG_FILE}" 2>&1; then
        SSL_ENABLED="1"
        sed -i "s|^APP_DEFAULT_URI=.*|APP_DEFAULT_URI=https://${DOMAIN}|" "${APP_DIR}/shared/.env.local"
        ok "HTTPS включён: https://${DOMAIN} (сертификат обновляется автоматически)"
    else
        warn "Не удалось получить сертификат (проверьте, что домен ${DOMAIN} указывает на этот сервер и открыт порт 80). Портал работает по HTTP; повторить: certbot --nginx -d ${DOMAIN}"
    fi
}

configure_firewall() {
    if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep_has "Status: active"; then
        info "Открытие порта ${HTTP_PORT} в ufw"
        ufw allow "${HTTP_PORT}/tcp" >>"${LOG_FILE}" 2>&1 || warn "Не удалось открыть порт в ufw."
        [[ "${SSL_ENABLED}" == "1" ]] && ufw allow 443/tcp >>"${LOG_FILE}" 2>&1 || true
    elif command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then
        info "Открытие порта ${HTTP_PORT} в firewalld"
        firewall-cmd --permanent --add-port="${HTTP_PORT}/tcp" >>"${LOG_FILE}" 2>&1 || warn "Не удалось открыть порт в firewalld."
        [[ "${SSL_ENABLED}" == "1" ]] && firewall-cmd --permanent --add-service=https >>"${LOG_FILE}" 2>&1 || true
        firewall-cmd --reload >>"${LOG_FILE}" 2>&1 || true
    fi
}


install_backup_cron() {
    if [[ "${BACKUP_CRON}" != "1" ]]; then
        cat > "/etc/cron.d/${APP_ID}" <<EOF
# Портал «${APP_TITLE}»: ежедневная проверка сроков актуальности документов (создано install.sh)
0 8 * * * root /usr/local/bin/${APP_ID} console app:documents:expiry --no-interaction >> ${LOG_DIR}/expiry-cron.log 2>&1
EOF
        chmod 644 "/etc/cron.d/${APP_ID}"
        return 0
    fi
    cat > "/etc/cron.d/${APP_ID}" <<EOF
# Портал «${APP_TITLE}» (создано install.sh):
#  - ежедневная проверка сроков актуальности документов и рассылка уведомлений в 08:00;
#  - ежедневная резервная копия в 03:15.
0 8 * * * root /usr/local/bin/${APP_ID} console app:documents:expiry --no-interaction >> ${LOG_DIR}/expiry-cron.log 2>&1
15 3 * * * root /usr/local/bin/${APP_ID} backup --quiet >> ${LOG_DIR}/backup-cron.log 2>&1
EOF
    chmod 644 "/etc/cron.d/${APP_ID}"
    if has_systemd; then svc enable cron >/dev/null 2>&1 || svc enable crond >/dev/null 2>&1 || true; fi
}

# health_check [попытки]: ждёт корректного ответа /health (см. health_probe в common.sh —
# после включения HTTPS проверяется https://ДОМЕН/health, перенаправления отслеживаются).
health_check() {
    local attempts=${1:-45} url body
    url=$(health_url)
    info "Проверка доступности: ${url}"
    if body=$(wait_for_health "${attempts}" 3); then
        ok "Портал отвечает: ${body}"
    else
        error "Портал не отвечает на ${url} (ответ: ${body:-нет})."
        return 1
    fi
}

install_native() {
    install_packages_native
    configure_php_native
    setup_database_native

    step "Копирование файлов проекта в ${APP_DIR}"
    if [[ -e "${APP_DIR}" ]]; then
        if [[ -n "$(ls -A "${APP_DIR}" 2>/dev/null)" ]]; then
            die "Каталог ${APP_DIR} уже существует и не пуст. Удалите его или укажите другой каталог: --dir"
        fi
    else
        mkdir -p "${APP_DIR}"; CREATED_APP_DIR="1"
    fi
    [[ "${CREATED_APP_DIR}" == "1" ]] || CREATED_APP_DIR="1"
    mkdir -p "${APP_DIR}/releases" "${APP_DIR}/shared"
    local release="${APP_DIR}/releases/${APP_VERSION}-$(date '+%Y%m%d%H%M%S')"
    install_release "${SOURCE_DIR}" "${release}"
    write_env_local_native
    link_shared "${release}"
    ln -sfn "${release}" "${APP_DIR}/current"
    set_permissions "${release}"
    ok "Релиз развёрнут: ${release}"

    bootstrap_app_native "${release}"
    configure_nginx_native
    # Работоспособность проверяется по HTTP ДО включения HTTPS: на этом этапе ошибка означает,
    # что приложение или веб-сервер настроены неверно, и установка откатывается.
    health_check || die "Установка завершена, но портал не отвечает. Проверьте: systemctl status nginx ${PHP_FPM_SERVICE}; журналы /var/log/nginx/${APP_ID}.error.log и ${APP_DIR}/shared/log/prod.log"
    configure_ssl_native
    configure_firewall
    install_cli_wrapper
    install_backup_cron
    # После включения HTTPS certbot перенаправляет HTTP→HTTPS; повторная проверка идёт уже по
    # https://ДОМЕН/health и не откатывает установку — приложение к этому моменту проверено.
    if [[ "${SSL_ENABLED}" == "1" ]]; then
        health_check 15 || warn "Портал не ответил по HTTPS. Проверьте вручную: curl -k https://${DOMAIN}/health ; nginx -t ; journalctl -u nginx. Установка сохранена."
    fi
}

# =============================================================================
#  РЕЖИМ DOCKER
# =============================================================================
COMPOSE_CMD=""

install_docker_engine() {
    step "Установка Docker"
    if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
        ok "Docker уже установлен: $(docker --version)"
    else
        case "${OS_FAMILY}" in
            debian)
                pkg_install ca-certificates curl gnupg
                local installed="0"
                if install -m 0755 -d /etc/apt/keyrings && curl -fsSL "https://download.docker.com/linux/${OS_ID}/gpg" -o /etc/apt/keyrings/docker.asc 2>>"${LOG_FILE}"; then
                    chmod a+r /etc/apt/keyrings/docker.asc
                    # shellcheck disable=SC1091
                    local codename; codename=$(. /etc/os-release && echo "${VERSION_CODENAME:-}")
                    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/${OS_ID} ${codename} stable" > /etc/apt/sources.list.d/docker.list
                    _APT_UPDATED=0
                    if env DEBIAN_FRONTEND=noninteractive apt-get update -q >>"${LOG_FILE}" 2>&1 && env DEBIAN_FRONTEND=noninteractive apt-get install -y -q docker-ce docker-ce-cli containerd.io docker-compose-plugin >>"${LOG_FILE}" 2>&1; then
                        installed="1"
                    else
                        rm -f /etc/apt/sources.list.d/docker.list; _APT_UPDATED=0
                    fi
                fi
                if [[ "${installed}" != "1" ]]; then
                    warn "Официальный репозиторий Docker недоступен, устанавливается docker.io из репозитория ОС."
                    pkg_install docker.io
                    apt-get install -y -q docker-compose-v2 >>"${LOG_FILE}" 2>&1 || apt-get install -y -q docker-compose-plugin >>"${LOG_FILE}" 2>&1 || warn "Плагин docker compose не установлен из репозитория ОС."
                fi
                ;;
            rhel)
                pkg_install dnf-plugins-core
                dnf config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo >>"${LOG_FILE}" 2>&1 || die "Не удалось подключить репозиторий Docker."
                pkg_install docker-ce docker-ce-cli containerd.io docker-compose-plugin
                ;;
        esac
        svc enable docker || die "Не удалось запустить службу docker."
    fi
    if docker compose version >/dev/null 2>&1; then
        COMPOSE_CMD="docker compose"
    elif command -v docker-compose >/dev/null 2>&1; then
        COMPOSE_CMD="docker-compose"
        warn "Используется устаревший docker-compose v1."
    else
        die "Не найден docker compose. Установите плагин docker-compose-plugin и повторите."
    fi
    if [[ -n "${DOCKER_MIRROR}" ]]; then
        info "Настройка зеркала Docker Hub: ${DOCKER_MIRROR}"
        mkdir -p /etc/docker
        if [[ -s /etc/docker/daemon.json ]] && command -v python3 >/dev/null 2>&1; then
            python3 - "${DOCKER_MIRROR}" <<'PY' >>"${LOG_FILE}" 2>&1 || warn "Не удалось обновить /etc/docker/daemon.json"
import json, sys
p = '/etc/docker/daemon.json'
d = json.load(open(p))
m = d.get('registry-mirrors', [])
if sys.argv[1] not in m:
    m.insert(0, sys.argv[1])
d['registry-mirrors'] = m
json.dump(d, open(p, 'w'), indent=2)
PY
        else
            printf '{\n  "registry-mirrors": ["%s"]\n}\n' "${DOCKER_MIRROR}" > /etc/docker/daemon.json
        fi
        svc restart docker || die "Не удалось перезапустить docker после настройки зеркала."
    fi
    ok "Docker готов: $(docker --version | head -n1); ${COMPOSE_CMD}"
}

install_docker() {
    install_docker_engine
    step "Копирование файлов проекта в ${APP_DIR}"
    if [[ -e "${APP_DIR}" && -n "$(ls -A "${APP_DIR}" 2>/dev/null)" ]]; then
        die "Каталог ${APP_DIR} уже существует и не пуст. Удалите его или укажите другой каталог: --dir"
    fi
    mkdir -p "${APP_DIR}"; CREATED_APP_DIR="1"
    rsync -a --exclude '/var/' --exclude '/.env.local' --exclude '/.git/' --exclude '/docker/.env' --exclude '/docker/mysql-data/' "${SOURCE_DIR}/" "${APP_DIR}/" >>"${LOG_FILE}" 2>&1 || die "Не удалось скопировать файлы проекта."
    chmod +x "${APP_DIR}"/deploy/*.sh "${APP_DIR}/docker/entrypoint.sh" "${APP_DIR}/bin/console" 2>/dev/null || true

    step "Создание конфигурации docker/.env"
    [[ -n "${DB_PASSWORD}" ]] || DB_PASSWORD=$(gen_password 24)
    if [[ -z "${ADMIN_PASSWORD}" ]]; then ADMIN_PASSWORD=$(gen_password 14); ADMIN_GENERATED="1"; fi
    local root_pw secret url
    root_pw=$(gen_password 24); secret=$(gen_secret_hex)
    if [[ -n "${DOMAIN}" ]]; then url="http://${DOMAIN}"; else url="http://localhost"; fi
    [[ "${HTTP_PORT}" != "80" ]] && url="${url}:${HTTP_PORT}"
    umask 077
    cat > "${APP_DIR}/docker/.env" <<EOF
# Настройки Docker-развёртывания портала «${APP_TITLE}» (создано install.sh $(date '+%Y-%m-%d %H:%M')).
# Описание параметров — docker/.env.example и docs/РУКОВОДСТВО.md.
COMPOSE_PROJECT_NAME=${APP_ID}
HTTP_PORT=${HTTP_PORT}
TZ=${TIMEZONE}
# Каталог импорта документов: том Docker «import» или путь на сервере (например ${APP_DIR}/import).
IMPORT_HOST_DIR=${APP_DIR}/import

MYSQL_IMAGE=mysql:8.0
NGINX_IMAGE=nginx:1.27-alpine
MYSQL_ROOT_PASSWORD=${root_pw}
MYSQL_DATABASE=${DB_NAME}
MYSQL_USER=${DB_USER}
MYSQL_PASSWORD=${DB_PASSWORD}

APP_ENV=prod
APP_DEBUG=0
APP_SECRET=${secret}
APP_NAME=${APP_NAME}
APP_COMPANY=${APP_COMPANY}
APP_DEFAULT_URI=${url}
APP_TIMEZONE=${TIMEZONE}
UPLOAD_MAX_MB=100
TRUSTED_PROXIES=172.16.0.0/12,10.0.0.0/8,192.168.0.0/16
MAILER_DSN=${MAIL_DSN:-null://null}
MAIL_FROM=${MAIL_FROM:-${APP_NAME} <noreply@${DOMAIN:-localhost}>}
LDAP_ENABLED=$([[ -n "${LDAP_HOST}" ]] && echo 1 || echo 0)
LDAP_HOST=${LDAP_HOST:-dc.example.local}
LDAP_PORT=${LDAP_PORT}
LDAP_ENCRYPTION=${LDAP_ENCRYPTION}
LDAP_BASE_DN=${LDAP_BASE_DN:-DC=example,DC=local}
LDAP_BIND_DN=${LDAP_BIND_DN}
LDAP_BIND_PASSWORD=${LDAP_BIND_PASSWORD}
LDAP_UPN_SUFFIX=${LDAP_UPN_SUFFIX}
LDAP_ADMIN_GROUP=${LDAP_ADMIN_GROUP}
LDAP_USER_GROUP=${LDAP_USER_GROUP}

ADMIN_USER=${ADMIN_USER}
ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
    umask 022
    chmod 600 "${APP_DIR}/docker/.env"
    # Каталог импорта на сервере (подключается в контейнеры как /var/www/html/var/import; uid 82 = www-data в alpine).
    mkdir -p "${APP_DIR}/import" && chown 82:82 "${APP_DIR}/import" && chmod 2775 "${APP_DIR}/import"
    DB_HOST="db"; DB_LOCAL="0"; DB_SERVICE="docker"; SERVICE_USER="www-data"; PHP_VERSION="8.4"; PHP_FPM_SERVICE="docker"; PHP_FPM_SOCK=""; DB_SERVER_VERSION="8.0"

    step "Сборка образа и запуск контейнеров (может занять несколько минут)"
    (cd "${APP_DIR}/docker" && ${COMPOSE_CMD} build --pull >>"${LOG_FILE}" 2>&1) \
        || die "Сборка образа не удалась. Если Docker Hub недоступен, укажите зеркало: --docker-mirror https://mirror.gcr.io (см. журнал ${LOG_FILE})."
    DOCKER_STARTED="1"
    (cd "${APP_DIR}/docker" && ${COMPOSE_CMD} up -d >>"${LOG_FILE}" 2>&1) || die "Не удалось запустить контейнеры. См. журнал ${LOG_FILE}"
    ok "Контейнеры запущены"

    install_cli_wrapper
    install_backup_cron
    configure_firewall
    health_check || {
        (cd "${APP_DIR}/docker" && ${COMPOSE_CMD} logs --tail=100 app >>"${LOG_FILE}" 2>&1) || true
        die "Контейнеры запущены, но портал не отвечает. Журнал контейнера: cd ${APP_DIR}/docker && ${COMPOSE_CMD} logs app"
    }
}

# =============================================================================
print_summary() {
    local url
    if [[ "${SSL_ENABLED}" == "1" ]]; then url="https://${DOMAIN}";
    else url="http://${DOMAIN:-$(hostname -I 2>/dev/null | awk '{print $1}')}"; [[ "${HTTP_PORT}" != "80" ]] && url="${url}:${HTTP_PORT}"; fi
    mkdir -p "${CONF_DIR}"
    umask 077
    cat > "${CONF_DIR}/admin-credentials.txt" <<EOF
Портал «${APP_TITLE}» — учётная запись первого администратора (создано $(date '+%Y-%m-%d %H:%M'))
Адрес:  ${url}
Логин:  ${ADMIN_USER}
Пароль: ${ADMIN_PASSWORD}
Смените пароль после первого входа и удалите этот файл.
EOF
    umask 022
    printf '\n%s%s\n' "${C_GREEN}${C_BOLD}" "=============================================================="
    printf ' УСТАНОВКА ЗАВЕРШЕНА УСПЕШНО\n'
    printf '%s%s\n' "==============================================================" "${C_RESET}"
    printf ' Адрес портала:      %s%s%s\n' "${C_BOLD}" "${url}" "${C_RESET}"
    printf ' Администратор:      %s\n' "${ADMIN_USER}"
    printf ' Пароль:             %s%s%s  %s\n' "${C_BOLD}" "${ADMIN_PASSWORD}" "${C_RESET}" "$([[ "${ADMIN_GENERATED:-0}" == "1" ]] && echo '(сгенерирован)')"
    printf ' Режим:              %s\n' "${INSTALL_MODE}"
    printf ' Каталог:            %s\n' "${APP_DIR}"
    printf ' Параметры:          %s\n' "${CONF_FILE}"
    printf ' Учётные данные:     %s (удалите после первого входа)\n' "${CONF_DIR}/admin-credentials.txt"
    printf ' Журнал установки:   %s\n' "${LOG_FILE}"
    printf ' Управление:         %s status | update | backup | restore | console | logs | uninstall\n' "${APP_ID}"
    printf '%s\n' "=============================================================="
    printf ' Дальше: войдите под администратором, смените пароль (шапка → имя пользователя),\n'
    printf ' создайте разделы и назначьте модераторов в «Панель администратора → Разделы и модераторы».\n'
    [[ -n "${LDAP_HOST}" ]] && printf ' Проверьте вход через домен: %s console app:ldap:test ЛОГИН\n' "${APP_ID}"
    printf '\n'
}

main() {
    parse_args "$@"
    banner "установка"
    log_init install "$@"
    validate_args
    preflight "$@"
    prepare_source
    setup_traps

    info "Режим: ${INSTALL_MODE}; каталог: ${APP_DIR}; порт: ${HTTP_PORT}; домен: ${DOMAIN:-не задан}"
    if [[ "${ASSUME_YES}" != "1" ]]; then
        confirm "Начать установку?" || die "Установка отменена."
    fi

    ROLLBACK_ENABLED="1"
    if [[ "${INSTALL_MODE}" == "native" ]]; then
        install_native
    else
        install_docker
    fi

    INSTALLED_AT="$(date '+%Y-%m-%d %H:%M:%S')"
    save_conf; CREATED_CONF="1"
    [[ -n "${CREATED_TMP}" ]] && rm -rf "${CREATED_TMP}" || true
    ROLLBACK_ENABLED="0"
    trap - ERR
    set +e
    print_summary
    exit 0
}

main "$@"
