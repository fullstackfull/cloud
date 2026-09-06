#!/usr/bin/env bash
# Run the API, the queue worker, the scheduler and the frontend together.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
pids=()

cleanup() {
  trap - INT TERM EXIT
  for pid in "${pids[@]:-}"; do kill "$pid" 2>/dev/null || true; done
  wait 2>/dev/null || true
}
trap cleanup INT TERM EXIT

( cd "$ROOT/apps/control-plane" && php artisan serve --host=0.0.0.0 --port="${API_PORT:-8000}" ) & pids+=($!)
( cd "$ROOT/apps/control-plane" && php artisan queue:work --queue=critical,payments,provisioning,infrastructure,notifications,monitoring,default ) & pids+=($!)
( cd "$ROOT/apps/control-plane" && php artisan schedule:work ) & pids+=($!)
( cd "$ROOT/apps/web" && npm run dev ) & pids+=($!)

echo "API      → http://localhost:${API_PORT:-8000}"
echo "Frontend → http://localhost:${WEB_PORT:-5173}"
wait
