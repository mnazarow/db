#!/usr/bin/env bash
# =============================================================================
#  Сервер языковой модели для портала документации — отдельно от портала (например, на сервере с видеокартой).
#
#  Скрипт самостоятельный: скопируйте его на сервер моделей и выполните от root:
#
#    sudo bash ai-server.sh --allow 10.0.0.15            # 10.0.0.15 — адрес сервера портала
#    sudo bash ai-server.sh --model qwen2.5:32b --allow 10.0.0.15,10.0.0.16 --tls-cert cert.pem --tls-key key.pem
#    sudo bash ai-server.sh --status | --rotate-key | --uninstall
#
#  Что делает: ставит Ollama (служба systemd, слушает только 127.0.0.1:11434), скачивает модель для ответов
#  (по объёму памяти или --model) и модель эмбеддингов (bge-m3) и открывает к ним вход для портала через nginx:
#  порт 11435, ключ доступа (Authorization: Bearer …), только с адресов из --allow, при желании — HTTPS.
#  В конце выводит команду для сервера портала:
#    sudo docportal ai connect --url http://ЭТОТ_СЕРВЕР:11435/v1 --api-key КЛЮЧ --model МОДЕЛЬ --embeddings bge-m3
#
#  Без интернета: Ollama — из архива (--archive ollama-linux-amd64.tgz, https://ollama.com/download/linux),
#  модели — копией каталога /usr/share/ollama/.ollama/models с сервера, где они уже скачаны.
#  Поддерживаются Debian, Ubuntu, AlmaLinux, Rocky Linux, RHEL.
# =============================================================================
set -Eeuo pipefail

SRV_NAME="docportal-ai"
CONF_DIR="/etc/${SRV_NAME}"
KEY_FILE="${CONF_DIR}/key"
NGINX_CONF="/etc/nginx/conf.d/${SRV_NAME}.conf"
OVERRIDE="/etc/systemd/system/ollama.service.d/${SRV_NAME}.conf"
LOG_FILE="/var/log/${SRV_NAME}-install.log"
OLLAMA_INSTALL_URL="${OLLAMA_INSTALL_URL:-https://ollama.com/install.sh}"

PORT="11435"; MODEL=""; EMBEDDINGS="bge-m3"; ALLOW=""; ARCHIVE=""; KEY=""; TLS_CERT=""; TLS_KEY=""; ACTION="install"; ASSUME_YES="0"

