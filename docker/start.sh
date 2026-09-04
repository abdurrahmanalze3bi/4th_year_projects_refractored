#!/bin/sh
set -e

APP_PORT="${PORT:-8000}"
WORKERS="${WEB_CONCURRENCY:-1}"

# ── Startup diagnostics ──────────────────────────────────────
echo "=== SyRide: port=${APP_PORT} workers=${WORKERS} ==="
echo "    PHP $(php -r 'echo phpversion();')"
echo "    Ext: $(php -m | grep -E '^(sockets|pcntl|redis|pdo_mysql)$' | tr '\n' ' ')"

# ── Laravel bootstrap ─────────────────────────────────────────
php artisan config:cache
php artisan route:cache
php artisan storage:link --no-interaction 2>/dev/null || true
php artisan migrate --force

# ── Worker boot probe ─────────────────────────────────────────
# Boots a worker process for 8 s with no RoadRunner attached.
# Any PHP bootstrap error (bad service provider, missing class, etc.)
# will print to stdout here and appear in Render's deploy log.
# Clean output + exit 124 (killed by timeout) = worker boots fine.
# Any PHP error printed = that error is crashing your workers.
echo "=== Worker probe ==="
( timeout 8s php artisan octane:worker --server=roadrunner 2>&1 || true )
echo "=== Probe complete ==="

# ── RoadRunner config ─────────────────────────────────────────
cat > /var/www/html/.rr.yaml << RRCFG
version: "3"

rpc:
  listen: "tcp://127.0.0.1:6001"

server:
  command: "php -d display_errors=stderr -d log_errors=1 -d error_log=/dev/stderr /var/www/html/artisan octane:worker --server=roadrunner"
  relay: pipes
  relay_timeout: 30s

http:
  address: "0.0.0.0:${APP_PORT}"
  middleware: ["headers", "gzip"]
  trusted_subnets:
    - "10.0.0.0/8"
    - "172.16.0.0/12"
    - "192.168.0.0/16"
    - "127.0.0.1/8"
    - "fd00::/8"
    - "::1/128"
  pool:
    debug: true
    num_workers: ${WORKERS}
    max_jobs: 500
    allocate_timeout: 60s
    destroy_timeout: 30s
    supervisor:
      exec_ttl: 120s
      max_worker_memory: 256

logs:
  mode: production
  level: debug
  encoding: json
  output: stderr
  channels:
    server:
      level: debug
    pool:
      level: debug
    http:
      level: warn
RRCFG

echo "=== Starting RoadRunner ==="
exec /var/www/html/rr serve -c /var/www/html/.rr.yaml
