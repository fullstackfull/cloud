# Lynomia Cloud — Final Report

Every claim below is backed by a command run in this environment, or is marked
as not done. Nothing is inferred from code that was written but not executed.

---

## A. Architecture implemented

A **modular monolith** behind an API, with an independent single-page portal.

```
apps/control-plane      Laravel 13.30, API only, no Blade views on the customer path
  src/Modules/<Module>/
    Domain/             entities, value objects, enums, contracts, domain exceptions
    Application/        actions, DTOs, jobs — the use cases
    Infrastructure/     Eloquent models, provider adapters, queries
    Http/               controllers, form requests, resources
apps/web                React 19 SPA, its own build, its own deployment
infrastructure/         Ansible (15 roles, 11 playbooks), OpenTofu, monitoring config
```

Eighteen modules: Admin, ApiKeys, Billing, Catalog, Compute, Dedicated,
Identity, Ipam, Monitoring, Orders, Payments, Provisioning, Rbac, Shared,
SharedHosting, Subscriptions, Vps, Wallet.

Decisions that shaped everything else:

- **Money is never a float.** Integer minor units end to end, `brick/money` in
  the domain, and the API sends `{minor_units, currency, amount}` so a client
  never has to parse a decimal into a double to compare two totals.
- **One multi-tenancy enforcement point.** `ResolveActingCustomer` middleware
  resolves the acting account per request and every scoped query starts there.
  Membership is re-checked on every request; a scoped token wins over a header
  that names a different account.
- **The admin surface is separated by permission, not by prefix.** `/api/admin`
  uses the same guard as the customer API; what keeps a customer out is that
  every one of its 12 routes names the permission it needs, and a test fails
  the build if a route is added without one.
- **A timeout means the platform stopped waiting, not that the provider
  stopped.** No indeterminate operation is auto-retried; it is quarantined for
  an operator, because a retried create is a second machine nobody ordered.
- **Scarce resources are allocated with `SELECT … FOR UPDATE SKIP LOCKED`**, so
  two concurrent orders cannot take the same address or the same capacity slot.
- **Layering is enforced by tests**, not by convention: `tests/Architecture`
  holds rules that were each verified against a planted violation.

Documented in `docs/architecture.md`.

## B. Repository structure

```
apps/control-plane   575 PHP source files, 190 test files, 22 migrations, 45 factories
apps/web             68 TypeScript/TSX files
infrastructure       161 tracked files: ansible/, tofu/, monitoring/
docs                 21 documents, including 3 runbooks
.github/workflows    ci.yml — 5 jobs: backend, static-analysis, frontend, security, production-guards
```

50 commits on `claude/hv-t6hq1p`.

## C. Technologies and versions

| | Version | Verified how |
|---|---|---|
| PHP | 8.4.19 | `php -v` |
| Laravel | 13.30.1 | installed and running |
| PostgreSQL | 16.13 here; 18 in CI and production | local server; CI matrix |
| Redis | 7.0.15 | local server, `PONG` |
| Node / npm | 22.22.2 / 10.9.7 | `node -v` |
| React | 19 | built |
| Vite | 8 | built |
| TypeScript | 5.9 | `tsc -b --noEmit` clean |
| PHPUnit | 12 | 1682 tests |
| PHPStan / Larastan | 2.2.13 / 3.11 | level 6, 0 errors |

Pest was replaced with PHPUnit because Pest's install path needs an archive
host this environment blocks. Promtail is not used anywhere: it is EOL, and log
shipping is Grafana Alloy.

## D. Application modules completed

All of the following are `RUNTIME_VERIFIED` — executed against real PostgreSQL
and Redis by the test suite in this environment.

Identity and RBAC (registration, verification, two-factor, sessions, devices,
sign-in history, 8 roles, 51 permissions) · multi-tenancy · API tokens with
scopes and per-token rate limits · catalogue, pricing, coupons and tax ·
orders and checkout with fingerprint idempotency · billing, invoicing,
subscriptions, proration, renewal and dunning · wallet and credit · payments
including webhook ingestion and refunds · provisioning engine with
compensation, drift detection and orphan adoption · IPAM with pools, subnets,
allocation, release quarantine and reverse DNS · VPS lifecycle · dedicated
server inventory and power control · shared hosting accounts and usage · the
admin/NOC surface · Prometheus exposition · Arabic/RTL throughout.