C_RESET=$'\033[0m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_RED=$'\033[31m'; C_BOLD=$'\033[1m'
[[ -t 1 ]] || { C_RESET=""; C_GREEN=""; C_YELLOW=""; C_RED=""; C_BOLD=""; }
info() { printf '%s\n' "$*"; printf '[INFO] %s\n' "$*" >> "${LOG_FILE}" 2>/dev/null || true; }
ok() { printf '%s✔%s %s\n' "${C_GREEN}" "${C_RESET}" "$*"; printf '[ OK ] %s\n' "$*" >> "${LOG_FILE}" 2>/dev/null || true; }
warn() { printf '%s⚠ %s%s\n' "${C_YELLOW}" "$*" "${C_RESET}" >&2; printf '[WARN] %s\n' "$*" >> "${LOG_FILE}" 2>/dev/null || true; }
die() { printf '%s✖ %s%s\n' "${C_RED}" "$*" "${C_RESET}" >&2; printf '[FAIL] %s\n' "$*" >> "${LOG_FILE}" 2>/dev/null || true; exit 1; }
step() { printf '\n%s▶ %s%s\n' "${C_BOLD}" "$*" "${C_RESET}"; printf '[STEP] %s\n' "$*" >> "${LOG_FILE}" 2>/dev/null || true; }
trap 'die "Ошибка в строке ${LINENO}: ${BASH_COMMAND} (журнал: ${LOG_FILE})"' ERR

usage() {
    cat <<EOF
Сервер языковой модели для портала документации.

Использование: sudo bash ai-server.sh [параметры]
  --allow IP[,IP]     адреса серверов портала, которым открыт вход (рекомендуется; без него — любой адрес с ключом)
  --model ИМЯ         модель для ответов (по умолчанию — по памяти: qwen2.5:32b / 14b / 7b)
  --embeddings ИМЯ    модель эмбеддингов (по умолчанию bge-m3); --no-embeddings — без неё
  --port N            порт входа для портала (по умолчанию ${PORT})
  --key КЛЮЧ          свой ключ доступа (по умолчанию создаётся случайный и хранится в ${KEY_FILE})
  --tls-cert ФАЙЛ --tls-key ФАЙЛ   HTTPS вместо HTTP (сертификат сервера и ключ)
  --archive ФАЙЛ      Ollama из архива — сервер без интернета
  --yes, -y           не спрашивать подтверждения
  --status            состояние: служба, модели, вход для портала
  --rotate-key        выдать новый ключ (старый перестаёт действовать; на портале: docportal ai connect …)
  --uninstall         убрать вход для портала и Ollama с моделями
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --allow) ALLOW="${2:-}"; shift 2 ;;
        --model) MODEL="${2:-}"; shift 2 ;;
        --embeddings) EMBEDDINGS="${2:-}"; shift 2 ;;
        --no-embeddings) EMBEDDINGS=""; shift ;;
        --port) PORT="${2:-}"; shift 2 ;;
        --key) KEY="${2:-}"; shift 2 ;;
        --tls-cert) TLS_CERT="${2:-}"; shift 2 ;;
        --tls-key) TLS_KEY="${2:-}"; shift 2 ;;
        --archive) ARCHIVE="${2:-}"; shift 2 ;;
        --yes|-y) ASSUME_YES="1"; shift ;;
        --status) ACTION="status"; shift ;;
        --rotate-key) ACTION="rotate"; shift ;;
        --uninstall) ACTION="uninstall"; shift ;;
        --help|-h) usage; exit 0 ;;
        *) usage; die "Неизвестный параметр: $1" ;;
    esac
done

[[ "${EUID}" -eq 0 ]] || die "Запустите от root: sudo bash $0 $*"
[[ "${PORT}" =~ ^[0-9]+$ && "${PORT}" -ge 1024 && "${PORT}" -le 65535 ]] || die "--port: число от 1024 до 65535."
[[ -z "${TLS_CERT}${TLS_KEY}" || ( -f "${TLS_CERT}" && -f "${TLS_KEY}" ) ]] || die "Для HTTPS укажите оба файла: --tls-cert и --tls-key."
if [[ -n "${ALLOW}" ]]; then
    for ip in ${ALLOW//,/ }; do
        [[ "${ip}" =~ ^[0-9a-fA-F:.]+(/[0-9]{1,3})?$ ]] || die "--allow: адрес или сеть вида 10.0.0.15 или 10.0.0.0/24 (через запятую), а не «${ip}»."
    done
fi
mkdir -p "$(dirname "${LOG_FILE}")"; : >> "${LOG_FILE}"; chmod 600 "${LOG_FILE}"
printf '===== %s %s %s =====\n' "$(date '+%F %T')" "$0" "${ACTION}" >> "${LOG_FILE}"

OS_FAMILY=""
if [[ -r /etc/os-release ]]; then
    # В подоболочке: /etc/os-release задаёт свои переменные (NAME, VERSION…) — здесь они не нужны.
    OS_IDS=$(. /etc/os-release && printf '%s %s' "${ID:-}" "${ID_LIKE:-}")
    case " ${OS_IDS} " in
        *" debian "*|*" ubuntu "*) OS_FAMILY="debian" ;;
        *" rhel "*|*" fedora "*|*" centos "*) OS_FAMILY="rhel" ;;
    esac
fi

