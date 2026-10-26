# syntax=docker/dockerfile:1.7
#
# Billas v4 — retry worker image.
# PHP CLI only: drains retry_queue (MikroTik push/disable/enable). Shares the
# SQLite volume and log volume with the app container; no web server, no root.

FROM composer:2 AS vendor

WORKDIR /build

COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --optimize-autoloader \
        --ignore-platform-req=ext-pdo_sqlite \
        --ignore-platform-req=ext-sqlite3 \
        --ignore-platform-req=ext-sockets

# ---------------------------------------------------------------------------

FROM php:8.3-cli-alpine AS runtime

RUN apk add --no-cache \
        tzdata \
        sqlite-libs \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && docker-php-ext-install pcntl sockets \
    && apk del .build-deps \
    && addgroup -g 1000 -S appuser \
    && adduser -u 1000 -S appuser -G appuser \
    && rm -rf /var/cache/apk/* /tmp/*

WORKDIR /app

COPY . /app
COPY --from=vendor /build/vendor /app/vendor

RUN mkdir -p /data /app/logs /app/var \
    && chown -R appuser:appuser /data /app/logs /app/var

ENV APP_ENV=production \
    APP_DEBUG=false \
    DB_PATH=/data/billas.db \
    LOG_DIR=/app/logs \
    RETRY_INTERVAL=60

USER appuser

HEALTHCHECK --interval=60s --timeout=5s --start-period=20s --retries=3 \
    CMD php /app/bin/worker.php --health >/dev/null || exit 1

CMD ["php", "/app/bin/worker.php"]
