#!/usr/bin/env bash
# =============================================================================
#  Раздел «Видео» портала документации: встроенный видеопортал (CorpVideo) по адресу /video.
#
#  Использование: docportal video <команда>   (или: sudo ./deploy/video.sh <команда>)
#    enable [--no-live] [--backup-media]   установить и включить раздел «Видео»
#    status                                состояние раздела
#    restart                               перезапустить видеопортал
#    logs [api|worker|mediamtx]            журнал видеопортала
#    disable                               выключить раздел (видео и база сохраняются)
#
#  Вход общий с порталом: видеопортал проверяет сессию портала по внутреннему адресу
#  (127.0.0.1:8079 на сервере, web:8079 в Docker), запрос подписан общим секретом. Роли — из портала:
#  маркетолог — модератор видео, помощник маркетолога загружает видео (публикуются после модерации),
#  пользователь смотрит одобренные.
#
#  Файл также подключается из update.sh, backup.sh, restore.sh, uninstall.sh и doctor.sh (функции video_*).
# =============================================================================

VIDEO_SH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if ! declare -F load_conf >/dev/null 2>&1; then
    # shellcheck source=deploy/common.sh
    . "${VIDEO_SH_DIR}/common.sh"
fi

# --- Константы ------------------------------------------------------------------
VIDEO_USER="${APP_ID}-video"
VIDEO_ENV="${CONF_DIR}/video.env"
VIDEO_LOG_DIR="${LOG_DIR}/video"
VIDEO_UNIT_API="${APP_ID}-video-api"
VIDEO_UNIT_WORKER="${APP_ID}-video-worker"
VIDEO_UNIT_MEDIAMTX="${APP_ID}-video-mediamtx"
VIDEO_DEFAULT_PORT="3100"
VIDEO_DEFAULT_INTERNAL_PORT="8079"
VIDEO_DEFAULT_DATA_DIR="/var/lib/${APP_ID}-video"
VIDEO_MEDIAMTX_VERSION="1.9.3"
VIDEO_NODE_MAJOR="22"
VIDEO_DB_NAME="corpvideo"
VIDEO_DB_USER="corpvideo"
VIDEO_NGINX_DIR="/etc/nginx/${APP_ID}-video"
VIDEO_NGINX_MAPS="/etc/nginx/conf.d/${APP_ID}-video-maps.conf"
VIDEO_NGINX_INTERNAL="/etc/nginx/conf.d/${APP_ID}-video-internal.conf"
VIDEO_MEDIAMTX_CONF="${CONF_DIR}/video-mediamtx.yml"

video_enabled() { [[ "${VIDEO_ENABLED:-0}" == "1" ]]; }
video_path() { printf '%s' "${VIDEO_PATH:-/video}"; }
video_port() { printf '%s' "${VIDEO_PORT:-${VIDEO_DEFAULT_PORT}}"; }
# Порт внутреннего сервера nginx для проверки сессии портала (на сервере; в Docker — всегда web:8079).
video_internal_port() { printf '%s' "${VIDEO_INTERNAL_PORT:-${VIDEO_DEFAULT_INTERNAL_PORT}}"; }
video_data_dir() { printf '%s' "${VIDEO_DATA_DIR:-${VIDEO_DEFAULT_DATA_DIR}}"; }

# Каталог кода видеопортала: в релизе (на сервере) или в каталоге установки (Docker).
video_code_dir() {
    if [[ "${INSTALL_MODE}" == "docker" ]]; then printf '%s/video' "${APP_DIR}"; else printf '%s/current/video' "${APP_DIR}"; fi
}

