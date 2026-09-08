# Phase 29 — Final Software Closure

The last software phase before real infrastructure. Its objective was to close
every remaining software gap, prove that every internal workflow actually
runs, and make the repository ready for real-infrastructure integration.

Its actual finding is narrower and worse than the five gaps it set out to
close. **The suite was green and the platform did not work.** Of everything
Lynomia Cloud sells and every button its portal offers, exactly one path —
creating a VPS — could reach a worker that would do it.

- **Branch:** `claude/hv-t6hq1p` · 16 commits
- **Date:** 2026-09-07
- **Baseline:** `docs/phase-29-baseline.md`
- **Backend suite:** 1875 tests, 42,000+ assertions
- **Frontend:** 45 unit tests, 40 browser tests
- **CI:** run #21 on `claude/hv-t6hq1p`, all eight jobs green — PHP 8.4 against
  PostgreSQL 16 **and** 18, browser end-to-end, PHPStan level 6, Pint, security
  audit, OpenAPI validation, production guards.

---

## A. The answer to the phase's own question

> *Is there any feature in Lynomia Cloud that appears available to the customer
> or operator but cannot actually complete its intended backend lifecycle?*

**At the start of this phase: yes, twenty-one of them** — five provisioning
handlers, thirteen actions and two methods, each written and unreachable. They
are listed in section C, and every one was covered by passing tests.

**Now: five, and each is stated rather than hidden.**

| Appears available | Cannot complete because |
| --- | --- |
| Reinstall a VPS | No handler is registered for `reinstall`. The job is created and fails loudly at the worker. The path from a running customer server to a rebuilt one has not been designed, and an endpoint inventing one erases a customer's disk. |
| Reinstall a dedicated server | The same, and documented at length in `RequestDedicatedReinstall`. |
| Open a VNC console | A permit is issued and nothing can redeem it. The console gateway is a separate process that does not exist in this repository. |
| Change plan from the portal | The API can. There is no screen. |
| Be told anything | There is no customer notification system. A customer is never told their server is ready, or that its build failed. |

Two of those five — the reinstalls — fail *loudly*: a job in `needs_review`,
visible on the operator screen and in `lynomia_provisioning_jobs_total`. The
other three fail silently, and are recorded in
`docs/customer-capability-matrix.md` so they are found by reading rather than
by a customer.

## B. Why a green suite could not see any of this

Every one of the twenty-one had tests. The tests passed. The pattern that made
them useless is the same each time, and it is worth stating because it will
recur:

**A test that constructs the thing it tests proves the thing works and proves
nothing about whether anything calls it.**

`VpsPowerHandlerTest` registered the three power handlers in its own `setUp`,
exercised them through the HTTP endpoint, and passed — while production had
registered only `CreateVps`, so every customer pressing "reboot" got a 202 and
a job that died at the worker. Its docblock even said so: *"four lines are
needed there before any of this executes in production."* It passed for
months with that sentence in it.

The same shape closed `SuspendHostingAccount`, `VoidInvoice`,
`AdoptOrphanResource`, `ReleaseNodeCapacity`, `recordFailedPayment` and the
rest.

Three gates now make that shape fail:

1. **`NoDeadCapabilitiesTest`** — every action, job and listener must be
   referenced in application code. It strips comments before searching, which
   is the whole difference: eight of them were named only in docblocks
   explaining they had no caller yet, and a naive grep counts that as a caller.
2. **`HandlerCoverageTest`** — total over the provisioning kind enum: every
   kind is registered or carries a written reason, every power action a
   customer can press resolves, and every registered handler is actually
   constructible.
3. **`VpsPowerHandlerTest` no longer registers anything.** It resolves through
   the container the application really boots, so removing the wiring turns it
   red.

Each was verified to bite by removing the fix and watching the test fail.

## C. Every capability that could not complete, and what it does now

