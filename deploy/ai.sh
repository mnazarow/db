#!/usr/bin/env bash
# =============================================================================
#  Языковая модель на своём сервере для портала документации: помощник (чат на портале и в Telegram), ответы
#  на странице поиска, описания документов и поиск по смыслу — без облачных сервисов.
#
#    sudo docportal ai enable [--model ИМЯ] [--embeddings ИМЯ | --no-embeddings] [--gpu] [--archive ФАЙЛ]
#    sudo docportal ai connect --url http://gpu-server:11435/v1 --model ИМЯ [--api-key КЛЮЧ] [--assistant-only]
#                                              — модель на другом сервере (подготовленном deploy/ai-server.sh,
#                                                Ollama, vLLM или облачном сервисе с OpenAI-совместимым API)
#    docportal ai status | test | models
#    sudo docportal ai pull ИМЯ                — скачать ещё одну модель
#    sudo docportal ai disable [--remove]      — выключить (с --remove — удалить Ollama и модели)
#
#  Сервер моделей — Ollama (OpenAI-совместимый API). Режим native: Ollama ставится службой systemd и слушает
#  только 127.0.0.1:11434; режим Docker: контейнер ollama (профиль compose «ai»), портал обращается к нему по
#  внутренней сети. Модели по умолчанию — по объёму памяти: qwen2.5:14b (от 32 ГБ или видеокарта от 16 ГБ),
#  qwen2.5:7b (от 12 ГБ), qwen2.5:3b (от 8 ГБ); эмбеддинги — bge-m3. Без доступа в интернет: Ollama — из архива
#  (--archive ollama-linux-amd64.tgz), модели — копией каталога моделей с другого сервера (см. РУКОВОДСТВО).
# =============================================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/common.sh
. "${SCRIPT_DIR}/common.sh"

OLLAMA_PORT="11434"
OLLAMA_LOCAL_URL="http://127.0.0.1:${OLLAMA_PORT}"
OLLAMA_DOCKER_URL="http://ollama:${OLLAMA_PORT}"
OLLAMA_INSTALL_URL="${OLLAMA_INSTALL_URL:-https://ollama.com/install.sh}"
OLLAMA_OVERRIDE="/etc/systemd/system/ollama.service.d/${APP_ID}.conf"
AI_DEFAULT_EMBEDDINGS="bge-m3"

usage() {
    cat <<EOF
Языковая модель на своём сервере для портала «${APP_TITLE}».

Использование: ${APP_ID} ai <команда> [параметры]
  enable              Установить Ollama, скачать модели и включить их в портале
      --model ИМЯ         модель для ответов (по умолчанию — по объёму памяти: qwen2.5:14b / 7b / 3b)
      --embeddings ИМЯ    модель эмбеддингов для поиска по смыслу (по умолчанию ${AI_DEFAULT_EMBEDDINGS})
      --no-embeddings     без поиска по смыслу
      --gpu               видеокарта NVIDIA (в Docker — подключить её к контейнеру ollama)
      --archive ФАЙЛ      Ollama из архива (ollama-linux-amd64.tgz) — сервер без интернета
      --yes, -y           не спрашивать подтверждения
  connect             Подключить модель на другом сервере (ничего не устанавливая здесь)
      --url АДРЕС         адрес API: http://сервер:11435/v1 (ai-server.sh), …:11434/v1 (Ollama), …:8000/v1 (vLLM)
      --model ИМЯ         модель для ответов (обязательно)
      --api-key КЛЮЧ      ключ (ai-server.sh выводит его в конце установки; облачные сервисы — из кабинета)
      --embeddings ИМЯ    модель эмбеддингов на том же сервере (по умолчанию — без поиска по смыслу)
      --assistant-only    только для помощника и ответов; описания документов — как настроены сейчас
  status              Состояние: сервер моделей, модели, настройки портала
  test                Пробный вопрос модели и пробный вектор
  models              Скачанные модели
  pull ИМЯ            Скачать модель
  disable [--remove]  Выключить модель в портале (с --remove — удалить Ollama и скачанные модели)
EOF
}