pkg_install() {
    case "${OS_FAMILY}" in
        debian) DEBIAN_FRONTEND=noninteractive apt-get update -qq >>"${LOG_FILE}" 2>&1 || true
                DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$@" >>"${LOG_FILE}" 2>&1 ;;
        rhel) dnf install -y -q "$@" >>"${LOG_FILE}" 2>&1 ;;
        *) die "Неизвестная ОС: поставьте вручную $*." ;;
    esac
}
mem_gb() { awk '/^MemTotal:/ {printf "%d", $2 / 1048576 + 0.5}' /proc/meminfo; }
gpu_gb() {
    local v=""
    if command -v nvidia-smi >/dev/null 2>&1; then
        v=$(nvidia-smi --query-gpu=memory.total --format=csv,noheader,nounits 2>/dev/null | awk 'NR==1 {printf "%d", $1 / 1024 + 0.5}' || true)
    fi
    printf '%s' "${v:-0}"
}
pick_model() {
    local ram gpu
    ram=$(mem_gb); gpu=$(gpu_gb)
    if [[ "${gpu}" -ge 24 || "${ram}" -ge 64 ]]; then echo "qwen2.5:32b"
    elif [[ "${gpu}" -ge 12 || "${ram}" -ge 32 ]]; then echo "qwen2.5:14b"
    else echo "qwen2.5:7b"; fi
}
host_address() {
    local a
    a=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i < NF; i++) if ($i == "src") {print $(i + 1); exit}}' || true)
    [[ -n "${a}" ]] || a=$(hostname -I 2>/dev/null | awk '{print $1}' || true)
    printf '%s' "${a:-$(hostname -f 2>/dev/null || hostname)}"
}
ollama_ok() { curl -fsS -m 5 http://127.0.0.1:11434/api/version >/dev/null 2>&1; }
scheme() { if grep -q 'ssl;' "${NGINX_CONF}" 2>/dev/null; then echo https; else echo http; fi; }
current_port() { awk '/listen/ {gsub(";", "", $2); print $2; exit}' "${NGINX_CONF}" 2>/dev/null || echo "${PORT}"; }

install_ollama() {
    if [[ -n "${ARCHIVE}" ]]; then
        [[ -f "${ARCHIVE}" ]] || die "Архив не найден: ${ARCHIVE}"
        case "${ARCHIVE}" in
            *.zst) command -v zstd >/dev/null 2>&1 || pkg_install zstd; tar --use-compress-program=unzstd -xf "${ARCHIVE}" -C /usr ;;
            *) tar -xzf "${ARCHIVE}" -C /usr ;;
        esac
    elif ! command -v ollama >/dev/null 2>&1; then
        local script
        script=$(mktemp)
        curl -fsSL --retry 3 -o "${script}" "${OLLAMA_INSTALL_URL}" 2>>"${LOG_FILE}" || die "Не удалось скачать установщик Ollama — сервер без интернета? Возьмите архив на https://ollama.com/download/linux и запустите с --archive ФАЙЛ."
        sh "${script}" >>"${LOG_FILE}" 2>&1 || die "Установка Ollama не удалась (журнал: ${LOG_FILE})."
        rm -f "${script}"
    fi
    command -v ollama >/dev/null 2>&1 || die "Программа ollama не найдена после установки."
    id ollama >/dev/null 2>&1 || useradd -r -s /bin/false -U -m -d /usr/share/ollama ollama
    if [[ ! -f /etc/systemd/system/ollama.service ]]; then
        cat > /etc/systemd/system/ollama.service <<EOF
[Unit]
Description=Ollama — языковые модели для портала документации
After=network-online.target

[Service]
ExecStart=$(command -v ollama) serve
User=ollama
Group=ollama
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
    fi
    mkdir -p "$(dirname "${OVERRIDE}")"
    cat > "${OVERRIDE}" <<EOF
# Создано ai-server.sh: Ollama слушает только этот сервер, портал заходит через nginx (порт ${PORT}, ключ).
[Service]
Environment="OLLAMA_HOST=127.0.0.1:11434"
Environment="OLLAMA_KEEP_ALIVE=24h"
Environment="OLLAMA_NUM_PARALLEL=4"
Environment="OLLAMA_MAX_LOADED_MODELS=2"
Environment="OLLAMA_CONTEXT_LENGTH=8192"
EOF
    systemctl daemon-reload
    systemctl enable ollama >>"${LOG_FILE}" 2>&1
    systemctl restart ollama
    local n=0
    until ollama_ok; do n=$((n + 1)); [[ ${n} -le 60 ]] || die "Ollama не отвечает: journalctl -u ollama"; sleep 2; done
    ok "Ollama работает: $(ollama --version 2>/dev/null | head -n1)"
}

