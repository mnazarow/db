#!/usr/bin/env bash
# =============================================================================
#  Точка входа контейнера приложения: ждёт базу данных, применяет миграции,
#  прогревает кэш, создаёт первого администратора, запускает обработчик фоновых задач и PHP-FPM.
#  Команда "cron" запускает планировщик: проверка сроков актуальности, описания через LLM, индексация
#  содержимого, напоминания об ознакомлении и по таблицам материалов, разбор сообщений бота Telegram.
#  Переменные окружения — см. docker/.env.example.
# =============================================================================
set -Eeuo pipefail

log()  { printf '[entrypoint %s] %s\n' "$(date '+%H:%M:%S')" "$*"; }
fail() { printf '[entrypoint %s] ОШИБКА: %s\n' "$(date '+%H:%M:%S')" "$*" >&2; exit 1; }

cd /var/www/html

: "${APP_ENV:=prod}"
export APP_ENV

wait_for_db() {
    [[ -n "${DATABASE_URL:-}" ]] || fail "Не задана переменная DATABASE_URL."
    log "Ожидание базы данных…"
    local attempt=0
    until php -r '
        $url = getenv("DATABASE_URL");
        $p = parse_url($url);
        if (!$p || empty($p["host"])) { fwrite(STDERR, "Некорректный DATABASE_URL\n"); exit(2); }
        $dsn = sprintf("mysql:host=%s;port=%d;dbname=%s", $p["host"], $p["port"] ?? 3306, ltrim($p["path"] ?? "", "/"));
        try { new PDO($dsn, urldecode($p["user"] ?? ""), urldecode($p["pass"] ?? ""), [PDO::ATTR_TIMEOUT => 3]); exit(0); }
        catch (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(1); }
    ' 2>/tmp/db-wait.err; do
        local rc=$?
        if [[ ${rc} -eq 2 ]]; then cat /tmp/db-wait.err >&2; fail "Проверьте DATABASE_URL."; fi
        attempt=$((attempt + 1))
        if [[ ${attempt} -ge 60 ]]; then
            cat /tmp/db-wait.err >&2
            fail "База данных не ответила за 2 минуты."
        fi
        sleep 2
    done
    log "База данных доступна."
}

run_console() { su-exec www-data php -d memory_limit=512M bin/console "$@" --no-interaction 2>&1 || return $?; }
if ! command -v su-exec >/dev/null 2>&1; then
    run_console() { php -d memory_limit=512M bin/console "$@" --no-interaction 2>&1 || return $?; }
fi