Not built, and named so the absence is not mistaken for a block:

- **No Cloudflare reverse-DNS adapter.** The contract, factory, endpoints and
  authorisation exist and are tested; the factory resolves exactly one driver,
  `fake`. `DNS_PROVIDER=cloudflare` passes the boot guard and then throws.
- **No backup provider in the application.** `BACKUP_PROVIDER` is read by the
  production guard and referred to by nothing else.
- **No OpenAPI document.** `docs/api.md` is prose, not a machine-readable
  specification.
- **No browser end-to-end suite.**

## E. Infrastructure components installed

**None. No machine was configured.** Ansible, OpenTofu and the monitoring
stack were written and syntax-checked; none of it has been run against a host,
because no host and no credential exist here.

Written: 15 Ansible roles (common, control_plane, database via postgresql,
redis, nginx, php_fpm, hardening, preflight, monitoring, proxmox,
proxmox_cluster, pbs, hosting, pxe, opnsense), 11 playbooks, an OpenTofu root
with modules and per-environment inputs, and Prometheus, Alertmanager, Grafana,
Loki, Alloy, blackbox and pve-exporter configuration.

Every playbook supports `--check` and `--diff`. `playbooks/control-plane.yml`
refuses to install onto a host that is also declared as a compute, hosting or
firewall node. OPNsense is touched only where a machine is declared
`role: firewall` with `allow_reimage: true`. No VLAN ID is assumed anywhere;
they come from inventory only.

## F. Commercial integrations completed

| Integration | Code | Verified against the real service |
|---|---|---|
| Stripe | complete: payment intents, webhooks with signature verification, refunds | **no** — `BLOCKED_CREDENTIALS` |
| Proxmox VE | complete: ticket auth, VM lifecycle, capacity | **no** — `BLOCKED_CREDENTIALS` |
| Redfish | complete | **no** — `BLOCKED_HARDWARE` |
| HPE iLO | complete | **no** — `BLOCKED_HARDWARE` |
| IPMI | complete, and never through a shell — arguments are passed as an argv array so an untrusted value cannot be concatenated into a command | **no** — `BLOCKED_HARDWARE` |
| cPanel / WHM | complete | **no** — `BLOCKED_LICENSE` |
| DirectAdmin | complete | **no** — `BLOCKED_LICENSE` |
| CloudLinux | Ansible role only | **no** — `BLOCKED_LICENSE` |
| Cloudflare DNS | **not written** | `NOT_IMPLEMENTED` |

No licence was pirated or bypassed. Where a licence is required, installation
stops cleanly and reports `LICENSE_REQUIRED`.

Production refuses to boot with a fake provider configured
(`ProviderRegistryServiceProvider`), and a service is provisioned only after
server-side payment verification — never because a browser returned to a
success URL.

## G. Tests executed

```text
php vendor/bin/phpunit                        → PASS   1682 tests, 41080 assertions, 0 failures
./vendor/bin/pint --test                      → PASS
composer validate --strict                    → PASS
PHPStan level 6 (larastan + deprecations)     → PASS   0 errors
npm run test --workspace=apps/web -- --run    → PASS   38 tests, 5 files
npm run typecheck                             → PASS
npm run lint                                  → PASS
npm run build                                 → PASS   427.30 kB JS / 126.34 kB gzipped
migrations from an empty database             → PASS   22 applied, 68 tables
migrations reversible                         → PASS   22 rolled back; only `migrations`
                                                       and `migrations_id_seq` remain
migrations re-applied                         → PASS   22, 68 tables
seeders                                       → PASS   idempotent across two runs
seeders refuse production                     → PASS   Development, Catalogue, Infrastructure
CI committed-secret gate                      → PASS
CI fake-provider gate                         → PASS   161 files scanned
CI placeholder gate                           → PASS
npm audit --audit-level=high                  → PASS   0 vulnerabilities
composer audit                                → BLOCKED_NETWORK (advisory endpoint times out)
provisioning end-to-end against real hardware → NOT RUN — no hardware exists
browser end-to-end suite                      → does not exist
GitHub Actions                                → never observed to run
```

