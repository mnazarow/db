#!/bin/sh
# Раздел «Видео» портала документации: запуск API и обработки видео в контейнере.
# Тома Docker создаются пустыми с владельцем root: каталоги данных передаются пользователю node
# (uid 1000 — от его имени пишет и сервер трансляций), затем команда запускается от его имени.
set -e
if [ "$(id -u)" = "0" ]; then
    for d in /data /data/media /data/uploads /data/live /data/live/recordings /data/import; do
        mkdir -p "$d"
        [ "$(stat -c %u "$d")" = "1000" ] || chown node:node "$d"
    done
    exec setpriv --reuid=node --regid=node --init-groups "$@"
fi
exec "$@"
