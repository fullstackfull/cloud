# Phase 30A — Independent baseline

Re-run rather than inherited. Phase 29's closing report states its own numbers;
this document records what the repository actually does today, on this machine,
at this commit, before anything in Phase 30A changes.

- **Commit:** `114743f`
- **Branch:** `claude/hv-t6hq1p`
- **Date:** 2026-09-07
- **PHP:** 8.4.19 · **PostgreSQL:** 16 (local), 18 (CI) · **Redis:** 7
- **Node:** 22

## Gates, as run

| Gate | Command | Result |
| --- | --- | --- |
| Backend suite | `php artisan test` | **1875 passed**, 44,134 assertions, 182 s |
| Code style | `vendor/bin/pint --test` | passed |
| Static analysis | `phpstan analyse` (level 6, larastan) | **0 errors** |
| Composer manifest | `composer validate --strict` | valid |
| Frontend types | `npm run typecheck` | 0 errors |
| Frontend lint | `npm run lint` | 0 errors |
| Frontend unit | `npm test -- --run` | **45 passed** |
| Production build | `npm run build` | built, 435.59 kB (128.40 kB gzipped) |
| Browser end-to-end | `npx playwright test` | **40 passed**, 1.4 min |
| OpenAPI drift | `php artisan openapi:generate --check` | up to date, **84 operations** |
| Migrations from empty | `migrate:fresh` | 28 applied |
| Migrations reversible | `migrate:rollback --step=100` then `migrate` | clean cycle, 28 ran, 0 pending |

Phase 29's reported figures were accurate. The two that differ are assertions
(44,134 rather than "42,000+", which was written before the last three
commits) and browser tests (40 rather than 39, for the same reason).

## What the baseline already proves

Carried forward from Phase 29 and re-confirmed by the suite above:

- A **real Redis worker** consumes provisioning work in a separate process, and
  the scheduler→Redis→worker→database chain runs end to end
  (`ARealWorkerConsumesTheQueueTest`, 5 cases including a provider refusal and
  a worker SIGKILLed mid-build).
- **No dead capabilities at class level** — `NoDeadCapabilitiesTest` fails if
  any action, job or listener has no reference in application code, with one
  documented exception (`RedeemConsoleSession`, whose gateway this phase
  builds).
- **Every provisioning kind is routable or explicitly excused**
  (`HandlerCoverageTest`).
- **Audit log** exists and is append-only at the model.
- **Drift detection** runs on a schedule and is alertable
  (`lynomia_resource_drift_open`).
- **Scheduler liveness** is observable
  (`lynomia_scheduled_command_last_success_timestamp_seconds`).

## The six gaps this phase must close

Stated by Phase 29's own report, and re-confirmed against the code:

| # | Gap | Confirmed by |
| --- | --- | --- |
| 1 | VPS reinstall not implemented | No handler registered for `ProvisioningJobKind::Reinstall`; `HandlerCoverageTest` lists it as a documented gap |
| 2 | Dedicated reinstall not implemented | The same kind, the same absence |
| 3 | Console permits cannot be redeemed | `RedeemConsoleSession` is the sole entry in `NoDeadCapabilitiesTest`'s allowlist |
| 4 | Change-plan has no customer UI | `POST /subscriptions/{subscription}/plan` exists; no route in `App.tsx` reaches it |
| 5 | No customer notification system | Only three notifications exist, all in Identity: verify email, reset password, registration-on-existing-account |
| 6 | Compute suspension is not enforced at the provider | `EnforceServiceStateForSubscription` moves the service row and tells a control panel for shared hosting; for VPS it deliberately does nothing at the hypervisor |

Gap 6 was recorded in Phase 29 as a deliberate refusal rather than an
oversight: dispatching a stop is indistinguishable at the hypervisor from the
customer stopping their own machine, and the customer could simply start it
again. This phase is where the policy that makes suspension mean something
gets designed.

## Starting inventory

| | Count |
| --- | --- |
| Backend tests | 1875 |
| Frontend unit tests | 45 |
| Browser tests | 40 |
| API operations described | 84 |
| Routes | 110 |
| Migrations | 28 |
| Scheduled commands | 9 |
| Registered provisioning handlers | 6 |
| Metric names | 17 |
| Audited act types | 11 |
| Domain events | 11 (4 with no listener, each documented) |
