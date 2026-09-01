#!/bin/sh
set -e

APP_PORT="${PORT:-8000}"

# Write .rr.yaml fresh at runtime — no status plugin, port from $PORT (Render injects 10000)
cat > /var/www/html/.rr.yaml << RREOF
version: "3"

rpc:
  listen: tcp://127.0.0.1:6001

server:
  command: "php artisan octane:worker --server=roadrunner"
  relay: pipes

http:
  address: "0.0.0.0:${APP_PORT}"
  middleware: ["static", "gzip", "headers"]
  static:
    dir: "public"
    forbid: [".php"]
  pool:
    num_workers: 1
    max_jobs: 500
    allocate_timeout: 60s
    destroy_timeout: 60s

logs:
  mode: production
  level: error
  encoding: json
  output: stdout
  err_output: stderr
RREOF

php artisan config:cache
php artisan route:cache
php artisan migrate --force

# exec replaces this shell with rr as PID 1 — clean signal handling, no wrapper overhead
exec /var/www/html/rr serve -c /var/www/html/.rr.yaml
