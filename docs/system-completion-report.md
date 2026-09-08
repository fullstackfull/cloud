# Lynomia Cloud — System Completion Report

What was asked for was the last mile: finish the missing software, test it hard,
and say plainly what still cannot be verified without real infrastructure. What
the work found instead is that three links of the platform's own money chain had
never been connected, and that its pipeline had never once run a test.

Everything below was produced by a command in this environment or observed in a
GitHub Actions run, and is reported with the status vocabulary the brief
mandates. `REAL_INFRA_VERIFIED` appears nowhere in this document.

---

## A. Baseline independently verified

Re-verified before any change, recorded in `docs/phase-11-baseline.md`, and
re-verified after every phase since.

| Gate | At baseline | Now |
|---|---|---|
| Backend suite | 1,685 tests / 41,085 assertions | **1,797 / 41,371** |
| Frontend unit | 38 | **42** |
| Browser end-to-end | none | **35 specs** |
| PHPStan (level 6, larastan) | 0 errors | **0 errors** |
| Pint | pass | pass |
| Typecheck / ESLint | pass | pass (now including the e2e suite) |
| Migrations | 22 / 68 tables | 23 / 69 tables |
| Production build | pass | pass |
| GitHub Actions | never green (6 runs, 6 failures) | **all 8 jobs green** |

The baseline never regressed silently: every phase ended with the whole set
above, and the two occasions on which it did regress are recorded in section D.

## B. Missing components discovered

Ordered by what each one costs a customer.

1. **A placed order never became payable.** `IssueInvoiceForOrder` was written,
   documented and covered — and had no caller. Checkout left the order at
   PENDING_PAYMENT with no invoice, so there was nothing to pay and the rest of
   the chain (capture → settle → fulfil) could not begin. The platform's own
   end-to-end test issued the invoice itself, at exactly the step the platform
   did not, and so read as proof of a flow that only worked when a test
   performed part of it.
2. **The platform had no clock.** The deployment installs a `schedule:run` cron
   on every application server; the application scheduled nothing. Subscriptions
   never renewed, scheduled cancellations never took effect, the dunning
   sequence never advanced, and backups stayed "running" for ever.
3. **A refund never reached the invoice.** `IssueRefund` sent the money and
   announced `RefundIssued`; nothing listened, and `RecordInvoiceRefund` had no
   caller. A fully refunded invoice still read as paid in full with nothing
   refunded — on the platform's books and on the customer's copy alike.
4. **Signed-in devices could never be listed or revoked.** The shipped
   configuration stored sessions in Redis while the account-security screen
   reads the `sessions` table. "Sign out other devices" deleted nothing and
   answered 204.
5. **The super admin was locked out of the operator area.** Super Admin holds no
   permission rows by design; `/me` reported those rows verbatim, so the portal
   hid every operator screen from the platform's most privileged login.
6. **A funded wallet was reported as empty.** The API answers a list of balances,
   one per currency; the portal declared a single object.
7. **Any unauthenticated non-JSON request answered 500**, because the framework
   redirected to a `login` route this application does not have.
8. **The API tokens screen was unreachable by its own URL**, because the dev
   proxy matched `/api` as a prefix and swallowed `/api-tokens`.
9. **A failed read anywhere in the portal rendered as an empty screen** — "you
   have nothing" where the truth was "you may not see this" or "the request
   failed".
10. **CI had never run a test.** Six runs, six failures, every one of them in the
    pipeline's own setup rather than in the code.
11. **A committed order was briefly visible as a draft**, because the write and
    the transition that places it committed separately.

## C. Missing components completed

| # | What was done | Where |
|---|---|---|
| 1 | `OrderPlaced` announced after the checkout transaction commits; `IssueInvoiceOnOrderPlaced` issues the invoice synchronously, idempotently | `Modules/Orders/Domain/Events`, `Modules/Billing/Application/Listeners` |
| 2 | `RenewDueSubscriptions`, `SweepSubscriptionLifecycle`, `ReconcileRunningBackups`, three commands, and a schedule with `withoutOverlapping` and `onOneServer` | `routes/console.php`, `app/Console/Commands` |
| 3 | `RecordRefundAgainstTheInvoice`, queued with retries because the money has already left | `Modules/Billing/Application/Listeners` |
| 4 | `SESSION_DRIVER=database`, plus a production guard that refuses any driver whose rows cannot be listed | `.env.example`, `ProviderRegistryServiceProvider` |
| 5 | `/me` reports effective authority, enumerated from the Permission enum | `UserResource` |
| 6 | The wallet screen renders every balance; the API's list shape is typed | `apps/web/src/lib`, `features/wallet` |
| 7 | `redirectGuestsTo(fn () => null)` so an unauthenticated request answers 401 | `bootstrap/app.php` |
| 8 | The dev proxy matches `^/api/` rather than `/api` | `apps/web/vite.config.ts` |
| 9 | `LoadFailure` reports a failed read on every screen that had no error path | `apps/web/src/components` |
| 10 | Four CI defects fixed and the pipeline watched until it ran (section E) | `.github/workflows/ci.yml` |
| 11 | The order and its placing transition commit together | `PlaceOrder` |