| Capability | Was | Now |
| --- | --- | --- |
| `StartVpsHandler`, `StopVpsHandler`, `RestartVpsHandler` | Written, tested, unregistered. Every power action died at the worker. | Registered; proven through the endpoint and the engine |
| `CreateHostingAccountHandler` | Unregistered. Ordering hosting created a job nothing could run. | Registered |
| `ProvisionDedicatedHandler` | Unregistered. Same for dedicated. | Registered |
| `ProvisionOrderedService` | **Did not exist.** Nothing created a service or a job for a purchase. | Built; `OrderToProvisionedServiceTest` |
| `SuspendHostingAccount` | No caller. Non-payment switched nothing off. | `EnforceServiceStateForSubscription` |
| `UnsuspendHostingAccount` | No caller. | The same listener, and an operator route |
| `AdvanceDunning::recordSuccessfulPayment` | No caller. **A customer paid in full and was terminated anyway.** | `ReviveSubscriptionOnRenewalPayment` |
| `AdvanceDunning::recordFailedPayment` | No caller. **A declined card cost nothing; service ran for ever.** | `StartDunningOnFailedPayment` |
| `ReleaseNodeCapacity` | No caller. Every failed build took a permanent bite out of the fleet. | `EveryReservationReleaser` |
| `ReapExpiredReservations` | No caller. Addresses left pools one-way. | `ipam:reclaim`, every ten minutes |
| `ReleaseQuarantinedAddresses` | No caller. | The same command |
| `SyncAccountUsage` | No caller. Every hosting account read zero disk for ever. | `hosting:sync-usage`, hourly |
| `SyncHardwareInventory` | No caller, while documenting itself as scheduled. | `dedicated:sync-inventory`, hourly |
| `ConfirmPaymentFromReturn` | No caller. A lost webhook was permanent. | `payments:reconcile`, every five minutes |
| `TerminateHostingAccount` | No caller. | Operator route, behind two permissions |
| `ChangeSubscriptionPlan` | No caller. Cancel-and-rebuy was the only route. | `POST /subscriptions/{subscription}/plan` |
| `VoidInvoice` | No caller. A duplicate invoice was unpayable and undismissable for ever. | Operator route with audit |
| `AdoptOrphanResource` | No caller. A timed-out job's machine stayed unbilled and unmanaged. | Operator route with required evidence |
| `PreflightHostingNode` | No caller. | `hosting:preflight`, exit code as the answer |
| `DetectVirtualMachineDrift` | **Did not exist.** `RecordDrift` had nothing to record. | Built; `ReconcileCluster` on a schedule |
| Drift review | No way to say "seen" or "fixed". | `ReviewDrift` and an operator screen |
| Audit log | **Did not exist**, despite an `audit.view` permission and a dozen docblocks referring to it. | Append-only table, enforced at the model |
| `RedeemConsoleSession` | No caller. | **Still none.** The console gateway is outside this repository; the action exists so that "single-use" is a property something can test. Reported in section A. |

## D. The five gaps the phase named

| # | Gap | Status |
| --- | --- | --- |
| 1 | Customer backups screen does not exist | **Closed.** `/backups`, with restore behind a typed confirmation. 9 API tests, 3 component tests, 3 browser specs. |
| 2 | Subscription / renewal screen does not exist | **Closed for viewing and cancelling.** The screen existed by this phase but had never been driven with data — the E2E seeder wrote no subscription, so it had only ever rendered its empty state. It now seeds a renewing one and a cancelled one, because both dates come from the same pair of columns and a fixture with one of them makes a half-built screen look correct. Plan change remains API-only and is reported as such. |
| 3 | Zero-total orders become PAID but never fulfil | **Closed** by the `OrderFinanciallySettled` domain concept — a settlement basis of `NoPaymentRequired`, with no fake payment record, no fake Stripe event and no zero-value transaction. |
| 4 | Reconciliation/drift actions with no execution path | **Closed**, and the audit that closed it found eight more. |
| 5 | Queue processing never proven with a real worker | **Closed.** Scheduler → Redis → separate `queue:work` → database, plus provider refusal and SIGKILL mid-build. |

## E. Runtime verification

Proven against a real running system, not a fake:

| Chain | Test |
| --- | --- |
| Scheduled command dispatches → Redis → separate worker process → database changes | `ARealWorkerConsumesTheQueueTest::the_scheduler_dispatches_work_that_a_worker_then_does` |
| A real worker builds a virtual machine from a queued job | `a_real_worker_builds_the_machine_a_paid_order_asked_for` |
| Two deliveries of one job build one machine | `two_deliveries_build_one_machine` |
| Provider refusal → recorded, rescheduled with backoff, nothing built, no `failed_jobs` row | `a_provider_refusal_is_recorded_and_rescheduled_rather_than_lost` |
| Worker SIGKILLed mid-build → a second worker builds nothing | `a_worker_killed_mid_build_does_not_produce_a_second_machine` |
| The portal, in Chromium, in both languages and both colour schemes | 40 browser specs |

`QUEUE_CONNECTION=sync` was not used for any of the above. The queue tests run
a real `php artisan queue:work` subprocess against an isolated Redis database.

## F. Observability

| Signal | Metric |
| --- | --- |
| Queue depth per queue, from the driver actually in use | `lynomia_queue_depth` |
| Failed jobs | `lynomia_failed_jobs_total` |
| Provisioning jobs by status and kind | `lynomia_provisioning_jobs_total` |
| **Unresolved drift by kind and severity** | `lynomia_resource_drift_open` — new |
| **Last successful run of each scheduled command** | `lynomia_scheduled_command_last_success_timestamp_seconds` — new |
| **Consecutive failures per command** | `lynomia_scheduled_command_consecutive_failures` — new |