# Шаблон → файл: ${ИМЯ} заменяется значением переменной ИМЯ (только перечисленные). Знаки & и \ в
# значениях подставляются буквально (замена в кавычках не зависит от patsub_replacement в bash 5.2).
video_render() {
    local src=$1 dest=$2 content var val pattern tmp
    shift 2
    content=$(<"${src}")
    for var in "$@"; do
        val="${!var-}"
        pattern="\${${var}}"
        content=${content//"${pattern}"/"${val}"}
    done
    tmp="${dest}.tmp.$$"
    printf '%s\n' "${content}" > "${tmp}" && mv -f "${tmp}" "${dest}"
}

# Значение из файла KEY=VALUE (кавычки вокруг значения снимаются).
video_env_get() {
    local file=$1 key=$2 line
    [[ -r "${file}" ]] || return 0
    line=$(grep -E "^${key}=" "${file}" 2>/dev/null | tail -n1 || true)
    line=${line#*=}
    line=${line%\"}; line=${line#\"}; line=${line%\'}; line=${line#\'}
    printf '%s' "${line}"
}

# Адрес портала (APP_DEFAULT_URI) — из него строится адрес раздела «Видео» для писем и ссылок.
video_portal_url() {
    local url=""
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        url=$(video_env_get "${APP_DIR}/docker/.env" APP_DEFAULT_URI)
    else
        url=$(video_env_get "${APP_DIR}/shared/.env.local" APP_DEFAULT_URI)
    fi
    url=${url%/}
    printf '%s' "${url:-http://localhost}"
}

# Имя сервера для адресов трансляций (RTMP, SRT, WebRTC): из адреса портала.
video_public_host() {
    local url host
    url=$(video_portal_url)
    host=${url#*://}; host=${host%%/*}; host=${host%%:*}
    printf '%s' "${host:-localhost}"
}

video_secret() { LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c "${1:-48}" || true; }

# --- Ресурсы и порты -------------------------------------------------------------------
# Хватает ли серверу ресурсов для раздела «Видео» (автоматическая установка при установке и обновлении
# портала). Причина отказа — в VIDEO_REASON.
VIDEO_REASON=""
video_resources_ok() {
    local mem swap free
    mem=$(mem_total_mb); swap=$(swap_total_mb)
    free=$(free_space_mb /var/lib 2>/dev/null || free_space_mb /)
    if [[ -n "${free}" && ${free} -lt 8192 ]]; then
        VIDEO_REASON="свободно ${free} МБ, а для раздела «Видео» нужно не меньше 8 ГБ"; return 1
    fi
    if [[ ${mem} -gt 0 && $((mem + swap)) -lt 1800 && "${NO_SWAP:-0}" == "1" ]]; then
        VIDEO_REASON="памяти ${mem} МБ (с подкачкой ${swap} МБ), а для обработки видео нужно не меньше 2 ГБ"; return 1
    fi
    return 0
}

# Порты видеопортала на сервере: API (3100) и внутренняя проверка сессии (8079). Занятые другой программой
# заменяются ближайшими свободными (запоминаются в ${CONF_FILE}); порты уже работающего видеопортала остаются.
video_choose_ports() {
    local p
    if [[ -z "${VIDEO_PORT:-}" ]]; then
        p=${VIDEO_DEFAULT_PORT}
        if port_busy "${p}" && ! systemctl is-active --quiet "${VIDEO_UNIT_API}" 2>/dev/null; then
            p=$(free_port $((p + 1))) || die "Нет свободного порта для API видеопортала (${VIDEO_DEFAULT_PORT}–$((VIDEO_DEFAULT_PORT + 100)))."
            warn "Порт ${VIDEO_DEFAULT_PORT} занят другой программой — API видеопортала будет слушать 127.0.0.1:${p}."
        fi
        VIDEO_PORT=${p}
    fi
    if [[ -z "${VIDEO_INTERNAL_PORT:-}" ]]; then
        p=${VIDEO_DEFAULT_INTERNAL_PORT}
        if port_busy "${p}" && ! grep_has "listen 127.0.0.1:${p};" "${VIDEO_NGINX_INTERNAL}"; then
            p=$(free_port $((p + 1))) || die "Нет свободного порта для проверки сессии портала (${VIDEO_DEFAULT_INTERNAL_PORT}–$((VIDEO_DEFAULT_INTERNAL_PORT + 100)))."
            warn "Порт ${VIDEO_DEFAULT_INTERNAL_PORT} занят другой программой — проверка сессии портала будет на 127.0.0.1:${p}."
        fi
        VIDEO_INTERNAL_PORT=${p}
    fi
}

# Порты сервера трансляций заняты другой программой — раздел включается без прямых трансляций
# (видео, загрузка и просмотр работают), а не завершается ошибкой.
video_check_live_ports() {
    [[ "${VIDEO_LIVE:-1}" == "1" ]] || return 0
    local busy=() p
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        video_compose ps --status running mediamtx 2>/dev/null | grep_has mediamtx && return 0
        for p in 1935 8189; do port_busy "${p}" tcp && busy+=("${p}/tcp"); done
    else
        systemctl is-active --quiet "${VIDEO_UNIT_MEDIAMTX}" 2>/dev/null && return 0
        for p in 1935 8189 8888 8889 9996 9997; do port_busy "${p}" tcp && busy+=("${p}/tcp"); done
    fi
    for p in 8890 8189; do port_busy "${p}" udp && busy+=("${p}/udp"); done
    if [[ ${#busy[@]} -gt 0 ]]; then
        VIDEO_LIVE="0"
        warn "Порты сервера трансляций заняты другой программой (${busy[*]}) — раздел «Видео» включается без прямых трансляций. Освободите порты и выполните: ${APP_ID} video enable --live"
    fi
}

# После включения: портал принимает проверку сессии видеопортала (несколько попыток — службы запускаются).
video_verify_session() {
    local n
    for n in 1 2 3 4 5; do
        video_session_ok && { ok "Общий вход: портал принимает проверку сессии видеопортала"; return 0; }
        sleep 3
    done
    warn "Портал пока не отвечает на проверку сессии видеопортала — вход в разделе «Видео» может не работать. Проверьте: ${APP_ID} video status (секреты VIDEO_SHARED_SECRET и PORTAL_SHARED_SECRET должны совпадать)."
    return 1
}

# --- Node.js и зависимости ---------------------------------------------------------
video_node_ok() {
    command -v node >/dev/null 2>&1 || return 1
    local v
    v=$(node -v 2>/dev/null | tr -d v || true)
    version_ge "${v:-0}" "20.12.0"
}

video_ensure_node() {
    if video_node_ok; then ok "Node.js $(node -v)"; return 0; fi
    info "Установка Node.js ${VIDEO_NODE_MAJOR} (репозиторий NodeSource)…"
    pkg_install curl ca-certificates gnupg
    local ns
    ns=$(mktemp)
    if download "https://deb.nodesource.com/setup_${VIDEO_NODE_MAJOR}.x" "${ns}"; then
        bash "${ns}" >>"${LOG_FILE}" 2>&1 9>&- || warn "Скрипт NodeSource завершился с ошибкой (см. журнал)."
        _APT_UPDATED=0
        pkg_install_optional nodejs || true
    fi
    rm -f "${ns}"
    if ! video_node_ok; then
        warn "NodeSource недоступен — пробуем пакет nodejs из репозитория ОС."
        pkg_install_optional nodejs npm || true
    fi
    video_node_ok || die "Нужен Node.js 20.12 или новее. Установите его вручную (https://nodejs.org/) и повторите: ${APP_ID} video enable"
    ok "Node.js $(node -v)"
}

# npm с повторами: в корпоративных сетях загрузка пакетов часто обрывается.
video_npm() {
    local dir=$1 try; shift
    export NPM_CONFIG_FETCH_RETRIES="${NPM_CONFIG_FETCH_RETRIES:-5}" NPM_CONFIG_FETCH_RETRY_MAXTIMEOUT="${NPM_CONFIG_FETCH_RETRY_MAXTIMEOUT:-120000}" NPM_CONFIG_FETCH_TIMEOUT="${NPM_CONFIG_FETCH_TIMEOUT:-600000}"
    command -v npm >/dev/null 2>&1 || return 1
    for try in 1 2 3; do
        if (cd "${dir}" && npm "$@" --no-audit --no-fund >>"${LOG_FILE}" 2>&1 9>&-); then return 0; fi
        warn "npm $1: попытка ${try} не удалась — повтор через 5 с"
        sleep 5
    done
    return 1
}

# Отпечаток исходников веб-интерфейса: если он не изменился, готовый web/dist берётся из прежнего релиза.
video_web_fingerprint() {
    local web=$1
    (cd "${web}" && find src public index.html vite.config.js package.json package-lock.json -type f 2>/dev/null | LC_ALL=C sort | xargs -r sha256sum 2>/dev/null | sha256sum | cut -d' ' -f1)
}

# Зависимости сервера и собранный веб-интерфейс в каталоге релиза.
#   video_prepare_code КАТАЛОГ_VIDEO [КАТАЛОГ_VIDEO_ПРЕЖНЕГО_РЕЛИЗА]
# Дистрибутив (архив) обычно уже содержит server/node_modules и web/dist, собранный для префикса /video —
# тогда npm и доступ в интернет не нужны.
video_prepare_code() {
    local code=$1 prev=${2:-} base fp
    base="$(video_path)/"
    [[ -f "${code}/server/package.json" ]] || die "В поставке нет видеопортала (${code}/server). Используйте архив дистрибутива 1.8.0 или новее."
    if [[ -d "${code}/server/node_modules/fastify" ]]; then
        ok "Зависимости сервера видеопортала — из поставки"
    elif [[ -n "${prev}" && -d "${prev}/server/node_modules/fastify" ]] && cmp -s "${prev}/server/package-lock.json" "${code}/server/package-lock.json"; then
        cp -a "${prev}/server/node_modules" "${code}/server/node_modules" || die "Не удалось скопировать зависимости из ${prev}."
        ok "Зависимости сервера видеопортала — из прежнего релиза (package-lock.json не изменился)"
    else
        info "Загрузка зависимостей сервера видеопортала (npm ci)…"
        video_npm "${code}/server" ci --omit=dev || die "Не удалось загрузить зависимости видеопортала (нужен доступ к registry.npmjs.org). Без доступа в интернет ставьте из архива дистрибутива: в нём зависимости уже есть."
        ok "Зависимости сервера видеопортала загружены"
    fi
    if [[ -f "${code}/web/dist/index.html" && "$(cat "${code}/web/dist/.base" 2>/dev/null)" == "${base}" ]]; then
        ok "Веб-интерфейс видеопортала — из поставки (собран для ${base})"
    elif [[ -n "${prev}" && -f "${prev}/web/dist/index.html" && "$(cat "${prev}/web/dist/.base" 2>/dev/null)" == "${base}" ]] \
         && fp=$(video_web_fingerprint "${code}/web") && [[ -n "${fp}" && "${fp}" == "$(cat "${prev}/web/dist/.source" 2>/dev/null)" ]]; then
        rm -rf "${code}/web/dist" && cp -a "${prev}/web/dist" "${code}/web/dist"
        ok "Веб-интерфейс видеопортала — из прежнего релиза (исходники не изменились)"
    else
        info "Сборка веб-интерфейса видеопортала (npm ci && vite build, несколько минут)…"
        ensure_swap 2048 2048
        video_npm "${code}/web" ci || die "Не удалось загрузить зависимости веб-интерфейса видеопортала (нужен доступ к registry.npmjs.org)."
        local mem
        mem=$(awk '/MemAvailable/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 0)
        if [[ ${mem:-0} -gt 0 && ${mem} -lt 1400 ]]; then export NODE_OPTIONS="--max-old-space-size=$(( mem > 700 ? mem - 200 : 512 ))"; fi
        (cd "${code}/web" && VITE_BASE="${base}" npm run build >>"${LOG_FILE}" 2>&1 9>&-) || die "Сборка веб-интерфейса видеопортала не удалась (см. ${LOG_FILE}; на маленьких серверах добавьте swap)."
        unset NODE_OPTIONS
        printf '%s' "${base}" > "${code}/web/dist/.base"
        video_web_fingerprint "${code}/web" > "${code}/web/dist/.source" || true
        rm -rf "${code}/web/node_modules"
        ok "Веб-интерфейс видеопортала собран"
    fi
    # Код видеопортала (в нём нет секретов) читает пользователь службы видеопортала.
    chmod -R o+rX "${code}" 2>/dev/null || true
    local d=${code}
    while [[ "${d}" != "/" && "${d}" != "${APP_DIR}" ]]; do d=$(dirname "${d}"); chmod o+x "${d}" 2>/dev/null || true; done
}

# --- PostgreSQL (сервер) ---------------------------------------------------------------
video_pg_ready() { runuser -u postgres -- psql -tAc 'SELECT 1' >/dev/null 2>&1 9>&-; }

video_setup_postgres() {
    local password=$1
    # Debian не создаёт кластер, если при установке пакета не было подходящей локали, — создаём сами.
    if command -v pg_lsclusters >/dev/null 2>&1 && [[ -z "$(pg_lsclusters -h 2>/dev/null)" ]]; then
        local pgver
        pgver=$(ls /usr/lib/postgresql 2>/dev/null | sort -V | tail -n1 || true)
        if [[ -n "${pgver}" ]]; then
            info "Кластер PostgreSQL не найден — создаётся ${pgver}/main…"
            LANG=C.UTF-8 pg_createcluster --locale C.UTF-8 --start "${pgver}" main >>"${LOG_FILE}" 2>&1 9>&- \
                || LANG=C.UTF-8 pg_createcluster --start "${pgver}" main >>"${LOG_FILE}" 2>&1 9>&- || warn "Не удалось создать кластер PostgreSQL (pg_createcluster)."
        fi
    fi
    svc enable postgresql || true
    local n=0
    until video_pg_ready; do
        n=$((n + 1))
        # Служба postgresql в Debian — обёртка: запускаем остановленные кластеры напрямую.
        if [[ ${n} -eq 10 ]] && command -v pg_lsclusters >/dev/null 2>&1; then
            { pg_lsclusters -h 2>/dev/null | awk '$4 != "online" {print $1, $2}' | while read -r v c; do
                pg_ctlcluster "${v}" "${c}" start >>"${LOG_FILE}" 2>&1 9>&- || true
            done; } || true
        fi
        [[ ${n} -gt 30 ]] && die "PostgreSQL не запустился (systemctl status postgresql; pg_lsclusters; журнал /var/log/postgresql/)."
        sleep 2
    done
    # Порт кластера по умолчанию — 5432, но при нескольких версиях PostgreSQL бывает и 5433.
    VIDEO_DB_PORT=$(runuser -u postgres -- psql -tAc 'SHOW port' 2>/dev/null 9>&- | tr -d '[:space:]' || true)
    [[ "${VIDEO_DB_PORT}" =~ ^[0-9]+$ ]] || VIDEO_DB_PORT=5432
    if runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='${VIDEO_DB_USER}'" 2>/dev/null | grep_has 1; then
        runuser -u postgres -- psql -q -c "ALTER ROLE ${VIDEO_DB_USER} LOGIN PASSWORD '${password}'" >>"${LOG_FILE}" 2>&1 9>&- || die "Не удалось задать пароль роли ${VIDEO_DB_USER}."
    else
        runuser -u postgres -- psql -q -c "CREATE ROLE ${VIDEO_DB_USER} LOGIN PASSWORD '${password}'" >>"${LOG_FILE}" 2>&1 9>&- || die "Не удалось создать роль ${VIDEO_DB_USER}."
    fi
    if ! runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_database WHERE datname='${VIDEO_DB_NAME}'" 2>/dev/null | grep_has 1; then
        runuser -u postgres -- psql -q -c "CREATE DATABASE ${VIDEO_DB_NAME} OWNER ${VIDEO_DB_USER} ENCODING 'UTF8' TEMPLATE template0" >>"${LOG_FILE}" 2>&1 9>&- || die "Не удалось создать базу ${VIDEO_DB_NAME}."
    fi
    # Расширения (citext, pg_trgm, unaccent) создаёт первая миграция — от владельца базы это разрешено
    # не везде, поэтому создаём их заранее от имени postgres.
    runuser -u postgres -- psql -q -d "${VIDEO_DB_NAME}" -c 'CREATE EXTENSION IF NOT EXISTS citext; CREATE EXTENSION IF NOT EXISTS pg_trgm; CREATE EXTENSION IF NOT EXISTS unaccent;' >>"${LOG_FILE}" 2>&1 9>&- \
        || warn "Не удалось создать расширения PostgreSQL заранее (их попробует создать миграция)."
    ok "База ${VIDEO_DB_NAME} (PostgreSQL) готова"
}

# --- Конфигурация видеопортала (сервер) --------------------------------------------------
video_write_env_native() {
    local code=$1 db_password=$2 shared_secret=$3
    local url host secret hook port data
    url="$(video_portal_url)$(video_path)"
    host=$(video_public_host)
    port=$(video_port); data=$(video_data_dir)
    secret=$(video_env_get "${VIDEO_ENV}" SECRET_KEY); [[ -n "${secret}" ]] || secret=$(video_secret 64)
    hook=$(video_env_get "${VIDEO_ENV}" MEDIAMTX_HOOK_SECRET); [[ -n "${hook}" ]] || hook=$(video_secret 32)
    # Число параллельных заданий обработки и уровень журнала администратор может менять — они сохраняются.
    local workers log_level
    workers=$(video_env_get "${VIDEO_ENV}" WORKER_CONCURRENCY)
    if ! [[ "${workers}" =~ ^[0-9]+$ ]]; then workers=$(( $(nproc 2>/dev/null || echo 2) / 2 )); [[ ${workers} -ge 1 ]] || workers=1; fi
    log_level=$(video_env_get "${VIDEO_ENV}" LOG_LEVEL); [[ "${log_level}" =~ ^[a-z]+$ ]] || log_level=info
    umask 027
    cat > "${VIDEO_ENV}.tmp" <<EOF
# Раздел «Видео» портала «${APP_TITLE}» — настройки видеопортала (создано deploy/video.sh $(date '+%Y-%m-%d %H:%M')).
# Файл перезаписывается командой «${APP_ID} video enable» и обновлением портала: служебные параметры ниже
# задаёт скрипт (WORKER_CONCURRENCY и LOG_LEVEL сохраняются). Свои параметры добавляйте в конец файла —
# они переносятся; параметр с именем служебного там не действует (меняйте его здесь, где он есть).
NODE_ENV=production
HOST=127.0.0.1
PORT=${port}
BASE_PATH=$(video_path)
BASE_URL=${url}
SECRET_KEY=${secret}
DATABASE_URL=postgres://${VIDEO_DB_USER}:${db_password}@127.0.0.1:${VIDEO_DB_PORT:-5432}/${VIDEO_DB_NAME}
DB_PASSWORD=${db_password}
DATA_DIR=${data}
WEB_DIST=${code}/web/dist
LOG_LEVEL=${log_level}
TRUST_PROXY=1
NGINX_ACCEL=1
WORKER_CONCURRENCY=${workers}
# Общий вход с порталом документации
PORTAL_INTERNAL_URL=http://127.0.0.1:$(video_internal_port)
PORTAL_SHARED_SECRET=${shared_secret}
PORTAL_NAME=${APP_TITLE}
# Трансляции (MediaMTX)
MEDIAMTX_API_URL=http://127.0.0.1:9997
MEDIAMTX_HLS_URL=http://127.0.0.1:8888
MEDIAMTX_PLAYBACK_URL=http://127.0.0.1:9996
MEDIAMTX_HOOK_SECRET=${hook}
LIVE_RTMP_URL=rtmp://${host}:1935
LIVE_SRT_URL=srt://${host}:8890
LIVE_WHIP_URL=${url}/whip
EOF
    # Свои параметры администратора (ниже служебных) переносятся из прежнего файла.
    if [[ -f "${VIDEO_ENV}" ]]; then
        local key
        while IFS= read -r line; do
            [[ "${line}" =~ ^([A-Z0-9_]+)= ]] || continue
            key=${BASH_REMATCH[1]}
            grep_has -E "^${key}=" "${VIDEO_ENV}.tmp" || printf '%s\n' "${line}" >> "${VIDEO_ENV}.tmp"
        done < "${VIDEO_ENV}"
    fi
    umask 022
    mv -f "${VIDEO_ENV}.tmp" "${VIDEO_ENV}"
    chown "root:${VIDEO_USER}" "${VIDEO_ENV}" && chmod 640 "${VIDEO_ENV}"
}

# --- Службы systemd (сервер) ------------------------------------------------------------
video_write_units() {
    local code=$1 node data
    node=$(command -v node)
    data=$(video_data_dir)
    cat > "/etc/systemd/system/${VIDEO_UNIT_API}.service" <<EOF
[Unit]
Description=${APP_TITLE}: раздел «Видео» — API
After=network.target postgresql.service
Wants=postgresql.service

[Service]
Type=simple
User=${VIDEO_USER}
Group=${VIDEO_USER}
WorkingDirectory=${APP_DIR}/current/video/server
EnvironmentFile=${VIDEO_ENV}
Environment=ENV_FILE=${VIDEO_ENV}
ExecStart=${node} src/index.js
Restart=always
RestartSec=3
LimitNOFILE=65536
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ReadWritePaths=${data} ${VIDEO_LOG_DIR}
StandardOutput=append:${VIDEO_LOG_DIR}/api.log
StandardError=append:${VIDEO_LOG_DIR}/api.log

[Install]
WantedBy=multi-user.target
EOF
    cat > "/etc/systemd/system/${VIDEO_UNIT_WORKER}.service" <<EOF
[Unit]
Description=${APP_TITLE}: раздел «Видео» — обработка (ffmpeg, субтитры, письма)
After=network.target postgresql.service ${VIDEO_UNIT_API}.service
Wants=postgresql.service

[Service]
Type=simple
User=${VIDEO_USER}
Group=${VIDEO_USER}
WorkingDirectory=${APP_DIR}/current/video/server
EnvironmentFile=${VIDEO_ENV}
Environment=ENV_FILE=${VIDEO_ENV}
ExecStart=${node} src/worker.js
Restart=always
RestartSec=5
TimeoutStopSec=40
LimitNOFILE=65536
Nice=5
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ReadWritePaths=${data} ${VIDEO_LOG_DIR}
StandardOutput=append:${VIDEO_LOG_DIR}/worker.log
StandardError=append:${VIDEO_LOG_DIR}/worker.log

[Install]
WantedBy=multi-user.target
EOF
    cat > "/etc/systemd/system/${VIDEO_UNIT_MEDIAMTX}.service" <<EOF
[Unit]
Description=${APP_TITLE}: раздел «Видео» — сервер трансляций (MediaMTX)
After=network.target ${VIDEO_UNIT_API}.service

[Service]
Type=simple
User=${VIDEO_USER}
Group=${VIDEO_USER}
WorkingDirectory=${data}/mediamtx
ExecStart=${data}/mediamtx/mediamtx ${VIDEO_MEDIAMTX_CONF}
Restart=always
RestartSec=3
LimitNOFILE=65536
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ReadWritePaths=${data} ${VIDEO_LOG_DIR}
StandardOutput=append:${VIDEO_LOG_DIR}/mediamtx.log
StandardError=append:${VIDEO_LOG_DIR}/mediamtx.log

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    cat > "/etc/logrotate.d/${APP_ID}-video" <<EOF
${VIDEO_LOG_DIR}/*.log {
    daily
    rotate 14
    missingok
    notifempty
    compress
    delaycompress
    copytruncate
}
EOF
    chmod 644 "/etc/logrotate.d/${APP_ID}-video"
}

video_mediamtx_native() {
    local data dir arch url tgz
    data=$(video_data_dir); dir="${data}/mediamtx"
    mkdir -p "${dir}"
    case "$(uname -m)" in
        x86_64|amd64) arch="amd64" ;;
        aarch64|arm64) arch="arm64v8" ;;
        armv7l) arch="armv7" ;;
        *) warn "Архитектура $(uname -m): сервер трансляций MediaMTX не устанавливается."; return 1 ;;
    esac
    if [[ -x "${dir}/mediamtx" ]] && "${dir}/mediamtx" --version 2>/dev/null | grep_has "${VIDEO_MEDIAMTX_VERSION}"; then
        ok "MediaMTX ${VIDEO_MEDIAMTX_VERSION} уже установлен"
    else
        url="https://github.com/bluenviron/mediamtx/releases/download/v${VIDEO_MEDIAMTX_VERSION}/mediamtx_v${VIDEO_MEDIAMTX_VERSION}_linux_${arch}.tar.gz"
        tgz=$(mktemp --suffix=.tar.gz)
        info "Загрузка сервера трансляций MediaMTX ${VIDEO_MEDIAMTX_VERSION}…"
        if download "${url}" "${tgz}" && tar -xzf "${tgz}" -C "${dir}" mediamtx 2>>"${LOG_FILE}" && "${dir}/mediamtx" --version >/dev/null 2>&1; then
            chmod 755 "${dir}/mediamtx"; chown root:root "${dir}/mediamtx"
            ok "MediaMTX ${VIDEO_MEDIAMTX_VERSION} установлен"
        else
            rm -f "${tgz}"
            warn "Не удалось скачать MediaMTX (${url}). Прямые трансляции работать не будут, пока файл ${dir}/mediamtx не появится; затем: ${APP_ID} video enable"
            return 1
        fi
        rm -f "${tgz}"
    fi
    local API_HOST HOOK_SECRET PUBLIC_HOST RECORD_DIR BIND_LOCAL
    API_HOST="127.0.0.1:$(video_port)"; HOOK_SECRET=$(video_env_get "${VIDEO_ENV}" MEDIAMTX_HOOK_SECRET)
    PUBLIC_HOST=$(video_public_host); RECORD_DIR="${data}/live/recordings"; BIND_LOCAL="127.0.0.1"
    video_render "$(video_code_dir)/deploy/mediamtx/mediamtx.yml.template" "${VIDEO_MEDIAMTX_CONF}" API_HOST HOOK_SECRET PUBLIC_HOST RECORD_DIR BIND_LOCAL
    chown "root:${VIDEO_USER}" "${VIDEO_MEDIAMTX_CONF}"; chmod 640 "${VIDEO_MEDIAMTX_CONF}"
    return 0
}

# --- nginx (сервер) -------------------------------------------------------------------------
# Файл сайта портала: в него один раз добавляется подключение правил раздела «Видео».
video_site_conf() {
    local f
    for f in "/etc/nginx/sites-available/${APP_ID}.conf" "/etc/nginx/conf.d/${APP_ID}.conf"; do
        [[ -f "${f}" ]] && { printf '%s' "${f}"; return 0; }
    done
    return 1
}

video_nginx_native() {
    local site tpl VIDEO_PATH_V VIDEO_API MEDIAMTX MEDIA_ROOT RESOLVER INTERNAL_LISTEN PUBLIC_ROOT FASTCGI_PASS SCRIPT_ROOT
    site=$(video_site_conf) || die "Не найден файл сайта nginx портала (/etc/nginx/sites-available/${APP_ID}.conf)."
    tpl="${VIDEO_SH_DIR}/video"
    install -d -m 755 "${VIDEO_NGINX_DIR}"
    # Прежние файлы — на случай, если новая конфигурация не пройдёт nginx -t.
    local f
    for f in "${VIDEO_NGINX_DIR}/locations.conf" "${VIDEO_NGINX_MAPS}" "${VIDEO_NGINX_INTERNAL}" "${site}"; do
        [[ -f "${f}" ]] && cp -f "${f}" "${f}.video-prev"
    done
    VIDEO_PATH=$(video_path); VIDEO_API="127.0.0.1:$(video_port)"; MEDIAMTX="127.0.0.1:8889"; MEDIA_ROOT="$(video_data_dir)/media"; RESOLVER=""
    video_render "${tpl}/locations.conf.template" "${VIDEO_NGINX_DIR}/locations.conf" VIDEO_PATH VIDEO_API MEDIAMTX MEDIA_ROOT RESOLVER
    cp -f "${tpl}/maps.conf" "${VIDEO_NGINX_MAPS}"
    INTERNAL_LISTEN="127.0.0.1:$(video_internal_port)"; PUBLIC_ROOT="${APP_DIR}/current/public"; FASTCGI_PASS="unix:${PHP_FPM_SOCK}"; SCRIPT_ROOT='$realpath_root'
    video_render "${tpl}/internal-server.conf.template" "${VIDEO_NGINX_INTERNAL}" INTERNAL_LISTEN PUBLIC_ROOT FASTCGI_PASS SCRIPT_ROOT
    # Подключение правил в server{} портала — после правила режима обслуживания (во время обновления
    # раздел «Видео» тоже показывает страницу «Идёт обновление портала»).
    if ! grep_has "${VIDEO_NGINX_DIR}/" "${site}"; then
        if grep_has "include ${MAINTENANCE_SNIPPET};" "${site}"; then
            sed -i "s|^\([[:space:]]*\)include ${MAINTENANCE_SNIPPET};|&\n\1include ${VIDEO_NGINX_DIR}/*.conf;|" "${site}"
        else
            # Сайт без режима обслуживания (очень старая установка): после строки root.
            sed -i "0,/^[[:space:]]*root .*;/s||&\n    include ${VIDEO_NGINX_DIR}/*.conf;|" "${site}"
        fi
    fi
    grep_has "${VIDEO_NGINX_DIR}/" "${site}" || die "Не удалось подключить правила раздела «Видео» в ${site}."
    # nginx читает медиафайлы видеопортала (их права проверяет API).
    usermod -aG "${VIDEO_USER}" www-data 2>/dev/null || usermod -aG "${VIDEO_USER}" nginx 2>/dev/null || true
    local out
    if ! out=$(nginx -t 2>&1); then
        _log_raw "${out}"
        for f in "${VIDEO_NGINX_DIR}/locations.conf" "${VIDEO_NGINX_MAPS}" "${VIDEO_NGINX_INTERNAL}" "${site}"; do
            if [[ -f "${f}.video-prev" ]]; then mv -f "${f}.video-prev" "${f}"; elif [[ "${f}" != "${site}" ]]; then rm -f "${f}"; fi
        done
        die "Конфигурация nginx с разделом «Видео» не прошла проверку (nginx -t): ${out##*$'\n'} — прежняя конфигурация возвращена."
    fi
    rm -f "${VIDEO_NGINX_DIR}/locations.conf.video-prev" "${VIDEO_NGINX_MAPS}.video-prev" "${VIDEO_NGINX_INTERNAL}.video-prev" "${site}.video-prev"
    svc reload nginx || svc restart nginx || die "Не удалось перезагрузить nginx."
    ok "nginx: раздел «Видео» подключён ($(video_path)/)"
}

video_nginx_remove_native() {
    local site
    rm -rf "${VIDEO_NGINX_DIR}" "${VIDEO_NGINX_MAPS}" "${VIDEO_NGINX_INTERNAL}"
    if site=$(video_site_conf); then
        sed -i "\|include ${VIDEO_NGINX_DIR}/\*\.conf;|d" "${site}"
    fi
    if command -v nginx >/dev/null 2>&1 && nginx -t >>"${LOG_FILE}" 2>&1; then svc reload nginx || true; fi
}

# --- Портал: включение раздела ---------------------------------------------------------------
video_portal_env_native() {
    local secret=$1 enabled=${2:-1} env_file="${APP_DIR}/shared/.env.local"
    set_env_var "${env_file}" VIDEO_ENABLED "${enabled}"
    set_env_var "${env_file}" VIDEO_BASE_PATH "$(video_path)"
    # Внутренний адрес API видеопортала: «Мониторинг», число видео на модерации, поиск видео в портале.
    set_env_var "${env_file}" VIDEO_API_URL "http://127.0.0.1:$(video_port)"
    [[ -n "${secret}" ]] && set_env_var "${env_file}" VIDEO_SHARED_SECRET "${secret}"
    chown "root:${SERVICE_USER}" "${env_file}" && chmod 640 "${env_file}"
}

# Консольная команда видеопортала (node src/cli.js …) от имени его службы и с его настройками.
video_cli() {
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        video_compose exec -T video-api node src/cli.js "$@"
        return
    fi
    local code
    code=$(video_code_dir)
    # shellcheck disable=SC2016
    runuser -u "${VIDEO_USER}" -- env -i PATH=/usr/local/bin:/usr/bin:/bin HOME="$(video_data_dir)" \
        bash -c 'set -a; . "$1"; set +a; cd "$2/server" || exit 1; shift 2; exec node src/cli.js "$@"' _ "${VIDEO_ENV}" "${code}" "$@"
}

# Сотрудники портала → учётные записи и группы-подразделения видеопортала (иначе тому, кто ещё не
# заходил в раздел, нельзя назначить обязательный просмотр). Дальше это делает сам видеопортал по расписанию.
video_portal_sync() {
    local out rc=0
    out=$(video_cli portal-sync 2>&1 9>&-) || rc=$?
    _log_raw "${out}"
    if [[ ${rc} -eq 0 ]]; then
        ok "$(printf '%s\n' "${out}" | grep -E '^Сотрудники портала' | tail -n1)"
        return 0
    fi
    warn "Сотрудники портала пока не перенесены в раздел «Видео»: $(printf '%s\n' "${out}" | tail -n1 | cut -c1-300). Видеопортал повторит сам в течение 15 минут; вручную: ${APP_ID} video sync-users"
    return 1
}

# Итог синхронизации сотрудников по сведениям API видеопортала (для docportal status).
video_sync_ok() {
    local out
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        # shellcheck disable=SC2016
        out=$(video_compose exec -T video-api sh -c 'curl -fsS -m 10 -H "X-Video-Secret: ${PORTAL_SHARED_SECRET}" http://127.0.0.1:3000/api/internal/status' 2>/dev/null) || return 1
    else
        local secret hdr rc=0
        secret=$(video_env_get "${VIDEO_ENV}" PORTAL_SHARED_SECRET)
        [[ -n "${secret}" ]] || return 1
        hdr=$(umask 077; mktemp)
        printf 'X-Video-Secret: %s\n' "${secret}" > "${hdr}"
        out=$(curl -fsS -m 10 --noproxy '*' -H "@${hdr}" "http://127.0.0.1:$(video_port)/api/internal/status" 2>/dev/null) || rc=1
        rm -f "${hdr}"
        [[ ${rc} -eq 0 ]] || return 1
    fi
    grep_has '"lastOkAt":"' <<<"${out}" && grep_has '"lastError":null' <<<"${out}"
}

video_firewall() {
    if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep_has "Status: active"; then
        info "Открытие портов трансляций в ufw: 1935/tcp (RTMP), 8890/udp (SRT), 8189 (WebRTC)"
        ufw allow 1935/tcp >>"${LOG_FILE}" 2>&1 || true
        ufw allow 8890/udp >>"${LOG_FILE}" 2>&1 || true
        ufw allow 8189/udp >>"${LOG_FILE}" 2>&1 || true
        ufw allow 8189/tcp >>"${LOG_FILE}" 2>&1 || true
    elif command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then
        firewall-cmd --permanent --add-port=1935/tcp --add-port=8890/udp --add-port=8189/udp --add-port=8189/tcp >>"${LOG_FILE}" 2>&1 || true
        firewall-cmd --reload >>"${LOG_FILE}" 2>&1 || true
    fi
}

video_wait_api() {
    local url n=0
    url="http://127.0.0.1:$(video_port)/api/health"
    until curl -fsS -m 5 --noproxy '*' "${url}" >/dev/null 2>&1; do
        n=$((n + 1)); [[ ${n} -gt ${1:-40} ]] && return 1
        sleep 3
    done
    return 0
}

video_restart_native() {
    systemctl restart "${VIDEO_UNIT_API}" "${VIDEO_UNIT_WORKER}" >>"${LOG_FILE}" 2>&1 || true
    [[ "${VIDEO_LIVE:-1}" == "1" && -x "$(video_data_dir)/mediamtx/mediamtx" ]] && systemctl restart "${VIDEO_UNIT_MEDIAMTX}" >>"${LOG_FILE}" 2>&1 || true
    if video_wait_api 40; then
        ok "Видеопортал отвечает (API http://127.0.0.1:$(video_port))"
        return 0
    fi
    error "Видеопортал не отвечает. Последние строки журнала ${VIDEO_LOG_DIR}/api.log:"
    tail -n 20 "${VIDEO_LOG_DIR}/api.log" 2>/dev/null | sed 's/^/   | /' >&2 || true
    return 1
}

video_enable_native() {
    detect_os
    [[ "${OS_FAMILY}" == "debian" ]] || die "На сервере раздел «Видео» ставится на Debian и Ubuntu. Для ${OS_ID} используйте установку в Docker (--mode docker)."
    local code data db_password shared_secret
    code=$(video_code_dir); data=$(video_data_dir)
    [[ -f "${code}/server/package.json" ]] || die "В установленном релизе нет видеопортала (${code}). Обновите портал до версии 1.8.0 или новее."
    video_resources_ok || warn "Ресурсов для раздела «Видео» мало: ${VIDEO_REASON} — продолжаем, но обработка видео может прерываться."
    pkg_preflight
    ensure_swap 2048 2048
    video_choose_ports
    video_check_live_ports

    step "Системные пакеты видеопортала"
    pkg_install postgresql postgresql-contrib ffmpeg curl ca-certificates fonts-dejavu-core
    pkg_install_optional yt-dlp || warn "yt-dlp не установлен — импорт видео по ссылке работает только для прямых ссылок на файлы."
    pkg_install_optional tesseract-ocr tesseract-ocr-rus tesseract-ocr-eng || true
    pkg_install_optional python3-opencv || info "python3-opencv не установлен — автопоиск лиц для размытия недоступен (области задаются вручную)."
    video_ensure_node

    step "Пользователь и каталоги видеопортала"
    id -u "${VIDEO_USER}" >/dev/null 2>&1 || useradd --system --home-dir "${data}" --shell /usr/sbin/nologin "${VIDEO_USER}"
    mkdir -p "${data}"/{media,uploads,live/recordings,mediamtx,import} "${VIDEO_LOG_DIR}"
    chown -R "${VIDEO_USER}:${VIDEO_USER}" "${data}" "${VIDEO_LOG_DIR}"
    chown root:root "${data}/mediamtx"
    chmod 750 "${data}"; chmod 755 "${data}/mediamtx"; chmod 2750 "${data}/media"
    ok "Пользователь ${VIDEO_USER}, данные: ${data}"

    step "База данных видеопортала"
    db_password=$(video_env_get "${VIDEO_ENV}" DB_PASSWORD); [[ -n "${db_password}" ]] || db_password=$(video_secret 32)
    video_setup_postgres "${db_password}"

    step "Код видеопортала"
    video_prepare_code "${code}"

    step "Настройки"
    shared_secret=$(video_env_get "${APP_DIR}/shared/.env.local" VIDEO_SHARED_SECRET); [[ ${#shared_secret} -ge 24 ]] || shared_secret=$(video_secret 48)
    mkdir -p "${CONF_DIR}" && chmod 755 "${CONF_DIR}"
    video_write_env_native "${code}" "${db_password}" "${shared_secret}"
    # Раздел в портале включается в конце, когда видеопортал уже отвечает (иначе ссылка «Видео» вела бы в никуда).
    video_portal_env_native "${shared_secret}" "$(video_enabled && echo 1 || echo 0)"
    ok "Настройки: ${VIDEO_ENV}"

    if [[ "${VIDEO_LIVE:-1}" == "1" ]]; then
        step "Сервер трансляций"
        video_mediamtx_native || VIDEO_LIVE="0"
    fi

    step "Службы видеопортала"
    video_write_units "${code}"
    systemctl enable "${VIDEO_UNIT_API}" "${VIDEO_UNIT_WORKER}" >>"${LOG_FILE}" 2>&1
    if [[ "${VIDEO_LIVE:-1}" == "1" ]]; then systemctl enable "${VIDEO_UNIT_MEDIAMTX}" >>"${LOG_FILE}" 2>&1 || true
    else systemctl disable --now "${VIDEO_UNIT_MEDIAMTX}" >>"${LOG_FILE}" 2>&1 || true; fi
    video_restart_native || die "Видеопортал не запустился (см. ${VIDEO_LOG_DIR}/api.log)."

    step "Веб-сервер"
    video_nginx_native
    [[ "${VIDEO_LIVE:-1}" == "1" ]] && video_firewall
    video_portal_env_native "" 1
    ok "Портал: VIDEO_ENABLED=1 — ссылка «Видео» в шапке"
    video_verify_session || true
    video_portal_sync || true
}

# --- Docker ------------------------------------------------------------------------------------
video_compose() { (cd "${APP_DIR}/docker" && docker compose "$@"); }
video_compose_logged() { video_compose "$@" >>"${LOG_FILE}" 2>&1 9>&-; }

# Файлы раздела для контейнеров: правила nginx (подключаются в docker/nginx/default.conf) и настройки MediaMTX.
video_render_docker() {
    local tpl="${VIDEO_SH_DIR}/video" VIDEO_PATH VIDEO_API MEDIAMTX MEDIA_ROOT RESOLVER
    install -d -m 755 "${APP_DIR}/docker/nginx/video" "${APP_DIR}/docker/video"
    VIDEO_PATH=$(video_path); VIDEO_API="video-api:3000"; MEDIAMTX="mediamtx:8889"; MEDIA_ROOT="/var/lib/${APP_ID}-video/media"
    RESOLVER="resolver 127.0.0.11 valid=30s ipv6=off;"
    video_render "${tpl}/locations.conf.template" "${APP_DIR}/docker/nginx/video/portal.locations" VIDEO_PATH VIDEO_API MEDIAMTX MEDIA_ROOT RESOLVER
    local API_HOST="video-api:3000" HOOK_SECRET PUBLIC_HOST RECORD_DIR="/data/live/recordings" BIND_LOCAL=""
    HOOK_SECRET=$(video_env_get "${APP_DIR}/docker/.env" VIDEO_HOOK_SECRET)
    PUBLIC_HOST=$(video_public_host)
    video_render "${APP_DIR}/video/deploy/mediamtx/mediamtx.yml.template" "${APP_DIR}/docker/video/mediamtx.yml" API_HOST HOOK_SECRET PUBLIC_HOST RECORD_DIR BIND_LOCAL
    chmod 644 "${APP_DIR}/docker/video/mediamtx.yml"
}

# Список профилей compose без профилей раздела «Видео»: video_profiles_without "a,video,video-live" → "a".
video_profiles_without() {
    local p out=() IFS=','
    for p in $1; do
        [[ -z "${p}" || "${p}" == "video" || "${p}" == "video-live" ]] || out+=("${p}")
    done
    printf '%s' "${out[*]}"
}

video_docker_env_set() {
    local file="${APP_DIR}/docker/.env" key=$1 value=$2
    if grep_has -E "^${key}=" "${file}"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "${file}"
    else
        printf '%s=%s\n' "${key}" "${value}" >> "${file}"
    fi
}

video_enable_docker() {
    local file="${APP_DIR}/docker/.env" host url profiles
    [[ -f "${APP_DIR}/video/server/package.json" ]] || die "В установке нет видеопортала (${APP_DIR}/video). Обновите портал до версии 1.8.0 или новее."
    [[ -f "${file}" ]] || die "Нет ${file}."
    step "Настройки docker/.env"
    host=$(video_public_host); url="$(video_portal_url)$(video_path)"
    grep_has '^# --- Раздел «Видео»' "${file}" || printf '\n# --- Раздел «Видео» (видеопортал по адресу %s/, общий вход с порталом; создано deploy/video.sh) ---\n' "$(video_path)" >> "${file}"
    video_docker_env_set VIDEO_BASE_PATH "$(video_path)"
    video_docker_env_set VIDEO_API_URL "http://video-api:3000"
    local s
    s=$(video_env_get "${file}" VIDEO_SHARED_SECRET); [[ ${#s} -ge 24 ]] || video_docker_env_set VIDEO_SHARED_SECRET "$(video_secret 48)"
    s=$(video_env_get "${file}" VIDEO_DB_PASSWORD); [[ -n "${s}" ]] || video_docker_env_set VIDEO_DB_PASSWORD "$(video_secret 32)"
    s=$(video_env_get "${file}" VIDEO_SECRET_KEY); [[ -n "${s}" ]] || video_docker_env_set VIDEO_SECRET_KEY "$(video_secret 64)"
    s=$(video_env_get "${file}" VIDEO_HOOK_SECRET); [[ -n "${s}" ]] || video_docker_env_set VIDEO_HOOK_SECRET "$(video_secret 32)"
    video_docker_env_set VIDEO_BASE_URL "${url}"
    video_docker_env_set VIDEO_LIVE_RTMP_URL "rtmp://${host}:1935"
    video_docker_env_set VIDEO_LIVE_SRT_URL "srt://${host}:8890"
    video_docker_env_set VIDEO_LIVE_WHIP_URL "${url}/whip"
    chmod 600 "${file}"
    ok "docker/.env: параметры раздела «Видео» записаны"

    step "Сборка образа видеопортала (несколько минут)"
    retry 3 30 video_compose_logged build --pull video-api || die "Сборка образа видеопортала не удалась (см. ${LOG_FILE}). Если Docker Hub, репозитории Debian или registry.npmjs.org недоступны, укажите зеркала (VIDEO_NODE_IMAGE в docker/.env) или ставьте из архива дистрибутива — в нём зависимости и интерфейс уже собраны."
    ok "Образ видеопортала собран"

    step "Запуск контейнеров раздела «Видео»"
    video_check_live_ports
    video_render_docker
    # Раздел включается только теперь, когда образ готов: ссылка «Видео» в портале не появится раньше времени.
    # Профили compose: «video» — video-db, video-api, video-worker; «video-live» — сервер трансляций mediamtx.
    profiles=$(video_profiles_without "$(video_env_get "${file}" COMPOSE_PROFILES)")
    profiles="${profiles:+${profiles},}video"
    [[ "${VIDEO_LIVE:-1}" == "1" ]] && profiles="${profiles},video-live"
    video_docker_env_set COMPOSE_PROFILES "${profiles}"
    video_docker_env_set VIDEO_ENABLED 1
    [[ "${VIDEO_LIVE:-1}" == "1" ]] || video_compose rm -sf mediamtx >>"${LOG_FILE}" 2>&1 || true
    # web пересоздаётся, если в нём ещё нет тома с видеофайлами (установка обновлена с версии до 1.8.0).
    video_compose up -d >>"${LOG_FILE}" 2>&1 || die "Не удалось запустить контейнеры (см. ${LOG_FILE})."
    video_compose exec -T web nginx -s reload >>"${LOG_FILE}" 2>&1 || true
    local n=0
    until video_compose exec -T video-api curl -fsS -m 5 http://127.0.0.1:3000/api/health >/dev/null 2>&1; do
        n=$((n + 1))
        if [[ ${n} -gt 40 ]]; then
            video_compose logs --tail=60 video-api >>"${LOG_FILE}" 2>&1 || true
            die "Видеопортал не отвечает. Журнал: cd ${APP_DIR}/docker && docker compose logs video-api"
        fi
        sleep 3
    done
    ok "Видеопортал отвечает"
    video_verify_session || true
    video_portal_sync || true
}

# --- Проверка через портал -------------------------------------------------------------------------
# Запрос к разделу «Видео» через сайт портала — как health_probe: имя сайта направляется на этот сервер.
video_public_url() { local base; base=$(health_url); printf '%s%s/api/health' "${base%/health}" "$(video_path)"; }
video_public_probe() {
    local host="${DOMAIN:-localhost}" body
    local resolve=(--resolve "${host}:80:127.0.0.1" --resolve "${host}:443:127.0.0.1")
    [[ "${HTTP_PORT:-80}" != "80" && "${HTTP_PORT:-80}" != "443" ]] && resolve+=(--resolve "${host}:${HTTP_PORT}:127.0.0.1")
    body=$(curl -fsS -L -k -m 10 --max-redirs 3 --noproxy '*' "${resolve[@]}" -H "Host: ${host}" "$(video_public_url)" 2>/dev/null) || return 1
    [[ "${body}" == *'"status"'* ]]
}

video_check_public() {
    if video_public_probe; then
        ok "Раздел «Видео» открывается через портал: $(video_portal_url)$(video_path)/"
        return 0
    fi
    warn "Через портал $(video_public_url) ответа нет — проверьте: nginx -t; ${APP_ID} video status"
    return 1
}

# --- Резервная копия и восстановление ---------------------------------------------------------------
# video_backup КАТАЛОГ_КОПИИ — выгрузка базы видеопортала (и видеофайлов при VIDEO_BACKUP_MEDIA=1)
# в КАТАЛОГ_КОПИИ/video. Для сервера массив VIDEO_TAR_ARGS получает аргументы tar, упаковывающие
# видеофайлы прямо в архив (без копии во временный каталог). Ошибка выгрузки базы — ошибка копии.
VIDEO_TAR_ARGS=()
video_backup() {
    local dest=$1/video name=$2 data
    video_enabled || return 0
    mkdir -p "${dest}"
    step "Выгрузка базы раздела «Видео»"
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        video_compose ps --status running video-db 2>/dev/null | grep_has video-db || die "Контейнер базы видеопортала (video-db) не запущен."
        video_compose exec -T video-db pg_dump -U "${VIDEO_DB_USER}" -Fc "${VIDEO_DB_NAME}" > "${dest}/db.dump" 2>>"${LOG_FILE}" || die "pg_dump видеопортала завершился с ошибкой."
    else
        runuser -u postgres -- pg_dump -Fc "${VIDEO_DB_NAME}" > "${dest}/db.dump" 2>>"${LOG_FILE}" 9>&- || die "pg_dump видеопортала завершился с ошибкой."
        cp -f "${VIDEO_ENV}" "${dest}/video.env" 2>/dev/null || true
    fi
    [[ -s "${dest}/db.dump" ]] || die "Выгрузка базы видеопортала пуста."
    ok "База видеопортала выгружена ($(human_size "$(stat -c %s "${dest}/db.dump")"))"
    if [[ "${VIDEO_BACKUP_MEDIA:-0}" == "1" || "${BACKUP_VIDEO_MEDIA:-0}" == "1" ]]; then
        if [[ "${INSTALL_MODE}" == "docker" ]]; then
            mkdir -p "${dest}/media"
            video_compose run --rm --no-deps -T --entrypoint tar video-api -C /data/media -cf - . 2>>"${LOG_FILE}" | tar -C "${dest}/media" -xf - 2>>"${LOG_FILE}" \
                || die "Не удалось скопировать видеофайлы из контейнера."
        else
            data=$(video_data_dir)
            # Имена media/… (без «./»): правило имён файлов документов (^\.) к ним не применяется.
            VIDEO_TAR_ARGS=(-C "${data}" --transform "s,^media\(/\|\$\),${name}/video/media\1,S" media)
        fi
        ok "Видеофайлы войдут в копию"
    else
        info "Видеофайлы в копию не входят (их бывает много). Включить: VIDEO_BACKUP_MEDIA=1 в ${CONF_FILE} или разово: ${APP_ID} backup --with-video-media"
    fi
}

# video_restore КАТАЛОГ_РАСПАКОВАННОЙ_КОПИИ — база видеопортала (и видеофайлы, если есть в копии).
video_restore() {
    local src=$1/video data
    [[ -f "${src}/db.dump" ]] || return 0
    if ! video_enabled; then
        warn "В копии есть данные раздела «Видео», но раздел здесь не включён — пропускаются (включите: ${APP_ID} video enable и восстановите копию снова)."
        return 0
    fi
    step "Восстановление раздела «Видео»"
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        video_compose stop video-api video-worker >>"${LOG_FILE}" 2>&1 || true
        video_compose exec -T video-db pg_restore -U "${VIDEO_DB_USER}" -d "${VIDEO_DB_NAME}" --clean --if-exists --no-owner < "${src}/db.dump" >>"${LOG_FILE}" 2>&1 \
            || warn "pg_restore сообщил о предупреждениях (см. журнал) — обычно это нормально для --clean."
        if [[ -d "${src}/media" ]]; then
            video_compose run --rm --no-deps -T --entrypoint sh video-api -c 'find /data/media -mindepth 1 -delete' >>"${LOG_FILE}" 2>&1 || true
            tar -C "${src}/media" -cf - . | video_compose run --rm --no-deps -T --entrypoint sh video-api -c 'tar -C /data/media -xf - && chown -R node:node /data/media' >>"${LOG_FILE}" 2>&1 \
                || die "Не удалось вернуть видеофайлы."
        fi
        video_compose up -d >>"${LOG_FILE}" 2>&1 || true
    else
        systemctl stop "${VIDEO_UNIT_API}" "${VIDEO_UNIT_WORKER}" >>"${LOG_FILE}" 2>&1 || true
        # --role: таблицы создаются от имени владельца базы видеопортала (как при обычной работе).
        runuser -u postgres -- pg_restore -d "${VIDEO_DB_NAME}" --clean --if-exists --no-owner --role="${VIDEO_DB_USER}" < "${src}/db.dump" >>"${LOG_FILE}" 2>&1 9>&- \
            || warn "pg_restore сообщил о предупреждениях (см. журнал) — обычно это нормально для --clean."
        if [[ -d "${src}/media" ]]; then
            data=$(video_data_dir)
            rm -rf "${data}/media.restore-old"
            mv "${data}/media" "${data}/media.restore-old" && cp -a "${src}/media" "${data}/media" && chown -R "${VIDEO_USER}:${VIDEO_USER}" "${data}/media" && chmod 2750 "${data}/media" \
                && rm -rf "${data}/media.restore-old" || die "Не удалось вернуть видеофайлы (прежние сохранены в ${data}/media.restore-old)."
        fi
        video_restart_native || warn "Видеопортал не запустился после восстановления (${APP_ID} video status)."
    fi
    ok "Раздел «Видео» восстановлен$([[ -d "${src}/media" ]] && echo ' вместе с видеофайлами' || echo ' (база; видеофайлов в копии не было — остались прежние)')"
}

# --- Обновление портала --------------------------------------------------------------------------------
# До переключения на новую версию.
#   Сервер: зависимости и интерфейс видеопортала в каталоге нового релиза (ошибка — ошибка обновления:
#           без них новая версия видеопортала не запустится, а прежняя ещё работает).
#   Docker: файлы раздела и образ видеопортала новой версии (ошибка сборки — предупреждение: контейнеры
#           видеопортала останутся на прежнем образе).
video_update_prepare() {
    local new_release=$1 prev_release=${2:-}
    video_enabled || return 0
    step "Раздел «Видео»: подготовка новой версии"
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        video_render_docker || warn "Не удалось обновить файлы раздела «Видео» в ${APP_DIR}/docker."
        # Портал обращается к API видеопортала (мониторинг, модерация, поиск) — адрес в docker/.env.
        grep_has '^VIDEO_API_URL=' "${APP_DIR}/docker/.env" || video_docker_env_set VIDEO_API_URL "http://video-api:3000"
        if retry 2 30 video_compose_logged build --pull video-api; then
            ok "Образ видеопортала новой версии собран"
        else
            warn "Образ видеопортала новой версии не собран (см. ${LOG_FILE}) — раздел «Видео» работает на прежнем образе. Повторить: cd ${APP_DIR}/docker && docker compose build video-api && docker compose up -d"
        fi
        return 0
    fi
    video_prepare_code "${new_release}/video" "${prev_release:+${prev_release}/video}"
}

# После переключения: настройки, службы, nginx. Ошибки здесь — предупреждения: портал уже обновлён.
video_update_finish() {
    video_enabled || return 0
    step "Раздел «Видео»: перезапуск"
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        video_render_docker || warn "Не удалось обновить файлы раздела «Видео» в docker/."
        video_compose exec -T web nginx -s reload >>"${LOG_FILE}" 2>&1 || true
        return 0
    fi
    local code shared db
    code=$(video_code_dir)
    shared=$(video_env_get "${APP_DIR}/shared/.env.local" VIDEO_SHARED_SECRET)
    db=$(video_env_get "${VIDEO_ENV}" DB_PASSWORD)
    if [[ -n "${shared}" && -n "${db}" ]]; then
        video_write_env_native "${code}" "${db}" "${shared}"
        set_env_var "${APP_DIR}/shared/.env.local" VIDEO_API_URL "http://127.0.0.1:$(video_port)"
    else
        warn "Нет секрета или пароля базы видеопортала — настройки не обновлены (${APP_ID} video enable)."
    fi
    video_write_units "${code}"
    [[ "${VIDEO_LIVE:-1}" == "1" && -x "$(video_data_dir)/mediamtx/mediamtx" ]] && video_mediamtx_native >/dev/null 2>&1 || true
    video_restart_native || warn "Видеопортал не запустился после обновления — портал документов работает. Проверьте: ${APP_ID} video status; журнал ${VIDEO_LOG_DIR}/api.log"
    ( video_nginx_native ) || warn "Правила nginx раздела «Видео» не обновлены — действуют прежние."
}

# --- Удаление -----------------------------------------------------------------------------------------
video_uninstall() {
    local purge=${1:-0}
    [[ "${INSTALL_MODE}" == "native" ]] || return 0
    if [[ -f "/etc/systemd/system/${VIDEO_UNIT_API}.service" ]]; then
        systemctl disable --now "${VIDEO_UNIT_API}" "${VIDEO_UNIT_WORKER}" "${VIDEO_UNIT_MEDIAMTX}" >>"${LOG_FILE}" 2>&1 || true
        rm -f /etc/systemd/system/"${APP_ID}"-video-*.service "/etc/logrotate.d/${APP_ID}-video"
        systemctl daemon-reload || true
    fi
    video_nginx_remove_native
    if [[ "${purge}" == "1" ]]; then
        if command -v psql >/dev/null 2>&1 && video_pg_ready; then
            runuser -u postgres -- psql -q -c "DROP DATABASE IF EXISTS ${VIDEO_DB_NAME}" >>"${LOG_FILE}" 2>&1 9>&- || true
            runuser -u postgres -- psql -q -c "DROP ROLE IF EXISTS ${VIDEO_DB_USER}" >>"${LOG_FILE}" 2>&1 9>&- || true
        fi
        rm -rf "$(video_data_dir)" "${VIDEO_LOG_DIR}" "${VIDEO_ENV}" "${VIDEO_MEDIAMTX_CONF}"
        id -u "${VIDEO_USER}" >/dev/null 2>&1 && userdel "${VIDEO_USER}" >/dev/null 2>&1 || true
        info "Раздел «Видео» удалён вместе с видео и базой."
    else
        info "Службы раздела «Видео» удалены; видео ($(video_data_dir)) и база ${VIDEO_DB_NAME} сохранены."
    fi
}

# --- Диагностика -----------------------------------------------------------------------------------------
# Портал отвечает видеопорталу на проверку сессии с его секретом (секреты совпадают, внутренний сервер nginx
# работает). Секрет не попадает в аргументы процессов: на сервере — через файл заголовков, в Docker —
# из окружения контейнера.
video_session_ok() {
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        # shellcheck disable=SC2016
        video_compose exec -T video-api sh -c 'curl -fsS -m 5 -H "X-Video-Secret: ${PORTAL_SHARED_SECRET}" "${PORTAL_INTERNAL_URL}/internal/video/session"' 2>/dev/null | grep_has '"state"'
        return
    fi
    local secret hdr rc=0
    secret=$(video_env_get "${VIDEO_ENV}" PORTAL_SHARED_SECRET)
    [[ -n "${secret}" ]] || return 1
    hdr=$(umask 077; mktemp)
    printf 'X-Video-Secret: %s\n' "${secret}" > "${hdr}"
    curl -fsS -m 5 --noproxy '*' -H "@${hdr}" "http://127.0.0.1:$(video_internal_port)/internal/video/session" 2>/dev/null | grep_has '"state"' || rc=1
    rm -f "${hdr}"
    return ${rc}
}

# video_doctor ФУНКЦИЯ_ПРОВЕРКИ — проверки раздела в отчёте doctor.sh (check "текст" команда…).
video_doctor() {
    local check_fn=${1:-check}
    video_enabled || { info "Раздел «Видео» не включён (включить: ${APP_ID} video enable)"; return 0; }
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        local s
        for s in video-db video-api video-worker $([[ "${VIDEO_LIVE:-1}" == "1" ]] && echo mediamtx); do
            "${check_fn}" "Видео: контейнер ${s} работает" bash -c "cd '${APP_DIR}/docker' && docker compose ps --status running ${s} | grep ${s} >/dev/null"
        done
        "${check_fn}" "Видео: API отвечает" bash -c "cd '${APP_DIR}/docker' && docker compose exec -T video-api curl -fsS -m 5 http://127.0.0.1:3000/api/health"
        "${check_fn}" "Видео: общий вход — портал принимает секрет видеопортала (web:${VIDEO_DEFAULT_INTERNAL_PORT})" video_session_ok
        "${check_fn}" "Видео: сотрудники портала синхронизированы (${APP_ID} video sync-users)" video_sync_ok
    else
        "${check_fn}" "Видео: служба ${VIDEO_UNIT_API} работает" systemctl is-active --quiet "${VIDEO_UNIT_API}"
        "${check_fn}" "Видео: служба ${VIDEO_UNIT_WORKER} работает" systemctl is-active --quiet "${VIDEO_UNIT_WORKER}"
        if [[ "${VIDEO_LIVE:-1}" == "1" ]]; then
            "${check_fn}" "Видео: сервер трансляций ${VIDEO_UNIT_MEDIAMTX} работает" systemctl is-active --quiet "${VIDEO_UNIT_MEDIAMTX}"
        fi
        "${check_fn}" "Видео: API отвечает (127.0.0.1:$(video_port))" curl -fsS -m 5 --noproxy '*' "http://127.0.0.1:$(video_port)/api/health"
        "${check_fn}" "Видео: общий вход — портал принимает секрет видеопортала (127.0.0.1:$(video_internal_port))" video_session_ok
        "${check_fn}" "Видео: сотрудники портала синхронизированы (${APP_ID} video sync-users)" video_sync_ok
        "${check_fn}" "Видео: правила nginx подключены" test -f "${VIDEO_NGINX_DIR}/locations.conf"
    fi
    "${check_fn}" "Видео: раздел открывается через портал ($(video_public_url))" video_public_probe
}

# --- Команды -------------------------------------------------------------------------------------------------
video_usage() {
    cat <<EOF
Использование: ${APP_ID} video <команда>
  enable [--no-live] [--backup-media]   Установить и включить раздел «Видео» (видеопортал по адресу /video,
                                        общий вход и роли с порталом). --no-live — без сервера трансляций;
                                        --backup-media — видеофайлы входят в ночные резервные копии.
  status                                Состояние раздела «Видео»
  restart                               Перезапустить видеопортал
  sync-users                            Перенести сотрудников портала в раздел «Видео» сейчас (учётные записи,
                                        блокировки, группы-подразделения; сам видеопортал делает это раз в 15 минут)
  logs [api|worker|mediamtx]            Журнал видеопортала
  disable                               Выключить раздел (видео и база сохраняются; включить снова — enable)
EOF
}

video_main() {
    local cmd=${1:-help}
    shift || true
    case "${cmd}" in
        enable)
            local live="${VIDEO_LIVE:-1}" media="${VIDEO_BACKUP_MEDIA:-0}"
            while [[ $# -gt 0 ]]; do
                case "$1" in
                    --no-live) live="0"; shift ;;
                    --live) live="1"; shift ;;
                    --backup-media) media="1"; shift ;;
                    --no-backup-media) media="0"; shift ;;
                    --yes|-y) shift ;;
                    *) video_usage; die "Неизвестный параметр: $1" ;;
                esac
            done
            require_root "$@"
            log_init video-enable "$@"
            load_conf || die "Портал не установлен: не найден ${CONF_FILE}."
            setup_traps
            acquire_lock 0
            banner "раздел «Видео»"
            VIDEO_LIVE="${live}"; VIDEO_BACKUP_MEDIA="${media}"
            # Порты API и проверки сессии выбирает video_choose_ports (занятые другой программой — заменяются).
            VIDEO_PATH=$(video_path); VIDEO_DATA_DIR=$(video_data_dir)
            if [[ "${INSTALL_MODE}" == "docker" ]]; then video_enable_docker; else video_enable_native; fi
            VIDEO_ENABLED="1"
            save_conf
            video_check_public || true
            printf '\n%sРаздел «Видео» включён:%s %s%s/\n' "${C_GREEN}${C_BOLD}" "${C_RESET}" "$(video_portal_url)" "$(video_path)"
            printf 'Вход — через портал. Роли назначаются в портале (Панель администратора → Пользователи):\n'
            printf '  маркетолог — модератор видео; помощник маркетолога — загружает видео (публикуются после модерации);\n'
            printf '  пользователь — смотрит одобренные видео. Журнал: %s\n' "${LOG_FILE}"
            ;;
        disable)
            require_root "$@"
            log_init video-disable "$@"
            load_conf || die "Портал не установлен: не найден ${CONF_FILE}."
            setup_traps
            acquire_lock 0
            if [[ "${INSTALL_MODE}" == "docker" ]]; then
                video_docker_env_set VIDEO_ENABLED 0
                video_docker_env_set COMPOSE_PROFILES "$(video_profiles_without "$(video_env_get "${APP_DIR}/docker/.env" COMPOSE_PROFILES)")"
                video_compose rm -sf video-api video-worker mediamtx video-db >>"${LOG_FILE}" 2>&1 || true
                rm -f "${APP_DIR}/docker/nginx/video/portal.locations"
                video_compose up -d >>"${LOG_FILE}" 2>&1 || true
                video_compose exec -T web nginx -s reload >>"${LOG_FILE}" 2>&1 || true
            else
                video_portal_env_native "" 0
                systemctl disable --now "${VIDEO_UNIT_API}" "${VIDEO_UNIT_WORKER}" "${VIDEO_UNIT_MEDIAMTX}" >>"${LOG_FILE}" 2>&1 || true
                video_nginx_remove_native
            fi
            VIDEO_ENABLED="0"
            save_conf
            ok "Раздел «Видео» выключен. Видео и база сохранены; включить снова: ${APP_ID} video enable"
            ;;
        status)
            require_root "$@"
            LOG_FILE="/dev/null"
            load_conf || die "Портал не установлен: не найден ${CONF_FILE}."
            local problems=0
            _video_check() { local t=$1; shift; if "$@" >/dev/null 2>&1; then ok "${t}"; else error "${t}"; problems=$((problems + 1)); fi; }
            video_doctor _video_check
            [[ ${problems} -eq 0 ]]
            ;;
        sync-users)
            require_root "$@"
            log_init video-sync "$@"
            load_conf || die "Портал не установлен: не найден ${CONF_FILE}."
            video_enabled || die "Раздел «Видео» не включён (${APP_ID} video enable)."
            video_portal_sync
            ;;
        restart)
            require_root "$@"
            log_init video-restart "$@"
            load_conf || die "Портал не установлен: не найден ${CONF_FILE}."
            video_enabled || die "Раздел «Видео» не включён (${APP_ID} video enable)."
            if [[ "${INSTALL_MODE}" == "docker" ]]; then
                local services=(video-api video-worker)
                [[ "${VIDEO_LIVE:-1}" == "1" ]] && services+=(mediamtx)
                video_compose restart "${services[@]}"
            else
                video_restart_native
            fi
            ;;
        logs)
            require_root "$@"
            load_conf || die "Портал не установлен: не найден ${CONF_FILE}."
            local which=${1:-api}
            if [[ "${INSTALL_MODE}" == "docker" ]]; then
                case "${which}" in api) which=video-api ;; worker) which=video-worker ;; esac
                cd "${APP_DIR}/docker" && exec docker compose logs -f --tail=200 "${which}"
            fi
            exec tail -n 200 -f "${VIDEO_LOG_DIR}/${which}.log"
            ;;
        help|--help|-h) video_usage ;;
        *) video_usage; die "Неизвестная команда: ${cmd}" ;;
    esac
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    video_main "$@"
fi