# --- Режим планировщика ---------------------------------------------------------------
if [[ "${1:-php-fpm}" == "cron" ]]; then
    wait_for_db
    mkdir -p /etc/crontabs
    printf '%s cd /var/www/html && su-exec www-data php bin/console app:documents:expiry --no-interaction >> /var/www/html/var/log/expiry-cron.log 2>&1\n' "${EXPIRY_CRON:-0 8 * * *}" > /etc/crontabs/root
    # Описания документов через LLM (только если интеграция включена в панели администратора).
    printf '%s cd /var/www/html && su-exec www-data php bin/console app:documents:describe --missing --limit=50 --quiet-if-disabled --no-interaction >> /var/www/html/var/log/describe-cron.log 2>&1\n' "${DESCRIBE_CRON:-20 * * * *}" >> /etc/crontabs/root
    # Индексация содержимого документов для полнотекстового поиска.
    printf '%s cd /var/www/html && su-exec www-data php bin/console app:search:reindex --limit=500 --no-interaction >> /var/www/html/var/log/reindex-cron.log 2>&1\n' "${REINDEX_CRON:-40 * * * *}" >> /etc/crontabs/root
    # Смысловой индекс для ответов ИИ и очистка журнала вопросов к ИИ (индекс — только если поиск по смыслу включён).
    printf '%s cd /var/www/html && su-exec www-data php bin/console app:ai:index --limit=200 --quiet-if-disabled --no-interaction >> /var/www/html/var/log/ai-index-cron.log 2>&1\n' "${AI_INDEX_CRON:-50 * * * *}" >> /etc/crontabs/root
    # Напоминания сотрудникам о документах, с которыми они не ознакомились.
    printf '%s cd /var/www/html && su-exec www-data php bin/console app:documents:acknowledge-remind --no-interaction >> /var/www/html/var/log/acknowledge-cron.log 2>&1\n' "${ACKNOWLEDGE_CRON:-30 8 * * *}" >> /etc/crontabs/root
    # Разбор сообщений бота Telegram (привязка чатов; без включённых уведомлений ничего не делает).
    printf '%s cd /var/www/html && su-exec www-data php bin/console app:telegram:poll --no-interaction >> /var/www/html/var/log/telegram-cron.log 2>&1\n' "${TELEGRAM_CRON:-* * * * *}" >> /etc/crontabs/root
    # Еженедельная сводка по таблицам материалов («Не соответствует», «Отсутствует»; включается в панели администратора).
    printf '%s cd /var/www/html && su-exec www-data php bin/console app:materials:remind --no-interaction >> /var/www/html/var/log/materials-remind-cron.log 2>&1\n' "${MATERIALS_REMIND_CRON:-10 9 * * 1}" >> /etc/crontabs/root
    # Еженедельная сводка руководителям: кто из сотрудников не ознакомился (включается в панели администратора).
    printf '%s cd /var/www/html && su-exec www-data php bin/console app:team:digest --no-interaction >> /var/www/html/var/log/team-digest-cron.log 2>&1\n' "${TEAM_DIGEST_CRON:-40 8 * * 1}" >> /etc/crontabs/root
    # Ежедневные итоги статистики — сразу после полуночи.
    printf '%s cd /var/www/html && su-exec www-data php bin/console app:stats:aggregate --no-interaction >> /var/www/html/var/log/stats-cron.log 2>&1\n' "${STATS_CRON:-5 0 * * *}" >> /etc/crontabs/root
    # Журналы заданий больше 10 МБ обнуляются раз в сутки (ротации в контейнере нет).
    printf '%s\n' '15 4 * * * find /var/www/html/var/log -maxdepth 1 \( -name "*-cron.log" -o -name "worker.log" \) -size +10240k -exec sh -c ": > \"\$1\"" _ {} \;' >> /etc/crontabs/root
    log "Планировщик запущен: проверка сроков актуальности по расписанию «${EXPIRY_CRON:-0 8 * * *}», разбор сообщений Telegram, индексация и напоминания."
    exec crond -f -l 6
fi

# Если запускается не php-fpm (например, bin/console), просто выполняем команду.
if [[ "${1:-php-fpm}" != "php-fpm" ]]; then
    exec "$@"
fi

# --- 1. Права на каталоги -----------------------------------------------------------
mkdir -p var/cache var/log var/storage var/import
chown -R www-data:www-data var/cache var/log var/storage 2>/dev/null || true
chown www-data:www-data var/import 2>/dev/null || true

# --- 2. Ожидание базы данных ----------------------------------------------------------
wait_for_db

# --- 3. Кэш и миграции -------------------------------------------------------------------
log "Очистка и прогрев кэша (${APP_ENV})…"
rm -rf "var/cache/${APP_ENV}"
run_console cache:warmup || fail "Не удалось прогреть кэш. Проверьте переменные окружения (APP_SECRET, DATABASE_URL)."
chown -R www-data:www-data var/cache 2>/dev/null || true

log "Применение миграций…"
run_console doctrine:migrations:migrate --allow-no-migration || fail "Ошибка применения миграций базы данных."

# --- 4. Первый администратор ----------------------------------------------------------------
if [[ -n "${ADMIN_USER:-}" && -n "${ADMIN_PASSWORD:-}" ]]; then
    log "Проверка администратора «${ADMIN_USER}»…"
    ADMIN_PASSWORD="${ADMIN_PASSWORD}" run_console app:user:create "${ADMIN_USER}" --admin --name "Администратор" --if-not-exists \
        || fail "Не удалось создать администратора."
fi

run_console app:check || log "ПРЕДУПРЕЖДЕНИЕ: проверка app:check сообщила о проблемах (см. выше)."

