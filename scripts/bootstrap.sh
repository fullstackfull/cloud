#!/usr/bin/env bash
# Clean-room bootstrap: takes a fresh git clone to a running development
# environment using nothing but this repository and documented env vars.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CP="$ROOT/apps/control-plane"
WEB="$ROOT/apps/web"

log()  { printf '\033[36m==>\033[0m %s\n' "$*"; }
fail() { printf '\033[31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

require() {
  command -v "$1" >/dev/null 2>&1 || fail "missing required tool: $1 ($2)"
}

log "Checking required tooling"
require php "PHP 8.3+"
require composer "Composer 2.x"
require node "Node 20+"
require npm "npm 10+"

php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' \
  || fail "PHP 8.3+ required, found $(php -r 'echo PHP_VERSION;')"

# Hard requirements: the application cannot run without these.
for ext in pdo_pgsql redis intl mbstring openssl tokenizer ctype json; do
  php -m | grep -qix "$ext" || fail "missing PHP extension: $ext"
done

# Recommended: brick/math falls back to a pure-PHP calculator without these,
# which is correct but measurably slower. Production must install one of them.
if ! php -m | grep -qixE 'bcmath|gmp'; then
  printf '\033[33mWARNING:\033[0m neither bcmath nor gmp is installed.\n'
  printf '          Money arithmetic will use the slower pure-PHP calculator.\n'
  printf '          Install php-bcmath (or php-gmp) before running in production.\n'
fi

log "Preparing environment files"
[[ -f "$ROOT/.env" ]]  || cp "$ROOT/.env.example" "$ROOT/.env"
[[ -f "$CP/.env" ]]    || cp "$CP/.env.example" "$CP/.env"
[[ -f "$WEB/.env" ]]   || cp "$WEB/.env.example" "$WEB/.env"

log "Installing PHP dependencies"
(cd "$CP" && composer install --no-interaction --prefer-dist)

log "Generating application key"
(cd "$CP" && grep -q '^APP_KEY=base64:' .env || php artisan key:generate)

log "Installing JavaScript dependencies"
(cd "$ROOT" && npm install)

log "Starting development dependencies"
(cd "$ROOT" && docker compose -f docker-compose.dev.yml up -d)
bash "$ROOT/scripts/wait-for-services.sh"

log "Running migrations and seeders from an empty database"
(cd "$CP" && php artisan migrate:fresh --seed)

log "Bootstrap complete."
cat <<'NEXT'

  Next steps:
    make serve        start API, queue worker and frontend
    make test         run the full test suite
    Mailpit UI        http://localhost:8025

NEXT
