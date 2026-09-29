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
# One worker per retry clock, each with its Horizon supervisor's timeout (F-08).
# A single worker on one connection either kills a 5,700-second build after its
# default 60 seconds or, given that timeout, outlives the 180-second clock the
# payments queue runs on and runs a job twice at once. The connection named is
# the clock; all three read the same Redis keys. Kept in step with
# config/horizon.php by TheDevelopmentScriptDrainsEveryQueueTest.
( cd "$ROOT/apps/control-plane" && php artisan queue:work redis --queue=payments,notifications,default --timeout=120 ) & pids+=($!)
( cd "$ROOT/apps/control-plane" && php artisan queue:work redis-provisioning --queue=provisioning --timeout=5700 ) & pids+=($!)
( cd "$ROOT/apps/control-plane" && php artisan queue:work redis-infrastructure --queue=infrastructure --timeout=1800 ) & pids+=($!)
( cd "$ROOT/apps/control-plane" && php artisan schedule:work ) & pids+=($!)
( cd "$ROOT/apps/web" && npm run dev ) & pids+=($!)

echo "API      → http://localhost:${API_PORT:-8000}"
echo "Frontend → http://localhost:${WEB_PORT:-5173}"
wait
