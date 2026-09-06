# Local development

## Requirements

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.3+ | with `pdo_pgsql`, `redis`, `intl`, `mbstring`, `openssl` |
| PHP (recommended) | — | `bcmath` **or** `gmp`; without either, money arithmetic falls back to a slower pure-PHP calculator |
| Composer | 2.x | |
| Node | 20+ | |
| Docker | any recent | for Postgres, Redis and Mailpit |

## One command

```bash
make bootstrap
```

This installs dependencies, writes `.env` files from the templates, generates
the application key, starts Postgres/Redis/Mailpit, waits for them to actually
accept connections, and migrates **from an empty database**.

Then:

```bash
make serve
```

| Service | URL |
|---|---|
| API | http://localhost:8000 |
| Frontend | http://localhost:5173 |
| Mailpit (captured mail) | http://localhost:8025 |

## What is *not* emulated

Docker is not a hypervisor. There is no fake Proxmox container, no fake iLO and
no fake cPanel server. Provider behaviour in development comes from explicitly
named in-process fakes:

```text
FakeComputeProvider · FakeDedicatedProvider · FakeHostingProvider
FakePaymentProvider · FakeDnsProvider       · FakeBackupProvider
```

They are selected through the `*_PROVIDER=fake` environment variables. Their
service provider throws during boot when `APP_ENV=production`, so they cannot
reach production by accident.

## Common tasks

```bash
make test            # backend + frontend tests
make test-backend    # php artisan test
make test-frontend   # vitest
make lint            # Pint, ESLint, tsc (plus PHPStan when its toolchain is installed)
make fmt             # auto-format everything
make fresh           # drop, migrate from empty, seed
make dev-reset       # destroy and recreate the Docker data volumes
```

## Working on infrastructure code

Infrastructure playbooks never run against real hosts from a developer machine
by default. Every infrastructure target defaults to a check/dry-run posture:

```bash
make infra-check ENV=development
make infra-plan
```

Applying anything requires an explicit inventory entry for the target host.

## Restricted-network environments

If your environment's egress policy blocks `api.github.com` and
`codeload.github.com`, Composer cannot use dist (zip) downloads and falls back
to git clones. Installs still work but are slow and leave large `.git`
directories under `vendor/`. Reclaim the space with:

```bash
find apps/control-plane/vendor -maxdepth 3 -name .git -type d -prune -exec rm -rf {} +
```

`phpstan/phpstan` is dist-only and cannot be installed at all under that policy;
static analysis then runs in CI rather than locally. See `tools/phpstan/README.md`.