CMD="${1:-help}"; shift || true
MODEL=""; EMBEDDINGS="${AI_DEFAULT_EMBEDDINGS}"; GPU="0"; ARCHIVE=""; REMOTE_URL=""; ASSUME_YES="0"; REMOVE="0"
API_KEY=""; ASSISTANT_ONLY="0"; EMBEDDINGS_SET="0"
while [[ $# -gt 0 ]]; do
    case "$1" in
        --api-key) API_KEY="${2:-}"; shift; [[ $# -gt 0 ]] && shift ;;
        --assistant-only) ASSISTANT_ONLY="1"; shift ;;
        --model) MODEL="${2:-}"; shift; [[ $# -gt 0 ]] && shift ;;
        --embeddings) EMBEDDINGS="${2:-}"; EMBEDDINGS_SET="1"; shift; [[ $# -gt 0 ]] && shift ;;
        --no-embeddings) EMBEDDINGS=""; EMBEDDINGS_SET="1"; shift ;;
        --gpu) GPU="1"; shift ;;
        --archive) ARCHIVE="${2:-}"; shift; [[ $# -gt 0 ]] && shift ;;
        --url) REMOTE_URL="${2:-}"; shift; [[ $# -gt 0 ]] && shift ;;
        --remove) REMOVE="1"; shift ;;
        --yes|-y) ASSUME_YES="1"; shift ;;
        --help|-h) usage; exit 0 ;;
        -*) usage; die "Неизвестный параметр: $1" ;;
        *) if [[ "${CMD}" == "pull" && -z "${MODEL}" ]]; then MODEL="$1"; shift; else usage; die "Лишний параметр: $1"; fi ;;
    esac
done

load_conf || die "Портал не установлен: не найден ${CONF_FILE}."

# --- Общие функции ------------------------------------------------------------------------------------------
portal_console() {
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        (cd "${APP_DIR}/docker" && docker compose exec -T -u www-data app php bin/console "$@" --no-interaction)
    else
        runuser -u "${SERVICE_USER}" -- "$(php_bin_for "${PHP_VERSION:-}")" "${APP_DIR}/current/bin/console" "$@" --env=prod --no-interaction
    fi
}
compose() { (cd "${APP_DIR}/docker" && docker compose "$@"); }
docker_env_get() { grep -E "^$1=" "${APP_DIR}/docker/.env" 2>/dev/null | tail -n1 | cut -d= -f2- || true; }
docker_env_set() {
    local file="${APP_DIR}/docker/.env" key=$1 value=$2
    if grep_has -E "^${key}=" "${file}"; then sed -i "s|^${key}=.*|${key}=${value}|" "${file}"; else printf '%s=%s\n' "${key}" "${value}" >> "${file}"; fi
}
# Профили compose без «ai» / с «ai».
profiles_without_ai() {
    local p out=() IFS=','
    for p in $1; do [[ -z "${p}" || "${p}" == "ai" ]] || out+=("${p}"); done
    printf '%s' "${out[*]}"
}
# ollama CLI: на сервере — программа, в Docker — в контейнере.
ollama_cli() {
    if [[ "${INSTALL_MODE}" == "docker" ]]; then compose exec -T ollama ollama "$@"; else OLLAMA_HOST="127.0.0.1:${OLLAMA_PORT}" ollama "$@"; fi
}
ollama_up() {
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        compose exec -T ollama ollama list >/dev/null 2>&1
    else
        curl -fsS -m 5 "${OLLAMA_LOCAL_URL}/api/version" >/dev/null 2>&1
    fi
}
wait_ollama() {
    local n=0
    until ollama_up; do
        n=$((n + 1)); [[ ${n} -le 60 ]] || return 1
        sleep 2
    done
}

mem_total_gb() { awk '/^MemTotal:/ {printf "%d", $2 / 1048576 + 0.5}' /proc/meminfo 2>/dev/null || echo 0; }
gpu_mem_gb() {
    command -v nvidia-smi >/dev/null 2>&1 || { echo 0; return 0; }
    nvidia-smi --query-gpu=memory.total --format=csv,noheader,nounits 2>/dev/null | awk 'NR==1 {printf "%d", $1 / 1024 + 0.5}' || echo 0
}
# Модель по объёму памяти: 14b — от 32 ГБ ОЗУ или 16 ГБ видеопамяти, 7b — от 12 ГБ, 3b — от 8 ГБ.
pick_model() {
    local ram gpu
    ram=$(mem_total_gb); gpu=$(gpu_mem_gb)
    if [[ "${gpu:-0}" -ge 16 || "${ram:-0}" -ge 32 ]]; then echo "qwen2.5:14b"
    elif [[ "${gpu:-0}" -ge 8 || "${ram:-0}" -ge 12 ]]; then echo "qwen2.5:7b"
    else echo "qwen2.5:3b"; fi
}
models_dir() {
    if [[ "${INSTALL_MODE}" == "docker" ]]; then docker volume inspect -f '{{ .Mountpoint }}' "$(basename "${APP_DIR}/docker" | tr -cd 'a-z0-9_-')_ollama-data" 2>/dev/null || echo "/var/lib/docker"
    elif [[ -d /usr/share/ollama/.ollama ]]; then echo /usr/share/ollama/.ollama
    else echo /var/lib/ollama; fi
}

