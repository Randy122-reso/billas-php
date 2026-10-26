# syntax=docker/dockerfile:1.7
#
# Billas v4 — application image (nginx + php-fpm 8.3).
# Multi-stage build: Composer dependencies are resolved in a disposable
# builder stage, then copied into a slim Alpine runtime. The container runs
# both php-fpm and nginx under the shell entrypoint (no OpenRC/Supervisor),
# which keeps the image small and avoids requiring privileged init.

FROM composer:2 AS vendor

WORKDIR /build

COPY composer.json composer.lock ./

# The Composer image may not ship every runtime extension; the lock file pins
# packages, so only platform checks are relaxed here.
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

FROM php:8.3-fpm-alpine AS runtime

RUN apk add --no-cache \
        nginx \
        curl \
        tzdata \
        sqlite-libs \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && docker-php-ext-install sockets \
    && apk del .build-deps \
    && addgroup -g 1000 -S appuser \
    && adduser -u 1000 -S appuser -G appuser \
    && rm -rf /var/cache/apk/* /tmp/*

WORKDIR /app

# Application source (vendor excluded by .dockerignore).
COPY . /app
COPY --from=vendor /build/vendor /app/vendor

COPY docker/app/nginx.conf /etc/nginx/nginx.conf
COPY docker/app/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-billas.conf
COPY docker/app/entrypoint.sh /usr/local/bin/billas-entrypoint

RUN chmod +x /usr/local/bin/billas-entrypoint \
    && rm -f /etc/nginx/conf.d/default.conf \
    && mkdir -p /data /app/logs /app/var \
    && chown -R appuser:appuser /data /app/logs /app/var \
    && php -l public/index.php >/dev/null

ENV APP_ENV=production \
    APP_DEBUG=false \
    DB_PATH=/data/billas.db \
    LOG_DIR=/app/logs \
    APP_LISTEN_PORT=8080

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/health >/dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/billas-entrypoint"]
CMD ["serve"]