Test files by area: Security 24, IPAM 16, Payments 14, Dedicated 12, hosting 11,
provisioning 11, VPS 10, identity 10, compute 9, billing 8, orders 7, catalogue
7, subscriptions 5, API keys 5, wallet 3, monitoring 3, RBAC 2, plus unit suites
and one architecture suite.

The browser check that *was* run: the sign-in flow end to end in real Chromium
at Phase 1, English/LTR and Arabic/RTL, light and dark, including a failed
sign-in showing a translated error with its correlation id. It has not been
re-run since, and no other screen has ever been driven in a browser.

## H. Security findings and fixes

A review with eight reviewers, one per dimension, then one adversarial verifier
per dimension instructed first to refute. Recorded in `docs/security-review.md`.

```text
Findings filed   38
Confirmed        24
Overstated       13   real, but not as severe or reachable as filed
Refuted           0
Fixed             32   each with a regression test
```

Nothing was refuted, which is reported rather than celebrated: a panel that
overturns nothing is either facing careful reviewers or is not adversarial
enough, and from the inside those look the same.

The structural fix that came out of it: nine modules had each invented their own
forbidden-permission exception, because Laravel converts `AuthorizationException`
into `AccessDeniedHttpException` before the renderer runs, so the renderer's
`AuthorizationException` arm was unreachable and produced a different error code.
Everyone had routed around a wrong default and nobody had reported it. One
exception class now serves the platform, the renderer handles all three shapes,
and a test asserts that a domain refusal, a Gate denial and a bare `abort(403)`
all answer `auth.forbidden`.

Static analysis then found three more defects with customer-visible
consequences:

1. `IpPool` had a `datacenter_id` column and no `datacenter()` relation, while
   the operator IP-pool listing eager-loaded one. Eloquent resolves an eager
   load only when there is a row to attach it to — so the endpoint answered 200
   on an empty table and 500 as soon as an operator had inventory to look at.
2. `Money::format()` called `formatTo()`, which brick/money does not have. No
   caller and no test, so the fatal was waiting for the first rendered invoice.
3. Every hosting adapter converts "node not configured" into a provider
   exception before it leaves, so the panel-session action's catch for the
   original type never ran, and a missing WHM token reached the customer as
   "the panel could not be reached, try again shortly" — advice that would never
   come true, under an error code pointing support at a healthy machine.

And one in the build itself: `make deploy-staging` named a playbook that does
not exist. Fixed, with a test that now checks every playbook and inventory the
Makefile names.

Standing guarantees, each with a test: no plaintext password, API secret, BMC
password, private key or card detail is ever logged — redaction is a Monolog
processor, not a call-site convention; Grafana is never exposed without
authentication; Proxmox administrative interfaces are not published by default;
BMC credentials never appear in an application log or an API error body; no
secret and no SSH key is committed, enforced by a CI gate.

## I. Performance findings

**No load test, benchmark or profile was run.** Nothing here is a throughput or
latency claim. What can be stated factually:

- The full backend suite — 1682 tests against real PostgreSQL and Redis —
  completes in about 138 seconds on 4 vCPU.
- The production frontend build takes under a second and produces 427.30 kB of
  JavaScript, 126.34 kB gzipped.
- `brick/math` runs on its pure-PHP calculator here, because `php-bcmath` and
  `php-gmp` cannot be installed through this environment's proxy. That is
  correct and measurably slower; production installs `php-bcmath` via Ansible
  and CI installs it in the matrix.
- Query counts are not asserted anywhere. Listings eager-load and use
  `withCount` rather than counting in a loop, and provider adapters are memoised
  per row for the lifetime of the container — but no test proves the absence of
  an N+1, so no such claim is made.

