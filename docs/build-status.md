# Lynomia Cloud — Build Status

The single honest record of what works, what has only been written, and what
cannot be verified here and why. Every number below was produced by a command
run in this environment; nothing is estimated, and nothing is carried over from
a run that was not repeated after the code changed.

## Status vocabulary

| Status | Meaning |
|---|---|
| `CODE_COMPLETE` | Implementation written, no placeholders left |
| `TESTED` | Covered by automated tests that pass |
| `RUNTIME_VERIFIED` | Executed against real PostgreSQL and Redis in this environment |
| `REAL_INFRA_VERIFIED` | Executed against genuine external infrastructure |
| `BLOCKED_CREDENTIALS` | Implementation done; no credentials exist to verify against |
| `BLOCKED_HARDWARE` | Implementation done; no physical hardware available |
| `BLOCKED_NETWORK` | Implementation done; egress policy blocks verification |
| `BLOCKED_LICENSE` | Implementation done; a commercial licence is required |
| `NOT_IMPLEMENTED` | Not written. Named here so its absence is not mistaken for a block |

`DONE` appears nowhere. Nothing exercised only against a fake is described as
working against the real thing.

**`REAL_INFRA_VERIFIED` appears nowhere in this document.** No Proxmox cluster,
no BMC, no cPanel or DirectAdmin licence, no Stripe account, no DNS API token
and no physical machine was available at any point. Every adapter for those was
written against its published API and exercised against a fake and against
recorded responses. None of them has spoken to the real thing.

## Where the platform stands

| Subsystem | Status | What that rests on |
|---|---|---|
| Repository, CI workflow, developer bootstrap | `RUNTIME_VERIFIED` | Clean-room clone, install and test run |
| Control plane (Laravel 13.30, PHP 8.4) | `RUNTIME_VERIFIED` | Full suite against PostgreSQL 16 and Redis |
| Portal SPA (React 19, Vite 8) | `RUNTIME_VERIFIED` | 54 component tests plus 105 browser specs driving the real API |
| Shared kernel — money, state machines, errors | `RUNTIME_VERIFIED` | Exercised by every module's tests |
| Identity, RBAC, two-factor, sessions, tokens | `RUNTIME_VERIFIED` | 8 roles, 51 permissions seeded and asserted |
| Multi-tenancy (`ResolveActingCustomer`) | `RUNTIME_VERIFIED` | One enforcement point, tested from both sides |
| Catalogue, pricing, coupons, tax | `RUNTIME_VERIFIED` | Seeded catalogue, integer minor units throughout |
| Orders and checkout | `RUNTIME_VERIFIED` | Including idempotency by request fingerprint |
| Billing, invoicing, subscriptions, dunning | `RUNTIME_VERIFIED` | Proration and renewal arithmetic under test |
| Wallet and credit | `RUNTIME_VERIFIED` | Credit is received and spent; six OS processes against one balance |
| Provisioning engine, compensation, drift | `RUNTIME_VERIFIED` | Timeouts quarantine rather than retry |
| IPAM — pools, subnets, allocation, quarantine | `RUNTIME_VERIFIED` | `FOR UPDATE SKIP LOCKED` under concurrency tests |
| Admin / NOC surface | `RUNTIME_VERIFIED` | 35 routes, each gated on its own permission |
| Monitoring exposition (Prometheus text) | `RUNTIME_VERIFIED` | Parsed by the test, not eyeballed |
| Structured logging and secret redaction | `RUNTIME_VERIFIED` | A Monolog processor, not a call-site convention |
| Arabic / RTL, six plural forms | `TESTED` | Translation-parity and plural-form suites |
| Stripe payments | `BLOCKED_CREDENTIALS` | Adapter complete; webhook verification tested against recorded payloads |
| Proxmox / VPS | `BLOCKED_CREDENTIALS` | Adapter and Ansible roles complete |
| Dedicated — Redfish, iLO, IPMI | `BLOCKED_HARDWARE` | Three adapters; IPMI never touches a shell |
| PXE / iPXE install profiles | `BLOCKED_HARDWARE` | Role and renderer complete; no machine to boot |
| Shared hosting — cPanel/WHM | `BLOCKED_LICENSE` | Adapter complete; installation stops at `LICENSE_REQUIRED` |
| Shared hosting — DirectAdmin | `BLOCKED_LICENSE` | As above |
| CloudLinux | `BLOCKED_LICENSE` | Ansible role only |
| Proxmox Backup Server | `BLOCKED_HARDWARE` | Ansible role only; see the gap below |
| Monitoring stack deployment | `BLOCKED_HARDWARE` | Prometheus, Alloy and Grafana configuration committed, never deployed |
| OpenTofu / Ansible execution | `BLOCKED_HARDWARE` | 15 roles, 11 playbooks, never run against a host |
| PHPStan in CI | `RUNTIME_VERIFIED` | Passes in GitHub Actions; still `BLOCKED_NETWORK` for its install path *here* |
| Scheduled work (renewals, dunning, backups) | `RUNTIME_VERIFIED` | Three sweeps, three commands, a schedule and a test that it is not empty |
| GitHub Actions | `RUNTIME_VERIFIED` | Observed, diagnosed and fixed on every red run; green at `5201a86` (run 67) |
| DNS and reverse DNS — Cloudflare | `BLOCKED_CREDENTIALS` | Adapter, zone discovery and PTR capability detection written and tested against recorded responses; forward DNS is now a customer product |
| Backups — Proxmox Backup Server | `BLOCKED_CREDENTIALS` | Adapter, state machine, API and reconciliation written and tested; no restore has been performed |
| OpenAPI 3.1 description | `RUNTIME_VERIFIED` | Generated from the route table, validated by redocly, three gates that fail the build on drift |
| Browser end-to-end suite | `RUNTIME_VERIFIED` | 94 Playwright specs, real portal, real API, real PostgreSQL and Redis |
| Customer-facing backups screen | `RUNTIME_VERIFIED` | Built in Phase 29; deletion and retention added in 30A+ |
| Team membership and invitations | `RUNTIME_VERIFIED` | Phase 30A+; an account can have more than one person in it |
| Support tickets | `RUNTIME_VERIFIED` | Phase 30A+; customer and operator halves, with internal notes |
| Forward DNS as a product | `BLOCKED_CREDENTIALS` | Phase 30A+; zones and records, no Cloudflare token here |
| Domain registration, renewal and transfer | `BLOCKED_CREDENTIALS` | Phase 30A++; the whole customer chain, proven against a fake registrar. No registrar account exists |
| `.sy` domains | `BLOCKED_LICENSE` | The seat exists and refuses every capability. No registry licence or technical contract; nothing invented |
| WordPress hosting | `BLOCKED_LICENSE` | Phase 30A++; ordering, provisioning and verification proven end to end against a fake panel. No cPanel or DirectAdmin adapter implements the installer, because neither toolkit's API is documented to this project |
| Customer-initiated termination | `RUNTIME_VERIFIED` | Phase 30A+; retention window, then the sweep that ends it |
| Hosting reconciliation | `BLOCKED_LICENSE` | Phase 30A+; five findings, and it repairs none of them |
| Provider task confirmation | `RUNTIME_VERIFIED` | Phase 30A+; a succeeded job is a request accepted until the poller says otherwise |
| Portal translation coverage | `RUNTIME_VERIFIED` | Every enum a screen renders has a string in both languages, gated by a test |

