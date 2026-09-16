#!/usr/bin/env bash
# =============================================================================
#  Точка входа контейнера приложения: ждёт базу данных, применяет миграции,
#  прогревает кэш, создаёт первого администратора и запускает PHP-FPM.
#  Команда "cron" запускает планировщик: проверка сроков актуальности и описания документов через LLM.
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
    log "Планировщик запущен: проверка сроков актуальности по расписанию «${EXPIRY_CRON:-0 8 * * *}»."
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

log "Запуск PHP-FPM."
exec php-fpm
