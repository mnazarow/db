# =============================================================================
#  Образ приложения «Портал документации»: PHP 8.4 FPM + расширения + код проекта.
#  Сборка выполняется из корня проекта: docker compose -f docker/docker-compose.yml build
# =============================================================================
ARG PHP_IMAGE=php:8.4-fpm-alpine
FROM ${PHP_IMAGE}

ARG TZ=Europe/Moscow
# Необязательные инструменты: предпросмотр офисных файлов (LibreOffice) и распознавание сканов
# (tesseract). Увеличивают образ на сотни мегабайт, поэтому по умолчанию выключены:
#   docker compose build --build-arg WITH_PREVIEW=1 --build-arg WITH_OCR=1
ARG WITH_PREVIEW=0
ARG WITH_OCR=0

# Системные библиотеки и расширения PHP, необходимые Symfony, Doctrine и входу через LDAP.
RUN set -eux; \
    apk add --no-cache icu-libs libzip libpng libjpeg-turbo freetype tzdata bash curl mysql-client su-exec libldap poppler-utils; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev openldap-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" intl zip gd pdo_mysql opcache ldap; \
    apk del .build-deps; \
    cp /usr/share/zoneinfo/${TZ} /etc/localtime && echo "${TZ}" > /etc/timezone

# Предпросмотр офисных файлов и распознавание сканов (по флагам сборки).
RUN set -eux; \
    if [ "${WITH_PREVIEW}" = "1" ]; then apk add --no-cache libreoffice ttf-dejavu font-noto; fi; \
    if [ "${WITH_OCR}" = "1" ]; then apk add --no-cache tesseract-ocr tesseract-ocr-data-rus; fi

# Настройки PHP для боевого режима.
COPY docker/php/php.ini /usr/local/etc/php/conf.d/90-docportal.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-docportal.conf

WORKDIR /var/www/html

# Composer нужен только если в поставке нет каталога vendor/.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Код проекта (каталоги var/, .env.local и т.п. исключены через .dockerignore).
COPY --chown=www-data:www-data . /var/www/html

RUN set -eux; \
    if [ ! -d vendor ]; then \
        COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist; \
    fi; \
    mkdir -p var/cache var/log var/storage var/import; \
    chown -R www-data:www-data var; \
    chmod +x bin/console docker/entrypoint.sh

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD php -r 'exit(@fsockopen("127.0.0.1", 9000) ? 0 : 1);'

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["php-fpm"]
