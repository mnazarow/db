# =============================================================================
#  Раздел «Видео» портала документации: образ видеопортала (API и обработка видео).
#  Собирается из docker/docker-compose.yml портала (сервис video-api, контекст — каталог video/):
#    cd docker && docker compose --profile video build video-api
#  Архив дистрибутива уже содержит server/node_modules и web/dist, собранный для /video/, —
#  тогда npm и доступ к registry.npmjs.org при сборке не нужны.
# =============================================================================
ARG NODE_IMAGE=node:22-bookworm-slim

# --- 1. Веб-интерфейс -------------------------------------------------------------
FROM ${NODE_IMAGE} AS web
WORKDIR /build/web
# Долгие таймауты и повторы: в корпоративных сетях npm часто обрывается по ETIMEDOUT.
ENV NPM_CONFIG_FETCH_RETRIES=5 NPM_CONFIG_FETCH_RETRY_MAXTIMEOUT=120000 NPM_CONFIG_FETCH_TIMEOUT=600000
# Префикс раздела в браузере (VIDEO_BASE_PATH=/video → /video/).
ARG VITE_BASE=/video/
COPY web/ ./
RUN set -eu; \
    if [ -f dist/index.html ] && [ "$(cat dist/.base 2>/dev/null)" = "${VITE_BASE}" ]; then \
        echo "Веб-интерфейс — из поставки (собран для ${VITE_BASE})"; \
    else \
        rm -rf dist node_modules; \
        npm ci --no-audit --no-fund; \
        VITE_BASE="${VITE_BASE}" npm run build; \
        printf '%s' "${VITE_BASE}" > dist/.base; \
    fi; \
    rm -rf node_modules

# --- 2. Сервер и его зависимости ------------------------------------------------------
FROM ${NODE_IMAGE} AS server
WORKDIR /build/server
ENV NPM_CONFIG_FETCH_RETRIES=5 NPM_CONFIG_FETCH_RETRY_MAXTIMEOUT=120000 NPM_CONFIG_FETCH_TIMEOUT=600000
COPY server/ ./
# Зависимости сервера — чистый JavaScript: готовый node_modules из поставки подходит для любой платформы.
RUN set -eu; \
    if [ -d node_modules/fastify ]; then \
        echo "Зависимости сервера — из поставки"; \
    else \
        rm -rf node_modules; \
        npm ci --omit=dev --no-audit --no-fund; \
    fi; \
    rm -rf test data .env

# --- 3. Образ ---------------------------------------------------------------------------
FROM ${NODE_IMAGE}
ARG TZ=Europe/Moscow
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ffmpeg ca-certificates curl tini yt-dlp tesseract-ocr tesseract-ocr-rus tesseract-ocr-eng \
        fonts-dejavu-core python3 python3-opencv tzdata; \
    rm -rf /var/lib/apt/lists/*; \
    ln -sf "/usr/share/zoneinfo/${TZ}" /etc/localtime && echo "${TZ}" > /etc/timezone
WORKDIR /app
COPY --from=server /build/server ./server
COPY --from=web /build/web/dist ./web/dist
COPY scripts/ ./scripts/
COPY VERSION ./VERSION
COPY deploy/docker/entrypoint.sh /usr/local/bin/docportal-video-entrypoint
ENV NODE_ENV=production \
    HOST=0.0.0.0 \
    PORT=3000 \
    DATA_DIR=/data \
    WEB_DIST=/app/web/dist \
    NGINX_ACCEL=1 \
    BASE_PATH=/video
# Каталоги данных (тома Docker); владельца пустым томам задаёт entrypoint, процессы работают от node.
RUN set -eux; \
    chmod 755 /usr/local/bin/docportal-video-entrypoint; \
    mkdir -p /data/media /data/uploads /data/live/recordings /data/import; \
    chown -R node:node /data
EXPOSE 3000
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 CMD curl -fsS http://127.0.0.1:3000/api/health || exit 1
ENTRYPOINT ["tini", "--", "/usr/local/bin/docportal-video-entrypoint"]
CMD ["node", "server/src/index.js"]
