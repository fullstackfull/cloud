# Phase 30A — final product closure

**Branch:** `claude/hv-t6hq1p` · **HEAD:** `26ad2c9` · **CI:** run 50,
[`34179461527`](https://github.com/fullstackfull/cloud/actions/runs/34179461527),
all eight jobs `success` on this exact commit.

**Status: all six Phase 30A product objectives are closed in software, and the
eleven supporting audits are complete. Nothing in this platform is
`REAL_INFRA_VERIFIED`, and this document never claims otherwise.**

Every provider this platform has ever spoken to is a fake, a recorded HTTP
exchange, or a controlled local socket. No Proxmox cluster, no BMC, no cPanel
or DirectAdmin licence, no payment gateway account and no Cloudflare token
exists here. That is the boundary of every claim below.

## The vocabulary this report uses

| Status | Meaning |
| --- | --- |
| `REAL_INFRA_VERIFIED` | Proven against real hardware or a real vendor. **Nothing holds this classification.** |
| `RUNTIME_VERIFIED` | Proven end to end against a running system — a real Redis worker in its own process, a browser, or both. |
| `TESTED` | Proven against the adapter and a fake provider. Correct by construction; no real vendor has answered. |
| `CODE_COMPLETE` | Written and reachable, with tests below the level that would prove the whole path. |
| `BLOCKED_CREDENTIALS` / `BLOCKED_HARDWARE` / `BLOCKED_LICENSE` / `BLOCKED_NETWORK` | Implemented and unprovable here: no token, no machine, no licence, no route. |
| `NOT_IMPLEMENTED` | The customer cannot do this. Stated here rather than discovered. |

No other word is used to describe state anywhere in this document.

---

## Correction to `docs/phase-30a-progress.md`

That report's header said:

> *three of the six product gaps are closed in software. Three are not started*

The second half contradicted its own table, and it is corrected here. The
table classified 30A.2, 30A.3 and 30A.4 as `NOT_IMPLEMENTED` — which the same
document defines as *"the customer cannot do this"* — and the evidence column
showed that two of the three had substantial backend work already in place:
`RedeemConsoleSession` existed and was the sole entry in the dead-capability
allowlist, and `ChangeSubscriptionPlan` existed with no customer surface
reaching it. "Not started" was an editorial summary, not a classification, and
it understated the code that existed while overstating how little remained.

The correct reading of that table at `b044471` was: **three objectives closed
in software, three `NOT_IMPLEMENTED` at the customer surface, two of those
three with a working backend behind an unbuilt front.** The classification
vocabulary carries the meaning; prose summaries that reach past it are how a
report starts drifting from its own evidence.

That is the only correction. Nothing else in the progress report is withdrawn.

---

## A. Actual continuation baseline

The continuation began at `aecec9b`, measured in `docs/phase-30a-continuation-baseline.md`
rather than inherited. The brief named `b044471`; two commits existed after it,
both written in this work — `b56e352` wrote the progress report and `aecec9b`
corrected a miscount in its header. No third party had pushed to the branch.

Nineteen commits carry the continuation, from `b0bace5` to `26ad2c9`:

| Commit | What it closed |
| --- | --- |
| `b0bace5` | Recorded the continuation baseline by measuring it |
| `5632528` | 30A.2 — dedicated reinstall |
| `df40a71` | 30A.3 — console gateway |
| `04d0367` | 30A.4 — change-plan customer UI |
| `84f50aa` | 30A.8 — method-level dead-capability hunt |
| `eb34b32` | 30A.8 — domain-event audit, gated both ways |
| `5774559` | 30A.14 — real-worker chains |
| `e823acf`, `7520780` | 30A.10 — operator surfaces |
| `0d59c66` | 30A.11 — audit coverage |
| `21a8618` | 30A.12 — observability |
| `a4ce937` | 30A.13 — browser E2E for the failure states |
| `2aeab25`, `957f292` | 30A.9 — whole-life business flows |
| `57ee18c` | 30A.8 — provider-adapter dead methods |
| `8c4d5d0` | 30A.7 — capability matrix |
| `57f24e9` | 30A.17 — adversarial review |
| `26ad2c9` | 30A.16 — make the build fail where a laptop may skip |

## B. Existing work preserved

Nothing from Phase 30A's first half or from Phase 29 was rebuilt, reverted or
regressed. The evidence is that the suite only ever grew: the continuation
baseline measured the tree at `aecec9b`, and every commit since ran the whole
backend suite, the frontend suite, the browser suite and the architecture
gates before it was pushed. At `26ad2c9`:

- **2 109 backend tests, 56 120 assertions** — all passing.
- **61 browser specs** — all passing.
- **45 frontend unit tests** — all passing.
- **254 backend test files**, **6 browser spec files**.

The gates that would have caught a regression in the older work are the same
gates that were already there — `LayeringTest`, `BuildEntryPointsTest`,
`HandlerCoverageTest`, `NoDeadCapabilitiesTest`, `OpenApiSpecificationTest` —
and they were extended, never relaxed. `HandlerCoverageTest::documentedGaps()`
is now empty: every job kind the engine can queue has a handler.

## C. Dedicated reinstall — `TESTED` + `BLOCKED_HARDWARE`

Commit `5632528`.

`POST /api/v1/dedicated/{server}/reinstall` → `RequestDedicatedReinstall` →
`reinstall_dedicated` → `ReinstallDedicatedHandler`, over a fourteen-state
machine (`DedicatedReinstallState`): `requested`, `queued`, `validating`,
`bmc_configuring`, `pxe_booting`, `installing`, `configuring`, `verifying`,
`completed`, and the five ways it can end badly — `failed`,
`hardware_unavailable`, `provisioning_timeout`, `indeterminate`,
`needs_review`.

What matters about a physical rebuild is that the platform knows **whether the
disks went**. `destructive_started_at` is stamped before the boot override
that will wipe them, and a handler that finds it already set on a fresh
attempt refuses to start a second one — it records
`dedicated.reinstall_interrupted` at `Indeterminate` and leaves the machine to
an operator. A worker that dies between the wipe and the outcome therefore
cannot be redelivered into a second wipe.

The customer confirms by typing the server's serial (`confirm_serial`), not by
pressing a button.

**Blocked:** no BMC has ever answered. The IPMI and Redfish adapters are
tested against recorded exchanges; PXE, the installer and its callback have
never run on metal. `BLOCKED_HARDWARE`, and this is the item Phase 30B exists
to close.

## D. Console gateway — `RUNTIME_VERIFIED` + `BLOCKED_CREDENTIALS`

Commit `df40a71`, hardened in `57f24e9`.

`GET /api/v1/vps/{vm}/console` issues a single-use permit;
`console-gateway:serve` is a standalone process that redeems it and proxies
bytes to the hypervisor's console endpoint. It needs no HTTP server, no queue
and no scheduler, which is what makes it deployable beside a hypervisor.

Twenty-five tests across three files prove the properties that matter, against
a real WebSocket on a real socket with a controlled upstream:

- a permit works **exactly once**, and is gone from Redis the moment it is spent;
- two simultaneous redemptions — one succeeds, exactly one;
- an expired permit, a wrong token, a permit for another machine, a permit for
  a machine that has gone, and a permit whose service was suspended after
  issue are each refused;
- a wrong token does **not** burn the permit;
- the upstream receives the hypervisor credentials and **the browser never does**;
- a page on another origin is refused before the permit is spent, and *a
  prefix of the portal's origin is not the portal*;
- a peer that hammers the gateway is stopped;
- every refusal says the same thing to the caller, and the stored record
  never contains the token itself;
- the gateway counts what it did **without naming anybody**.

The gateway boots and stops cleanly outside the test harness: verified in the
clean room (§S).

**Blocked:** no Proxmox cluster has ever supplied a real console endpoint.
`BLOCKED_CREDENTIALS` for the hypervisor leg only; the gateway itself is
`RUNTIME_VERIFIED`.

## E. Change-plan customer UI — `RUNTIME_VERIFIED`

Commit `04d0367`.

`/subscriptions/:id/plan` prices **every** plan the customer could move to
before they choose one — `GET /subscriptions/{id}/plan-options` runs
`QuotePlanChange` per candidate and returns proration, the new price and, for
the options that are refused, why. The refusals arrive as
`error.details.refusals` and the screen shows them rather than hiding the
option, so a customer learns that a smaller disk is impossible instead of
finding a disabled button with no explanation.

`POST /subscriptions/{id}/plan` → `ApplyPlanChange` moves the money and queues
the infrastructure half: `resize` for a VPS, `change_hosting_package` for a
hosting account. Browser specs cover the priced list, the refusal of a
disk-shrink, and both locales.

## F. Notifications — `RUNTIME_VERIFIED`

Inbox, per-category preferences, both locales, deep links, and the refusal to
switch off the messages a customer must receive. Proven in a browser
(`portal.e2e.ts`), and end to end through a real worker in its own process:
`a_finished_build_reaches_the_customer_through_a_second_worker`.

Hardened in `57f24e9`: every customer-chosen value is flattened to one line
before it reaches a rendered subject or body, at the point of rendering rather
than at each place that reads one. A hostname containing a newline is a mail
header a customer chose; it is now impossible to write one.

**Blocked:** no mail leaves this environment. SMTP delivery is `TESTED`.

## G. Suspension — `TESTED` + `BLOCKED_CREDENTIALS`, with the runtime chain `RUNTIME_VERIFIED`

`compute.suspension_policy` takes one of three named values and refuses an
unrecognised one at boot. Suspension stops the guest, clears `onboot` and sets
the config lock **last**; reactivation is deliberately asymmetric — the
service reaches `active` only when the provider confirms, and otherwise falls
back to `suspended` and tells the customer.

`NoCustomerPathBypassesSuspensionTest` proves the guard holds across power,
console, resize, reinstall and plan change. `a_payment_unlocks_the_machine_before_the_service_is_called_active` proves the reactivation chain through a
real worker. Reconciliation records `suspension_mismatch` at critical severity
in both directions.

**Blocked:** no hypervisor has ever locked a real VM. `BLOCKED_CREDENTIALS`.

## H. VPS reinstall — `RUNTIME_VERIFIED` + `BLOCKED_CREDENTIALS`

Ten-state machine, hostname confirmation, guard against a rebuild while one is
running or while the service is suspended, and — the property that makes it
safe — a handler that refuses to start again when `destroyedData()` is already
true. `a_reinstall_travels_from_the_customers_confirmation_to_the_hypervisor`
and `a_reinstall_whose_worker_died_is_not_started_again_by_the_next_one` both
run against a real Redis worker in a separate process.

**Blocked:** `reinstallVm` has never reached a real cluster.

## I. Capability matrix

`docs/customer-capability-matrix.md`, rewritten in full at `8c4d5d0`. Nine
columns — UI, API, Action, Queue, Handler, Provider, E2E, State, Real provider
— across eight capability areas and every customer-facing action the platform
has: account and access, buying, subscriptions and plan change, VPS, backups,
dedicated, shared hosting, addresses and DNS, notifications and support.

It answers one question per row: *if a customer does this, does the thing they
wanted actually happen?* Writing it found a live bug — the hosting screen
declared the package a string while the API sends an object, so any account
with a package rendered an object as a React child and took the whole table
down. Nothing had caught it because the browser fixtures had no hosting
account and the seeded catalogue had no packages; the two gaps hid each other.

The document ends with **what a customer still cannot do** (six items, §U) and
**what no test in this repository proves** (five items, §V), so a reader who
skims the green rows still meets the boundary.

## J. Method-level dead-capability audit

Commits `84f50aa` and `57ee18c`. `NoDeadMethodsTest` scans Actions,
Application and Domain services, Handlers, Listeners, Commands, Jobs, state
transitions **and provider adapters** — the last was the addition that
mattered, because the class-level gate had found nothing while the product
still had a hole in it. The first provider-adapter run found **49 methods with
no production caller**.

Each is now classified as HTTP, EVENT, COMMAND, SCHEDULE, QUEUE, PROVIDER
CALLBACK, INTERNAL, or listed in `RESERVED` with the missing half of the
mechanism named. Nothing was left DEAD. What was wired rather than excused:

- `changePackage` — implemented in both panel adapters, called by nothing. An
  upgraded hosting customer kept their old quota for ever.
- Node licence and health columns — read by the scheduler's placement
  weighting, written by nobody since the seeder. A licence that lapsed in
  March was still valid in December. `SyncHostingNodeHealth` now asks each
  node, treats silence as unlicensed rather than as fine, and never writes the
  account count.
- `IpAllocator::releaseAssignment` — no caller anywhere, so a terminated
  customer's address was held for ever.

Twenty entries remain in `RESERVED`, each naming the product gap rather
than the method: forward DNS zones are not a product; there is no hosting
reconciler, so `listAccounts` has no caller; provider tasks are recorded for
an operator and never polled; the customer API deliberately offers no hard
reset and sends an ACPI shutdown rather than cutting power; backup retention
and verification at the provider are not managed. **No future provider
contract was deleted because the hardware is unavailable.**

The excuse list goes stale in both directions:
`no_reserved_method_has_quietly_acquired_a_caller` fails the build when a
reserved method is wired, the same way the main gate fails when a live one
dies.

## K. Domain-event audit

Commit `eb34b32`. Eleven domain events, each with a consumer and each gated:

| Event | Consumed by |
| --- | --- |
| `Orders\OrderPlaced` | order pricing and invoice issue |
| `Billing\InvoicePaid` | `SettleOrderOnInvoicePaid` |
| `Billing\OrderFinanciallySettled` | `ProvisionOrderedService` |
| `Payments\PaymentCaptured` | invoice settlement, dispatched only after the capture commits |
| `Payments\PaymentFailed` | dunning |
| `Payments\RefundIssued` | invoice and audit |
| `Provisioning\ProvisioningJobSucceeded` | `NotifyOnProvisioningOutcome`, service activation |
| `Provisioning\ProvisioningJobFailed` | `NotifyOnProvisioningOutcome` |
| `Provisioning\ProvisioningJobNeedsReview` | operator surfacing and notification |
| `Provisioning\DriftRecorded` | drift queue listener (this was the gap `eb34b32` closed) |
| `Subscriptions\SubscriptionStatusChanged` | `EnforceServiceStateForSubscription`, `NotifyOnSubscriptionChange` |

`EveryDomainEventIsConsumedTest` gates it both ways: an event with no listener
fails, and a listener bound to no event fails.

## L. Full business flow results

Three tests follow one service each through its whole life, where every step
starts from whatever the previous step actually left behind. They are in
`tests/Feature/EndToEnd/`, and between them they found four defects no
single-step test could see.

**VPS** — `TheWholeLifeOfAVpsTest`, 39 assertions. Order → settlement →
service → provisioning → ready notification → power → plan change → resize →
suspend → payment → reactivate → backup → restore → reinstall → console
permit → terminate. Found: a VPS could not be terminated at all —
`destroy_vps` was a job kind with no handler, `ReleaseReason::service_terminated`
had no producer, and the address, the machine and the node's capacity were
held for ever.

**Shared hosting** — `TheWholeLifeOfAHostingAccountTest`. Order → account →
usage sync → plan change → failed payment → dunning → suspend → payment →
unsuspend → terminate. Found two: a hosting order queued a job naming no
package, so every hosting order in every deployment failed at the worker; and
a hosting plan change moved the money and left the quota alone, because the
comparison that decides whether a plan change touches a provider understood
only vCPU, memory and disk.

**Dedicated** — `TheWholeLifeOfADedicatedServerTest`. Order → reserve →
provision → ready → power → reinstall operation → cancellation → return to
inventory. Found: the lifecycle had no end. The state machine described the
edges and nothing could travel them, so a cancelled customer's server stayed
attached to them for ever.

That last one is deliberately two acts. A VPS termination destroys the machine
in software; a physical server cannot be finished that way, because the disks
hold the customer's data until somebody erases them and no call this platform
can make proves that happened. Ending the service takes the machine off the
customer and holds it in maintenance; a second, deliberate operator act
records a person's word that the disks are empty before it is offered to
anybody else. Collapsing the two would sell the next customer the last one's
data, every time.

**The physical reinstall inside the dedicated flow is `BLOCKED_HARDWARE`.**
The flow proves the operation is requested, recorded, guarded and settled; it
does not prove a disk was written, and this report does not say it does.

## M. Operator / NOC closure

Commits `e823acf`, `7520780`. An operator no longer needs SQL for any normal
failure state. Four screens, and a rule about what is on them.

- **Provisioning** — a failed job can be retried. `RetryProvisioningJob` locks
  the row and refuses three cases outright: a job that is not settled
  (`provisioning.retry_not_settled`), one whose resource was already built
  (`provisioning.retry_would_duplicate`), and one that has already destroyed
  data (`provisioning.retry_destroys_again`). It does not raise `max_attempts`.
- **Reinstalls** — one queue merging VPS and dedicated operations, with the
  operation, its job attempt log and its audit trail on one page. Resolution
  takes a verdict (`completed` or `failed`) **and required written evidence**,
  settles the job and notifies the customer.
- **Drift** — the reconciler's findings, reviewable, with a per-cluster
  re-run.
- **Services** — termination behind the retention window, and return-to-stock
  as the separate second act described above.

**There is no "Force Success" anywhere in this platform, and no screen edits
provider state.** Every operator action is one of: retry a safe operation,
re-run reconciliation, acknowledge, resolve with evidence, or read history.
An operator who wants to say a rebuild succeeded must say what they saw.

## N. Audit coverage

Commit `0d59c66`. Twenty-four audit actions, and
`EveryAuditActionIsRecordedSomewhereTest` holds an **empty** unwritten list —
every word in the vocabulary has a writer.

The harder guarantee is that the record cannot be lost after the act. `RecordActAtomically`
runs the act and its audit entry inside one transaction, so a failed write
rolls the act back rather than leaving it unrecorded:

```php
public function execute(callable $act, callable $describe): mixed
{
    return DB::transaction(function () use ($act, $describe) {
        $result = $act();
        $entry = $describe($result);
        $this->record->execute($entry->action, $entry->subject, $entry->customerId, $entry->context);
        return $result;
    });
}
```

Applied to customer suspend/unsuspend, provisioning adopt and retry, reinstall
resolution, invoice voiding, drift review, subscription cancellation and
notification-preference changes. `AnActMustNotOutliveItsRecordTest` renames
the audit table out from under a committed sensitive operation and proves the
operation does not survive: **an audit failure cannot silently follow a
committed sensitive administrative operation.**

Two call sites are non-atomic by design and say so: a refund and a hosting
termination both reach an external system first, and a rolled-back local
transaction would not un-refund the money or un-delete the account. Their
audit entries are written immediately after, and the ordering is the honest
one.

Append-only guarantees are unchanged: no update or delete path exists on
`audit_log`.

## O. Observability

Commit `21a8618`. Twenty-five metric families, all bounded, documented
in `docs/monitoring.md` — twenty rows, the three scheduled-command series
sharing a wildcard row — with a section stating what is never a label.

`MetricsCarryNoIdentifiersTest` renders the whole exposition and fails on:
`customer_id`, `service_id`, `hostname`, `email`, `provider_resource_id` and
any IP, both by label name and by **shape** — a value that looks like a ULID,
an email, an IP or a hostname fails even under an innocent label name. Total
series are capped at 1 000. `MetricsQueryBudgetTest` caps the whole scrape at
26 queries.

New in this phase: `lynomia_service_status_total{kind,status}` and
`lynomia_plan_change_total{status}` — the two things an operator wanted during
this work and had to count by hand.

## P. Queue / runtime verification

Commit `5774559`. Thirteen tests run a **real worker in its own operating
system process**, against real Redis, against a database whose rows must have
been committed for the worker to see them. The harness (`WorkerHarness`) makes
that explicit: `outsideTheTransaction()` writes rows on a separate connection,
because a fixture inside the test's transaction is invisible to another
process, and a test that did not notice would prove nothing.

- `a_worker_in_another_process_builds_the_machine`
- `a_worker_killed_mid_build_does_not_produce_a_second_machine`
- `a_second_delivery_of_the_same_message_builds_nothing_more`
- `a_provider_refusal_is_recorded_and_rescheduled_rather_than_lost`
- `the_scheduler_dispatches_work_that_a_worker_then_does`
- `a_finished_build_reaches_the_customer_through_a_second_worker`
- `a_reinstall_travels_from_the_customers_confirmation_to_the_hypervisor`
- `a_payment_unlocks_the_machine_before_the_service_is_called_active`
- `a_reinstall_whose_worker_died_is_not_started_again_by_the_next_one`
- `every_queue_the_platform_uses_has_a_supervisor`
- `provisioning_is_never_retried_by_the_queue`
- `no_supervisor_kills_a_worker_faster_than_a_provider_call_can_finish`
- `horizon_is_namespaced_per_environment`

The Timeout Rule holds through all of it: an indeterminate provider answer
quarantines the job for review and is never retried automatically.

## Q. Browser E2E

Commits `a4ce937`, `8c4d5d0`. **61 specs, all passing**, across six files —
`auth`, `account`, `portal`, `admin`, `operations`, `appearance`.

Covered in this phase: the reinstall dialogue for both VPS (hostname typed)
and dedicated (serial typed), including Escape closing it without rebuilding
anything; the console page asking for a permit and saying honestly when
consoles are unavailable; the change-plan screen pricing every option and
being refused a smaller disk by the backend; notifications; a suspended
service and a reactivating one; and the operator failure surfaces — the
reinstall queue, an indeterminate operation, a needs-review operation, an open
critical drift, and the evidence-required resolution dialogue.

`appearance.e2e.ts` runs the reinstall and plan-change paths in **Arabic and
RTL** as well as English and LTR.

The fixtures behind them are real states, not decoration: `E2ESeeder` seeds a
suspended service, a reactivating service, an indeterminate VPS reinstall with
`destroyed_at` set, a needs-review dedicated reinstall, and an open critical
`suspension_mismatch` drift — *work nobody can settle*, which is exactly what
the operator screens exist for.

## R. Security / adversarial review

Commit `57f24e9`. Every attack in the brief was attempted. Four findings,
three of them real and fixed here; the rest were already covered and are named
with what covers them.

**Found and fixed**

1. **A price from another plan bought that plan cheaply.** Plan and price both
   arrive from the client and were validated only for existence. A request
   naming the largest plan with the smallest plan's price passed every other
   rule — same product, same currency, same period — and moved the
   subscription onto the large plan at the small price, for ever. The
   catalogue is the authority: a price belonging to another plan is now
   `PlanChangeRefusal::PriceNotForPlan`.
2. **A suspended customer could be sold an upgrade.** The money moves when a
   plan change is confirmed; the machine catches up after. On a suspended
   service that charged for an upgrade the platform had deliberately locked
   the machine against, and the resize then failed at the hypervisor for the
   reason the suspension exists. Now `PlanChangeRefusal::ServiceNotActive`,
   and the options screen agrees with the endpoint.
3. **A hostname could smuggle a mail header.** §F.
4. **The console gateway accepted any browser origin.** A WebSocket is not
   subject to the same-origin policy. The permit is what authenticates and a
   hostile page cannot read one, so this was defence in depth rather than a
   hole — but a gateway any page can reach is a gateway any page can spend the
   rate limit of. Origins are checked whole and case-insensitively, never by
   prefix; a request with no Origin is still allowed, because native clients
   send none and an attacker is not constrained by a browser either.

**Attempted and already covered**

| Attack | What refuses it |
| --- | --- |
| Console permit replay | `a_permit_works_exactly_once`, `a_permit_is_gone_from_redis_the_moment_it_is_spent` |
| Cross-tenant permit / wrong VPS | `a_permit_for_one_machine_does_not_open_another`, `a_permit_for_another_machine_is_refused_at_the_socket` |
| Expired permit | `an_expired_permit_is_refused` |
| Token leakage | `the_upstream_receives_the_credentials_and_the_browser_never_does`, `the_stored_record_never_contains_the_token_itself` |
| Gateway rate-limit bypass | `a_peer_that_hammers_the_gateway_is_stopped`; a wrong token does not burn the permit |
| Provider credential leakage | `every_refusal_says_the_same_thing_to_the_caller` |
| Reinstall confirmation bypass | hostname / serial required; endpoint tests for both |
| Reinstall cross-tenant | `VpsReinstallEndpointTest`, dedicated equivalent |
| Duplicate destructive request | in-flight guard, plus the `destroyedData()` refusal |
| Worker redelivery of a destructive job | `a_reinstall_whose_worker_died_is_not_started_again_by_the_next_one` |
| Provider timeout | the Timeout Rule — quarantine, never auto-retry |
| CSRF | Sanctum stateful sessions; `SecurityHeadersTest`, `WildcardCorsOriginIsRejectedTest` |
| Client-altered quoted price or proration | the quote is recomputed server-side; the client's numbers are never trusted |
| Plan-family bypass | `DifferentProduct` refusal |
| Disk-shrink bypass | refused in `QuotePlanChange`, proven in the browser |
| Double submission | idempotency keys |
| Notification cross-tenant deep links and enumeration | `NotificationsCannotBeTurnedAgainstACustomerTest` |
| Sensitive provider errors reaching a customer | the same file |
| Suspension bypass via power, console, resize, reinstall or plan change | `NoCustomerPathBypassesSuspensionTest` |

Twenty-six security test files in total.

## S. Clean-room result

Run at `26ad2c9` in `/tmp/cleanroom`, from `git clone --branch claude/hv-t6hq1p`
of the public repository — no reuse of the working copy, no existing database,
no existing Redis data, no untracked env file, no manual keys, no hidden
fixtures. Documented environment variables and documented commands only.

| Step | Command | Result |
| --- | --- | --- |
| PHP dependencies | `composer install` | OK |
| Node dependencies | `npm ci` | OK |
| Databases | fresh `lynomia_cleanroom`, `_test`, `_e2e` | created empty |
| Environment | `.env` / `.env.testing` from the committed templates, `php artisan key:generate` | OK |
| Migrations | `php artisan migrate --force` | OK |
| Seed | `php artisan db:seed --force` | 2 nodes, 61 allocatable addresses, 1 hosting node, 5 dedicated servers |
| Backend suite | `php artisan test` | **2 109 passed, 56 120 assertions** |
| Typecheck | `npm run typecheck` | OK |
| Lint | `npm run lint` | OK |
| Frontend unit | `npm run test` | 45 passed |
| Production build | `npm run build` | OK |
| OpenAPI generation | `npm run openapi:generate -- --check` | up to date: 96 operations |
| OpenAPI validation | `npm run openapi:lint` | valid, 1 warning |
| Production guards | the CI script, replicated | 161 templates scanned; no fake provider outside the development template; no unresolved placeholders |
| Queue worker | `php artisan queue:work redis --queue=provisioning --stop-when-empty` | started, drained, exited 0 |
| Scheduler | `php artisan schedule:list` | 11 entries registered, each with a next-due time |
| Console gateway | `php artisan console-gateway:serve --port=8391` | listening in 2s; `Console gateway stopped.` on SIGTERM |
| Browser suite | `npx playwright test` against a fresh `lynomia_cleanroom_e2e` | **61 passed** (2.3m) |

**One documented failure, and it is environmental.** `tools/phpstan`'s own
`composer install` fails inside the clean room with *"Could not authenticate
against github.com"* — the outbound proxy in this environment refuses codeload
dist downloads without a token. It was not worked around. PHPStan therefore
did not run in the clean room; it passes in the working copy and in every CI
run, including run 50's **Static analysis** job on this exact commit. The gap
is in the network, not in the repository: a clean room with unrestricted
outbound access installs the toolchain from the committed `composer.json` like
any other dependency.

Everything else a documented install needs, a documented install got.

## T. GitHub Actions result

Pushed and observed, not assumed.

**Run 50 — [`34179461527`](https://github.com/fullstackfull/cloud/actions/runs/34179461527)**
· commit `26ad2c9` · event `push` · branch `claude/hv-t6hq1p` · started
2026-09-08T02:16:01Z, finished 02:21:20Z · **conclusion: `success`**.

| Job | Conclusion | Covers |
| --- | --- | --- |
| Backend (PHP 8.4, **PostgreSQL 16**) | `success` | Pint, migrations from empty, migrations reversible, the full suite — including the queue integration, console gateway, dead-capability and capability-wiring gates |
| Backend (PHP 8.4, **PostgreSQL 18**) | `success` | the same, on the other supported major |
| Static analysis | `success` | PHPStan |
| Frontend | `success` | typecheck, lint, unit tests, production build |
| API description | `success` | `docs/openapi.yaml` validated |
| Security checks | `success` | PHP and JavaScript dependency audits; committed-secret gate |
| Production guards | `success` | no fake provider outside the development template; no unresolved placeholders |
| Browser end-to-end | `success` | Playwright, 61 specs, against PostgreSQL and Redis services |

Every gate the brief asked CI to carry is carried, and the queue and console
proofs no longer skip themselves there: `26ad2c9` makes them **skip on a
laptop with no Redis and fail in CI**, where Redis is a declared service and
its absence is a broken runner rather than a missing dependency. They are the
only tests that prove work leaves the queue and that a permit cannot be spent
twice; a suite that quietly skipped them would report green for a platform
whose provisioning does not run.

Preceding runs on this branch, for the record: 46 `34177672936` `success`,
47 `34178150534` `success`, 48 `34178730512` `success`, 49 `34179328487`
`cancelled` — superseded by run 50's push before it finished.

## U. Remaining product gaps

Stated plainly, because a report of mostly-closed items is easy to skim past.
None of these is a Phase 30A objective; all are `NOT_IMPLEMENTED` and named
here rather than left to be discovered.

1. **Team membership.** A customer is one login. Memberships exist in the
   schema; nothing creates one.
2. **Spending wallet credit.** It can be received — an overpayment lands there
   — and no path spends it. `WalletLedger::debit` is in `RESERVED`.
3. **Support tickets.** The role model anticipates them; nothing else does.
4. **Backup deletion and provider-side retention.** The adapter can;
   nothing calls it.
5. **Forward DNS as a product.** The reverse-DNS half exists.
6. **Customer-initiated termination.** A customer cancels the subscription;
   termination is an operator act behind a retention window.
7. **A hosting reconciler.** Panel-side drift is not detected;
   `listAccounts` has no caller.
8. **Provider task polling.** Tasks are recorded for an operator and never
   polled.

## V. External infrastructure blockers

| Blocker | Classification | What it stops |
| --- | --- | --- |
| No Proxmox cluster | `BLOCKED_CREDENTIALS` | create, resize, reinstall, suspend, destroy, console endpoint, backups |
| No physical server, no BMC | `BLOCKED_HARDWARE` | dedicated power, PXE, the physical reinstall, hardware inventory |
| No cPanel or DirectAdmin licence | `BLOCKED_LICENSE` | account creation, usage, SSO, suspension, repackaging, node health |
| No payment gateway account | `BLOCKED_CREDENTIALS` | real capture, refund and settlement |
| No Cloudflare token | `BLOCKED_CREDENTIALS` | publishing a PTR record |
| No outbound SMTP | `BLOCKED_CREDENTIALS` | delivery of any message; rendering and queueing are proven |
| Proxy refuses codeload dist downloads | `BLOCKED_NETWORK` | installing `tools/phpstan` inside the clean room only |

## W. Phase 30A verdict

```text
Dedicated Reinstall          COMPLETE IN SOFTWARE   (TESTED + BLOCKED_HARDWARE)
Console Gateway              COMPLETE               (RUNTIME_VERIFIED)
Change Plan Customer UI      COMPLETE               (RUNTIME_VERIFIED)

Notifications                VERIFIED               (RUNTIME_VERIFIED)
Compute Suspension           VERIFIED IN SOFTWARE   (TESTED + BLOCKED_CREDENTIALS)
VPS Reinstall                VERIFIED IN SOFTWARE   (RUNTIME_VERIFIED + BLOCKED_CREDENTIALS)

Capability Matrix            COMPLETE
Dead Capability Audit        COMPLETE
Domain Event Audit           COMPLETE
Business Flow Audit          COMPLETE
Operator Recovery            COMPLETE
Audit Coverage               COMPLETE
Observability                COMPLETE
Browser E2E                  COMPLETE
Queue Verification           COMPLETE
Security Review              COMPLETE
Clean Room                   COMPLETE (one documented environmental failure, §S)
CI                           GREEN (run 50, 34179461527)
```

No Phase 30A objective is `NOT_IMPLEMENTED`. **Nothing is
`REAL_INFRA_VERIFIED`**, and the only thing that can change that is Phase 30B
against real infrastructure.

---

## Required final table

Evidence, not optimism. "Real Infra" is `No` on every row of this platform,
and will be until a real vendor answers.

| Capability | UI | API | Action | Queue | Handler | Provider | E2E | Real Infra |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| VPS Create | — (ordered) | `POST /orders` | `ProvisionOrderedService` | `create_vps` | `CreateVpsHandler` | `createVirtualMachine` | `portal.e2e.ts` (the resulting machine) | No — `BLOCKED_CREDENTIALS` |
| VPS Power | `/vps` | `POST /vps/{vm}/power` | `RequestVpsPowerChange` | `start`/`stop`/`restart` | `StartVpsHandler`, `StopVpsHandler`, `RestartVpsHandler` | `startVm`, `stopVm`, `shutdownVm`, `rebootVm` | `portal.e2e.ts` | No — `BLOCKED_CREDENTIALS` |
| VPS Resize | `/subscriptions/:id/plan` | `POST /subscriptions/{id}/plan` | `ApplyPlanChange` | `resize` | `ResizeVpsHandler` | `resizeVm` | `portal.e2e.ts` | No — `BLOCKED_CREDENTIALS` |
| VPS Suspend | — (billing-driven) | — | `EnforceServiceStateForSubscription` | `provisioning` | suspension listener | `suspendVm` / `liftSuspension` | `operations.e2e.ts` (the customer's view) | No — `BLOCKED_CREDENTIALS` |
| VPS Reinstall | `/vps` | `POST /vps/{vm}/reinstall` | `RequestVpsReinstall` | `reinstall_vps` | `ReinstallVpsHandler` | `reinstallVm` | `portal.e2e.ts`, `appearance.e2e.ts` (AR) | No — `BLOCKED_CREDENTIALS` |
| VPS Console | `/vps/:id/console` | `GET /vps/{vm}/console` | `IssueConsoleSession` → `AuthoriseConsoleConnection` | sync + the gateway process | `GatewayServer` | `consoleEndpoint` | `portal.e2e.ts` | No — `BLOCKED_CREDENTIALS` |
| VPS Backup | `/backups` | `POST /vps/{vm}/backups` | `RequestServiceBackup` | sync + `ReconcileRunningBackups` | — | `startBackup` | — | No — `BLOCKED_CREDENTIALS` (no backup server) |
| VPS Restore | `/backups` | `POST /vps/{vm}/backups/{backup}/restore` | `RestoreServiceBackup` | sync | — | `startRestore` | `portal.e2e.ts` (3 specs) | No — `BLOCKED_CREDENTIALS` |
| Dedicated Provision | — (ordered) | `POST /orders` | `ProvisionOrderedService` | `provision_dedicated` | `ProvisionDedicatedHandler` | reservation + BMC | — | No — `BLOCKED_HARDWARE` |
| Dedicated Power | `/dedicated` | `POST /dedicated/{server}/power` | `ChangeDedicatedServerPower` | sync | — | `powerOn`, `gracefulShutdown`, `reset` | — | No — `BLOCKED_HARDWARE` |
| Dedicated Reinstall | `/dedicated` | `POST /dedicated/{server}/reinstall` | `RequestDedicatedReinstall` | `reinstall_dedicated` | `ReinstallDedicatedHandler` | BMC boot override, PXE, installer callback | `portal.e2e.ts`, `appearance.e2e.ts` (AR) | No — `BLOCKED_HARDWARE` |
| Shared Hosting Provision | — (ordered) | `POST /orders` | `ProvisionOrderedService` | `create_hosting_account` | `CreateHostingAccountHandler` | `createAccount` | — | No — `BLOCKED_LICENSE` |
| Hosting Suspend | — (billing-driven) | — | `SuspendHostingAccount` | `provisioning` | suspension listener | `suspendAccount` | — | No — `BLOCKED_LICENSE` |
| Hosting Unsuspend | — (billing-driven) | — | `UnsuspendHostingAccount` | `provisioning` | suspension listener | `unsuspendAccount` | — | No — `BLOCKED_LICENSE` |
| Plan Change | `/subscriptions/:id/plan` | `GET`/`POST /subscriptions/{id}/plan` | `QuotePlanChange`, `ApplyPlanChange` | `resize` / `change_hosting_package` | `ResizeVpsHandler`, `ChangeHostingPackageHandler` | `resizeVm`, `changePackage` | `portal.e2e.ts`, `appearance.e2e.ts` (AR) | No — `BLOCKED_CREDENTIALS` / `BLOCKED_LICENSE` |
| Notifications | `/notifications` | `GET /notifications`, `PUT /me/notification-preferences` | `NotifyOnProvisioningOutcome`, `NotifyOnBillingEvent`, `NotifyOnSubscriptionChange` | `notifications` | `DeliverNotification` | SMTP | `portal.e2e.ts`, `appearance.e2e.ts` (AR) | No — no mail leaves this environment |

Per-capability state, with the test that proves each link, is in
`docs/customer-capability-matrix.md`. Where a row above shows no E2E, no
browser spec drives it and the row says so rather than borrowing a neighbour's
evidence.
