# Phase 30A+ — baseline

Measured, not inherited. Every number below was produced by running the gate on
this working tree at the commit named, in this session, on 2026-09-08.

**HEAD:** `6c91d47` · **Branch:** `claude/hv-t6hq1p`

One commit exists after the `26ad2c9` the brief names: `6c91d47`, which added
`docs/phase-30a-final-product-closure.md` and changed no code. It is preserved.
No third party has pushed to this branch.

## What the gates actually said

| Gate | Command | Result |
| --- | --- | --- |
| Backend suite | `php artisan test` | **2 109 passed, 56 120 assertions**, 224.6 s |
| Architecture gates | `php artisan test --testsuite=Architecture` | 20 passed, 34 assertions |
| Queue + console runtime | `php artisan test tests/Feature/Queue tests/Feature/Console` | 41 passed, 185 assertions, 40.1 s |
| Code style | `vendor/bin/pint --test` | passed |
| Composer manifest | `composer validate --strict` | `./composer.json is valid` |
| Static analysis | `phpstan analyse -c tools/phpstan/phpstan.neon` | passed, **0 errors** |
| Typecheck | `npm run typecheck` | passed |
| Lint | `npm run lint` | passed |
| Frontend unit | `npm run test` | **45 passed** (7 files), 6.9 s |
| Production build | `npm run build` | built in 616 ms, 479.71 kB JS (138.31 kB gzip) |
| Browser E2E | `npx playwright test` | **61 passed**, 2.3 min |
| OpenAPI generation | `npm run openapi:generate -- --check` | up to date: **96 operations** |
| OpenAPI validation | `npm run openapi:lint` | valid, 1 warning |
| Migrations from empty | `migrate --force` on a fresh database | ran to the last migration |
| Migrations reversible | `migrate:rollback --force` | rolled back to the first |
| Migrations reapply | `migrate --force` again | ran to the last migration |
| Production guards | the two CI scripts, run locally | no fake provider outside the development template; no unresolved placeholders |
| Scheduler | `schedule:list` | **11 commands** registered |
| Metrics scrape | recorded by the suite | 25 metrics, 352 series, 20 queries, 36.4 ms |

The Phase 30A closing numbers were 2 109 / 56 120, 61 browser specs, 45 frontend
tests, PHPStan 0. **They reproduce exactly.** Nothing has drifted, and nothing
in the historical record needed correcting.

## The shape of the repository at this commit

| Measure | Count |
| --- | --- |
| Modules under `src/Modules` | 23 |
| Backend test files | 254 |
| Browser spec files | 6 |
| Scheduled commands | 11 |
| Documented API operations | 96 |
| Metric families | 25 |
| Audit actions | 24 |
| Domain events | 11 |

## Environment these numbers came from

| Component | Version |
| --- | --- |
| PHP | 8.4.19 |
| Node | 22.22.2 |
| PostgreSQL | 16.13 |
| Redis | 7.0.15 |
| Chromium | pinned Playwright build 1194 |

CI additionally runs the backend suite on PostgreSQL 18; that is not reproduced
locally, and the CI job is the evidence for it.

## What the eight gaps look like from inside the code

Read before writing anything, so the work starts from what exists rather than
from the list.

| # | Gap | What is already there | What is missing |
| --- | --- | --- | --- |
| 1 | Team membership | `customer_members` table with role, `invited_at`, `accepted_at`, `invited_by`; `CustomerRole` with four roles and a permission set each; `ResolveActingCustomer` enforcing tenancy once per request; `AuthorisesWithinAccount`; `User::roleWithin()` refusing an unaccepted membership | Nothing creates a membership. No invitation token, no accept, no role change, no ownership transfer, no screen. Roles lack a technical and a read-only tier. |
| 2 | Wallet spending | `WalletLedger` with row-locked debit, idempotency keys, immutable entries, balance re-derivation; `SettleInvoice` already documents the contract a wallet payment must meet — provider `wallet`, kind charge, status succeeded | No action debits a wallet against an invoice. `WalletLedger::debit` is in `NoDeadMethodsTest::RESERVED`. |
| 3 | Support tickets | `support.manage` permission on every customer role | Nothing else. No table, no model, no endpoint, no screen. |
| 4 | Backup retention | `retention_days`, `expires_at`, `last_polled_at`, `poll_count` on `backups`; `BackupProvider::deleteBackup()` and `listBackups()` implemented | No caller for either. No retention policy on plans, no deletion request state, no enforcement sweep, no customer path. |
| 5 | Forward DNS | `DnsProvider` with `zones()`, `findZone()`, `zoneFor()`, `canCreateZones()`, `createZone()`, `records()`, `publish()`, `delete()`; `DnsRecordType` already limited to A, AAAA, CNAME, MX, TXT, CAA | No zone or record model, no ownership policy, no endpoint, no screen. Five adapter methods are in `RESERVED` as "not a product". |
| 6 | Customer termination | `CancelCustomerSubscription` with both forms and an id-typed confirmation for the irreversible one; operator `DELETE /api/admin/services/{service}` behind a retention window; the dedicated two-act return-to-stock | No customer-facing termination request, and no path from a cancellation to the termination the retention window is supposed to gate. |
| 7 | Hosting reconciliation | `HostingProvider::listAccounts()`; a generic `resource_drifts` table keyed by provider and resource type; `RecordDrift`, `ReviewDrift`, `AlertOnCriticalDrift`, and an operator drift screen | No hosting reconciler. `listAccounts` has no caller. |
| 8 | Provider task polling | Task ids recorded on the rows that own them — `backups.provider_task_id`, `vm_reinstalls.provider_task_id`; `ReconcileBackup` already polls `taskState` on a five-minute schedule | Nothing polls a reinstall task. `ProxmoxComputeProvider::getTask` is in `RESERVED`. There is no shared notion of a task that is due. |

Two conclusions follow, and they shape the phase:

- **Six of the eight are half-built rather than unbuilt.** The missing half is
  almost always the product path, not the adapter — which is exactly the
  failure mode Phase 30A's dead-capability gate was built to surface.
- **No new tenancy, ledger, drift or task substrate is needed.** Each gap
  attaches to a mechanism that already exists and is already tested.