pull() {
    info "Скачивание модели $1 (несколько гигабайт)…"
    if OLLAMA_HOST=127.0.0.1:11434 ollama pull "$1" >>"${LOG_FILE}" 2>&1; then ok "Модель $1 готова"; return 0; fi
    warn "Модель $1 не скачалась (журнал: ${LOG_FILE}). Без доступа к registry.ollama.ai скопируйте каталог моделей с другого сервера в /usr/share/ollama/.ollama/models и перезапустите: systemctl restart ollama"
    return 1
}

write_nginx() {
    local listen="${PORT}" tls="" allow=""
    command -v nginx >/dev/null 2>&1 || { info "Установка nginx…"; pkg_install nginx; }
    if [[ -n "${TLS_CERT}" ]]; then
        install -d -m 750 "${CONF_DIR}"
        install -m 644 "${TLS_CERT}" "${CONF_DIR}/tls.crt"
        install -m 600 "${TLS_KEY}" "${CONF_DIR}/tls.key"
        listen="${PORT} ssl"
        tls="    ssl_certificate ${CONF_DIR}/tls.crt;
    ssl_certificate_key ${CONF_DIR}/tls.key;
    ssl_protocols TLSv1.2 TLSv1.3;"
    fi
    if [[ -n "${ALLOW}" ]]; then
        for ip in ${ALLOW//,/ }; do allow+="    allow ${ip};"$'\n'; done
        allow+="    deny all;"
    fi
    install -d -m 755 "$(dirname "${NGINX_CONF}")"
    cat > "${NGINX_CONF}" <<EOF
# Вход портала документации к языковой модели (создано ai-server.sh). Ключ — ${KEY_FILE}.
map \$http_authorization \$docportal_ai_key_ok {
    default 0;
    "Bearer ${KEY}" 1;
}

server {
    listen ${listen};
    server_name _;
${tls}
${allow}
    client_max_body_size 32m;
    access_log /var/log/nginx/${SRV_NAME}.access.log;

    location = /health { return 200 "ok\n"; }

    location /v1/ {
        if (\$docportal_ai_key_ok = 0) { return 401 '{"error":{"message":"Неверный ключ доступа"}}'; }
        proxy_pass http://127.0.0.1:11434;
        proxy_http_version 1.1;
        proxy_set_header Host 127.0.0.1:11434;
        proxy_set_header Origin "";
        proxy_set_header Authorization "";
        proxy_buffering off;
        proxy_read_timeout 900s;
        proxy_send_timeout 900s;
    }

    location / { return 404; }
}
EOF
    chmod 640 "${NGINX_CONF}"
    # SELinux (AlmaLinux, Rocky, RHEL): nginx на нестандартном порту и с подключением к Ollama.
    if command -v getenforce >/dev/null 2>&1 && [[ "$(getenforce 2>/dev/null)" == "Enforcing" ]]; then
        setsebool -P httpd_can_network_connect 1 >>"${LOG_FILE}" 2>&1 || warn "setsebool httpd_can_network_connect не выполнен."
        command -v semanage >/dev/null 2>&1 || pkg_install policycoreutils-python-utils || true
        semanage port -a -t http_port_t -p tcp "${PORT}" >>"${LOG_FILE}" 2>&1 || semanage port -m -t http_port_t -p tcp "${PORT}" >>"${LOG_FILE}" 2>&1 || warn "Порт ${PORT} не добавлен в SELinux (semanage port -a -t http_port_t -p tcp ${PORT})."
    fi
    nginx -t >>"${LOG_FILE}" 2>&1 || die "Конфигурация nginx с ошибкой (журнал: ${LOG_FILE}): nginx -t"
    systemctl enable nginx >>"${LOG_FILE}" 2>&1 || true
    systemctl reload nginx >>"${LOG_FILE}" 2>&1 || systemctl restart nginx >>"${LOG_FILE}" 2>&1 || die "nginx не запустился: systemctl status nginx"
    ok "Вход для портала: порт ${PORT}$([[ -n "${TLS_CERT}" ]] && echo ', HTTPS'), ключ доступа$([[ -n "${ALLOW}" ]] && echo ", только с ${ALLOW}")"
}

open_firewall() {
    if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q '^Status: active'; then
        if [[ -n "${ALLOW}" ]]; then
            for ip in ${ALLOW//,/ }; do ufw allow from "${ip}" to any port "${PORT}" proto tcp >>"${LOG_FILE}" 2>&1 || true; done
        else
            ufw allow "${PORT}/tcp" >>"${LOG_FILE}" 2>&1 || true
        fi
        ok "Сетевой экран (ufw): порт ${PORT} открыт"
    elif command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then
        if [[ -n "${ALLOW}" ]]; then
            for ip in ${ALLOW//,/ }; do
                local family="ipv4"; [[ "${ip}" == *:* ]] && family="ipv6"
                firewall-cmd --permanent --add-rich-rule="rule family=${family} source address=${ip} port port=${PORT} protocol=tcp accept" >>"${LOG_FILE}" 2>&1 || true
            done
        else
            firewall-cmd --permanent --add-port="${PORT}/tcp" >>"${LOG_FILE}" 2>&1 || true
        fi
        firewall-cmd --reload >>"${LOG_FILE}" 2>&1 || true
        ok "Сетевой экран (firewalld): порт ${PORT} открыт"
    fi
}

print_connect() {
    local url
    url="$(scheme)://$(host_address):$(current_port)/v1"
    printf '\n%sНа сервере портала выполните:%s\n\n' "${C_BOLD}" "${C_RESET}"
    printf '  sudo docportal ai connect --url %s --api-key %s --model %s%s\n\n' "${url}" "$(cat "${KEY_FILE}")" "${MODEL:-$(cat "${CONF_DIR}/model" 2>/dev/null || echo qwen2.5:7b)}" \
        "$( e=$(cat "${CONF_DIR}/embeddings" 2>/dev/null || true); [[ -n "${e}" ]] && printf ' --embeddings %s' "${e}" || true )"
    printf 'Только для помощника (описания документов — как настроены сейчас): добавьте --assistant-only.\n'
    printf 'Или в портале: Панель администратора → Интеграции → «Помощник» → «Отдельный сервер модели»: адрес %s и ключ выше.\n' "${url}"
}

case "${ACTION}" in
    status)
        ollama_ok && ok "Ollama работает" || warn "Ollama не отвечает: systemctl status ollama"
        OLLAMA_HOST=127.0.0.1:11434 ollama list 2>/dev/null || true
        if [[ -f "${NGINX_CONF}" && -f "${KEY_FILE}" ]]; then
            code=$(curl -ks -o /dev/null -w '%{http_code}' -m 10 -H "Authorization: Bearer $(cat "${KEY_FILE}")" "$(scheme)://127.0.0.1:$(current_port)/v1/models" || true)
            [[ "${code}" == "200" ]] && ok "Вход для портала отвечает (порт $(current_port))" || warn "Вход для портала ответил HTTP ${code:-нет ответа} (nginx -t; systemctl status nginx)"
            print_connect
        else
            warn "Вход для портала не настроен — запустите скрипт без --status."
        fi
        exit 0 ;;
    rotate)
        [[ -f "${NGINX_CONF}" ]] || die "Вход для портала не настроен."
        KEY=$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')
        sed -i "s|\"Bearer [^\"]*\" 1;|\"Bearer ${KEY}\" 1;|" "${NGINX_CONF}"
        printf '%s\n' "${KEY}" > "${KEY_FILE}"; chmod 600 "${KEY_FILE}"
        nginx -t >>"${LOG_FILE}" 2>&1 && systemctl reload nginx
        ok "Новый ключ выдан — прежний больше не действует."
        print_connect
        exit 0 ;;
    uninstall)
        if [[ "${ASSUME_YES}" != "1" ]]; then
            a=""; read -r -p "Удалить вход для портала, Ollama и скачанные модели? [y/N] " a || true
            [[ "${a}" =~ ^[YyДд] ]] || exit 0
        fi
        rm -f "${NGINX_CONF}"; nginx -t >/dev/null 2>&1 && systemctl reload nginx || true
        systemctl disable --now ollama >>"${LOG_FILE}" 2>&1 || true
        rm -f /etc/systemd/system/ollama.service "${OVERRIDE}"; systemctl daemon-reload
        rm -f "$(command -v ollama 2>/dev/null || echo /nonexistent)"
        rm -rf /usr/share/ollama /usr/lib/ollama /usr/local/lib/ollama "${CONF_DIR}"
        id ollama >/dev/null 2>&1 && userdel ollama 2>/dev/null || true
        ok "Удалено."
        exit 0 ;;
