#!/usr/bin/env bash
# =============================================================================
#  Установка портала документации на чистый сервер Linux одной командой — полностью автоматически:
#  без вопросов, вместе с разделом «Видео» (если серверу хватает ресурсов), с проверками до начала
#  работы, повторами сетевых операций, восстановлением менеджера пакетов и откатом при ошибке.
#  На сервере, где портал уже установлен, вместо установки выполняется обновление (update.sh).
#
#  Примеры:
#    sudo ./deploy/install.sh                                # локальная установка (nginx + PHP-FPM + MariaDB)
#    sudo ./deploy/install.sh --domain docs.example.ru --ssl-email admin@example.ru
#    sudo ./deploy/install.sh --domain docs.example.ru --ldap-host dc1.example.local --ldap-base-dn "DC=example,DC=local" --ldap-upn-suffix example.local
#    sudo ./deploy/install.sh --mode docker --port 8080      # установка в Docker
#    sudo ./deploy/install.sh --archive docportal-1.8.0.tar.gz
#    sudo ./deploy/install.sh --domain docs.example.ru --no-video     # без раздела «Видео» (/video)
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
  --ldap-marketer-group DN  Группа AD для роли «Маркетолог» (модератор всех разделов и видео).
  --ldap-assistant-group DN Группа AD для роли «Помощник маркетолога» (добавляет материалы на модерацию).
                            С любой из этих групп роль сотрудника при входе через домен берётся из AD.
  --ldap-user-group DN      Группа AD, обязательная для входа (пусто — все пользователи домена).

Почта (уведомления о сроках актуальности):
  --mail-dsn DSN            Транспорт Symfony Mailer, например smtp://user:pass@mail.example.ru:587 (по умолчанию письма не отправляются).
  --mail-from "Адрес"       Отправитель, например "Портал документации <noreply@example.ru>".