### The two gaps that were absences are now code, and one new absence

The previous revision of this file recorded reverse DNS and backups as
`NOT_IMPLEMENTED` behind a production guard that made them look present. Both
are now written:

- **DNS and reverse DNS.** A `Dns` module with a Cloudflare adapter — zone
  discovery, record publication, and a reverse-DNS adapter that detects whether
  the account actually serves the PTR zone for an address and refuses honestly
  when it does not, rather than writing a record nobody will resolve. RFC 2317
  classless delegation is deliberately not attempted. Nothing has yet spoken to
  Cloudflare, so the status is `BLOCKED_CREDENTIALS`, not verified.
- **Backups.** A `Backups` module with a Proxmox Backup Server adapter, a state
  machine whose terminal states are terminal, a customer-facing API, and
  reconciliation that quarantines an indeterminate task instead of retrying it.
  **No restore has been performed**, so this cannot become
  `REAL_INFRA_VERIFIED` under this project's own rule no matter how green the
  tests are.

The new absence, found by writing a browser test for a screen that turned out
not to exist:

- **No backups screen.** `GET /api/v1/vps/{vm}/backups` is implemented and
  tested, the seeder writes both a succeeded and a needs-review backup, and
  `nav.backups` exists as a translation — but the portal has no page that reads
  any of it. A customer cannot see their backups. This is recorded rather than
  papered over with a spec that asserts around it.

### History: what a browser found that 1,753 backend tests and 42 component tests did not

*Recorded when it happened, at the commit that had those counts. Kept because
the lesson outlived the numbers.*

Five defects, each customer-visible, each invisible to the suites that existed
because those suites supply the shape they expect:

1. **A funded wallet reported as empty.** The API answers a list of balances,
   one per currency; the portal declared a single object and read `.balance` off
   the collection. A customer with money was shown "No wallet yet."
2. **Every signed-in device list empty.** The shipped configuration stored
   sessions in Redis while the account-security screen reads the `sessions`
   table, so device revocation silently did nothing while answering as though it
   had worked.
3. **The super admin locked out of the operator area.** Super Admin holds no
   permission rows by design — the gate grants it everything — and `/me`
   reported those rows verbatim, so the portal hid every operator screen from
   the platform's most privileged login.
4. **A 500 on any unauthenticated non-JSON request**, because the framework
   redirected to a `login` route this application does not have.
5. **The API tokens screen unreachable by its own URL**, because the development
   proxy matched `/api` as a prefix and forwarded `/api-tokens` to the control
   plane.

Two further classes were fixed alongside them: a failed read anywhere in the
portal rendered as an empty state rather than as a failure, and `make
lint-backend` invoked PHPStan without its configuration, so the static-analysis
gate failed on itself wherever the analyser was installed.

## Evidence

Every command below was run in this environment after the last commit of code,
and these are its actual outputs.

### Backend

```text
php artisan test                        2444 tests, 69575 assertions, 0 failures
./vendor/bin/pint --test                PASS
composer validate --strict              PASS  (./composer.json is valid)
```

### Migrations, from an empty database

```text
CREATE DATABASE; php artisan migrate    45 migrations applied
php artisan migrate:rollback --step=99  45 rolled back
                                        left: migrations only
                                        no orphan tables, sequences or enum types
php artisan migrate                     45 re-applied
```

The same two steps CI runs (`migrate:fresh --seed`, then rollback and migrate
against `--env=testing`) were run here as CI runs them, and passed.

### Seeders

```text
db:seed (local)      RolePermission, Development, Catalogue, Infrastructure — all DONE
                     3 products, 9 plans, 32 prices
                     2 compute nodes, 61 allocatable addresses, 1 hosting node,
                     5 dedicated servers
db:seed again        row counts unchanged: the seeders are idempotent
db:seed (production) DevelopmentSeeder     RuntimeException — known passwords
                     CatalogueSeeder       RuntimeException — prices are an operator decision
                     InfrastructureSeeder  RuntimeException — real inventory is declared
                     RolePermissionSeeder  runs, by design: roles and permissions
                                           are reference data production needs
```

The three refusals were verified with the boot-time provider guard satisfied,
so that the refusal observed is each seeder's own and not the guard firing
first.

### Frontend

```text
npm run test --workspace=apps/web -- --run   54 tests in 9 files, all passing
npm run typecheck                             PASS  (now including the e2e suite)
npm run lint                                  PASS  (now including the e2e suite)
npm run build                                 428.45 kB JS / 126.58 kB gzipped
                                              23.61 kB CSS / 5.71 kB gzipped
npm run test:e2e --workspace=apps/web         94 specs in 13 files, all passing
```

### Static analysis

PHPStan level 6 with Larastan and the deprecation rules: **0 errors**, over
`src`, `app`, `database` and `routes`.

Its first run reported 141. Seventy-one were the analyser rather than the code —
this project declares casts in the `casts()` method, and Larastan needs
`parseModelCastsMethod` to read them. The remaining seventy were worked through
individually; three were defects with customer-visible consequences, and the
rest were annotations claiming more than the code guaranteed, branches an
exhaustive match had already made unreachable, and a factory trait on four
models that had no factory. There is no `ignoreErrors` block and no baseline.

`composer install --working-dir=tools/phpstan` still does not complete here.
Composer resolves every package's archive from `api.github.com`, which this
environment's egress policy answers 403 for; sixty-six of the sixty-seven
packages fall back to a git clone, which works, and `phpstan/phpstan` cannot,
because it is distributed as an archive with no installable source. The run
above was made by fetching the analyser's own signed release at the version the
lock file pins, assembling it into a scratch composer root outside this
repository, and analysing with the committed configuration — same version, same
larastan, same neon file. Status: `BLOCKED_NETWORK` for the CI install path, and
a real result for the analysis itself.

The Makefile invocation of the analyser was also wrong for the whole of this
build: it ran from the application root, where there is no `phpstan.neon`, so
`make lint-backend` answered "At least one path must be specified to analyse"
and stopped. It is fixed, and a test now checks that the invocation names a
configuration file which exists.

### GitHub Actions, observed

The workflow had run six times and failed six times, and this pass is the first
time anybody read the logs. Every failure was in the pipeline's own setup:

```text
run 1-6   composer install died in `artisan package:discover`: no .env on the
          runner, so APP_ENV defaulted to production and the provider guard
          refused. The suite, the migrations, the style check and PHPStan had
          therefore never executed in CI at all.
          composer audit ran without installing anything.
          the analyser was invoked with no configuration, from a second
          toolchain directory that had none to find.
          the PHP 8.3 matrix entry could not install a single package: the lock
          file pins Symfony 8, which needs PHP >= 8.4.1.
          the browser job's readiness probe opened a session — which now lives
          in PostgreSQL — against a database the suite had not yet migrated.

run 9     backend 8.4/PG16, backend 8.4/PG18, static analysis, frontend,
          API description, security checks, production guards, browser E2E:
          all eight green — the first green run this repository has had.
          https://github.com/fullstackfull/cloud/actions/runs/34139048572
```

The guard was also made to distinguish a chosen production environment from a
defaulted one, so tooling no longer trips it; the static-analysis job passes
without being told what environment it is in.

### CI gates, run exactly as CI runs them

```text
committed secrets           PASS  no tracked .env, no private key, no live credential shape
fake provider outside the
  development templates     PASS  161 files scanned, 3 templates excluded by exact name
unresolved placeholders     PASS  no TODO: implement / FIXME: required / NotImplementedException
composer validate --strict  PASS
npm audit --audit-level=high  0 vulnerabilities
composer audit              BLOCKED_NETWORK — the advisory endpoint times out at the proxy
```

The provider gate had to be corrected during this pass: tracking
`.env.testing.example` — itself the fix for an earlier gate that could not pass
on a fresh checkout — added a third template that declares `fake` deliberately,
because a test run that reaches a real provider bills a real card or creates a
real machine. The exclusion now names it, still by exact name, and the gate was
re-verified against a planted violation, which it still fails.

### A clean-room clone

```text
git clone --depth 1 --branch claude/hv-t6hq1p …
composer install                      85 packages, no failures
cp .env.testing.example .env.testing  + database credentials
APP_ENV=testing php artisan test      1793 passed, 41363 assertions, 3m00s
npm ci && npm run typecheck           PASS
npm run test --workspace=apps/web     54 passed
npm run build                         PASS
```

Two things a fresh clone needs that the example file cannot carry: the database
password, and `APP_ENV=testing` — because `artisan test` boots the application
before PHPUnit, and Laravel does not read `.env.testing` unless the environment
says so. `make test-backend` now sets it; the clean-room run is what found that.

### What has been verified in a browser

A Playwright suite of **94 specs across thirteen files**, run in real Chromium
against the portal, the control plane, PostgreSQL and Redis. Nothing in it stubs
a network call: it signs in through the form, so the CSRF cookie, the origin
match and the session cookie are all under test, and every assertion is on a
value a seeder wrote.

```text
npm run test:e2e --workspace=apps/web        94 passed
  portal.e2e.ts        24  dashboard, catalogue, invoices, wallet, services, VPS,
                           power, console, backups, restore, plan change
  auth.e2e.ts          10  sign-in, failure, rate limit, language and direction
  operations.e2e.ts    10  suspension, reactivation, rebuild refusals, the
                           provisioning queue an operator has to work
  team.e2e.ts           7  the list, roles, invitations, and a member who may not
  admin.e2e.ts          6  operator screens and the permission boundary
  appearance.e2e.ts     6  dark and light palettes, Arabic layout, Western numerals
  support.e2e.ts        6  a ticket from both sides, and the note one side never sees
  account.e2e.ts        5  devices, sign-in history, two-factor, API token, sign-out
  dns.e2e.ts            5  claiming a domain, publishing records, giving it up
  leaving.e2e.ts        5  cancelling, ending today, and the date the data goes
  backup-deletion.e2e.ts 4  the typed reference, and changing your mind
  reconciliation.e2e.ts  4  what the sweeps found, on the screen that shows it
  wallet-credit.e2e.ts   2  spending credit, in both languages
```

The suite starts both servers itself, refuses any database whose name does not
contain "e2e" before migrating it, and runs with one worker and no retries — a
browser test that passes on the second attempt has said nothing.

It runs in CI as its own job, with the report uploaded when it fails.

What it does **not** yet cover: ordering and paying by card (no payment provider
credentials, and a fake capture in a browser would prove nothing about the real
one), and provisioning a machine end to end, which is proven instead by a real
worker in `tests/Feature/Queue`.

## This environment

