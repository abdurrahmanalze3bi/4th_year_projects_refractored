#!/bin/sh
set -e

APP_PORT="${PORT:-8000}"
WORKERS="${WEB_CONCURRENCY:-1}"

php artisan config:cache
php artisan route:cache
php artisan migrate --force

# Write .rr.yaml at boot — no status plugin, port comes from $PORT.
cat > /var/www/html/.rr.yaml << RRCFG
version: "3"

rpc:
  listen: "tcp://127.0.0.1:6001"

server:
  command: "php /var/www/html/artisan octane:worker --server=roadrunner --host=0.0.0.0 --rpc-port=6001 --port=${APP_PORT}"
  relay: pipes

http:
  address: "0.0.0.0:${APP_PORT}"
  middleware: ["static", "headers", "gzip"]
  trusted_subnets: ["10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "127.0.0.1/8", "fd00::/8", "::1/128"]
  pool:
    num_workers: ${WORKERS}
    max_jobs: 500
    supervisor:
      exec_ttl: 60s
  static:
    dir: "public"
    forbid: [".php"]
  headers:
    response:
      X-Powered-By: "RoadRunner"

logs:
  mode: production
  level: warn
  encoding: json
  output: stderr
RRCFG

exec /var/www/html/rr serve -c /var/www/html/.rr.yaml
