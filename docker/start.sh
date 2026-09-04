#!/bin/sh
set -e

APP_PORT="${PORT:-8000}"
WORKERS="${WEB_CONCURRENCY:-1}"

echo "=== SyRide: port=${APP_PORT} workers=${WORKERS} ==="
echo "    PHP $(php -r 'echo phpversion();')"
echo "    Ext: $(php -m | grep -E '^(sockets|pcntl|redis|pdo_mysql)$' | tr '\n' ' ')"

php artisan config:cache
php artisan route:cache
php artisan storage:link --no-interaction 2>/dev/null || true
php artisan migrate --force

echo "=== Provider discovery check ==="
php -r "
\$pkg = '/var/www/html/bootstrap/cache/packages.php';
if (!file_exists(\$pkg)) { echo 'packages.php: MISSING'; exit; }
\$providers = (require \$pkg)['providers'] ?? [];
echo in_array('Laravel\\Octane\\OctaneServiceProvider', \$providers) ? 'Octane SP: IN CACHE' : 'Octane SP: NOT IN CACHE';
echo PHP_EOL;
"

echo "=== Class loader check ==="
php -r "
require '/var/www/html/vendor/autoload.php';
\$classes = [
  'Spiral\\RoadRunner\\Worker',
  'Spiral\\RoadRunner\\Http\\PSR7Worker',
  'Laravel\\Octane\\Commands\\WorkerCommand',
];
foreach (\$classes as \$c) {
  echo \$c . ': ' . (class_exists(\$c) ? 'OK' : 'MISSING') . PHP_EOL;
}
"

echo "=== Worker probe ==="
( timeout 8s php artisan octane:worker --server=roadrunner 2>&1 || true )
echo "=== Probe complete ==="

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