Cardinality is bounded everywhere. Nothing is labelled by customer, service,
host or provider reference — a series per customer is how a Prometheus falls
over, and a drift metric labelled by machine would explode on exactly the
incident it exists to report. `MetricsQueryBudgetTest` asserts that the query
count does not change when the amount of data does.

Measured: 19 metrics, 270 series, 15 queries, 47–53 ms per scrape.

## G. Security

- The audit log is append-only and the model refuses updates and deletes.
  Actor labels are denormalised so the trail does not change when somebody is
  renamed; the subject has no foreign key, so a record survives the thing it
  describes; context passes through the redacting cast.
- Audited: invoice void, orphan adoption, drift acknowledge and resolve,
  reconciliation request, backup restore, hosting unsuspend and terminate.
- Cross-tenant access answers 404, not 403 — asserted for the new restore
  endpoint in both directions.
- **A guard was enforced against this phase's own work.** The obvious home for
  `ConfirmPaymentFromReturn` is `POST /payments/confirm`, and
  `PaymentConfirmationIsServerSideOnlyTest` forbids any client-callable
  payment confirmation under `api/`. It caught the endpoint. The endpoint was
  removed and replaced with a server-initiated sweep, which is stronger: it
  takes no input from anybody, does not depend on the customer returning, and
  covers payments started anywhere.
- Skipping a hosting account's retention window requires `service.terminate`
  on top of `hosting_account.manage`. The first draft of that guard checked
  the same permission the route already required — a no-op pretending to be a
  gate — and was corrected.
- No "force success" control exists anywhere. The drift screen offers no way
  to change a provider; adoption requires stated evidence.

## H. What is deliberately not built

- **Compute suspension.** Dispatching a stop is indistinguishable at the
  hypervisor from the customer stopping their own machine, and nothing would
  prevent them pressing start again — now that the start handler is registered,
  they really could. Real suspension needs the provider to refuse the
  customer's own start, which has not been designed. The service row moves to
  suspended and `VpsOperationGuard` refuses every power, resize and reinstall
  request, which is real enforcement; the machine keeps running.
- **Reinstall**, virtual and physical, per section A.
- **Backup deletion**, which interacts with a retention policy the platform
  does not own.
- **Customer notifications.**
- **Team membership.** A customer is one login; memberships are created only
  at registration.

## I. Defects this phase introduced and caught

Recorded because a report that only lists what went right is not a report.

1. **`audit_log.subject_type` was `varchar(64)`** and a fully-qualified model
   name is 66. The insert threw *after* the audited act had committed — the
   act happened and its audit row did not, which is the exact hole the table
   exists to close. Found by the first test that audited a provisioning job.
2. **Four CI runs were red on the frontend** while everything passed locally,
   because I ran `npx tsc --noEmit` instead of the repo's `npm run typecheck`.
   One of the three errors was behavioural: under `exactOptionalPropertyTypes`,
   passing `requiredPhrase={machine?.hostname}` meant "I could not find the
   machine" became "no confirmation needed" — on the dialog guarding a restore.
3. **A latent flake**, pre-existing: two catalogue tests asserted a price does
   not leak by substring-searching the JSON body for `"3000"`. ULIDs are base32
   including the digits, so an id containing `3000` failed the test at random
   with a message pointing at a leak that had not happened.
4. **The clean room found that `make test` wiped the developer's database.**
   `bootstrap.sh` never created `.env.testing`, so `APP_ENV=testing` fell back
   to `.env` and `RefreshDatabase` truncated it. Invisible to CI, which writes
   its own environment file.
5. **A guard that guarded nothing** — the retention-window check in section G.
6. **A gate that passed trivially.** The first `HandlerCoverageTest` scanned
   source for job-creation call sites; it passed with the stop handler
   unregistered, because the power kinds come from `PowerAction::jobKind()` and
   no scan can follow that. Rewritten to be total over the enum.

## J. Known limits of the dead-capability gate

`NoDeadCapabilitiesTest` finds a **class** nothing references. It does not find
a **method** nothing calls: `AdvanceDunning` was referenced by the lifecycle
sweep the whole time while two of its three public methods had no caller, and
that is where the two worst findings of this phase lived. The event audit in
section K is what caught those, and it was done by hand.

## K. Domain events

| Event | Listeners | Assessment |
| --- | --- | --- |
| `OrderPlaced` | 1 | |
| `PaymentCaptured` | 1 | |
| `PaymentFailed` | 1 | **Was 0.** |
| `InvoicePaid` | 2 | |
| `OrderFinanciallySettled` | 1 | |
| `SubscriptionStatusChanged` | 1 | New this phase |
| `RefundIssued` | 1 | |
| `DriftRecorded` | 0 | Alerting is the metric, not a listener |
| `ProvisioningJobSucceeded` | 0 | No customer notification system |
| `ProvisioningJobFailed` | 0 | The same |
| `ProvisioningJobNeedsReview` | 0 | Operators see it on the screen and in metrics |

