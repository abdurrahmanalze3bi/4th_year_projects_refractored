#!/bin/sh
set -e

APP_PORT="${PORT:-8000}"

php artisan config:cache
php artisan route:cache
php artisan migrate --force

exec php artisan octane:start \
    --server=roadrunner \
    --host=0.0.0.0 \
    --port="${APP_PORT}" \
    --workers=1 \
    --max-requests=500