Earlier in the same pass, and reported before this document: the Cloudflare DNS
and reverse-DNS adapters, the Proxmox Backup Server adapter with its state
machine and customer API, and the generated OpenAPI 3.1 description with three
build-failing drift gates.

## D. Tests added

112 backend tests, 4 frontend tests and 35 browser specs since the baseline.
**Every one was verified failing first**, and the two that were not initially
honest are recorded here rather than quietly corrected:

- A colour-scheme assertion that read an oklch lightness through an sRGB
  formula and returned the same number for both palettes — it passed whatever
  the page did.
- A query-count comparison whose one-row measurement included a first-call cache
  warm-up, making the one-row cost *higher* than the twelve-row cost and hiding
  the N+1 it was written to find.

Notable additions: two-connection concurrency tests for order idempotency and
subscription renewal; a lock-ordering test that proves the money paths take
their two rows in one order (verified by reversing them); a schedule test that
fails when the cron has nothing to run; twelve N+1 guards; and four tests that a
failed read is reported rather than rendered as emptiness.

## E. End-to-end results

**Browser suite — 35 specs, all passing**, in real Chromium against the real
portal, the real API, real PostgreSQL and real Redis. One worker, no retries.

```
auth.e2e.ts        10  sign-in, failure, rate limit, language and direction
portal.e2e.ts       9  dashboard, catalogue, product, invoices, wallet, VPS
appearance.e2e.ts   5  dark and light palettes, Arabic layout, Western numerals
account.e2e.ts      5  devices, sign-in history, two-factor, API token, sign-out
admin.e2e.ts        6  operator screens and the permission boundary
```

Five of the eleven defects in section B were found by this suite within minutes
of its first run.

**GitHub Actions — observed, diagnosed and fixed.** The workflow had run six
times and failed six times, and nobody had read why. In order: composer's
package discovery booted the app with no environment file, defaulted to
production and was refused by the platform's own provider guard, so the suite,
the migrations, the style check and PHPStan had **never executed in CI**;
`composer audit` ran without installing; the analyser was invoked with no
configuration, from a second toolchain directory that had no configuration to
find; the PHP 8.3 matrix entry could not install a single package because the
lock file pins Symfony 8 (PHP ≥ 8.4.1) while composer.json claimed ^8.3; and the
browser job's readiness probe started a session, which now lives in PostgreSQL,
against a database the suite had not yet migrated.

**Run 9 on this branch is green: all eight jobs, for the first time in this
repository's history** — backend on PostgreSQL 16 and 18, static analysis,
frontend, API description, security checks, production guards, and the browser
suite against a database CI created from empty. The workflow also builds feature
branches now: it ran only on pull requests and on main, so this branch — thirty
pushes — had never been built at all.

## F. Load-test results

Full numbers, method and limits in `docs/performance-report.md`.

| Endpoint (1 client, caches built) | p50 | p90 | p99 |
|---|---|---|---|
| `/sanctum/csrf-cookie` (framework floor) | 29.2 ms | 35.5 ms | 37.4 ms |
| `/api/v1/me` | 43.4 ms | 50.6 ms | 56.6 ms |
| `/api/v1/invoices` | 50.0 ms | 54.0 ms | 60.6 ms |
| `/api/v1/invoices?per_page=100` | 70.8 ms | 80.8 ms | 104.9 ms |

At 4 workers and 8 concurrent clients: 55–124 req/s per endpoint, p50 62–141 ms.
Checkout, in process, against 560,000 invoices: **p50 72.6 ms, 24 queries**.

The finding that matters: a request doing no application work costs 29 ms, so
`/api/v1/invoices` spends ~21 ms in the application and 0.15 ms in the database.