## L. Scheduled work

Nine entries, each `withoutOverlapping()` and `onOneServer()`. Four are new to
this phase and one existed before it.

```
0 * * * *    subscriptions:renew
0 * * * *    subscriptions:sweep
*/30 * * * * infrastructure:reconcile
*/5 * * * *  provisioning:detect-stale
*/5 * * * *  backups:reconcile
*/5 * * * *  payments:reconcile
*/10 * * * * ipam:reclaim
0 * * * *    hosting:sync-usage
0 * * * *    dedicated:sync-inventory
```

## M. Per-subsystem status

| Subsystem | Status | Blocked by |
| --- | --- | --- |
| Identity, RBAC, sessions, tokens | `RUNTIME_VERIFIED` | |
| Catalogue and pricing | `RUNTIME_VERIFIED` | |
| Orders, invoicing, settlement | `TESTED` | Real payment provider |
| Payments | `BLOCKED_CREDENTIALS` | No Stripe account |
| Subscriptions and dunning | `TESTED` | |
| Provisioning engine | `RUNTIME_VERIFIED` | |
| Compute / Proxmox | `BLOCKED_CREDENTIALS` | No hypervisor |
| Backups | `TESTED` | No Proxmox Backup Server |
| Dedicated / BMC | `BLOCKED_HARDWARE` | No chassis |
| Shared hosting | `BLOCKED_LICENSE` | cPanel and DirectAdmin are commercial |
| IPAM | `TESTED` | |
| DNS | `BLOCKED_CREDENTIALS` | No Cloudflare token |
| Reconciliation and drift | `TESTED` | |
| Audit | `TESTED` | New this phase |
| Monitoring | `RUNTIME_VERIFIED` | |
| Customer portal | `RUNTIME_VERIFIED` | |
| Operator portal | `RUNTIME_VERIFIED` | Drift screen is API-only |
| Notifications | `NOT_IMPLEMENTED` | |

No subsystem is `REAL_INFRA_VERIFIED`. Nothing in this repository has spoken to
a real hypervisor, a real BMC, a real control panel or a real payment provider,
and this phase deliberately did not connect any.

## N. Readiness for real infrastructure

Ready, with one qualification.

The adapters are written against real API shapes and exercised against fakes
that model the awkward parts — indeterminate responses, tasks that accept in
milliseconds and run for an hour, panels that refuse. The provider registry
refuses fake providers in production, and CI enforces that no fake is
configured outside the development template.

The qualification: **`TESTED` and `REAL_INFRA_VERIFIED` are far apart**, and
the gap is where the next phase's work is. A fake returns what the adapter
expects. A real Proxmox returns what Proxmox returns.

The first real integration should be one cluster, one node, one machine, with
`infrastructure:reconcile` running and the drift screen open — because the
reconciler comparing the platform's beliefs with a real hypervisor is the
cheapest possible way to find out which of them is wrong.

## O. What this phase changed, by the numbers

| | Before | After |
| --- | --- | --- |
| Backend tests | 1797 | 1875 |
| Provisioning handlers registered | 1 | 6 |
| Scheduled commands | 3 | 9 |
| Capabilities with no execution path | 21 | 1, documented |
| Domain events with no listener | 5 | 4, each explained |
| Audit log | none | 11 audited acts |
| Metric names | 13 | 17 |

Each figure was read from the repository at the phase's baseline commit and at
its head, not remembered.

## P. Documents

- `docs/customer-capability-matrix.md` — every customer action, and whether it
  finishes
- `docs/performance-report.md` — measured only, with a section on what this
  environment cannot measure
- `docs/build-status.md` — per-component honest classification
- `docs/phase-29-baseline.md` — the gates as they stood at the start

## Q. The honest summary

This phase did not add features. It found that a platform with 1797 passing
tests could not reboot a server, could not fulfil an order for hosting or
dedicated hardware, never switched anything off for non-payment, never
switched it back on when somebody paid, leaked node capacity permanently on
every failed build, never returned an IP address to its pool, and had no audit
log behind a permission called `audit.view`.

All of that was written. Most of it was tested. None of it was connected.

The remaining gaps are five, they are named in section A, and each is a
decision somebody made rather than something nobody noticed.

## R. Sign-off

Phase 29 is complete under its own terms: every internal workflow that the
platform presents as working has been proven to run, or is documented as not
implemented. The repository is ready for a first real-infrastructure
integration, with the expectation that integration will find things — because
that is what integration is for, and because the reconciler now exists to
report them.
