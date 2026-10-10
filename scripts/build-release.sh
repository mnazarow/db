#!/usr/bin/env bash
# =============================================================================
#  Сборка дистрибутива портала документации: dist/docportal-<версия>.tar.gz (+ .sha256).
#  В архив входит каталог vendor/, поэтому на сервере Composer и доступ к packagist.org не нужны.
#  Раздел «Видео» (video/): в архив входят готовые зависимости сервера видеопортала (video/server/node_modules)
#  и веб-интерфейс, собранный для адреса /video/ (video/web/dist), — на сервере npm не нужен. Для этого на
#  машине сборки нужны Node.js 20.12+ и npm с доступом к registry.npmjs.org.
#  Использование: ./scripts/build-release.sh [--no-vendor] [--no-video-build]
# =============================================================================
set -Eeuo pipefail
cd "$(dirname "$0")/.."
VERSION=$(tr -d ' \n\r' < VERSION)
NAME="docportal"
OUT="dist"
WITH_VENDOR="1"
VIDEO_BUILD="1"
for arg in "$@"; do
    case "${arg}" in
        --no-vendor) WITH_VENDOR="0" ;;
        --no-video-build) VIDEO_BUILD="0" ;;
        *) echo "Неизвестный параметр: ${arg}" >&2; exit 2 ;;
    esac
done

if [[ "${WITH_VENDOR}" == "1" && ! -d vendor ]]; then
    echo "Нет каталога vendor/. Выполните: composer install --no-dev --optimize-autoloader" >&2
    exit 1
fi

mkdir -p "${OUT}"
STAGE=$(mktemp -d)
trap 'rm -rf "${STAGE}"' EXIT
mkdir -p "${STAGE}/${NAME}"

EXCLUDES=(
    --exclude '/var/*' --exclude '/.env.local' --exclude '/.env.*.local' --exclude '/docker/.env'
    --exclude '/docker/mysql-data' --exclude '/dist' --exclude '/backups' --exclude '/node_modules'
    --exclude '/.git' --exclude '/.idea' --exclude '/.vscode' --exclude '*.log' --exclude '.DS_Store'
    --exclude '/docs/build' --exclude '/var/storage' --exclude '/.env.test' --exclude '/maintenance'
    # Раздел «Видео»: без руководства видеопортала в Word/PDF и снимков экрана (история изменений остаётся), локальных данных
    # и зависимостей машины сборки (их ставит npm ci ниже — без пакетов разработки).
    --exclude '/video/docs/img' --exclude '/video/docs/src' --exclude '/video/docs/*.docx' --exclude '/video/docs/*.pdf'
    --exclude '/video/img' --exclude '/video/web/node_modules' --exclude '/video/web/dist'
    --exclude '/video/server/node_modules' --exclude '/video/server/data' --exclude '/video/server/.env' --exclude '/video/.env'
    --exclude '/video/deploy/mediamtx/mediamtx.yml' --exclude '/docker/nginx/video' --exclude '/docker/video'
)
[[ "${WITH_VENDOR}" == "0" ]] && EXCLUDES+=(--exclude '/vendor')

# Права в архиве не зависят от umask машины сборки: каталоги 755, файлы 644 (исполняемые — ниже).
rsync -a --chmod=Du=rwx,Dgo=rx,Fu=rw,Fgo=r "${EXCLUDES[@]}" ./ "${STAGE}/${NAME}/"
if [[ "${WITH_VENDOR}" == "1" ]]; then
    # В поставку — только зависимости для работы (без PHPUnit и других пакетов разработки).
    if command -v composer >/dev/null 2>&1; then
        (cd "${STAGE}/${NAME}" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-scripts --no-interaction --no-progress --quiet) \
            || { echo "composer install --no-dev в каталоге сборки завершился с ошибкой" >&2; exit 1; }
    else
        echo "ВНИМАНИЕ: composer не найден — в архив попадёт vendor/ как есть (возможно, с пакетами разработки)." >&2
    fi
