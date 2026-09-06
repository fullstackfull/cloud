#!/usr/bin/env bash
# Block until the local development dependencies are actually accepting
# connections. Never assume "docker compose up" means "ready".
set -Eeuo pipefail

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
REDIS_PORT="${REDIS_PORT:-6379}"
TIMEOUT="${WAIT_TIMEOUT:-90}"

wait_for() {
  local name="$1" host="$2" port="$3" elapsed=0
  printf 'waiting for %-10s %s:%s ' "$name" "$host" "$port"
  until (exec 3<>"/dev/tcp/${host}/${port}") 2>/dev/null; do
    if (( elapsed >= TIMEOUT )); then
      printf ' TIMEOUT after %ss\n' "$TIMEOUT" >&2
      return 1
    fi
    printf '.'
    sleep 1
    elapsed=$((elapsed + 1))
  done
  exec 3<&- 3>&-
  printf ' ready\n'
}

wait_for postgres "$DB_HOST" "$DB_PORT"
wait_for redis    "$REDIS_HOST" "$REDIS_PORT"
echo "All development dependencies are ready."
