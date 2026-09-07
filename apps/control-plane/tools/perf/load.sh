#!/usr/bin/env bash
#
# A load harness for the control plane's HTTP surface.
#
# What it does: starts N single-threaded PHP web servers, distributes requests
# across them with a fixed number of concurrent clients, and reports latency
# percentiles and throughput per endpoint.
#
# What it is NOT: a production benchmark. There is no nginx, no PHP-FPM process
# manager, no separate database host, no TLS termination and no network between
# the client and the server. It models an FPM pool of N children on one machine
# and nothing beyond that. The numbers are useful for comparing endpoints with
# each other and for catching a regression on the same hardware; they are not a
# capacity figure for a deployment.
#
# Usage:
#   DB_DATABASE=lynomia_perf tools/perf/load.sh [workers] [concurrency] [requests]
#
set -euo pipefail

WORKERS="${1:-4}"
CONCURRENCY="${2:-8}"
REQUESTS="${3:-400}"
BASE_PORT="${BASE_PORT:-8200}"
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OPCACHE="${OPCACHE:-1}"

cd "$APP_DIR"

if [ "${APP_ENV:-local}" = "production" ]; then
  echo "Refusing to load-test a production environment." >&2
  exit 1
fi

pids=()
ports=()

cleanup() {
  for pid in "${pids[@]:-}"; do
    kill "$pid" 2>/dev/null || true
  done
}
trap cleanup EXIT

echo "Starting $WORKERS worker(s) (opcache.enable_cli=$OPCACHE)…"
for ((i = 0; i < WORKERS; i++)); do
  port=$((BASE_PORT + i))
  ports+=("$port")
  # PHP's own server rather than `artisan serve`: the artisan command spawns
  # the server as a separate process and the -d flags never reach it, so an
  # "opcache on" run measured exactly the same thing as an "opcache off" one.
  # PHP_CLI_SERVER_WORKERS forks the server so a worker is not strictly
  # one-request-at-a-time.
  PHP_CLI_SERVER_WORKERS="${SERVER_WORKERS:-2}" \
  php -d opcache.enable_cli="$OPCACHE" -d opcache.validate_timestamps=0 \
    -S "127.0.0.1:$port" -t public public/index.php >/dev/null 2>&1 &
  pids+=($!)
done

# Wait for every worker to answer before timing anything: a request that is
# really "PHP booting for the first time" is not a measurement of an endpoint.
for port in "${ports[@]}"; do
  for _ in $(seq 1 60); do
    if curl -fsS -o /dev/null "http://127.0.0.1:$port/sanctum/csrf-cookie" 2>/dev/null; then
      break
    fi
    sleep 0.5
  done
done

TOKEN="$(php artisan perf:token --quiet-output)"

if [ -z "$TOKEN" ]; then
  echo "Could not mint an API token to authenticate with." >&2
  exit 1
fi

measure() {
  local name="$1" path="$2" auth="$3"
  local timings
  timings="$(mktemp)"

  # One warm-up request per worker, discarded.
  for port in "${ports[@]}"; do
    curl -fsS -o /dev/null -H "$auth" "http://127.0.0.1:$port$path" >/dev/null 2>&1 || true
  done

  seq 1 "$REQUESTS" \
    | xargs -P "$CONCURRENCY" -I{} bash -c '
        port=$(( '"$BASE_PORT"' + (RANDOM % '"$WORKERS"') ))
        curl -fsS -o /dev/null -w "%{time_total} %{http_code}\n" \
          -H "'"$auth"'" "http://127.0.0.1:$port'"$path"'" 2>/dev/null || echo "0 000"
      ' > "$timings"

  python3 - "$name" "$timings" "$CONCURRENCY" <<'PY'
import sys, statistics

name, path, concurrency = sys.argv[1], sys.argv[2], int(sys.argv[3])
times, bad, statuses = [], 0, {}

for line in open(path):
    parts = line.split()
    if len(parts) != 2:
        continue
    seconds, code = float(parts[0]), parts[1]
    statuses[code] = statuses.get(code, 0) + 1
    if not code.startswith('2'):
        bad += 1
        continue
    times.append(seconds * 1000)

times.sort()
if not times:
    print(f"{name}: no successful responses; statuses seen: {statuses}")
    sys.exit(0)

def pct(p):
    return times[min(len(times) - 1, int(len(times) * p))]

total_seconds = sum(times) / 1000 / concurrency
rps = len(times) / total_seconds if total_seconds else 0

codes = " ".join(f"{code}×{n}" for code, n in sorted(statuses.items()))

print(f"{name:34s} n={len(times):4d} "
      f"p50={pct(0.50):6.1f}ms p90={pct(0.90):6.1f}ms p99={pct(0.99):6.1f}ms "
      f"max={times[-1]:6.1f}ms ~{rps:6.1f} req/s  [{codes}]")
PY

  rm -f "$timings"
}

echo "Workers: $WORKERS  concurrency: $CONCURRENCY  requests per endpoint: $REQUESTS"
echo

measure "csrf cookie (framework floor)" "/sanctum/csrf-cookie" "Accept: application/json"
measure "GET /api/v1/me" "/api/v1/me" "Authorization: Bearer $TOKEN"
measure "GET /api/v1/catalog/products" "/api/v1/catalog/products" "Authorization: Bearer $TOKEN"
measure "GET /api/v1/invoices" "/api/v1/invoices" "Authorization: Bearer $TOKEN"
measure "GET /api/v1/invoices?per_page=100" "/api/v1/invoices?per_page=100" "Authorization: Bearer $TOKEN"
