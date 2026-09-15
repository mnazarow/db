#!/usr/bin/env bash
# Запуск портала на встроенном сервере PHP для разработки/проверки (не для боевой эксплуатации).
set -euo pipefail
cd "$(dirname "$0")/.."
HOST="${1:-127.0.0.1}"; PORT="${2:-8080}"
echo "Портал: http://${HOST}:${PORT}  (Ctrl+C — остановить)"
exec php -d upload_max_filesize=128M -d post_max_size=130M -d memory_limit=512M -S "${HOST}:${PORT}" -t public scripts/dev-router.php