# --- 5. Фоновые задачи ----------------------------------------------------------------------
# Очередь в базе (рассылки «Отправить сейчас», PDF-сборники, уведомления об ознакомлении, итоги статистики)
# разбирает процесс в этом же контейнере — ему видны те же файлы, что и сайту. Он перезапускается каждую минуту:
# так не копит память и сразу работает с новым кодом. WORKER_ENABLED=0 — не запускать (задачи выполняются сразу).
# При остановке контейнера (docker compose stop, обновление) обработчик получает SIGTERM и дожидается конца
# текущей задачи (не дольше WORKER_STOP_TIMEOUT секунд, по умолчанию 90; stop_grace_period в docker-compose.yml
# больше), и только потом останавливается PHP-FPM.
WORKER_PID=""
if [[ "${WORKER_ENABLED:-1}" == "1" ]]; then
    as_www=()
    command -v su-exec >/dev/null 2>&1 && as_www=(su-exec www-data)
    (
        stop=0
        consumer=""
        # Сигнал — обработчику (Symfony Messenger доделывает текущую задачу и выходит), цикл больше не перезапускает его.
        trap 'stop=1; [[ -n "${consumer}" ]] && kill -TERM "${consumer}" 2>/dev/null; true' TERM INT QUIT
        while [[ ${stop} -eq 0 ]]; do
            "${as_www[@]}" php -d memory_limit=512M bin/console messenger:consume async --time-limit=60 --memory-limit=256M --quiet --no-interaction \
                >> var/log/worker.log 2>&1 &
            consumer=$!
            rc=0
            # wait прерывается сигналом (сработает trap) — тогда ждём, пока обработчик доделает задачу.
            wait "${consumer}" || rc=$?
            while kill -0 "${consumer}" 2>/dev/null; do wait "${consumer}" || true; done
            consumer=""
            if [[ ${stop} -eq 0 ]]; then
                if [[ ${rc} -ne 0 ]]; then sleep 10 & wait $! || true; fi
                sleep 1 & wait $! || true
            fi
        done
    ) &
    WORKER_PID=$!
    log "Обработчик фоновых задач запущен."
fi

if [[ -z "${WORKER_PID}" ]]; then
    log "Запуск PHP-FPM."
    exec php-fpm
fi

log "Запуск PHP-FPM."
php-fpm &
FPM_PID=$!
shutdown() {
    trap - TERM INT
    log "Остановка: обработчик фоновых задач завершает текущую задачу…"
    kill -TERM "${WORKER_PID}" 2>/dev/null || true
    local waited=0 limit="${WORKER_STOP_TIMEOUT:-90}"
    while kill -0 "${WORKER_PID}" 2>/dev/null && [[ ${waited} -lt ${limit} ]]; do
        sleep 1
        waited=$((waited + 1))
    done
    if kill -0 "${WORKER_PID}" 2>/dev/null; then
        log "Обработчик не завершился за ${limit} с — прерывается (задача повторно не выполнится: см. AtMostOnceMiddleware)."
        pkill -KILL -P "${WORKER_PID}" 2>/dev/null || true
        kill -KILL "${WORKER_PID}" 2>/dev/null || true
    fi
    # PHP-FPM — мягкая остановка (SIGQUIT): доделывает текущие запросы; не успел — обычная остановка.
    kill -QUIT "${FPM_PID}" 2>/dev/null || true
    waited=0
    while kill -0 "${FPM_PID}" 2>/dev/null && [[ ${waited} -lt 20 ]]; do
        sleep 1
        waited=$((waited + 1))
    done
    kill -TERM "${FPM_PID}" 2>/dev/null || true
    wait "${FPM_PID}" 2>/dev/null || true
    log "Остановлено."
    exit 0
}
# Образ php:*-fpm останавливается сигналом SIGQUIT (STOPSIGNAL), docker kill и Ctrl+C — SIGTERM и SIGINT.
trap shutdown TERM INT QUIT
rc=0
wait "${FPM_PID}" || rc=$?
# PHP-FPM завершился сам (ошибка) — останавливаем и обработчик, контейнер перезапустит Docker.
kill -TERM "${WORKER_PID}" 2>/dev/null || true
exit "${rc}"
