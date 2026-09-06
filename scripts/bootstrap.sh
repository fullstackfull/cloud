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

# A machine that already has PostgreSQL and Redis listening does not need the
# containers, and starting them would fight over the ports. This matters more
# than it looks: CI runners, managed dev environments and anyone running the
# database natively all land here, and a bootstrap that insists on Docker turns
# "clone and run" into "install Docker first" for people who already have
# everything it was going to install.
db_host="${DB_HOST:-127.0.0.1}"; db_port="${DB_PORT:-5432}"
redis_host="${REDIS_HOST:-127.0.0.1}"; redis_port="${REDIS_PORT:-6379}"

listening() { (exec 3<>"/dev/tcp/$1/$2") 2>/dev/null && exec 3<&- 3>&-; }

if listening "$db_host" "$db_port" && listening "$redis_host" "$redis_port"; then
  log "PostgreSQL and Redis are already listening; not starting containers"
elif docker compose version >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
  (cd "$ROOT" && docker compose -f docker-compose.dev.yml up -d)
else
  fail "PostgreSQL ($db_host:$db_port) and Redis ($redis_host:$redis_port) are not \
reachable, and Docker is not available to start them.
       Either start them yourself and re-run, or install Docker and re-run."
fi

bash "$ROOT/scripts/wait-for-services.sh"

# Listening is not the same as reachable. The port answers as soon as the server
# is up; whether these credentials open a session is a different question, and
# finding out at the migration step produces a stack trace instead of an answer.
log "Checking database credentials"
(
  cd "$CP"
  php -r '
    // A hand-rolled reader rather than parse_ini_file: an .env is not an ini
    // file, and a perfectly valid unquoted value containing a parenthesis or a
    // hash makes parse_ini_file warn and drop the line - which would report a
    // wrong password when the password was fine.
    $env = [];
    foreach (file(".env", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
      $line = ltrim($line);
      if ($line === "" || $line[0] === "#" || ! str_contains($line, "=")) { continue; }
      [$key, $value] = explode("=", $line, 2);
      $env[trim($key)] = trim(trim(trim($value), "\"" ), "'"'"'");
    }
    $get = static fn (string $k, string $d = "") => (string) ($_SERVER[$k] ?? $env[$k] ?? $d);
    $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s",
      $get("DB_HOST", "127.0.0.1"), $get("DB_PORT", "5432"), $get("DB_DATABASE", "lynomia"));
    try {
      new PDO($dsn, $get("DB_USERNAME", "lynomia"), $get("DB_PASSWORD"), [PDO::ATTR_TIMEOUT => 5]);
      exit(0);
    } catch (PDOException $e) {
      fwrite(STDERR, $e->getMessage()."\n");
      exit(1);
    }
  '
) || fail "cannot open a database session with the credentials in $CP/.env.
       Set DB_DATABASE, DB_USERNAME and DB_PASSWORD there to match your server,
       or start the bundled one with: docker compose -f docker-compose.dev.yml up -d
       (its defaults are in docker-compose.dev.yml and match .env.example)."

log "Running migrations and seeders from an empty database"
(cd "$CP" && php artisan migrate:fresh --seed)

log "Bootstrap complete."
cat <<'NEXT'

  Next steps:
    make serve        start API, queue worker and frontend
    make test         run the full test suite
    Mailpit UI        http://localhost:8025

NEXT