# --- Установка Ollama (native) ------------------------------------------------------------------------------
install_ollama_native() {
    if command -v ollama >/dev/null 2>&1 && [[ -z "${ARCHIVE}" ]]; then
        ok "Ollama уже установлена: $(ollama --version 2>/dev/null | head -n1)"
    elif [[ -n "${ARCHIVE}" ]]; then
        [[ -f "${ARCHIVE}" ]] || die "Архив не найден: ${ARCHIVE}"
        info "Распаковка Ollama из ${ARCHIVE} в /usr…"
        case "${ARCHIVE}" in
            *.zst) command -v zstd >/dev/null 2>&1 || pkg_install zstd; tar --use-compress-program=unzstd -xf "${ARCHIVE}" -C /usr ;;
            *) tar -xzf "${ARCHIVE}" -C /usr ;;
        esac
        command -v ollama >/dev/null 2>&1 || die "В архиве нет программы ollama (ожидается bin/ollama)."
        ok "Ollama установлена из архива: $(ollama --version 2>/dev/null | head -n1)"
    else
        info "Установка Ollama (${OLLAMA_INSTALL_URL})…"
        local script
        script=$(mktemp)
        download "${OLLAMA_INSTALL_URL}" "${script}" || { rm -f "${script}"; die "Не удалось скачать установщик Ollama. Сервер без интернета: скачайте ollama-linux-amd64.tgz на другом компьютере (https://ollama.com/download/linux) и запустите: ${APP_ID} ai enable --archive ФАЙЛ"; }
        sh "${script}" >>"${LOG_FILE}" 2>&1 9>&- || { rm -f "${script}"; die "Установка Ollama не удалась (журнал: ${LOG_FILE})."; }
        rm -f "${script}"
        ok "Ollama установлена: $(ollama --version 2>/dev/null | head -n1)"
    fi
    # Служба: установщик создаёт её сам; из архива — создаём (пользователь ollama, модели в /usr/share/ollama).
    if ! id ollama >/dev/null 2>&1; then
        useradd -r -s /bin/false -U -m -d /usr/share/ollama ollama
    fi
    if has_systemd && [[ ! -f /etc/systemd/system/ollama.service ]]; then
        cat > /etc/systemd/system/ollama.service <<EOF
[Unit]
Description=Ollama — языковые модели для ${APP_TITLE}
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
    # Настройки для портала: только локальный адрес, модель держится в памяти сутки, окно контекста под источники.
    install -d -m 755 "$(dirname "${OLLAMA_OVERRIDE}")"
    cat > "${OLLAMA_OVERRIDE}" <<EOF
# Создано ${APP_ID} ai enable. Ollama слушает только этот сервер; портал — по адресу ${OLLAMA_LOCAL_URL}/v1.
[Service]
Environment="OLLAMA_HOST=127.0.0.1:${OLLAMA_PORT}"
Environment="OLLAMA_KEEP_ALIVE=24h"
Environment="OLLAMA_NUM_PARALLEL=2"
Environment="OLLAMA_MAX_LOADED_MODELS=2"
Environment="OLLAMA_CONTEXT_LENGTH=8192"
EOF
    if has_systemd; then
        systemctl daemon-reload
        systemctl enable --now ollama >>"${LOG_FILE}" 2>&1 || die "Служба ollama не запустилась: journalctl -u ollama"
        systemctl restart ollama >>"${LOG_FILE}" 2>&1 || true
    else
        (setsid nohup env OLLAMA_HOST="127.0.0.1:${OLLAMA_PORT}" OLLAMA_KEEP_ALIVE=24h ollama serve >>"${LOG_DIR}/ollama.log" 2>&1 &)
        warn "systemd не найден — Ollama запущена в фоне до перезагрузки сервера."
    fi
    wait_ollama || die "Ollama не отвечает на ${OLLAMA_LOCAL_URL}: journalctl -u ollama"
    ok "Ollama работает: ${OLLAMA_LOCAL_URL}"
}