**What this is not:** there is no nginx, no FPM, no separate database host and no
network here, and the load generator shares four vCPU with the servers. It
compares endpoints and catches regressions on this hardware. It is not a
capacity figure, and no capacity claim should be made until it is re-run on
staging.

## G. Security findings

The review after the new components. Nothing in it is a customer-exploitable
hole; two are security *features that did not work*, which is worse than absent
because they get trusted.

1. **Session revocation did nothing** (section B4). A customer who suspected
   their account was compromised saw no devices and was told they had signed
   the intruder out. Fixed, with a production guard so a deployment cannot
   silently choose a driver that breaks it again.
2. **The most privileged login could not reach the operator area** (B5). Not an
   escalation — the API would have allowed every request — but the surface was
   unreachable, which pushes an operator toward the database.
3. **The provider guard fired in build contexts**, and its message read as a
   misconfigured deployment. Six red CI runs went unexamined partly because of
   it. It now distinguishes "production was chosen" from "production is what was
   left when nothing was said", and stands down only when there is no
   environment file *and* no APP_ENV anywhere in the process.
4. **A guard that used the framework's env helper would have stood down in
   production**, because a cached configuration makes that helper return null.
   Caught by the architecture suite and PHPStan before it left the machine.
5. **Cross-tenant isolation re-checked** on the new surface: the backups
   endpoints answer 404 rather than 403 for another tenant's machine or backup,
   in line with the rest of the API; 105 security tests pass.
6. **No credential reaches a customer, a response or a log** on the new paths:
   the Cloudflare connection names its configuration key and never its value;
   provider exceptions are redacted before they are stored; the scheduler
   commands print counts only. The committed-secret gate passes in CI.
7. **The load-test tooling mints a token with a raised rate limit** and prints
   it. Both commands refuse production outright and refuse any database whose
   name does not mark it as scratch.

## H. Performance findings

1. **No N+1 anywhere.** Twelve list endpoints issue the same number of queries
   for twelve rows as for one, including the VPS list, which batches addresses
   deliberately.
2. **No sequential scan** in any customer list query at 560,000 rows; execution
   times 0.05–0.15 ms.
3. **The framework boot dominates every request** (section F). The work worth
   doing is runtime tuning on a real host, not query optimisation.
4. **The authenticated API is limited to 120 requests a minute per user**, which
   is the first ceiling any single client meets — by design.
5. **Checkout costs 24 queries**, with no repetition per line.

## I. Infrastructure automation status

Unchanged by this pass and still `BLOCKED_HARDWARE`: 15 Ansible roles and 11
playbooks, none of which has been run against a host. The deployment role
installs a scheduler cron and a Horizon unit — the cron now has work to do,
which it did not before.

Preflight (Phase 16) and staging (Phase 17) were **not performed**: both begin
with connecting to a machine, and there is no machine, no inventory and no
credential. The inventory schema the brief specifies, including
`allow_reimage: false` as the default, remains unwritten; writing one that
describes no real host would be fiction.

## J. Real integrations tested

None. No Stripe key, no Proxmox endpoint, no BMC, no Cloudflare token, no cPanel
or DirectAdmin licence, no Proxmox Backup Server datastore and no physical
machine existed at any point in this environment.

| Component | Code | Tests | Local runtime | Real provider | Real end-to-end |
|---|---|---|---|---|---|
| Identity, RBAC, sessions, tokens | ✅ | ✅ | ✅ | N/A | ✅ (browser) |
| Multi-tenancy | ✅ | ✅ | ✅ | N/A | ✅ (browser) |
| Catalogue and pricing | ✅ | ✅ | ✅ | N/A | ✅ (browser) |
| Orders and checkout | ✅ | ✅ | ✅ | N/A | ⚠️ API only |
| Billing and invoicing | ✅ | ✅ | ✅ | N/A | ✅ (browser) |
| Subscriptions, renewals, dunning | ✅ | ✅ | ✅ | N/A | ❌ no screen |
| Wallet | ✅ | ✅ | ✅ | N/A | ✅ (browser) |
| Provisioning engine | ✅ | ✅ | ✅ | N/A | ❌ |
| IPAM | ✅ | ✅ | ✅ | N/A | ✅ (browser) |
| Admin / NOC surface | ✅ | ✅ | ✅ | N/A | ✅ (browser) |
| Stripe payments | ✅ | ✅ | ✅ | 🚫 BLOCKED_CREDENTIALS | 🚫 |
| Proxmox / VPS | ✅ | ✅ | ✅ | 🚫 BLOCKED_CREDENTIALS | 🚫 |
| Cloudflare DNS + reverse DNS | ✅ | ✅ | ✅ | 🚫 BLOCKED_CREDENTIALS | 🚫 |
| Proxmox Backup Server | ✅ | ✅ | ✅ | 🚫 BLOCKED_CREDENTIALS | 🚫 no restore performed |
| Shared hosting (cPanel/DirectAdmin) | ✅ | ✅ | ✅ | 🚫 BLOCKED_LICENSE | 🚫 |
| Dedicated (Redfish/iLO/IPMI) | ✅ | ✅ | ✅ | 🚫 BLOCKED_HARDWARE | 🚫 |
| PXE / install profiles | ✅ | ✅ | ✅ | 🚫 BLOCKED_HARDWARE | 🚫 |
| Monitoring stack | ✅ | ✅ | ⚠️ exposition only | 🚫 BLOCKED_HARDWARE | 🚫 |
| Backups — customer screen | ❌ NOT_IMPLEMENTED | — | — | — | — |