| Item | Finding |
|---|---|
| OS / kernel | Ubuntu 24.04.4 LTS, kernel 6.18 |
| CPU / RAM | 4 vCPU / 15 GiB |
| PHP | 8.4.19 |
| Composer | 2.8.12 |
| Node / npm | 22.22.2 / 10.9.7 |
| PostgreSQL | 16.13, local, accepting connections |
| Redis | 7.0.15, local |
| Privileges | root |
| Infrastructure credentials | none, of any kind |

### Constraints that are properties of this environment, not of the platform

1. **No credential for anything external exists here.** No Proxmox token, no
   BMC access, no cPanel or DirectAdmin licence, no Stripe key, no DNS token, no
   SSH access to any machine. Everything depending on one is delivered as
   complete, test-covered code plus runnable automation, and marked `BLOCKED_*`.

2. **The egress proxy answers 403 for several hosts.** Confirmed:
   `api.github.com` and `codeload.github.com` archive endpoints,
   `getcomposer.org/versions`, `ppa.launchpadcontent.net`. Consequences:
   Composer falls back to git clones; `php-bcmath` and `php-gmp` cannot be
   installed, so `brick/math` uses its pure-PHP calculator — correct, and
   slower; the npm registry is reachable, so frontend installs are unaffected.
   Production installs `php-bcmath` through Ansible and CI installs it in the
   matrix.

3. **The container is ephemeral.** Anything not committed and pushed is lost.

### Deviations from the requested baseline

| Requested | Used here | Reason |
|---|---|---|
| PostgreSQL 18 | 16.13 locally | 16 is what this image ships. `docker-compose.dev.yml` and the production Ansible role both target 18; no 17- or 18-only feature is used, and CI runs the suite against both. |
| Pest | PHPUnit 12 | Pest's install path needs the blocked archive host. Coverage is unaffected. |
| Promtail | Grafana Alloy | Promtail is EOL, as the specification requires. |

## The product roadmap

Where the platform is, and what comes next. A phase is complete when its own
closure report says so and its CI run is green — not when its code exists.

| Phase | What it is | State |
| --- | --- | --- |
| 29 | Final software closure | Complete |
| 30A | Final product closure | Complete |
| 30A+ | Core product completeness — team, wallet, support, backup retention and deletion, forward DNS, customer termination, hosting reconciliation, provider task polling | Complete; see [phase-30a-plus-core-product-completeness.md](phase-30a-plus-core-product-completeness.md) |
| 30A++ | Lynomia Domains and Lynomia WordPress Hosting | Complete; see [phase-30a-plusplus-domains-wordpress-final.md](phase-30a-plusplus-domains-wordpress-final.md) |
| 30B | Real infrastructure validation | Attempted; **NO GO**. Nothing real was reachable — see [phase-30b-real-infrastructure-validation.md](phase-30b-real-infrastructure-validation.md) |
| — | Production validation | |
| — | Go live | |

Phase 30A++ is a product phase and 30B is an infrastructure one. Nothing in
30A++ may be described as verified against a real registry, registrar or
control panel: that is precisely what 30B is for.

Phase 30B ran and could not do it. The environment has no route to any
management network, no credential for any provider, no hardware, and an
outbound proxy that refuses every third-party API this platform integrates
with — recorded probe by probe in
[phase-30b-real-infrastructure-inventory.md](phase-30b-real-infrastructure-inventory.md).
Every capability's exact blocker is in
[real-infrastructure-verification-matrix.md](real-infrastructure-verification-matrix.md),
where no row is `REAL_INFRA_VERIFIED`.

What 30B did deliver is the part that needs no machine: `infra/` holds the
infrastructure source of truth, the safety classification that a gate enforces
rather than a person remembers, the monitoring configuration, and nineteen
runbooks. Every physical machine is classified `DO_NOT_TOUCH`, which is the
default and the only defensible classification for a machine whose owner has
not spoken.

## What would have to happen next, and in what order

1. A Stripe test key, to take payments and webhook verification from
   `BLOCKED_CREDENTIALS` to `REAL_INFRA_VERIFIED`. It is the cheapest of these
   to obtain and unblocks the whole order-to-service path end to end.
2. A Proxmox endpoint and API token, for VPS provisioning.
3. A Cloudflare token. The adapter is written now; nothing has spoken to the API.
4. A cPanel or DirectAdmin licence and host.
5. Physical machines with BMCs, for the dedicated and PXE paths.
6. A Proxmox Backup Server datastore — and a restore performed from it, since a
   backup feature does not become `REAL_INFRA_VERIFIED` until a restore
   succeeds.
7. A first observed GitHub Actions run.

Until each of those happens, the corresponding row above stays exactly as it
reads now.
