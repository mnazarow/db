#!/usr/bin/env bash
# =============================================================================
#  Обновление портала документации до новой версии одной командой.
#
#  Запускается из каталога НОВОЙ версии:   sudo ./deploy/update.sh
#  или с указанием источника:              sudo docportal update --archive docportal-1.6.0.tar.gz
#
#  Порядок: резервная копия → новый релиз рядом с текущим → миграции → переключение →
#  проверка работоспособности; при ошибке — автоматический откат на предыдущий релиз.
# =============================================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/common.sh
. "${SCRIPT_DIR}/common.sh"
# shellcheck source=deploy/video.sh
. "${SCRIPT_DIR}/video.sh"

SOURCE_DIR=""
ARCHIVE=""
KEEP_RELEASES=3
ASSUME_YES="0"
NO_BACKUP="0"
NO_DB_ROLLBACK="0"
NO_MAINT="0"
# Восстановлена ли база при откате (0 — миграции изменили базу, а восстановить её не удалось).
DB_RESTORED="1"
# Включил ли этот запуск режим обслуживания (выключается только такой — включённый администратором остаётся).
MAINT_SET="0"
CREATED_TMP=""
NEW_RELEASE=""
PREV_RELEASE=""
BACKUP_FILE=""
SWITCHED="0"
MIGRATED="0"
# Docker: создал ли этот запуск тег :previous и копию кода .prev (откатываться можно только на них —
# тег и копия, оставшиеся от прошлых обновлений, вернули бы позапрошлую версию поверх новой схемы базы).
TAGGED_PREVIOUS="0"
SAVED_PREV="0"
APP_IMAGE_NAME=""
# Что не переносится между версиями кода в Docker-режиме (данные и настройки этой установки).
# docker/nginx/video/ и docker/video/ — файлы раздела «Видео», созданные командой «docportal video enable».
PREV_EXCLUDES=(--exclude '/docker/.env' --exclude '/import/' --exclude '/backups/' --exclude '/var/' --exclude '/.env.local' --exclude '/.git/' --exclude '/docker/mysql-data/' --exclude '/maintenance/'
               --exclude '/docker/nginx/video/' --exclude '/docker/video/')

usage() {
    cat <<EOF
Обновление портала «${APP_TITLE}».

Использование: sudo $0 [параметры]
  --source DIR       Каталог с новой версией (по умолчанию — каталог, где лежит скрипт).
  --archive FILE     Архив новой версии (.tar.gz/.zip).
  --keep N           Сколько предыдущих релизов хранить (по умолчанию ${KEEP_RELEASES}).
  --no-backup        Не делать резервную копию перед обновлением (не рекомендуется).
  --no-db-rollback   При откате не восстанавливать базу данных из резервной копии.
  --no-maintenance   Не включать режим обслуживания (страницу «Идёт обновление портала») на время миграций.
  --yes, -y          Не спрашивать подтверждения.
  --help             Справка.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --source) SOURCE_DIR="${2:-}"; shift 2 ;;
        --archive) ARCHIVE="${2:-}"; shift 2 ;;
        --keep) KEEP_RELEASES="${2:-}"; shift 2 ;;
        --no-backup) NO_BACKUP="1"; shift ;;
        --no-db-rollback) NO_DB_ROLLBACK="1"; shift ;;
        --no-maintenance) NO_MAINT="1"; shift ;;
        --yes|-y) ASSUME_YES="1"; shift ;;
        --help|-h) usage; exit 0 ;;
        *) usage; die "Неизвестный параметр: $1" ;;
    esac
done

require_root "$@"
log_init update "$@"
load_conf || die "Портал не установлен: не найден ${CONF_FILE}. Для первой установки используйте install.sh."
[[ "${KEEP_RELEASES}" =~ ^[0-9]+$ ]] || die "--keep: укажите число."
acquire_lock 0
banner "обновление"