"Local runtime" means executed against real PostgreSQL and Redis in this
environment with a fake provider. It is not evidence about the real provider,
and the two columns are kept apart for that reason.

## K. Real infrastructure discovered

None. No host, no cluster, no BMC and no inventory file exists in this
repository or this environment. Nothing was scanned, connected to, or
configured, and no destructive action of any kind was possible or attempted.

## L. Remaining blockers

**Software, and mine to finish:**

1. No customer-facing backups screen. The API and its tests exist; the portal has
   no page that reads them.
2. No subscription or renewal screen in the portal.
3. Nothing fulfils a zero-total order: it is placed as PAID, and fulfilment
   hangs off `InvoicePaid`, which never arrives for an invoice that is never
   issued.
4. Drift detection records drift but nothing detects it; `SyncHardwareInventory`,
   `AdoptOrphanResource`, `PreflightHostingNode`, `UnsuspendHostingAccount` and
   `VoidInvoice` have no route, command or schedule.
5. The queue has never been exercised: no worker ran in any test or measurement.

**External inputs required for the next phase.** This is the complete list; each
line is what unblocks the row above it.

| # | Input | Unblocks |
|---|---|---|
| 1 | Stripe **test-mode** secret key and webhook signing secret | Payments end to end: order → invoice → capture → settle → fulfil |
| 2 | Proxmox VE endpoint URL + API token ID and secret, with a node name and a storage name to use | VPS provisioning, power, console |
| 3 | A test IP pool: subnet, gateway, VLAN id, and confirmation it is not in production use | Real IPAM allocation |
| 4 | Cloudflare API token scoped to one zone, and the zone name | Forward DNS; reverse DNS only if the PTR zone is genuinely delegated to that account |
| 5 | Proxmox Backup Server endpoint, datastore name and credentials | Backups — and a **restore** must be performed before backups can be called verified |
| 6 | A cPanel/WHM or DirectAdmin licence and a host to install it on | Shared hosting |
| 7 | One dedicated machine with BMC address and credentials, and explicit written authorisation naming that host for reimaging | Dedicated power control, and PXE only on a dedicated provisioning VLAN |
| 8 | A staging host (or three) with SSH access for the Ansible roles | Preflight, staging, monitoring deployment |
| 9 | A DNS zone and TLS certificates for the staging hostnames | Staging over HTTPS |

Nothing on that list can be substituted, and no work below the software line
should be reported as done until the corresponding item arrives.

## M. Production-readiness verdict

**The software is `RUNTIME_VERIFIED`. The system is not
`REAL_INFRA_VERIFIED`, and no part of it should be sold yet.**

What that means precisely:

- Every business rule the platform implements is executed against a real
  database and a real browser in this environment, and the pipeline that proves
  it now runs on every push.
- The money chain — order, invoice, payment, settlement, fulfilment, refund,
  renewal, dunning — is connected end to end for the first time. Three of its
  links were missing at the start of this pass, and each was invisible to a
  green test suite.
- Not one provider integration has spoken to the real thing. Until items 1–7
  above exist, "Stripe works" means "the adapter satisfies a fake and recorded
  responses", and this document will not say more than that.
- A backup feature does not become verified until a restore succeeds. None has
  been attempted, because there is no datastore to attempt it against.

The honest sentence for a customer-facing statement of readiness is: *the
platform is complete as software and unproven as infrastructure.*
