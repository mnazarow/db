#!/usr/bin/env bash
# =============================================================================
#  Сборка дистрибутива портала документации: dist/docportal-<версия>.tar.gz (+ .sha256).
#  В архив входит каталог vendor/, поэтому на сервере Composer и доступ к packagist.org не нужны.
#  Использование: ./scripts/build-release.sh [--no-vendor]
# =============================================================================
set -Eeuo pipefail
cd "$(dirname "$0")/.."
VERSION=$(tr -d ' \n\r' < VERSION)
NAME="docportal"
OUT="dist"
WITH_VENDOR="1"
[[ "${1:-}" == "--no-vendor" ]] && WITH_VENDOR="0"

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
    --exclude '/docs/build' --exclude '/var/storage' --exclude '/.env.test'
)
[[ "${WITH_VENDOR}" == "0" ]] && EXCLUDES+=(--exclude '/vendor')

rsync -a "${EXCLUDES[@]}" ./ "${STAGE}/${NAME}/"
mkdir -p "${STAGE}/${NAME}/var/cache" "${STAGE}/${NAME}/var/log" "${STAGE}/${NAME}/var/storage" "${STAGE}/${NAME}/var/import"
touch "${STAGE}/${NAME}/var/storage/.gitkeep" "${STAGE}/${NAME}/var/import/.gitkeep"
chmod +x "${STAGE}/${NAME}/bin/console" "${STAGE}/${NAME}"/deploy/*.sh "${STAGE}/${NAME}"/scripts/*.sh "${STAGE}/${NAME}/docker/entrypoint.sh"

SUFFIX=""
[[ "${WITH_VENDOR}" == "0" ]] && SUFFIX="-src"
ARCHIVE="${OUT}/${NAME}-${VERSION}${SUFFIX}.tar.gz"
tar -C "${STAGE}" -czf "${ARCHIVE}" "${NAME}"
(cd "${OUT}" && sha256sum "$(basename "${ARCHIVE}")" > "$(basename "${ARCHIVE}").sha256")
echo "Готово: ${ARCHIVE} ($(du -h "${ARCHIVE}" | cut -f1))"