# --- Ollama в Docker -----------------------------------------------------------------------------------------
enable_ollama_docker() {
    local file="${APP_DIR}/docker/.env" profiles
    [[ -f "${file}" ]] || die "Нет ${file}."
    grep_has 'ollama:' "${APP_DIR}/docker/docker-compose.yml" || die "В docker-compose.yml нет сервиса ollama — обновите портал до версии 1.8.0 или новее."
    profiles=$(profiles_without_ai "$(docker_env_get COMPOSE_PROFILES)")
    docker_env_set COMPOSE_PROFILES "${profiles:+${profiles},}ai"
    if [[ "${GPU}" == "1" ]]; then
        docker info 2>/dev/null | grep_has -i nvidia || warn "Docker не видит видеокарту NVIDIA (нет nvidia-container-toolkit?) — контейнер может не запуститься."
        docker_env_set COMPOSE_FILE "docker-compose.yml:docker-compose.gpu.yml"
    fi
    info "Запуск контейнера ollama (образ $(docker_env_get OLLAMA_IMAGE | grep . || echo ollama/ollama:latest))…"
    retry 3 20 compose pull ollama >>"${LOG_FILE}" 2>&1 9>&- || warn "Образ ollama не скачался — если Docker Hub недоступен, укажите зеркало (OLLAMA_IMAGE в docker/.env)."
    compose up -d ollama >>"${LOG_FILE}" 2>&1 9>&- || die "Контейнер ollama не запустился (журнал: ${LOG_FILE})."
    wait_ollama || die "Ollama не отвечает: cd ${APP_DIR}/docker && docker compose logs ollama"
    ok "Ollama работает в контейнере (портал обращается к ${OLLAMA_DOCKER_URL}/v1)"
}

pull_model() {
    local name=$1
    info "Скачивание модели ${name} (несколько гигабайт — может занять время)…"
    if ollama_cli pull "${name}" >>"${LOG_FILE}" 2>&1 9>&-; then
        ok "Модель ${name} скачана"
        return 0
    fi
    error "Модель ${name} не скачалась (журнал: ${LOG_FILE}). Без доступа к registry.ollama.ai скопируйте каталог моделей с другого сервера в $(models_dir)/models."
    return 1
}

configure_portal() {
    local url=$1
    local args=(app:ai:configure --url "${url}" --model "${MODEL}" --timeout 240 --max-input-chars 12000 --assistant-shared --enable)
    [[ -n "${EMBEDDINGS}" ]] && args+=(--embeddings-model "${EMBEDDINGS}")
    portal_console "${args[@]}" >>"${LOG_FILE}" 2>&1 || die "Не удалось записать настройки портала (журнал: ${LOG_FILE})."
    ok "Портал настроен: модель ${MODEL}${EMBEDDINGS:+, поиск по смыслу — ${EMBEDDINGS}} (${url})"
}

cmd_enable() {
    require_root "$@"
    log_init ai-enable
    banner "языковая модель на своём сервере"
    local ram cores free url
    ram=$(mem_total_gb); cores=$(nproc 2>/dev/null || echo 1)
    [[ -n "${MODEL}" ]] || MODEL=$(pick_model)
    step "Проверка сервера"
    info "Память: ${ram} ГБ, ядер: ${cores}, видеокарта NVIDIA: $( [[ "$(gpu_mem_gb)" -gt 0 ]] && echo "$(gpu_mem_gb) ГБ" || echo нет )"
    [[ "${ram}" -ge 8 ]] || warn "Памяти меньше 8 ГБ — модель будет отвечать медленно или не загрузится. Лучше отдельный сервер: deploy/ai-server.sh на нём и ${APP_ID} ai connect здесь."
    [[ "$(gpu_mem_gb)" -gt 0 ]] || info "Без видеокарты ответ модели ${MODEL} занимает обычно 10–60 секунд (на процессоре)."
    free=$(free_space_mb "$(dirname "$(models_dir)")" 2>/dev/null || echo 0)
    [[ "${free:-0}" -ge 15000 ]] || warn "Свободно меньше 15 ГБ ($(( ${free:-0} / 1024 )) ГБ) — моделям может не хватить места."
    info "Модель для ответов: ${MODEL}${EMBEDDINGS:+, эмбеддинги: ${EMBEDDINGS}}"
    if [[ "${ASSUME_YES}" != "1" ]] && ! confirm "Продолжить?"; then
        info "Отменено."; exit 0
    fi
    step "Сервер моделей Ollama"
    if [[ "${INSTALL_MODE}" == "docker" ]]; then enable_ollama_docker; url="${OLLAMA_DOCKER_URL}/v1"; else install_ollama_native; url="${OLLAMA_LOCAL_URL}/v1"; fi
    step "Модели"
    pull_model "${MODEL}" || die "Без модели для ответов портал не сможет отвечать."
    if [[ -n "${EMBEDDINGS}" ]] && ! pull_model "${EMBEDDINGS}"; then
        warn "Поиск по смыслу не включён — ответы ищутся по словам."
        EMBEDDINGS=""
    fi
    step "Настройки портала"
    configure_portal "${url}"
    step "Проверка"
    if portal_console app:ai:configure --test >>"${LOG_FILE}" 2>&1; then
        ok "Модель отвечает порталу"
    else
        warn "Пробный вопрос не прошёл (журнал: ${LOG_FILE}). Первая загрузка модели в память может занять минуту — повторите: ${APP_ID} ai test"
    fi
    if [[ -n "${EMBEDDINGS}" ]]; then
        info "Смысловой индекс документов строится в фоне (задание раз в час) — или сразу: ${APP_ID} ai-index --all"
    fi
    printf '\n'
    ok "Готово. Кнопка «Помощник» появилась на страницах портала у вошедших сотрудников; в Telegram помощник отвечает в чате с ботом."
    print_warnings_summary 2>/dev/null || true
}