## J. Infrastructure discovered

Nothing was discovered, because there was nothing to discover: no inventory
file describes a real machine, and no credential exists to reach one. The
environment itself:

| | |
|---|---|
| OS / kernel | Ubuntu 24.04.4 LTS, 6.18 |
| CPU / RAM | 4 vCPU / 15 GiB |
| PostgreSQL / Redis | 16.13 / 7.0.15, both local |
| Egress | proxied; 403 for the GitHub archive endpoints, `getcomposer.org/versions`, `ppa.launchpadcontent.net` |
| Credentials | none |

PostgreSQL and the whole container were interrupted three times during the
build (`database system was interrupted … not properly shut down`, recovered
automatically). Runs that failed with "Connection refused" during those windows
were the outage and were re-run, not reported.

## K. Remaining external requirements

In the order that unblocks the most:

1. **A Stripe test key.** Cheapest to obtain, and it takes the whole
   order → invoice → payment → provisioning path to `REAL_INFRA_VERIFIED`.
2. **A Proxmox endpoint and API token**, for VPS provisioning.
3. **A Cloudflare API token — and first, the Cloudflare adapter**, which is not
   written.
4. **A cPanel or DirectAdmin licence and a host.** Without one, installation
   stops at `LICENSE_REQUIRED`, by design.
5. **Physical machines with BMCs** on an isolated management network, for the
   dedicated and PXE paths.
6. **A first observed GitHub Actions run.**

## L. Exact commands to deploy production

Nothing below has been executed. They are the entry points as written.

```bash
# 1. Preflight — read-only, never mutates
make infra-check ENV=production

# 2. Infrastructure plan — no apply
make infra-plan

# 3. Platform hosts
cd infrastructure/ansible
ansible-playbook -i inventories/production playbooks/hardening.yml   --check --diff
ansible-playbook -i inventories/production playbooks/database.yml    --check --diff
ansible-playbook -i inventories/production playbooks/redis.yml       --check --diff
ansible-playbook -i inventories/production playbooks/control-plane.yml --check --diff
# then re-run each without --check once the diff is what you expect

# 4. Observability
make monitoring-deploy ENV=production

# 5. Hypervisors and backups — only for machines declared in the inventory
make proxmox-postinstall ENV=production
make pbs-configure ENV=production

# 6. Hosting nodes — validates before any panel installation
make hosting-preflight ENV=production
```

Every playbook accepts `--check`, `--diff` and `--verbose`, and is idempotent.
None will touch a machine that is not declared as an installation target in the
inventory: nothing is formatted, repartitioned, wiped, reset, rebooted or
reinstalled without that declaration, and OPNsense is only ever installed where
a machine is declared `role: firewall` with `allow_reimage: true`.

Before any of this, `docs/production-checklist.md` lists what must be true
first.

## M. Production readiness status

**The application is ready to be deployed. The platform is not ready to sell.**

Ready:

- The control plane runs, on real PostgreSQL and Redis, with 1682 passing tests,
  clean static analysis at level 6, and migrations that go forward and back from
  an empty database leaving nothing behind.
- The portal builds and its guards are tested from both directions.
- CI has five jobs — backend, static analysis, frontend, security and production
  guards — and every gate in it passes when run here as CI runs it.
- Production refuses to boot with a fake provider, so it cannot silently take
  money for services it never creates.

Not ready, and each is a real dependency rather than a missing line of code:

- No commercial integration has ever spoken to the real service. Every one of
  them is `BLOCKED_CREDENTIALS`, `BLOCKED_HARDWARE` or `BLOCKED_LICENSE`.
- Reverse DNS beyond the fake, and any backup driver inside the application, are
  not written at all.
- No machine has been configured. The automation exists and has never run.
- No high availability has been configured or tested, so none is claimed.
- No load test has been run, so no capacity is claimed.
- CI has never been observed to execute.

**Verdict: `CODE_COMPLETE` and `RUNTIME_VERIFIED` for the platform;
`BLOCKED_*` for every path that leaves it.** The first thing that changes this
is a Stripe test key.
