#!/bin/sh
#
# Billas v4 container entrypoint.
#  * Prepares the persistent /data volume and /app/logs.
#  * Optionally applies schema/migrations before serving traffic.
#  * Runs php-fpm and nginx side by side and forwards termination signals.

set -eu

APP_ROOT="${APP_ROOT:-/app}"
DATA_DIR="${DATA_DIR:-/data}"
LOG_DIR="${LOG_DIR:-/app/logs}"

log() {
    printf '%s [billas-entrypoint] %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*" >&2
}

mkdir -p "$DATA_DIR" "$LOG_DIR" "$APP_ROOT/var"

# Named volumes are usually created as root; the application runs as UID 1000.
if [ "$(id -u)" = "0" ]; then
    chown -R appuser:appuser "$DATA_DIR" "$LOG_DIR" "$APP_ROOT/var" 2>/dev/null || true
fi

if [ "${AUTO_MIGRATE:-0}" = "1" ]; then
    log "AUTO_MIGRATE=1 — applying database schema/migrations"
    if ! php "$APP_ROOT/bin/console" migrate; then
        log "migration failed; refusing to start web services"
        exit 1
    fi
fi

# The CLI above runs as root when the container starts. Re-apply ownership so
# the freshly created DB/WAL/session files remain writable by php-fpm (UID 1000)
# and by the separate worker container.
if [ "$(id -u)" = "0" ]; then
    chown -R appuser:appuser "$DATA_DIR" "$LOG_DIR" "$APP_ROOT/var" 2>/dev/null || true
fi

if [ "$#" -gt 0 ] && [ "$1" != "serve" ]; then
    exec "$@"
fi

cleanup() {
    trap - TERM INT QUIT
    [ -n "${FPM_PID:-}" ] && kill -TERM "$FPM_PID" 2>/dev/null || true
    [ -n "${NGINX_PID:-}" ] && kill -TERM "$NGINX_PID" 2>/dev/null || true
    wait "$FPM_PID" 2>/dev/null || true
    wait "$NGINX_PID" 2>/dev/null || true
    exit 0
}
trap cleanup TERM INT QUIT

log "starting php-fpm"
php-fpm -F &
FPM_PID=$!

# Wait briefly for the FPM listener before starting nginx (avoids noisy 502s).
i=0
while [ "$i" -lt 30 ]; do
    if php -r '$s=@fsockopen("127.0.0.1",9000,$e,$m,0.2); if($s){fclose($s);exit(0);} exit(1);'; then
        break
    fi
    i=$((i + 1))
    sleep 0.2
done

log "starting nginx on :${APP_LISTEN_PORT:-8080}"
nginx -g 'daemon off;' &
NGINX_PID=$!

# If either process dies, tear the other down and let Docker restart us.
while kill -0 "$FPM_PID" 2>/dev/null && kill -0 "$NGINX_PID" 2>/dev/null; do
    sleep 1
done

log "a child process exited; shutting down"
cleanup