Docker:
  --docker-mirror URL       Зеркало Docker Hub (например, https://mirror.gcr.io) — записывается в /etc/docker/daemon.json.

Раздел «Видео» (видеопортал по адресу /video: вход и роли общие с порталом):
                            По умолчанию ставится автоматически, если хватает памяти и места на диске
                            (PostgreSQL, Node.js, ffmpeg, MediaMTX; в Docker — профили video и video-live).
  --with-video              Ставить раздел «Видео» в любом случае (даже при нехватке ресурсов).
  --no-video                Не ставить раздел «Видео» (включить позже: ${APP_ID} video enable).
  --video-no-live           Без сервера прямых трансляций (MediaMTX, порты 1935, 8890/udp, 8189).
  --video-backup-media      Видеофайлы входят в ежедневные резервные копии (по умолчанию — только база видео).

Прочее:
  --backup-dir DIR          Каталог резервных копий (по умолчанию ${DEFAULT_BACKUP_DIR}).
  --no-backup-cron          Не добавлять ежедневное резервное копирование в cron.
  --with-preview            Установить LibreOffice: просмотр docx/xlsx/pptx прямо в браузере (~500 МБ).
  --with-ocr                Установить tesseract: распознавание сканов для поиска.
  --with-extras             И то, и другое.
  --no-swap                 Не создавать файл подкачки на сервере с малой памятью (по умолчанию создаётся
                            /${APP_ID}.swap, если памяти вместе с подкачкой меньше 2 ГБ).

Автоматическое обновление (по умолчанию включено: ежедневно в 04:30 портал обновляется, если в каталоге
/var/lib/${APP_ID}/updates или в источнике ниже появилась версия новее; управление — ${APP_ID} auto-update):
  --update-github ВЛ/РЕПО   Скачивать новые версии из релизов GitHub.
  --update-token-file ФАЙЛ  Токен GitHub для закрытого репозитория.
  --update-url АДРЕС        Скачивать из каталога на веб-сервере (АДРЕС/latest.txt и архивы).
  --no-auto-update          Не включать автоматическое обновление.
  --confirm                 Спросить подтверждение перед началом (по умолчанию установка идёт без вопросов).
  --yes, -y                 Не задавать вопросов (так и по умолчанию; оставлено для совместимости).
  --verbose                 Подробный вывод.
  --help, -h                Эта справка.

Если портал на сервере уже установлен, скрипт выполняет обновление до версии из этого каталога или архива
(${APP_ID} update). Ошибка на любом шаге — откат сделанного (системные пакеты остаются); повторный запуск
безопасен. Журнал — ${LOG_DIR}/install-<дата>.log.
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
LDAP_MARKETER_GROUP=""
LDAP_ASSISTANT_GROUP=""
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
DB_SERVER_VERSION="8.0.0"
DOCKER_MIRROR=""
BACKUP_DIR="${DEFAULT_BACKUP_DIR}"
BACKUP_CRON="1"
WITH_PREVIEW="0"
WITH_OCR="0"
# auto — раздел «Видео» ставится, если хватает ресурсов; 1 — всегда; 0 — нет.
WITH_VIDEO="auto"
VIDEO_LIVE="1"
VIDEO_BACKUP_MEDIA="0"
VIDEO_REASON=""
# --no-video — явный отказ: обновления не будут включать раздел «Видео» сами (VIDEO_ENABLED=0).
VIDEO_OPT_OUT="0"
NO_SWAP="0"
# Автоматическое обновление по расписанию (docportal auto-update): включается при установке; источник новых
# версий — каталог /var/lib/docportal/updates и, если задан, релизы GitHub или каталог на веб-сервере.
AUTO_UPDATE_ON="1"
AUTO_UPDATE_GITHUB=""
AUTO_UPDATE_URL=""
AUTO_UPDATE_TOKEN_FILE=""
# Установка полностью автоматическая: вопрос «Начать установку?» — только с --confirm.
ASSUME_YES="1"
VERBOSE="0"
SERVICE_USER=""
PHP_VERSION=""
PHP_FPM_SERVICE=""
PHP_FPM_SOCK=""
APP_VERSION=""
INSTALLED_AT=""

# Что было создано в ходе установки (для отката при ошибке).
CREATED_APP_DIR="0"; CREATED_DB="0"; CREATED_DB_USER="0"; CREATED_NGINX_SITE="0"; CREATED_CONF="0"; CREATED_TMP=""; DOCKER_STARTED="0"; ADDED_PHP_REPO="0"
CREATED_MAINT_DIR=""; CREATED_MAINT_SNIPPET="0"

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
            --ldap-marketer-group) LDAP_MARKETER_GROUP="${2:-}"; shift 2 ;;
            --ldap-assistant-group) LDAP_ASSISTANT_GROUP="${2:-}"; shift 2 ;;
            --ldap-user-group) LDAP_USER_GROUP="${2:-}"; shift 2 ;;
            --mail-dsn) MAIL_DSN="${2:-}"; shift 2 ;;
            --mail-from) MAIL_FROM="${2:-}"; shift 2 ;;
            --docker-mirror) DOCKER_MIRROR="${2:-}"; shift 2 ;;
            --backup-dir) BACKUP_DIR="${2:-}"; shift 2 ;;
            --no-backup-cron) BACKUP_CRON="0"; shift ;;
            --with-preview) WITH_PREVIEW="1"; shift ;;
            --with-ocr) WITH_OCR="1"; shift ;;
            --with-extras) WITH_PREVIEW="1"; WITH_OCR="1"; shift ;;
            --with-video) WITH_VIDEO="1"; shift ;;
            --no-video) WITH_VIDEO="0"; VIDEO_OPT_OUT="1"; shift ;;
            --no-swap) NO_SWAP="1"; shift ;;
            --no-auto-update) AUTO_UPDATE_ON="0"; shift ;;
            --update-github) AUTO_UPDATE_GITHUB="${2:-}"; shift 2 ;;
            --update-url) AUTO_UPDATE_URL="${2:-}"; shift 2 ;;
            --update-token-file) AUTO_UPDATE_TOKEN_FILE="${2:-}"; shift 2 ;;
            --confirm) ASSUME_YES="0"; shift ;;
            --video-no-live) VIDEO_LIVE="0"; shift ;;
            --video-backup-media) VIDEO_BACKUP_MEDIA="1"; shift ;;
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
    if [[ -n "${AUTO_UPDATE_GITHUB}" && ! "${AUTO_UPDATE_GITHUB}" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]]; then die "--update-github: укажите репозиторий как ВЛАДЕЛЕЦ/РЕПОЗИТОРИЙ."; fi
    if [[ -n "${AUTO_UPDATE_URL}" && ! "${AUTO_UPDATE_URL}" =~ ^https?://[^[:space:]]+$ ]]; then die "--update-url: укажите адрес каталога обновлений (http:// или https://)."; fi
    if [[ -n "${AUTO_UPDATE_GITHUB}" && -n "${AUTO_UPDATE_URL}" ]]; then die "Укажите один источник обновлений: --update-github или --update-url."; fi
    if [[ -n "${AUTO_UPDATE_TOKEN_FILE}" ]]; then
        [[ -n "${AUTO_UPDATE_GITHUB}" ]] || die "--update-token-file нужен только вместе с --update-github."
        [[ -s "${AUTO_UPDATE_TOKEN_FILE}" ]] || die "--update-token-file: файл с токеном не найден или пуст: ${AUTO_UPDATE_TOKEN_FILE}"
        AUTO_UPDATE_TOKEN_FILE="$(cd "$(dirname "${AUTO_UPDATE_TOKEN_FILE}")" && pwd)/$(basename "${AUTO_UPDATE_TOKEN_FILE}")"
    fi
    if [[ -n "${DOMAIN}" && ! "${DOMAIN}" =~ ^[a-zA-Z0-9.-]+$ ]]; then die "--domain: недопустимое доменное имя «${DOMAIN}»."; fi
    [[ "${APP_DIR}" == /* ]] || die "--dir: укажите абсолютный путь."
    if [[ -n "${LDAP_HOST}" ]]; then
        [[ -n "${LDAP_BASE_DN}" ]] || die "--ldap-host требует --ldap-base-dn (корень поиска, например \"DC=example,DC=local\")."
        [[ "${LDAP_PORT}" =~ ^[0-9]+$ ]] || die "--ldap-port: недопустимый номер порта."
        [[ "${LDAP_ENCRYPTION}" =~ ^(none|ssl|tls)$ ]] || die "--ldap-encryption: допустимы none, ssl или tls."
        if [[ -z "${LDAP_BIND_DN}" && -z "${LDAP_UPN_SUFFIX}" ]]; then
            LDAP_UPN_SUFFIX=$(printf '%s' "${LDAP_BASE_DN}" | grep -oi 'DC=[^,]*' | sed 's/^DC=//I' | paste -sd . - || true)
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

# Имя хоста для адреса портала (APP_DEFAULT_URI): домен, иначе первый IP-адрес сервера.
portal_host() {
    local ip
    if [[ -n "${DOMAIN}" ]]; then printf '%s' "${DOMAIN}"; return; fi
    ip=$(hostname -I 2>/dev/null | tr ' ' '\n' | grep -v ':' | head -n1 || true)
    printf '%s' "${ip:-localhost}"
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
            sub=$(find "${CREATED_TMP}" -maxdepth 2 -name composer.json -printf '%h\n' 2>/dev/null | head -n1 || true)
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
    check_os_supported
    if [[ -f "${CONF_FILE}" ]]; then
        redirect_to_update
    fi
    if [[ "${INSTALL_MODE}" == "native" ]] && ! has_systemd; then
        warn "systemd не найден (контейнер или нестандартная система) — службы будут запускаться без него; для боевого сервера используйте систему с systemd."
    fi
    pkg_preflight
    check_clock || true

    # Место на диске: портал — 2 ГБ; раздел «Видео» — ещё около 6 ГБ (пакеты, образы, первые видео).
    local free mem swap
    free=$(free_space_mb "$(dirname "${APP_DIR}")" 2>/dev/null || free_space_mb /)
    if [[ -n "${free}" && ${free} -lt 2048 ]]; then
        die "Недостаточно места на диске: свободно ${free} МБ, требуется не менее 2 ГБ."
    fi
    if [[ -n "${free}" && ${free} -lt 8192 && "${WITH_VIDEO}" == "auto" ]]; then
        WITH_VIDEO="0"; VIDEO_REASON="свободно ${free} МБ, а для раздела «Видео» нужно не меньше 8 ГБ"
    fi
    mem=$(mem_total_mb); swap=$(swap_total_mb)
    if [[ ${mem} -gt 0 && ${mem} -lt 900 ]]; then
        warn "Оперативной памяти ${mem} МБ — рекомендуется не менее 1 ГБ (недостающее восполнит файл подкачки)."
    fi
    if [[ ${mem} -gt 0 && $((mem + swap)) -lt 1800 && "${WITH_VIDEO}" == "auto" && "${NO_SWAP}" == "1" ]]; then
        WITH_VIDEO="0"; VIDEO_REASON="памяти ${mem} МБ (с подкачкой ${swap} МБ), а для обработки видео нужно не меньше 2 ГБ"
    fi

    # Порты.
    if port_busy "${HTTP_PORT}"; then
        if [[ "${INSTALL_MODE}" == "docker" ]] || ! command -v nginx >/dev/null 2>&1; then
            local who
            who=$(ss -ltnp "sport = :${HTTP_PORT}" 2>/dev/null | awk 'NR > 1 {print $NF; exit}' || true)
            die "Порт ${HTTP_PORT} уже занят другой программой${who:+ (${who})}. Освободите его или укажите другой порт: --port N"
        else
            warn "Порт ${HTTP_PORT} уже прослушивается (вероятно, nginx). Сайт по умолчанию будет отключён."
        fi
    fi
    if [[ -n "${SSL_EMAIL}" ]] && port_busy 443 && ! pgrep -x nginx >/dev/null 2>&1; then
        warn "Порт 443 занят другой программой — HTTPS может не заработать."
    fi

    # Домен для Let's Encrypt должен указывать на этот сервер.
    if [[ -n "${SSL_EMAIL}" && -n "${DOMAIN}" ]]; then
        local resolved
        resolved=$(getent ahostsv4 "${DOMAIN}" 2>/dev/null | awk '{print $1}' | sort -u | tr '\n' ' ' || true)
        if [[ -z "${resolved// /}" ]]; then
            warn "Домен ${DOMAIN} не разрешается в адрес — сертификат Let's Encrypt не будет получен (портал заработает по HTTP; сертификат можно получить позже: certbot --nginx -d ${DOMAIN})."
        fi
    fi

    # Доступ к нужным узлам (недоступность — предупреждение: часть узлов бывает закрыта, а пакеты берутся с зеркал).
    local hosts=() h
    while read -r h; do [[ -n "${h}" ]] && hosts+=("репозиторий пакетов ОС|${h}|80"); done < <(os_repo_hosts | head -n 2)
    [[ "${INSTALL_MODE}" == "docker" ]] && hosts+=("репозиторий Docker|download.docker.com|443" "Docker Hub|registry-1.docker.io|443")
    [[ -n "${SSL_EMAIL}" ]] && hosts+=("Let's Encrypt|acme-v02.api.letsencrypt.org|443")
    if [[ "${WITH_VIDEO}" != "0" ]]; then
        if [[ "${INSTALL_MODE}" == "native" ]]; then
            hosts+=("Node.js (NodeSource)|deb.nodesource.com|443" "MediaMTX (github.com)|github.com|443")
        fi
        local src_video="${SOURCE_DIR:-${SCRIPT_DIR}/..}/video"
        [[ -d "${src_video}/server/node_modules/fastify" && -f "${src_video}/web/dist/index.html" ]] || hosts+=("npm|registry.npmjs.org|443")
    fi
    if ! check_hosts "${hosts[@]}"; then
        for h in "${UNREACHABLE_HOSTS[@]}"; do warn "Нет доступа: ${h} — установка попробует продолжить (повторы, запасные источники)."; done
    fi
    ok "Проверки пройдены"
}

# Узлы репозиториев пакетов ОС (из /etc/apt/sources.list*, /etc/yum.repos.d) — для проверки доступа.
os_repo_hosts() {
    {
        grep -rhoE '^[^#]*(deb|URIs:)[^#]*https?://[^/ ]+' /etc/apt/sources.list /etc/apt/sources.list.d/ 2>/dev/null
        grep -rhoE '^(baseurl|mirrorlist|metalink)=https?://[^/ ]+' /etc/yum.repos.d/ 2>/dev/null
    } | grep -oE 'https?://[^/ ]+' | sed -E 's#https?://##' | grep -v -E '^(localhost|127\.)' | awk '!seen[$0]++'
}

# Портал уже установлен: вместо установки — обновление до версии из этого каталога (архива). Параметры
# установки (домен, порт, база) при этом не меняются — они берутся из ${CONF_FILE}.
redirect_to_update() {
    local installed_version installed_dir args=(--yes) src
    # shellcheck disable=SC1090
    installed_version=$(. "${CONF_FILE}" 2>/dev/null; printf '%s' "${APP_VERSION:-?}")
    # shellcheck disable=SC1090
    installed_dir=$(. "${CONF_FILE}" 2>/dev/null; printf '%s' "${APP_DIR:-${DEFAULT_APP_DIR}}")
    if [[ -n "${ARCHIVE}" ]]; then
        args+=(--archive "${ARCHIVE}")
    else
        src="${SOURCE_DIR:-$(cd "${SCRIPT_DIR}/.." && pwd)}"
        # Скрипт из установленного релиза: новую версию update.sh найдёт сам (архив рядом или в каталоге обновлений).
        [[ "${src}" == "${installed_dir}"/* || "${src}" == "${installed_dir}" ]] || args+=(--source "${src}")
    fi
    [[ "${WITH_VIDEO}" == "0" ]] && args+=(--no-video)
    [[ "${WITH_VIDEO}" == "1" ]] && args+=(--with-video)
    [[ "${ASSUME_YES}" == "1" ]] || args=("${args[@]/--yes/--confirm}")
    info "Портал уже установлен (версия ${installed_version}, ${installed_dir}) — вместо установки выполняется обновление."
    info "Параметры установки (домен, порт, база данных) сохраняются; переустановка с нуля — после ${APP_ID} uninstall."
    exec bash "${SCRIPT_DIR}/update.sh" "${args[@]}"
}

# Каталог установки уже есть и не пуст. Остатки прерванной установки портала (откат которой не
# завершился) переносятся в сторону — ничего не удаляется; посторонние файлы не трогаются.
prepare_app_dir() {
    if [[ -e "${APP_DIR}" && -n "$(ls -A "${APP_DIR}" 2>/dev/null)" ]]; then
        if [[ -d "${APP_DIR}/releases" || -f "${APP_DIR}/docker/docker-compose.yml" || -f "${APP_DIR}/composer.json" || -d "${APP_DIR}/shared" ]]; then
            local aside
            aside="${APP_DIR}.failed-$(date '+%Y%m%d-%H%M%S')"
            mv "${APP_DIR}" "${aside}" || die "Не удалось перенести остатки прежней установки из ${APP_DIR}."
            PREV_ATTEMPT_DIR="${aside}"
            warn "В ${APP_DIR} остались файлы прерванной установки портала — перенесены в ${aside} (удалите каталог, когда убедитесь, что он не нужен)."
        else
            die "Каталог ${APP_DIR} уже существует, не пуст и не похож на установку портала. Укажите другой каталог (--dir) или освободите этот."
        fi
    fi
    mkdir -p "${APP_DIR}"; CREATED_APP_DIR="1"
}
PREV_ATTEMPT_DIR=""

# Решение о разделе «Видео»: --with-video — ставить всегда, --no-video — нет, по умолчанию — если серверу
# хватает ресурсов (место и память проверены в preflight) и поставка содержит видеопортал.
decide_video() {
    [[ "${WITH_VIDEO}" == "0" ]] && return 0
    if [[ ! -f "${SOURCE_DIR}/video/server/package.json" ]]; then
        [[ "${WITH_VIDEO}" == "1" ]] && warn "В поставке нет видеопортала (${SOURCE_DIR}/video) — раздел «Видео» не устанавливается."
        WITH_VIDEO="0"; VIDEO_REASON="в поставке нет каталога video/"
        return 0
    fi
    if [[ "${INSTALL_MODE}" == "native" && "${OS_FAMILY}" != "debian" ]]; then
        if [[ "${WITH_VIDEO}" == "1" ]]; then warn "Раздел «Видео» на сервере ставится только на Debian и Ubuntu — на ${OS_ID} используйте --mode docker."; fi
        WITH_VIDEO="0"; VIDEO_REASON="на ${OS_ID} раздел «Видео» ставится только в режиме Docker"
        return 0
    fi
    WITH_VIDEO="1"
}

# Пароли прежней установки в Docker — из docker/.env остатков прерванной установки (${APP_DIR}.failed-*)
# или из последней резервной копии (docker.env в архиве; удаление портала без --purge делает её само).
REUSED_DATA="0"; REUSED_ROOT_PW=""; REUSED_APP_SECRET=""
reuse_docker_credentials() {
    local candidates=() f env_file="" tmp
    [[ -n "${PREV_ATTEMPT_DIR}" ]] && candidates+=("${PREV_ATTEMPT_DIR}/docker/.env")
    while IFS= read -r f; do candidates+=("${f}"); done < <(ls -1dt "${APP_DIR}".failed-*/docker/.env 2>/dev/null || true)
    for f in "${candidates[@]}"; do
        [[ -s "${f}" ]] && grep_has '^MYSQL_PASSWORD=' "${f}" && { env_file="${f}"; break; }
    done
    if [[ -z "${env_file}" ]]; then
        local archive
        for archive in $(ls -1t "${BACKUP_DIR}"/"${APP_ID}"-backup-*.tar.gz 2>/dev/null | head -n 5 || true); do
            tmp=$(mktemp)
            if tar -xzf "${archive}" -O --wildcards '*/docker.env' > "${tmp}" 2>/dev/null && grep_has '^MYSQL_PASSWORD=' "${tmp}"; then
                env_file="${tmp}"; info "Пароли прежней установки — из резервной копии $(basename "${archive}")"
                break
            fi
            rm -f "${tmp}"
        done
    fi
    [[ -n "${env_file}" ]] || return 1
    local v
    _env_val() { sed -n "s/^$1=//p" "${env_file}" | tail -n1 | sed -e 's/^"//' -e 's/"$//'; }
    v=$(_env_val MYSQL_PASSWORD); [[ -n "${v}" ]] && DB_PASSWORD="${v}"
    v=$(_env_val MYSQL_USER); [[ -n "${v}" ]] && DB_USER="${v}"
    v=$(_env_val MYSQL_DATABASE); [[ -n "${v}" ]] && DB_NAME="${v}"
    REUSED_ROOT_PW=$(_env_val MYSQL_ROOT_PASSWORD)
    REUSED_APP_SECRET=$(_env_val APP_SECRET)
    unset -f _env_val
    [[ "${env_file}" == /tmp/* ]] && rm -f "${env_file}"
    [[ -n "${REUSED_ROOT_PW}" ]] || return 1
    REUSED_DATA="1"
    warn "Найдены данные прежней установки в томах Docker — они сохранены и используются (база, документы). Пароль администратора — прежний."
    return 0
}

# --- Откат при ошибке -----------------------------------------------------------------
rollback() {
    warn "Откат незавершённой установки…"
    if [[ "${INSTALL_MODE}" == "docker" && "${DOCKER_STARTED}" == "1" && -f "${APP_DIR}/docker/docker-compose.yml" ]]; then
        # Тома с данными прежней установки (REUSED_DATA) не удаляются — только созданные этой установкой.
        if [[ "${REUSED_DATA}" == "1" ]]; then
            (cd "${APP_DIR}/docker" && ${COMPOSE_CMD:-docker compose} down >>"${LOG_FILE}" 2>&1) || true
        else
            (cd "${APP_DIR}/docker" && ${COMPOSE_CMD:-docker compose} down -v >>"${LOG_FILE}" 2>&1) || true
        fi
    fi
    if [[ "${CREATED_NGINX_SITE}" == "1" ]]; then
        rm -f "/etc/nginx/sites-enabled/${APP_ID}.conf" "/etc/nginx/sites-available/${APP_ID}.conf" "/etc/nginx/conf.d/${APP_ID}.conf"
        nginx -t >>"${LOG_FILE}" 2>&1 && svc reload nginx || true
    fi
    # Правило режима обслуживания — после сайта, который его подключал.
    [[ "${CREATED_MAINT_SNIPPET}" == "1" ]] && rm -f "${MAINTENANCE_SNIPPET}" "${MAINTENANCE_SNIPPET}.prev"
    [[ -n "${CREATED_MAINT_DIR}" && "${INSTALL_MODE}" == "native" ]] && rm -rf "${CREATED_MAINT_DIR}"
    if [[ "${CREATED_DB}" == "1" || "${CREATED_DB_USER}" == "1" ]] && [[ "${DB_LOCAL}" == "1" ]]; then
        [[ "${CREATED_DB}" == "1" ]] && mysql_root_exec "DROP DATABASE IF EXISTS \`${DB_NAME}\`;" || true
        [[ "${CREATED_DB_USER}" == "1" ]] && mysql_root_exec "DROP USER IF EXISTS '${DB_USER}'@'localhost'; DROP USER IF EXISTS '${DB_USER}'@'127.0.0.1';" || true
    fi
    if [[ "${CREATED_APP_DIR}" == "1" && -d "${APP_DIR}" ]]; then
        rm -rf "${APP_DIR}"
    fi
    [[ "${CREATED_CONF}" == "1" ]] && rm -f "${CONF_FILE}" || true
    rm -f /etc/cron.d/"${APP_ID}" "/usr/local/bin/${APP_ID}" "/etc/logrotate.d/${APP_ID}"
    if [[ -n "${PHP_POOL_DIR:-}" && -f "${PHP_POOL_DIR}/zz-${APP_ID}.conf" ]]; then
        rm -f "${PHP_POOL_DIR}/zz-${APP_ID}.conf"
        svc restart "${PHP_FPM_SERVICE}" || true
    fi
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
            download https://packages.sury.org/php/apt.gpg /usr/share/keyrings/deb.sury.org-php.gpg || die "Не удалось скачать ключ репозитория sury.org (нет доступа к packages.sury.org)."
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
    PHP_POOL_DIR="/etc/php/${PHP_VERSION}/fpm/pool.d"
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
    PHP_POOL_DIR="/etc/php-fpm.d"
    SERVICE_USER="apache"
    # PHP-FPM должен слушать unix-сокет, доступный nginx.
    sed -i 's|^listen = .*|listen = /run/php-fpm/www.sock|; s|^;listen.owner = .*|listen.owner = nginx|; s|^;listen.group = .*|listen.group = nginx|; s|^listen.acl_users = .*|listen.acl_users = apache,nginx|' /etc/php-fpm.d/www.conf
}

# Необязательные инструменты ставятся только по запросу (--with-preview / --with-ocr / --with-extras).
install_optional_tools() {
    if [[ "${WITH_PREVIEW}" == "1" ]]; then
        if install_preview_tools; then info "Установлен LibreOffice: просмотр офисных файлов в браузере."
        else warn "Не удалось установить LibreOffice — предпросмотр docx/xlsx/pptx будет недоступен."; fi
    fi
    if [[ "${WITH_OCR}" == "1" ]]; then
        if install_ocr_tools; then info "Установлен tesseract: распознавание сканов для поиска (включается в панели администратора)."
        else warn "Не удалось установить tesseract — распознавание сканов будет недоступно."; fi
    fi
}

install_packages_native() {
    step "Установка системных пакетов"
    case "${OS_FAMILY}" in
        debian)
            pkg_install ca-certificates curl gnupg rsync tar gzip unzip cron acl lsb-release git
            pkg_install_optional poppler-utils || warn "Пакет poppler-utils (pdftotext) не установлен — текст из PDF для API и LLM извлекаться не будет."
            pkg_install_optional qpdf || warn "Пакет qpdf не установлен — водяной знак на PDF будет недоступен."
            install_optional_tools
            install_php_debian
            pkg_install nginx
            if [[ "${DB_LOCAL}" == "1" ]]; then pkg_install mariadb-server mariadb-client; DB_SERVICE="mariadb"; else pkg_install mariadb-client; fi
            ;;
        rhel)
            pkg_install ca-certificates curl rsync tar gzip unzip cronie policycoreutils-python-utils git
            pkg_install_optional poppler-utils || warn "Пакет poppler-utils (pdftotext) не установлен — текст из PDF для API и LLM извлекаться не будет."
            pkg_install_optional qpdf || warn "Пакет qpdf не установлен — водяной знак на PDF будет недоступен."
            install_optional_tools
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
; Время жизни сессии: столько же, сколько SESSION_LIFETIME приложения (12 ч). Задание очистки сессий
; Debian/Ubuntu (phpsessionclean) читает значение отсюда — без него сессии удалялись через 24 минуты простоя.
session.gc_maxlifetime = 43200
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
    configure_fpm_pool "${PHP_POOL_DIR}"
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
    raw=$(mysql_exec "${DB_HOST}" "${DB_PORT}" "${DB_USER}" "${DB_PASSWORD}" "SELECT VERSION()" | head -n1 || true)
    [[ -n "${raw}" ]] || die "Сервер базы данных ${DB_HOST}:${DB_PORT} не сообщил версию (SELECT VERSION()). Проверьте права пользователя ${DB_USER} на базу ${DB_NAME}."
    if [[ "${raw}" == *MariaDB* ]]; then
        DB_SERVER_VERSION="$(echo "${raw}" | grep -oE '^[0-9]+\.[0-9]+\.[0-9]+' || echo '10.6.0')-MariaDB"
    else
        DB_SERVER_VERSION="$(echo "${raw}" | grep -oE '^[0-9]+\.[0-9]+\.[0-9]+' || echo '8.0.0')"
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
    local composer_log attempt ok_install="0"
    composer_log=$(mktemp)
    for attempt in 1 2 3; do
        if (cd "${dir}" && COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1 COMPOSER_PROCESS_TIMEOUT=900 timeout 3600 composer install --no-dev --optimize-autoloader --no-progress --prefer-dist --no-audit >"${composer_log}" 2>&1); then
            ok_install="1"; break
        fi
        cat "${composer_log}" >>"${LOG_FILE}"
        [[ ${attempt} -lt 3 ]] && { warn_transient "composer install не удался (попытка ${attempt}/3) — повтор через $((attempt * 20)) с"; sleep $((attempt * 20)); }
    done
    if [[ "${ok_install}" != "1" ]]; then
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
    # Адрес портала для ссылок в письмах и уведомлениях: домен, а без него — IP-адрес сервера.
    url="http://$(portal_host)"; [[ "${HTTP_PORT}" != "80" ]] && url="${url}:${HTTP_PORT}"
    local pw_url
    pw_url=$(php -r 'echo rawurlencode($argv[1]);' "${DB_PASSWORD}")
    umask 027
    cat > "${env_file}" <<EOF
# Локальные настройки портала «${APP_TITLE}» (создано install.sh $(date '+%Y-%m-%d %H:%M')).
# Описание всех параметров — в файле .env и в документации docs/РУКОВОДСТВО.md.
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=${secret}
APP_NAME=$(env_quote "${APP_NAME}")
APP_COMPANY=$(env_quote "${APP_COMPANY}")
APP_DEFAULT_URI=${url}
APP_TIMEZONE=${TIMEZONE}
DATABASE_URL=$(env_quote "mysql://${DB_USER}:${pw_url}@${DB_HOST}:${DB_PORT}/${DB_NAME}?serverVersion=${DB_SERVER_VERSION}&charset=utf8mb4")
STORAGE_DIR=${APP_DIR}/shared/storage
# Каталог импорта: положите сюда папки с документами и запустите импорт в панели администратора.
IMPORT_DIR=${APP_DIR}/shared/import
UPLOAD_MAX_MB=100
TRUSTED_PROXIES=
MAILER_DSN=$(env_quote "${MAIL_DSN:-null://null}")
MAIL_FROM=$(env_quote "${MAIL_FROM:-${APP_NAME} <noreply@${DOMAIN:-localhost}>}")
LDAP_ENABLED=$([[ -n "${LDAP_HOST}" ]] && echo 1 || echo 0)
LDAP_HOST=${LDAP_HOST:-dc.example.local}
LDAP_PORT=${LDAP_PORT}
LDAP_ENCRYPTION=${LDAP_ENCRYPTION}
LDAP_BASE_DN=$(env_quote "${LDAP_BASE_DN:-DC=example,DC=local}")
LDAP_BIND_DN=$(env_quote "${LDAP_BIND_DN}")
LDAP_BIND_PASSWORD=$(env_quote "${LDAP_BIND_PASSWORD}")
LDAP_UPN_SUFFIX=$(env_quote "${LDAP_UPN_SUFFIX}")
LDAP_ADMIN_GROUP=$(env_quote "${LDAP_ADMIN_GROUP}")
LDAP_MARKETER_GROUP=$(env_quote "${LDAP_MARKETER_GROUP}")
LDAP_ASSISTANT_GROUP=$(env_quote "${LDAP_ASSISTANT_GROUP}")
LDAP_USER_GROUP=$(env_quote "${LDAP_USER_GROUP}")
EOF
    umask 022
    # Настройки читает пользователь службы, но изменить их он не может (владелец — root).
    chown "root:${SERVICE_USER}" "${env_file}"
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
    # Код раздела «Видео» читает пользователь службы видеопортала (секретов в нём нет).
    [[ -d "${rel}/video" ]] && chmod -R o+rX "${rel}/video"
    chmod 750 "${APP_DIR}/shared/storage" "${APP_DIR}/shared/log"
    # Настройки читает пользователь службы, но изменить их он не может (владелец — root).
    [[ -f "${APP_DIR}/shared/.env.local" ]] && chown "root:${SERVICE_USER}" "${APP_DIR}/shared/.env.local" && chmod 640 "${APP_DIR}/shared/.env.local"
    # Каталог импорта: группа службы может записывать, новые файлы наследуют группу (setgid).
    chmod 2770 "${APP_DIR}/shared/import"
    chmod +x "${rel}/bin/console" "${rel}"/deploy/*.sh 2>/dev/null || true
    if command -v getenforce >/dev/null 2>&1 && [[ "$(getenforce 2>/dev/null)" == "Enforcing" ]]; then
        info "SELinux в режиме Enforcing — настройка контекстов"
        # Правила по шаблону: каталоги var/ всех будущих релизов (update.sh) получают тот же контекст.
        semanage fcontext -a -t httpd_sys_rw_content_t "${APP_DIR}/shared(/.*)?" >>"${LOG_FILE}" 2>&1 || true
        semanage fcontext -a -t httpd_sys_rw_content_t "${APP_DIR}/releases/[^/]+/var(/.*)?" >>"${LOG_FILE}" 2>&1 || true
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
    # Правило режима обслуживания (страница «Идёт обновление портала» на время миграций) — до сайта:
    # сайт его подключает, и без файла nginx -t не пройдёт.
    local maint_dir
    maint_dir=$(maintenance_dir)
    if [[ ! -d "$(dirname "${maint_dir}")" ]]; then CREATED_MAINT_DIR="$(dirname "${maint_dir}")";
    elif [[ ! -d "${maint_dir}" ]]; then CREATED_MAINT_DIR="${maint_dir}"; fi
    install -d -m 755 "$(dirname "${maint_dir}")" "${maint_dir}"
    maintenance_write_page "" || die "Не удалось подготовить страницу режима обслуживания."
    # SELinux (RHEL): каталог режима должен быть доступен nginx, иначе режим не будет работать.
    maintenance_selinux_label
    write_maintenance_snippet || die "Не удалось записать ${MAINTENANCE_SNIPPET}."
    CREATED_MAINT_SNIPPET="1"
    cat > "${conf_path}" <<EOF
# Портал «${APP_TITLE}» — создано install.sh $(date '+%Y-%m-%d %H:%M')
server {
    listen ${HTTP_PORT};
${LISTEN_V6}    server_name ${server_name};

    root ${APP_DIR}/current/public;
    include ${MAINTENANCE_SNIPPET};
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

    # Без add_header внутри location: иначе nginx не отправляет заголовки безопасности уровня server.
    location ~* \\.(css|js|woff2?|svg|png|jpg|ico)\$ {
        expires 30d;
        access_log off;
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
    if certbot --nginx -d "${DOMAIN}" --non-interactive --agree-tos -m "${SSL_EMAIL}" --redirect >>"${LOG_FILE}" 2>&1 9>&-; then
        SSL_ENABLED="1"
        sed -i "s|^APP_DEFAULT_URI=.*|APP_DEFAULT_URI=https://${DOMAIN}|" "${APP_DIR}/shared/.env.local"
        ok "HTTPS включён: https://${DOMAIN} (сертификат обновляется автоматически)"
    else
        warn "Не удалось получить сертификат (проверьте, что домен ${DOMAIN} указывает на этот сервер и открыт порт 80). Портал работает по HTTP; повторить: certbot --nginx -d ${DOMAIN}"
    fi
}

configure_firewall() {
    # Вызывается до certbot: для проверки домена Let's Encrypt нужен открытый порт 80, для работы — 443.
    local ssl="0"
    [[ -n "${SSL_EMAIL}" || "${SSL_ENABLED}" == "1" ]] && ssl="1"
    if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep_has "Status: active"; then
        info "Открытие порта ${HTTP_PORT} в ufw"
        ufw allow "${HTTP_PORT}/tcp" >>"${LOG_FILE}" 2>&1 || warn "Не удалось открыть порт в ufw."
        if [[ "${ssl}" == "1" ]]; then ufw allow 80/tcp >>"${LOG_FILE}" 2>&1 || true; ufw allow 443/tcp >>"${LOG_FILE}" 2>&1 || true; fi
    elif command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then
        info "Открытие порта ${HTTP_PORT} в firewalld"
        firewall-cmd --permanent --add-port="${HTTP_PORT}/tcp" >>"${LOG_FILE}" 2>&1 || warn "Не удалось открыть порт в firewalld."
        if [[ "${ssl}" == "1" ]]; then
            firewall-cmd --permanent --add-service=http >>"${LOG_FILE}" 2>&1 || true
            firewall-cmd --permanent --add-service=https >>"${LOG_FILE}" 2>&1 || true
        fi
        firewall-cmd --reload >>"${LOG_FILE}" 2>&1 || true
    fi
}


install_backup_cron() {
    # Native: задания приложения (+ резервная копия); Docker: только резервная копия — задания
    # приложения выполняет контейнер cron (см. docker/entrypoint.sh).
    write_cron_file "${BACKUP_CRON}"
    install_logrotate
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
        collect_diagnostics
        return 1
    fi
}


install_native() {
    install_packages_native
    configure_php_native
    setup_database_native

    step "Копирование файлов проекта в ${APP_DIR}"
    prepare_app_dir
    mkdir -p "${APP_DIR}/releases" "${APP_DIR}/shared"
    local release
    release="${APP_DIR}/releases/${APP_VERSION}-$(date '+%Y%m%d%H%M%S')"
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
    configure_firewall
    configure_ssl_native
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
                if install -m 0755 -d /etc/apt/keyrings && download "https://download.docker.com/linux/${OS_ID}/gpg" /etc/apt/keyrings/docker.asc; then
                    chmod a+r /etc/apt/keyrings/docker.asc
                    # shellcheck disable=SC1091
                    local codename; codename=$(. /etc/os-release && echo "${VERSION_CODENAME:-}")
                    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/${OS_ID} ${codename} stable" > /etc/apt/sources.list.d/docker.list
                    _APT_UPDATED=0
                    apt_wait_lock
                    if apt_get update -q >>"${LOG_FILE}" 2>&1 9>&- && apt_get install -y -q docker-ce docker-ce-cli containerd.io docker-compose-plugin >>"${LOG_FILE}" 2>&1 9>&-; then
                        installed="1"
                    else
                        rm -f /etc/apt/sources.list.d/docker.list; _APT_UPDATED=0
                    fi
                fi
                if [[ "${installed}" != "1" ]]; then
                    warn "Официальный репозиторий Docker недоступен, устанавливается docker.io из репозитория ОС."
                    pkg_install docker.io
                    apt_get install -y -q docker-compose-v2 >>"${LOG_FILE}" 2>&1 9>&- || apt_get install -y -q docker-compose-plugin >>"${LOG_FILE}" 2>&1 9>&- || warn "Плагин docker compose не установлен из репозитория ОС."
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
    # Демон Docker после запуска отвечает не сразу; остановленный — запускается.
    if ! docker info >/dev/null 2>&1; then
        svc enable docker || true
        local n=0
        until docker info >/dev/null 2>&1; do
            n=$((n + 1)); [[ ${n} -gt 30 ]] && die "Служба Docker не отвечает (docker info). Проверьте: systemctl status docker; journalctl -u docker."
            sleep 2
        done
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
    prepare_app_dir
    rsync -a --exclude '/var/' --exclude '/.env.local' --exclude '/.git/' --exclude '/docker/.env' --exclude '/docker/mysql-data/' --exclude '/maintenance/' "${SOURCE_DIR}/" "${APP_DIR}/" >>"${LOG_FILE}" 2>&1 || die "Не удалось скопировать файлы проекта."
    chmod +x "${APP_DIR}"/deploy/*.sh "${APP_DIR}/docker/entrypoint.sh" "${APP_DIR}/bin/console" 2>/dev/null || true
    # Каталог режима обслуживания подключается в контейнер web: страница «Идёт обновление портала».
    maintenance_write_page "" || warn "Не удалось подготовить страницу режима обслуживания."

    step "Создание конфигурации docker/.env"
    [[ -n "${DB_PASSWORD}" ]] || DB_PASSWORD=$(gen_password 24)
    # docker-compose.yml подставляет пароль в DATABASE_URL как есть — символы @ : / ? # % его ломают.
    [[ "${DB_PASSWORD}" =~ ^[A-Za-z0-9._~-]+$ ]] || die "--db-password: в режиме docker допустимы латинские буквы, цифры и символы . _ ~ -"
    if [[ -z "${ADMIN_PASSWORD}" ]]; then ADMIN_PASSWORD=$(gen_password 14); ADMIN_GENERATED="1"; fi
    local root_pw secret url
    root_pw=$(gen_password 24); secret=$(gen_secret_hex)
    url="http://$(portal_host)"; [[ "${HTTP_PORT}" != "80" ]] && url="${url}:${HTTP_PORT}"
    # Том базы остался от прежней установки (удаление без --purge или прерванная установка): MySQL
    # не примет новые пароли — используются прежние (из остатков установки или резервной копии).
    if docker volume inspect "${APP_ID}_db-data" >/dev/null 2>&1; then
        reuse_docker_credentials || die "Остался том базы данных прежней установки (${APP_ID}_db-data), а её пароли не найдены (нет прежнего docker/.env и резервной копии с docker.env в ${BACKUP_DIR}). Чтобы начать с чистой базы, удалите тома: docker volume rm ${APP_ID}_db-data ${APP_ID}_storage — или восстановите прежнюю установку из резервной копии."
        root_pw="${REUSED_ROOT_PW}"; secret="${REUSED_APP_SECRET:-${secret}}"
    fi
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
APP_NAME=$(compose_env_value "${APP_NAME}")
APP_COMPANY=$(compose_env_value "${APP_COMPANY}")
APP_DEFAULT_URI=${url}
APP_TIMEZONE=${TIMEZONE}
UPLOAD_MAX_MB=100
# nginx передаёт запросы в PHP-FPM по FastCGI с настоящим адресом клиента, поэтому прокси по умолчанию не нужны.
# Если перед порталом стоит ещё один обратный прокси — укажите здесь его адрес (иначе клиенты локальной
# сети смогут подменять свой IP заголовком X-Forwarded-For и обходить ограничения доступа по IP).
TRUSTED_PROXIES=
MAILER_DSN=$(compose_env_value "${MAIL_DSN:-null://null}")
MAIL_FROM=$(compose_env_value "${MAIL_FROM:-${APP_NAME} <noreply@${DOMAIN:-localhost}>}")
LDAP_ENABLED=$([[ -n "${LDAP_HOST}" ]] && echo 1 || echo 0)
LDAP_HOST=${LDAP_HOST:-dc.example.local}
LDAP_PORT=${LDAP_PORT}
LDAP_ENCRYPTION=${LDAP_ENCRYPTION}
LDAP_BASE_DN=$(compose_env_value "${LDAP_BASE_DN:-DC=example,DC=local}")
LDAP_BIND_DN=$(compose_env_value "${LDAP_BIND_DN}")
LDAP_BIND_PASSWORD=$(compose_env_value "${LDAP_BIND_PASSWORD}")
LDAP_UPN_SUFFIX=$(compose_env_value "${LDAP_UPN_SUFFIX}")
LDAP_ADMIN_GROUP=$(compose_env_value "${LDAP_ADMIN_GROUP}")
LDAP_MARKETER_GROUP=$(compose_env_value "${LDAP_MARKETER_GROUP}")
LDAP_ASSISTANT_GROUP=$(compose_env_value "${LDAP_ASSISTANT_GROUP}")
LDAP_USER_GROUP=$(compose_env_value "${LDAP_USER_GROUP}")

# Первый администратор создаётся при первом запуске; пароль удаляется из файла после успешной установки.
ADMIN_USER=${ADMIN_USER}
ADMIN_PASSWORD=$(compose_env_value "${ADMIN_PASSWORD}")
EOF
    umask 022
    chmod 600 "${APP_DIR}/docker/.env"
    # Каталог импорта на сервере (подключается в контейнеры как /var/www/html/var/import; uid 82 = www-data в alpine).
    mkdir -p "${APP_DIR}/import" && chown 82:82 "${APP_DIR}/import" && chmod 2775 "${APP_DIR}/import"
    DB_HOST="db"; DB_LOCAL="0"; DB_SERVICE="docker"; SERVICE_USER="www-data"; PHP_VERSION="8.4"; PHP_FPM_SERVICE="docker"; PHP_FPM_SOCK=""; DB_SERVER_VERSION="8.0.0"

    step "Сборка образа и запуск контейнеров (может занять несколько минут)"
    # Загрузка образов прерывается при сбоях сети и ограничениях Docker Hub — до трёх попыток.
    retry 3 30 bash -c "cd '${APP_DIR}/docker' && ${COMPOSE_CMD} build --pull >>'${LOG_FILE}' 2>&1" \
        || die "Сборка образа не удалась. Если Docker Hub недоступен, укажите зеркало: --docker-mirror https://mirror.gcr.io (см. журнал ${LOG_FILE})."
    DOCKER_STARTED="1"
    retry 3 15 bash -c "cd '${APP_DIR}/docker' && ${COMPOSE_CMD} up -d >>'${LOG_FILE}' 2>&1" || die "Не удалось запустить контейнеры. См. журнал ${LOG_FILE}"
    ok "Контейнеры запущены"

    install_cli_wrapper
    install_backup_cron
    configure_firewall
    health_check || {
        (cd "${APP_DIR}/docker" && ${COMPOSE_CMD} logs --tail=100 app >>"${LOG_FILE}" 2>&1) || true
        die "Контейнеры запущены, но портал не отвечает. Журнал контейнера: cd ${APP_DIR}/docker && ${COMPOSE_CMD} logs app"
    }
    # Администратор создан — пароль в docker/.env больше не нужен (он выведен в конце установки и
    # сохранён в ${CONF_DIR}/admin-credentials.txt).
    sed -i '/^ADMIN_PASSWORD=/d' "${APP_DIR}/docker/.env" || true
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
    if [[ "${REUSED_DATA}" == "1" ]]; then
        printf ' Пароль:             прежний (данные прежней установки сохранены)\n'
    else
        printf ' Пароль:             %s%s%s  %s\n' "${C_BOLD}" "${ADMIN_PASSWORD}" "${C_RESET}" "$([[ "${ADMIN_GENERATED:-0}" == "1" ]] && echo '(сгенерирован)')"
    fi
    printf ' Режим:              %s\n' "${INSTALL_MODE}"
    printf ' Каталог:            %s\n' "${APP_DIR}"
    printf ' Параметры:          %s\n' "${CONF_FILE}"
    printf ' Учётные данные:     %s (удалите после первого входа)\n' "${CONF_DIR}/admin-credentials.txt"
    printf ' Журнал установки:   %s\n' "${LOG_FILE}"
    printf ' Управление:         %s status | update | auto-update | backup | restore | maintenance | video | console | logs | uninstall\n' "${APP_ID}"
    case "${VIDEO_RESULT:-}" in
        ok) printf ' Раздел «Видео»:     %s/video/ (вход — через портал)\n' "${url}" ;;
        error) printf ' Раздел «Видео»:     %sне включён%s — см. предупреждение выше; повторить: %s video enable\n' "${C_YELLOW}" "${C_RESET}" "${APP_ID}" ;;
        *) [[ -n "${VIDEO_REASON}" ]] && printf ' Раздел «Видео»:     не установлен — %s; включить: %s video enable\n' "${VIDEO_REASON}" "${APP_ID}" ;;
    esac
    if [[ "${AUTO_UPDATE_RESULT:-}" == "ok" ]]; then
        local au_src="архив в /var/lib/${APP_ID}/updates"
        [[ -n "${AUTO_UPDATE_GITHUB}" ]] && au_src="релизы GitHub ${AUTO_UPDATE_GITHUB}"
        [[ -n "${AUTO_UPDATE_URL}" ]] && au_src="${AUTO_UPDATE_URL}"
        printf ' Автообновление:     ежедневно в 04:30, источник — %s (%s auto-update status)\n' "${au_src}" "${APP_ID}"
    fi
    printf '%s\n' "=============================================================="
    printf ' Дальше: войдите под администратором, смените пароль (шапка → имя пользователя),\n'
    printf ' создайте разделы и назначьте модераторов в «Панель администратора → Разделы и модераторы».\n'
    printf ' Роли (Панель администратора → Пользователи): маркетолог — модератор всех разделов и видео;\n'
    printf ' помощник маркетолога — добавляет материалы, они публикуются после модерации; пользователь — просмотр.\n'
    [[ -n "${LDAP_HOST}" ]] && printf ' Проверьте вход через домен: %s console app:ldap:test ЛОГИН\n' "${APP_ID}"
    print_warnings_summary
    printf '\n'
}

main() {
    parse_args "$@"
    banner "установка"
    log_init install "$@"
    validate_args
    preflight "$@"
    prepare_source
    decide_video
    setup_traps

    acquire_lock 0
    info "Режим: ${INSTALL_MODE}; каталог: ${APP_DIR}; порт: ${HTTP_PORT}; домен: ${DOMAIN:-не задан}; раздел «Видео»: $([[ "${WITH_VIDEO}" == "1" ]] && echo 'да' || echo "нет${VIDEO_REASON:+ (${VIDEO_REASON})}")"
    if [[ "${ASSUME_YES}" != "1" ]]; then
        confirm "Начать установку?" || die "Установка отменена."
    fi
    # Малая память: файл подкачки до установки пакетов (MariaDB, Composer, сборка и обработка видео).
    ensure_swap 2048 2048

    ROLLBACK_ENABLED="1"
    if [[ "${INSTALL_MODE}" == "native" ]]; then
        install_native
    else
        install_docker
    fi

    INSTALLED_AT="$(date '+%Y-%m-%d %H:%M:%S')"
    # uninstall.sh --purge удаляет базу и пользователя БД, только если их создала установка.
    DB_CREATED_BY_INSTALL="${CREATED_DB}"; DB_USER_CREATED_BY_INSTALL="${CREATED_DB_USER}"
    [[ "${INSTALL_MODE}" == "docker" ]] && { DB_CREATED_BY_INSTALL="1"; DB_USER_CREATED_BY_INSTALL="1"; }
    [[ "${VIDEO_OPT_OUT}" == "1" ]] && VIDEO_ENABLED="0"
    save_conf; CREATED_CONF="1"
    ROLLBACK_ENABLED="0"
    trap - ERR
    set +e
    # Раздел «Видео» ставится после портала отдельным шагом: его ошибка (нет доступа к npm, Docker Hub,
    # github.com) не отменяет установку портала — раздел можно включить позже командой «${APP_ID} video enable».
    VIDEO_RESULT=""
    if [[ "${WITH_VIDEO}" == "1" ]]; then
        step "Раздел «Видео»"
        local video_args=(enable)
        [[ "${VIDEO_LIVE}" == "1" ]] || video_args+=(--no-live)
        [[ "${VIDEO_BACKUP_MEDIA}" == "1" ]] && video_args+=(--backup-media)
        # Сетевые сбои (npm, Docker Hub, github.com) часто временные — вторая попытка через минуту.
        local attempt
        for attempt in 1 2; do
            if NO_SWAP="${NO_SWAP}" bash "${SCRIPT_DIR}/video.sh" "${video_args[@]}"; then
                VIDEO_RESULT="ok"; break
            fi
            VIDEO_RESULT="error"
            [[ ${attempt} -lt 2 ]] && { warn_transient "Раздел «Видео» не включился — повтор через 60 с"; sleep 60; }
        done
        if [[ "${VIDEO_RESULT}" == "error" ]]; then
            warn "Портал установлен, но раздел «Видео» включить не удалось (журнал — ${LOG_DIR}/video-enable-*.log). Исправьте причину и выполните: ${APP_ID} video enable"
        fi
    fi
    # Автоматическое обновление по расписанию: ошибка здесь — предупреждение (портал уже установлен).
    AUTO_UPDATE_RESULT=""
    if [[ "${AUTO_UPDATE_ON}" == "1" ]]; then
        local au_args=(on)
        [[ -n "${AUTO_UPDATE_GITHUB}" ]] && au_args+=(--github "${AUTO_UPDATE_GITHUB}")
        [[ -n "${AUTO_UPDATE_URL}" ]] && au_args+=(--url "${AUTO_UPDATE_URL}")
        [[ -n "${AUTO_UPDATE_TOKEN_FILE}" ]] && au_args+=(--token-file "${AUTO_UPDATE_TOKEN_FILE}")
        if bash "${SCRIPT_DIR}/auto-update.sh" "${au_args[@]}" >>"${LOG_FILE}" 2>&1; then
            AUTO_UPDATE_RESULT="ok"
        else
            AUTO_UPDATE_RESULT="error"
            warn "Автоматическое обновление не включено (см. ${LOG_FILE}). Включить: ${APP_ID} auto-update on"
        fi
    fi
    [[ -n "${CREATED_TMP}" ]] && rm -rf "${CREATED_TMP}" || true
    print_summary
    exit 0
}

main "$@"