esac

# --- Установка ------------------------------------------------------------------------------------------------
command -v systemctl >/dev/null 2>&1 && [[ -d /run/systemd/system ]] || die "Нужен systemd."
command -v curl >/dev/null 2>&1 || pkg_install curl
[[ -n "${MODEL}" ]] || MODEL=$(pick_model)
printf '%sСервер языковой модели для портала документации%s\n' "${C_BOLD}" "${C_RESET}"
info "Память: $(mem_gb) ГБ, ядер: $(nproc), видеокарта NVIDIA: $( g=$(gpu_gb); [[ "${g}" -gt 0 ]] && echo "${g} ГБ" || echo нет )"
info "Модель для ответов: ${MODEL}${EMBEDDINGS:+, эмбеддинги: ${EMBEDDINGS}}; вход для портала — порт ${PORT}${ALLOW:+, только с ${ALLOW}}"
[[ -n "${ALLOW}" ]] || warn "Не указан --allow: вход откроется с любого адреса (только по ключу). Укажите адрес сервера портала: --allow 10.0.0.15"
[[ "$(gpu_gb)" -gt 0 ]] || warn "Видеокарты NVIDIA нет — модель будет отвечать на процессоре (десятки секунд на ответ)."
if [[ "${ASSUME_YES}" != "1" && -t 0 ]]; then
    a=""; read -r -p "Продолжить? [Y/n] " a || true
    [[ -z "${a}" || "${a}" =~ ^[YyДд] ]] || exit 0
