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
| Portal SPA (React 19, Vite 8) | `TESTED` | 38 tests, typecheck, lint, production build |
| Shared kernel — money, state machines, errors | `RUNTIME_VERIFIED` | Exercised by every module's tests |
| Identity, RBAC, two-factor, sessions, tokens | `RUNTIME_VERIFIED` | 8 roles, 51 permissions seeded and asserted |
| Multi-tenancy (`ResolveActingCustomer`) | `RUNTIME_VERIFIED` | One enforcement point, tested from both sides |
| Catalogue, pricing, coupons, tax | `RUNTIME_VERIFIED` | Seeded catalogue, integer minor units throughout |
| Orders and checkout | `RUNTIME_VERIFIED` | Including idempotency by request fingerprint |
| Billing, invoicing, subscriptions, dunning | `RUNTIME_VERIFIED` | Proration and renewal arithmetic under test |
| Wallet and credit | `RUNTIME_VERIFIED` | |
| Provisioning engine, compensation, drift | `RUNTIME_VERIFIED` | Timeouts quarantine rather than retry |
| IPAM — pools, subnets, allocation, quarantine | `RUNTIME_VERIFIED` | `FOR UPDATE SKIP LOCKED` under concurrency tests |
| Admin / NOC surface | `RUNTIME_VERIFIED` | 12 routes, each gated on its own permission |
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
| PHPStan in CI | `BLOCKED_NETWORK` | Runs clean here by another route; see below |
| GitHub Actions | not observed | The workflow has never been seen to execute |
| **Reverse DNS beyond the fake** | `NOT_IMPLEMENTED` | Contract and fake only |
| **Backup provider in the application** | `NOT_IMPLEMENTED` | Config key only |

### Two gaps that are absences, not blocks

`DNS_PROVIDER` and `BACKUP_PROVIDER` are configurable, and the production guard
refuses `fake` for both. That guard makes it look as though a real driver exists
behind each. It does not.

- **Reverse DNS.** `ReverseDnsProviderFactory` resolves exactly one driver,
  `fake`. There is no Cloudflare adapter. Setting `DNS_PROVIDER=cloudflare`
  passes the boot-time guard and then throws `UnknownReverseDnsDriverException`
  the first time a PTR is set. The contract, the factory, the customer-facing
  PTR endpoints and their authorisation are all written and tested; the adapter
  is the missing piece.
- **Backups.** `BACKUP_PROVIDER` is read into `config('billing.providers')` and
  checked by the production guard, and nothing else in the application refers to
  it: there is no backup contract, factory or adapter. Backups are handled
  entirely by the Proxmox Backup Server Ansible role, which has never been run.

Both are recorded here rather than quietly left for someone to discover during a
deployment.

## Evidence

Every command below was run in this environment after the last commit of code,
and these are its actual outputs.

### Backend

```text
php vendor/bin/phpunit                  1680 tests, 41070 assertions, 0 failures
./vendor/bin/pint --test                PASS
composer validate --strict              PASS  (./composer.json is valid)
```

### Migrations, from an empty database

```text
CREATE DATABASE; php artisan migrate    22 migrations applied, 68 tables
php artisan migrate:rollback --step=99  22 rolled back
                                        left: migrations, migrations_id_seq
                                        no orphan tables, sequences or enum types
php artisan migrate                     22 re-applied, 68 tables
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
npm run test --workspace=apps/web -- --run   38 tests in 5 files, all passing
npm run typecheck                             PASS
npm run lint                                  PASS
npm run build                                 427.30 kB JS / 126.34 kB gzipped
                                              23.27 kB CSS / 5.65 kB gzipped
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

`composer install --working-dir=tools/phpstan` does not complete here:
`phpstan/phpstan` is distributed as an archive with no git source, and both
archive endpoints answer 403 through this environment's proxy while plain `git
clone` succeeds. The run above was made by cloning the PHPStan distribution
repository at its locked tag into a scratch composer root outside this
repository, with the same configuration. `apps/control-plane/tools/phpstan/README.md`
records this. Status: `BLOCKED_NETWORK` for the CI install path, and a real
result for the analysis itself.

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

### What has been verified in a browser

The sign-in flow was exercised end to end in real Chromium at Phase 1, in
English/LTR and Arabic/RTL, light and dark, including a failed sign-in showing a
translated error with its correlation id. **That has not been re-run since**,
and no other screen has ever been driven in a browser. There is no end-to-end
suite; when one exists it will be reported here, and until then nothing in this
repository claims to have run one.

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

## What would have to happen next, and in what order

1. A Stripe test key, to take payments and webhook verification from
   `BLOCKED_CREDENTIALS` to `REAL_INFRA_VERIFIED`. It is the cheapest of these
   to obtain and unblocks the whole order-to-service path end to end.
2. A Proxmox endpoint and API token, for VPS provisioning.
3. A Cloudflare token — and first, the Cloudflare adapter, which is not written.
4. A cPanel or DirectAdmin licence and host.
5. Physical machines with BMCs, for the dedicated and PXE paths.
6. A first observed GitHub Actions run.

Until each of those happens, the corresponding row above stays exactly as it
reads now.
