#!/bin/sh
set -e

APP_PORT="${PORT:-8000}"
WORKERS="${WEB_CONCURRENCY:-1}"

php artisan config:cache
php artisan route:cache
php artisan migrate --force

exec php artisan octane:start \
    --server=roadrunner \
    --host=0.0.0.0 \
    --port="${APP_PORT}" \
    --workers="${WORKERS}" \
    --max-requests=500