# Модель на другом сервере: здесь ничего не ставится, портал настраивается на её адрес.
cmd_connect() {
    require_root "$@"
    log_init ai-connect
    [[ -n "${REMOTE_URL}" ]] || die "Укажите адрес: ${APP_ID} ai connect --url http://сервер:11435/v1 --model ИМЯ [--api-key КЛЮЧ]"
    [[ -n "${MODEL}" ]] || die "Укажите модель: --model (например, qwen2.5:14b — как на сервере моделей)."
    [[ "${EMBEDDINGS_SET}" == "1" ]] || EMBEDDINGS=""
    local url="${REMOTE_URL%/}" args code hdr
    [[ "${url}" == */v1 ]] || warn "Адрес обычно заканчивается на /v1 (OpenAI-совместимый API): ${url}"
    step "Проверка сервера модели"
    hdr=$(umask 077; mktemp)
    [[ -n "${API_KEY}" ]] && printf 'Authorization: Bearer %s\n' "${API_KEY}" > "${hdr}"
    code=$(curl -sS -o /dev/null -w '%{http_code}' -m 15 -H "@${hdr}" "${url}/models" 2>>"${LOG_FILE}" || true)
    rm -f "${hdr}"
    case "${code}" in
        200) ok "Сервер модели отвечает: ${url}" ;;
        401|403) die "Сервер модели отклонил ключ (HTTP ${code}) — проверьте --api-key." ;;
        000|"") die "Сервер ${url} недоступен с этого сервера — проверьте адрес, порт и сетевой экран (ai-server.sh --allow IP_портала)." ;;
        *) warn "Сервер модели ответил HTTP ${code} на ${url}/models — продолжаю, проверка ниже покажет, отвечает ли модель." ;;
    esac
    step "Настройки портала"
    if [[ "${ASSISTANT_ONLY}" == "1" ]]; then
        args=(app:ai:configure --assistant-url "${url}" --assistant-model "${MODEL}" --assistant-timeout 240 --enable)
        [[ -n "${API_KEY}" ]] && args+=(--assistant-api-key "${API_KEY}")
        if [[ -n "${EMBEDDINGS}" ]]; then
            args+=(--embeddings-model "${EMBEDDINGS}" --embeddings-url "${url}")
            [[ -n "${API_KEY}" ]] && args+=(--embeddings-api-key "${API_KEY}")
        fi
    else
        args=(app:ai:configure --url "${url}" --model "${MODEL}" --timeout 240 --max-input-chars 12000 --assistant-shared --enable)
        [[ -n "${API_KEY}" ]] && args+=(--api-key "${API_KEY}")
        [[ -n "${EMBEDDINGS}" ]] && args+=(--embeddings-model "${EMBEDDINGS}")
    fi
    portal_console "${args[@]}" >>"${LOG_FILE}" 2>&1 || die "Не удалось записать настройки портала (журнал: ${LOG_FILE})."
    ok "Портал настроен: ${MODEL} на ${url}$([[ "${ASSISTANT_ONLY}" == "1" ]] && echo ' (только помощник)')${EMBEDDINGS:+, поиск по смыслу — ${EMBEDDINGS}}"
    step "Проверка"
    if portal_console app:ai:configure --test; then
        ok "Готово: помощник отвечает моделью на ${url}."
    else
        warn "Пробный вопрос не прошёл. Первая загрузка модели в память на сервере моделей может занять минуту — повторите: ${APP_ID} ai test"
    fi
}