fi
# Раздел «Видео»: зависимости сервера (чистый JavaScript — подходят для любой платформы) и веб-интерфейс,
# собранный для /video/. Отпечаток исходников (.source) — как в deploy/video.sh (video_web_fingerprint):
# обновление без готового интерфейса возьмёт его из прежнего релиза, если исходники не менялись.
SHA256=(sha256sum)
command -v sha256sum >/dev/null 2>&1 || SHA256=(shasum -a 256)
if [[ "${VIDEO_BUILD}" == "1" && -f "${STAGE}/${NAME}/video/server/package.json" ]]; then
    command -v npm >/dev/null 2>&1 || { echo "Для сборки раздела «Видео» нужен npm (Node.js 20.12+). Без готового видеопортала: --no-video-build" >&2; exit 1; }
    export NPM_CONFIG_FETCH_RETRIES=5 NPM_CONFIG_FETCH_RETRY_MAXTIMEOUT=120000 NPM_CONFIG_FETCH_TIMEOUT=600000
    echo "Раздел «Видео»: зависимости сервера (npm ci --omit=dev)…"
    (cd "${STAGE}/${NAME}/video/server" && npm ci --omit=dev --no-audit --no-fund --loglevel=error) \
        || { echo "npm ci для video/server завершился с ошибкой" >&2; exit 1; }
    echo "Раздел «Видео»: сборка веб-интерфейса для /video/…"
    (cd "${STAGE}/${NAME}/video/web" && npm ci --no-audit --no-fund --loglevel=error && VITE_BASE=/video/ npm run build --silent) \
        || { echo "Сборка video/web завершилась с ошибкой" >&2; exit 1; }
    rm -rf "${STAGE}/${NAME}/video/web/node_modules"
    printf '%s' "/video/" > "${STAGE}/${NAME}/video/web/dist/.base"
    (cd "${STAGE}/${NAME}/video/web" && find src public index.html vite.config.js package.json package-lock.json -type f 2>/dev/null \
        | LC_ALL=C sort | xargs "${SHA256[@]}" 2>/dev/null | "${SHA256[@]}" | cut -d' ' -f1) > "${STAGE}/${NAME}/video/web/dist/.source"
    # Права — как у остальных файлов архива (npm создаёт их по umask машины сборки).
    chmod -R u+rwX,go+rX,go-w "${STAGE}/${NAME}/video/server/node_modules" "${STAGE}/${NAME}/video/web/dist"
elif [[ -f "${STAGE}/${NAME}/video/server/package.json" ]]; then
    echo "ВНИМАНИЕ: --no-video-build — раздел «Видео» без готовых зависимостей: на сервере понадобится npm с доступом к registry.npmjs.org." >&2
fi
mkdir -p "${STAGE}/${NAME}/var/cache" "${STAGE}/${NAME}/var/log" "${STAGE}/${NAME}/var/storage" "${STAGE}/${NAME}/var/import"
touch "${STAGE}/${NAME}/var/storage/.gitkeep" "${STAGE}/${NAME}/var/import/.gitkeep"
chmod +x "${STAGE}/${NAME}/bin/console" "${STAGE}/${NAME}"/deploy/*.sh "${STAGE}/${NAME}"/scripts/*.sh "${STAGE}/${NAME}/docker/entrypoint.sh"
[[ -f "${STAGE}/${NAME}/video/deploy/docker/entrypoint.sh" ]] && chmod +x "${STAGE}/${NAME}/video/deploy/docker/entrypoint.sh"
[[ -d "${STAGE}/${NAME}/vendor/bin" ]] && chmod -R +x "${STAGE}/${NAME}/vendor/bin" 2>/dev/null || true

SUFFIX=""
[[ "${WITH_VENDOR}" == "0" ]] && SUFFIX="-src"
ARCHIVE="${OUT}/${NAME}-${VERSION}${SUFFIX}.tar.gz"
tar -C "${STAGE}" --owner=0 --group=0 --numeric-owner -czf "${ARCHIVE}" "${NAME}"
(cd "${OUT}" && sha256sum "$(basename "${ARCHIVE}")" > "$(basename "${ARCHIVE}").sha256")
echo "Готово: ${ARCHIVE} ($(du -h "${ARCHIVE}" | cut -f1))"