# --- Источник новой версии --------------------------------------------------------
if [[ -n "${ARCHIVE}" ]]; then
    [[ -f "${ARCHIVE}" ]] || die "Архив не найден: ${ARCHIVE}"
    CREATED_TMP=$(mktemp -d "/tmp/${APP_ID}-upd.XXXXXX")
    # Распакованный архив удаляем при любом завершении (в том числе при отмене); при перезапуске
    # из новой версии (exec ниже) ловушка не срабатывает — каталог удалит перезапущенный скрипт.
    trap '[[ -n "${CREATED_TMP:-}" ]] && rm -rf "${CREATED_TMP}"' EXIT
    case "${ARCHIVE}" in
        *.tar.gz|*.tgz) tar -xzf "${ARCHIVE}" -C "${CREATED_TMP}" || die "Не удалось распаковать архив." ;;
        *.zip) require_cmd unzip; unzip -q "${ARCHIVE}" -d "${CREATED_TMP}" || die "Не удалось распаковать архив." ;;
        *) die "Неизвестный формат архива." ;;
    esac
    if is_project_dir "${CREATED_TMP}"; then SOURCE_DIR="${CREATED_TMP}"; else
        sub=$(find "${CREATED_TMP}" -maxdepth 2 -name composer.json -printf '%h\n' | head -n1)
        [[ -n "${sub}" ]] && is_project_dir "${sub}" || die "В архиве не найден проект."
        SOURCE_DIR="${sub}"
    fi
elif [[ -z "${SOURCE_DIR}" ]]; then
    SOURCE_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
fi
SOURCE_DIR="$(cd "${SOURCE_DIR}" && pwd)"
is_project_dir "${SOURCE_DIR}" || die "Каталог «${SOURCE_DIR}» не похож на проект портала."

# Скрипты обновления берём из НОВОЙ версии: при запуске «docportal update --archive …» этот файл
# принадлежит установленной (старой) версии, а новая может содержать изменения в самих скриптах
# (команда управления, задания cron, шаги обновления). Один раз перезапускаемся из нового каталога.
if [[ -z "${DOCPORTAL_UPDATE_REEXEC:-}" && -f "${SOURCE_DIR}/deploy/update.sh" && -f "${SOURCE_DIR}/deploy/common.sh" \
      && "$(cd "${SOURCE_DIR}/deploy" && pwd)" != "${SCRIPT_DIR}" ]]; then
    REEXEC_ARGS=(--source "${SOURCE_DIR}" --keep "${KEEP_RELEASES}")
    [[ "${ASSUME_YES}" == "1" ]] && REEXEC_ARGS+=(--yes)
    [[ "${NO_BACKUP}" == "1" ]] && REEXEC_ARGS+=(--no-backup)
    [[ "${NO_DB_ROLLBACK}" == "1" ]] && REEXEC_ARGS+=(--no-db-rollback)
    # Параметр появился в 1.7.0: новой версии передаётся, только если она его знает.
    [[ "${NO_MAINT}" == "1" ]] && grep -q -- '--no-maintenance' "${SOURCE_DIR}/deploy/update.sh" && REEXEC_ARGS+=(--no-maintenance)
    export DOCPORTAL_UPDATE_REEXEC=1 DOCPORTAL_UPDATE_TMP="${CREATED_TMP}"
    info "Обновление продолжится скриптами новой версии: ${SOURCE_DIR}/deploy/update.sh"
    exec bash "${SOURCE_DIR}/deploy/update.sh" "${REEXEC_ARGS[@]}"
fi
# Временный каталог распакованного архива (из первого запуска) удаляем по завершении.
if [[ -n "${DOCPORTAL_UPDATE_TMP:-}" && -z "${CREATED_TMP}" ]]; then
    CREATED_TMP="${DOCPORTAL_UPDATE_TMP}"
    trap '[[ -n "${CREATED_TMP:-}" ]] && rm -rf "${CREATED_TMP}"' EXIT