cmd_status() {
    if [[ "${INSTALL_MODE}" == "docker" ]]; then
        if [[ ",$(docker_env_get COMPOSE_PROFILES)," == *",ai,"* ]]; then
            ollama_up && ok "Ollama работает (контейнер ollama)" || warn "Профиль «ai» включён, но Ollama не отвечает: cd ${APP_DIR}/docker && docker compose logs ollama"
        else
            info "Ollama в Docker не включена (${APP_ID} ai enable)."
        fi
    elif command -v ollama >/dev/null 2>&1; then
        ollama_up && ok "Ollama работает: ${OLLAMA_LOCAL_URL} ($(ollama --version 2>/dev/null | head -n1))" || warn "Ollama установлена, но не отвечает: systemctl status ollama"
    else
        info "Ollama на этом сервере не установлена (модель может работать на другом сервере — см. настройки ниже)."
    fi
    if ollama_up; then
        printf '\nСкачанные модели:\n'
        ollama_cli list 2>/dev/null || true
        printf '\nЗагружены в память:\n'
        ollama_cli ps 2>/dev/null || true
    fi
    printf '\nНастройки портала:\n'
    portal_console app:ai:configure --status || true
}

case "${CMD}" in
    enable)
        # enable --url — то же, что connect (так подключали модель на другом сервере в первых сборках 1.8.0).
        if [[ -n "${REMOTE_URL}" ]]; then cmd_connect "$@"; else cmd_enable "$@"; fi ;;
    connect) cmd_connect "$@" ;;
    status) cmd_status ;;
    test) portal_console app:ai:configure --test ;;
    models) ollama_up || die "Ollama не отвечает."; ollama_cli list ;;
    pull)
        require_root "$@"; log_init ai-pull
        [[ -n "${MODEL}" ]] || die "Укажите модель: ${APP_ID} ai pull qwen2.5:7b"
        ollama_up || die "Ollama не отвечает — сначала ${APP_ID} ai enable."
        pull_model "${MODEL}" || exit 1
        info "Чтобы портал отвечал этой моделью: ${APP_ID} console app:ai:configure --model ${MODEL}"
        ;;
    disable)
        require_root "$@"; log_init ai-disable
        portal_console app:ai:configure --disable || warn "Настройки портала не изменены."
        if [[ "${REMOVE}" == "1" ]]; then
            if [[ "${INSTALL_MODE}" == "docker" ]]; then
                docker_env_set COMPOSE_PROFILES "$(profiles_without_ai "$(docker_env_get COMPOSE_PROFILES)")"
                [[ "$(docker_env_get COMPOSE_FILE)" == *gpu* ]] && sed -i '/^COMPOSE_FILE=/d' "${APP_DIR}/docker/.env"
                compose rm -sf ollama >>"${LOG_FILE}" 2>&1 || true
                docker volume rm "$(basename "${APP_DIR}/docker" | tr -cd 'a-z0-9_-')_ollama-data" >>"${LOG_FILE}" 2>&1 || warn "Том с моделями не удалён: docker volume ls | grep ollama"
            elif command -v ollama >/dev/null 2>&1; then
                has_systemd && { systemctl disable --now ollama >>"${LOG_FILE}" 2>&1 || true; rm -f /etc/systemd/system/ollama.service "${OLLAMA_OVERRIDE}"; systemctl daemon-reload; }
                rm -f "$(command -v ollama)"
                rm -rf /usr/share/ollama /usr/lib/ollama /usr/local/lib/ollama
                id ollama >/dev/null 2>&1 && userdel ollama 2>/dev/null || true
            fi
            ok "Ollama и скачанные модели удалены."
        else
            info "Ollama и модели остались на сервере: снова включить — ${APP_ID} ai enable, удалить — ${APP_ID} ai disable --remove."
        fi
        ;;
    help|--help|-h) usage ;;
    *) usage; die "Неизвестная команда: ${CMD}" ;;
esac
