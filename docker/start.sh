#!/bin/sh
set -e

APP_PORT="${PORT:-8000}"

php artisan config:cache
php artisan route:cache
php artisan migrate --force

# Patch the listening port in .rr.yaml to whatever the platform injects via $PORT.
# (Render sets PORT=10000; plain Docker uses the 8000 default.)
# sed is safe even if this file has Windows CRLF line endings — no heredoc involved.
sed -i "s|address: \"0.0.0.0:[0-9]*\"|address: \"0.0.0.0:${APP_PORT}\"|" /var/www/html/.rr.yaml

# Start RoadRunner directly. Never call octane:start — that's what regenerates
# .rr.yaml with a status plugin and opens port 8001.
exec /var/www/html/rr serve -c /var/www/html/.rr.yaml