fi

step "Ollama"
install_ollama
step "Модели"
pull "${MODEL}" || die "Без модели для ответов портал не сможет отвечать."
if [[ -n "${EMBEDDINGS}" ]] && ! pull "${EMBEDDINGS}"; then EMBEDDINGS=""; fi
install -d -m 750 "${CONF_DIR}"
printf '%s\n' "${MODEL}" > "${CONF_DIR}/model"
printf '%s\n' "${EMBEDDINGS}" > "${CONF_DIR}/embeddings"
step "Вход для портала"
if [[ -z "${KEY}" ]]; then
    KEY=$(cat "${KEY_FILE}" 2>/dev/null || true)
    [[ -n "${KEY}" ]] || KEY=$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')
fi
[[ "${KEY}" =~ ^[A-Za-z0-9._~+-]{16,}$ ]] || die "--key: не короче 16 символов (буквы, цифры, . _ ~ + -)."
printf '%s\n' "${KEY}" > "${KEY_FILE}"; chmod 600 "${KEY_FILE}"
write_nginx
open_firewall
step "Проверка"
code=$(curl -ks -o /dev/null -w '%{http_code}' -m 20 -H "Authorization: Bearer ${KEY}" "$(scheme)://127.0.0.1:${PORT}/v1/models" || true)
[[ "${code}" == "200" ]] && ok "Вход отвечает: $(scheme)://127.0.0.1:${PORT}/v1" || warn "Проверка входа: HTTP ${code:-нет ответа} — nginx -t; journalctl -u nginx"
code=$(curl -ks -o /dev/null -w '%{http_code}' -m 10 "$(scheme)://127.0.0.1:${PORT}/v1/models" || true)
[[ "${code}" == "401" ]] && ok "Без ключа вход закрыт" || warn "Без ключа вход ответил HTTP ${code} — проверьте ${NGINX_CONF}"
print_connect
