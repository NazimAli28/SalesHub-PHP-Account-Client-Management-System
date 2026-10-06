#!/usr/bin/env bash
# Boots SalesHub in one container: prepares the app, then supervises the web server and the
# scheduler (and, optionally, a queue worker). Stops everything on SIGTERM/SIGINT.
set -euo pipefail
cd /app

log() { echo "[entrypoint] $*" >&2; }

is_true() { case "${1,,}" in 1|true|yes|on) return 0 ;; *) return 1 ;; esac; }

if [ -z "${APP_KEY:-}" ]; then
    if is_true "${DEMO_MODE:-false}"; then
        APP_KEY="$(php artisan key:generate --show)"
        export APP_KEY
        log "WARNING: APP_KEY is not set. Using an ephemeral key (demo mode): sessions end on every restart."
    else
        log "ERROR: APP_KEY is not set. Generate one with 'php artisan key:generate --show' and pass it as APP_KEY."
        exit 1
    fi
fi

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    export DB_CONNECTION=sqlite
    export DB_DATABASE="${DB_DATABASE:-/app/storage/database/saleshub.sqlite}"
    mkdir -p "$(dirname "$DB_DATABASE")"
    touch "$DB_DATABASE"
fi

# Build the framework caches now that the environment is known.
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan event:cache --no-interaction
php artisan view:cache --no-interaction

if is_true "${DEMO_MODE:-false}"; then
    log "Demo mode: rebuilding the database with demo data."
    php artisan migrate:fresh --seed --force --no-interaction
else
    php artisan migrate --force --no-interaction
fi

pids=()
stop() {
    trap - TERM INT
    log "Shutting down."
    kill "${pids[@]}" 2>/dev/null || true
    wait 2>/dev/null || true
}
trap 'stop; exit 143' TERM INT

log "Starting the web server on port ${PORT:-8080}."
frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile &
pids+=($!)

log "Starting the scheduler."
php artisan schedule:work --no-interaction &
pids+=($!)

if is_true "${RUN_QUEUE_WORKER:-false}"; then
    log "Starting a queue worker."
    php artisan queue:work --tries=3 --max-time=3600 --no-interaction &
    pids+=($!)
fi

# If any child dies, stop the rest and exit so the platform restarts the container.
wait -n
status=$?
log "A process exited (status ${status}); stopping the container."
stop
exit "$status"