fi
NEW_VERSION=$(project_version "${SOURCE_DIR}")
info "Текущая версия: ${APP_VERSION}; новая версия: ${NEW_VERSION}; источник: ${SOURCE_DIR}"
if [[ "${INSTALL_MODE}" == "native" && "${SOURCE_DIR}" == "${APP_DIR}"/* ]]; then
    die "Источник обновления находится внутри каталога установки. Распакуйте новую версию в другой каталог (например, /tmp) и запустите update.sh оттуда."
fi
if [[ "${ASSUME_YES}" != "1" ]]; then
    confirm "Обновить портал ${APP_VERSION} → ${NEW_VERSION}?" || die "Обновление отменено."
fi

# --- Откат ------------------------------------------------------------------------
rollback() {
    warn "Откат обновления…"
    if [[ "${INSTALL_MODE}" == "native" ]]; then
        if [[ "${SWITCHED}" == "1" && -n "${PREV_RELEASE}" && -d "${PREV_RELEASE}" ]]; then
            ln -sfn "${PREV_RELEASE}" "${APP_DIR}/current"
            svc reload "${PHP_FPM_SERVICE}" || svc restart "${PHP_FPM_SERVICE}" || true
            svc reload nginx || true
            ok "Возвращён предыдущий релиз: ${PREV_RELEASE}"
        fi
        if [[ "${MIGRATED}" == "1" ]]; then
            DB_RESTORED="0"
            if [[ "${NO_DB_ROLLBACK}" != "1" && -n "${BACKUP_FILE}" && -f "${BACKUP_FILE}" ]]; then
                warn "Восстановление базы данных из ${BACKUP_FILE}…"
                if "${SCRIPT_DIR}/restore.sh" "${BACKUP_FILE}" --db-only --yes --no-safety-backup; then
                    DB_RESTORED="1"
                else
                    warn "Не удалось восстановить базу данных автоматически. Восстановите вручную: ${APP_ID} restore ${BACKUP_FILE} --db-only"
                fi
            fi
        fi
        [[ -n "${NEW_RELEASE}" && -d "${NEW_RELEASE}" ]] && rm -rf "${NEW_RELEASE}"
    else
        # Сначала прежний код (в том числе docker-compose.yml), затем прежний образ: контейнеры пересоздаются
        # по прежнему описанию, а не по новому с прежним образом.
        if [[ "${SAVED_PREV}" == "1" && -d "${APP_DIR}.prev" ]]; then
            rsync -a --delete "${PREV_EXCLUDES[@]}" "${APP_DIR}.prev/" "${APP_DIR}/" >>"${LOG_FILE}" 2>&1 || true
            rm -rf "${APP_DIR}.prev"
        fi
        if [[ "${TAGGED_PREVIOUS}" == "1" ]] && docker image inspect "${APP_ID}:previous" >/dev/null 2>&1; then
            warn "Возврат предыдущего образа контейнера…"
            (cd "${APP_DIR}/docker" && docker tag "${APP_ID}:previous" "${APP_IMAGE_NAME}" && docker compose up -d >>"${LOG_FILE}" 2>&1) || warn "Не удалось вернуть предыдущий образ."
        fi
        if [[ "${MIGRATED}" == "1" ]]; then
            DB_RESTORED="0"
            if [[ "${NO_DB_ROLLBACK}" != "1" && -n "${BACKUP_FILE}" && -f "${BACKUP_FILE}" ]]; then
                if "${SCRIPT_DIR}/restore.sh" "${BACKUP_FILE}" --db-only --yes --no-safety-backup; then
                    DB_RESTORED="1"
                else
                    warn "Не удалось восстановить базу данных автоматически."
                fi
            fi
        fi
    fi
    [[ -n "${CREATED_TMP}" ]] && rm -rf "${CREATED_TMP}" || true
    if [[ "${DB_RESTORED}" == "0" ]]; then
        # Миграции новой версии уже изменили базу, а прежняя версия с ней может работать неправильно:
        # портал остаётся закрытым, пока администратор не восстановит базу или не повторит обновление.
        if [[ "${MAINT_SET}" == "1" ]]; then
            printf 'admin\n' > "$(maintenance_dir)/owner" 2>/dev/null || true
            MAINT_SET="0"
            warn "Режим обслуживания оставлен включённым: база данных изменена миграциями новой версии и не восстановлена."
        fi
        warn "Восстановите базу (${APP_ID} restore ${BACKUP_FILE:-АРХИВ} --db-only) или повторите обновление; затем: ${APP_ID} maintenance off"
        warn "Откат кода завершён (версия ${APP_VERSION}), база данных НЕ восстановлена."
        return 0
    fi
    maintenance_end
    warn "Откат завершён. Портал работает на версии ${APP_VERSION}."
}

# Режим обслуживания на время миграций и переключения версии: посетители видят страницу «Идёт обновление
# портала», а не ошибки прежнего кода на новой схеме базы. Режим, включённый администратором до обновления,
# после обновления остаётся включённым.
maintenance_begin() {
    [[ "${NO_MAINT}" == "1" ]] && return 0
    if ! maintenance_wired; then
        warn "Режим обслуживания не подключён к веб-серверу — во время миграций портал будет открыт посетителям."
        return 0
    fi
    if maintenance_is_on; then
        if [[ "$(maintenance_owner)" == "update" ]]; then
            # Режим остался от прерванного обновления — после успешного обновления он выключится.
            MAINT_SET="1"
            info "Режим обслуживания остался включённым после прерванного обновления — выключится после этого обновления."
        else
            info "Режим обслуживания уже включён администратором — после обновления он останется включённым."
        fi
        return 0
    fi
    if maintenance_on "Обновление до версии ${NEW_VERSION}." "" update; then
        MAINT_SET="1"
        info "Включён режим обслуживания: посетители видят страницу «Идёт обновление портала»."
    else
        warn "Не удалось включить режим обслуживания — обновление продолжается без него."
    fi
}
maintenance_end() {
    if [[ "${MAINT_SET}" == "1" ]]; then
        maintenance_off
        MAINT_SET="0"
        ok "Режим обслуживания выключен — портал снова открыт посетителям."
    fi
    return 0
}

setup_traps
ROLLBACK_ENABLED="1"

# Режим обслуживания включается до резервной копии: всё, что сотрудники успели бы изменить между копией и
# миграциями, пропало бы при откате (а загруженные файлы остались бы без записей в базе). Портал закрыт на
# время копии, установки новой версии и миграций; --no-maintenance — оставить его открытым.
if [[ "${NO_MAINT}" != "1" ]]; then
    if [[ "${INSTALL_MODE}" == "native" ]]; then
        ensure_nginx_maintenance || warn "Обновление продолжится без страницы обслуживания."
    fi
    maintenance_begin
fi

# --- Резервная копия --------------------------------------------------------------
if [[ "${NO_BACKUP}" != "1" ]]; then
    step "Резервная копия перед обновлением"
    BACKUP_FILE=$("${SCRIPT_DIR}/backup.sh" --tag before-update-"${NEW_VERSION}" --keep 0 --quiet) || die "Не удалось создать резервную копию. Обновление прервано."
    ok "Резервная копия: ${BACKUP_FILE}"
fi

# Проверка /health (health_probe из common.sh: при включённом HTTPS — https://ДОМЕН/health,
# перенаправления HTTP→HTTPS отслеживаются).
health() {
    local body
    if body=$(wait_for_health 40 3); then
        ok "Портал отвечает: ${body}"
    else
        error "Портал не отвечает после обновления на $(health_url) (${body:-нет ответа})."
        return 1
    fi
}

if [[ "${INSTALL_MODE}" == "native" ]]; then
    PHP_BIN=$(php_bin_for "${PHP_VERSION}")
    [[ -n "${PHP_BIN}" ]] || die "Не найден php${PHP_VERSION}."
    PREV_RELEASE=$(readlink -f "${APP_DIR}/current" 2>/dev/null || true)
    NEW_RELEASE="${APP_DIR}/releases/${NEW_VERSION}-$(date '+%Y%m%d%H%M%S')"

    step "Развёртывание релиза ${NEW_RELEASE}"
    mkdir -p "${NEW_RELEASE}"
    rsync -a --exclude '/var/' --exclude '/.env.local' --exclude '/.env.*.local' --exclude '/.git/' \
        --exclude '/docker/.env' --exclude '/backups/' "${SOURCE_DIR}/" "${NEW_RELEASE}/" >>"${LOG_FILE}" 2>&1 || die "Не удалось скопировать файлы новой версии."
    if [[ ! -d "${NEW_RELEASE}/vendor" ]]; then
        if [[ -d "${PREV_RELEASE}/vendor" ]] && cmp -s "${PREV_RELEASE}/composer.lock" "${NEW_RELEASE}/composer.lock"; then
            info "composer.lock не изменился — каталог vendor/ копируется из предыдущего релиза."
            rsync -a "${PREV_RELEASE}/vendor/" "${NEW_RELEASE}/vendor/" >>"${LOG_FILE}" 2>&1 || die "Не удалось скопировать vendor/."
        else
            command -v composer >/dev/null 2>&1 || die "Composer не найден, а в поставке нет vendor/. Установите composer или используйте архив с vendor/."
            (cd "${NEW_RELEASE}" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-progress >>"${LOG_FILE}" 2>&1) || die "composer install завершился с ошибкой."
        fi
    fi
    mkdir -p "${NEW_RELEASE}/var/cache" "${APP_DIR}/shared/storage" "${APP_DIR}/shared/log" "${APP_DIR}/shared/import"
    rm -rf "${NEW_RELEASE}/var/storage" "${NEW_RELEASE}/var/log" "${NEW_RELEASE}/var/import"
    ln -sfn "${APP_DIR}/shared/storage" "${NEW_RELEASE}/var/storage"
    ln -sfn "${APP_DIR}/shared/log" "${NEW_RELEASE}/var/log"
    ln -sfn "${APP_DIR}/shared/import" "${NEW_RELEASE}/var/import"
    ln -sfn "${APP_DIR}/shared/.env.local" "${NEW_RELEASE}/.env.local"
    # Каталог импорта появился в 1.1.0: у старых установок добавляем параметр и права.
    if ! grep -q '^IMPORT_DIR=' "${APP_DIR}/shared/.env.local" 2>/dev/null; then
        printf '\n# Каталог импорта: положите сюда папки с документами и запустите импорт в панели администратора.\nIMPORT_DIR=%s/shared/import\n' "${APP_DIR}" >> "${APP_DIR}/shared/.env.local"
    fi
    chown "${SERVICE_USER}:${SERVICE_USER}" "${APP_DIR}/shared/import" && chmod 2770 "${APP_DIR}/shared/import"
    chown -R root:"${SERVICE_USER}" "${NEW_RELEASE}"
    chown -R "${SERVICE_USER}:${SERVICE_USER}" "${NEW_RELEASE}/var"
    chmod -R u+rwX,g+rX,o-rwx "${NEW_RELEASE}" 2>/dev/null || true
    chmod 751 "${NEW_RELEASE}"; chmod -R o+rX "${NEW_RELEASE}/public"
    chmod +x "${NEW_RELEASE}/bin/console" "${NEW_RELEASE}"/deploy/*.sh 2>/dev/null || true
    [[ -d "${NEW_RELEASE}/video" ]] && chmod -R o+rX "${NEW_RELEASE}/video"
    if command -v restorecon >/dev/null 2>&1; then restorecon -R "${NEW_RELEASE}" >>"${LOG_FILE}" 2>&1 || true; fi
    # Раздел «Видео»: зависимости и веб-интерфейс видеопортала в новом релизе — до переключения, пока
    # прежняя версия продолжает работать (без них новая версия видеопортала не запустится).
    video_update_prepare "${NEW_RELEASE}" "${PREV_RELEASE}"

    console() { runuser -u "${SERVICE_USER}" -- "${PHP_BIN}" -d memory_limit=512M "${NEW_RELEASE}/bin/console" "$@" --no-interaction --env=prod >>"${LOG_FILE}" 2>&1; }


    step "Прогрев кэша и миграции"
    console cache:clear || die "Ошибка очистки кэша новой версии."
    console cache:warmup || die "Ошибка прогрева кэша новой версии."
    # База меняется, только если у новой версии есть миграции: иначе при откате её восстанавливать не нужно.
    if console doctrine:migrations:up-to-date; then
        info "Новых миграций базы данных нет."
    else
        MIGRATED="1"
        console doctrine:migrations:migrate --allow-no-migration || die "Ошибка применения миграций."
    fi
    console app:check || die "Проверка новой версии (app:check) не пройдена."

    step "Переключение на новый релиз"
    ln -sfn "${NEW_RELEASE}" "${APP_DIR}/current"; SWITCHED="1"
    svc reload "${PHP_FPM_SERVICE}" || svc restart "${PHP_FPM_SERVICE}" || die "Не удалось перезапустить ${PHP_FPM_SERVICE}."
    nginx -t >>"${LOG_FILE}" 2>&1 && svc reload nginx || true
    health || die "Новая версия не отвечает."
    maintenance_end
    install_cli_wrapper && info "Команда управления ${APP_ID} обновлена."
    ensure_cron_entries
    install_logrotate
    upgrade_native_system_config "${NEW_RELEASE}"
    # Видеопортал перезапускается на новой версии; его ошибка — предупреждение (портал документов уже обновлён).
    # Внутри — без отката обновления: портал к этому моменту обновлён и проверен.
    ( ROLLBACK_ENABLED="0"; video_update_finish ) || warn "Раздел «Видео» не перезапущен — проверьте: ${APP_ID} video status"
    # Извлечение текста из PDF (API, LLM) появилось в 1.3.0 — пакет poppler-utils ставим при обновлении, если его нет.
    # Обновление к этому моменту уже выполнено и проверено, поэтому любая неудача здесь — только предупреждение.
    if ! command -v pdftotext >/dev/null 2>&1; then
        if pkg_install_optional poppler-utils && command -v pdftotext >/dev/null 2>&1; then
            info "Установлен пакет poppler-utils (pdftotext)."
        else
            warn "Не удалось установить poppler-utils — текст из PDF для API и описаний через LLM извлекаться не будет (установите пакет вручную)."
        fi
    fi

    # Удаление старых релизов
    mapfile -t old < <(ls -1dt "${APP_DIR}"/releases/*/ 2>/dev/null | tail -n +$((KEEP_RELEASES + 2)))
    for d in "${old[@]:-}"; do
        [[ -n "${d}" && "$(readlink -f "${d}")" != "${NEW_RELEASE}" && "$(readlink -f "${d}")" != "${PREV_RELEASE}" ]] || continue
        rm -rf "${d}" && info "Удалён старый релиз: ${d}"
    done
else
    step "Обновление Docker-развёртывания"
    cd "${APP_DIR}/docker" || die "Каталог ${APP_DIR}/docker не найден."
    # Образ приложения — как в docker-compose.yml (APP_IMAGE в docker/.env, по умолчанию docportal:latest).
    APP_IMAGE_NAME=$(docker compose config --format json 2>/dev/null | python3 -c 'import json,sys; print(json.load(sys.stdin)["services"]["app"]["image"])' 2>/dev/null || true)
    [[ -n "${APP_IMAGE_NAME}" ]] || APP_IMAGE_NAME=$( (sed -n 's/^APP_IMAGE=//p' .env 2>/dev/null || true) | tail -n1 | tr -d "\"'")
    APP_IMAGE_NAME=${APP_IMAGE_NAME:-${APP_ID}:latest}
    if docker image inspect "${APP_IMAGE_NAME}" >/dev/null 2>&1 && docker tag "${APP_IMAGE_NAME}" "${APP_ID}:previous" >>"${LOG_FILE}" 2>&1; then
        TAGGED_PREVIOUS="1"
    fi
    rm -rf "${APP_DIR}.prev"
    if rsync -a "${PREV_EXCLUDES[@]}" "${APP_DIR}/" "${APP_DIR}.prev/" >>"${LOG_FILE}" 2>&1; then
        SAVED_PREV="1"
    else
        warn "Не удалось сохранить копию текущего кода."
    fi
    # /import/ — каталог импорта на сервере (IMPORT_HOST_DIR): его содержимое при обновлении не трогаем.
    # /maintenance/ — состояние режима обслуживания (каталог подключён в контейнер web).
    rsync -a --delete --exclude '/var/' --exclude '/.env.local' --exclude '/.git/' --exclude '/docker/.env' --exclude '/docker/mysql-data/' --exclude '/backups/' --exclude '/import/' \
        --exclude '/maintenance/' --exclude '/docker/nginx/video/' --exclude '/docker/video/' "${SOURCE_DIR}/" "${APP_DIR}/" >>"${LOG_FILE}" 2>&1 || die "Не удалось скопировать файлы новой версии."
    chmod +x "${APP_DIR}"/deploy/*.sh "${APP_DIR}/docker/entrypoint.sh" "${APP_DIR}/bin/console" 2>/dev/null || true
    docker compose build --pull app >>"${LOG_FILE}" 2>&1 || die "Сборка образа новой версии не удалась."
    # Раздел «Видео»: образ видеопортала собирается отдельно — его ошибка не мешает обновить портал
    # (контейнеры видеопортала продолжат работать на прежнем образе).
    video_update_prepare "${APP_DIR}"
    # Каталог импорта документов появился в 1.1.0: подключается в контейнеры как /var/www/html/var/import.
    if ! grep -q '^IMPORT_HOST_DIR=' "${APP_DIR}/docker/.env" 2>/dev/null; then
        printf '\n# Каталог импорта документов: путь на сервере или имя тома Docker.\nIMPORT_HOST_DIR=%s/import\n' "${APP_DIR}" >> "${APP_DIR}/docker/.env"
    fi
    mkdir -p "${APP_DIR}/import" && chown 82:82 "${APP_DIR}/import" 2>/dev/null && chmod 2775 "${APP_DIR}/import" || true
    # До 1.6.1 установщик доверял заголовкам X-Forwarded-* от всех частных сетей — клиенты локальной сети
    # могли подменить свой IP. nginx передаёт настоящий адрес по FastCGI, поэтому прокси не нужны.
    if grep -q '^TRUSTED_PROXIES=172.16.0.0/12,10.0.0.0/8,192.168.0.0/16$' "${APP_DIR}/docker/.env" 2>/dev/null; then
        sed -i 's|^TRUSTED_PROXIES=172.16.0.0/12,10.0.0.0/8,192.168.0.0/16$|TRUSTED_PROXIES=|' "${APP_DIR}/docker/.env" || true
        warn "TRUSTED_PROXIES в docker/.env очищен: заголовкам X-Forwarded-* от всех частных сетей портал больше не доверяет. Если перед порталом стоит обратный прокси (TLS), укажите его адрес: TRUSTED_PROXIES=<IP прокси> в ${APP_DIR}/docker/.env и выполните: cd ${APP_DIR}/docker && docker compose up -d"
    fi
    # Страница обслуживания показывается, пока контейнер приложения применяет миграции (с 1.7.0 каталог
    # ${APP_DIR}/maintenance подключён в контейнер web; при переходе с прежних версий web пересоздаётся
    # командой up -d ниже и сразу начинает показывать страницу; режим включён в начале обновления).
    MIGRATED="1"
    docker compose up -d >>"${LOG_FILE}" 2>&1 || die "Не удалось запустить обновлённые контейнеры."
    # Каталог docker/nginx подключён в web целиком: изменённая конфигурация применяется без пересоздания.
    docker compose exec -T web nginx -s reload >>"${LOG_FILE}" 2>&1 || true
    health || die "Новая версия не отвечает."
    maintenance_end
    install_cli_wrapper && info "Команда управления ${APP_ID} обновлена."
    # Задания приложения выполняет контейнер cron; на сервере остаётся только резервное копирование.
    ensure_cron_entries
    install_logrotate
    # Внутри — без отката обновления: портал к этому моменту обновлён и проверен.
    ( ROLLBACK_ENABLED="0"; video_update_finish ) || warn "Раздел «Видео» не перезапущен — проверьте: ${APP_ID} video status"
fi

APP_VERSION="${NEW_VERSION}"
save_conf
ROLLBACK_ENABLED="0"
if [[ "${INSTALL_MODE}" == "docker" ]]; then
    # Прежние код и образ убираются только теперь, когда откат уже не понадобится (и не должны достаться
    # откату следующего обновления).
    rm -rf "${APP_DIR}.prev"
    [[ "${TAGGED_PREVIOUS}" == "1" ]] && docker image rm "${APP_ID}:previous" >>"${LOG_FILE}" 2>&1 || true
    docker image prune -f >>"${LOG_FILE}" 2>&1 || true
fi
[[ -n "${CREATED_TMP}" ]] && rm -rf "${CREATED_TMP}" || true
trap - ERR
printf '\n%sОбновление завершено: версия %s.%s Журнал: %s\n' "${C_GREEN}${C_BOLD}" "${NEW_VERSION}" "${C_RESET}" "${LOG_FILE}"
[[ -n "${BACKUP_FILE}" ]] && printf 'Резервная копия перед обновлением: %s\n' "${BACKUP_FILE}"
exit 0
