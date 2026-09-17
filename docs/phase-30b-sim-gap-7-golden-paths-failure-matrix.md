# Phase 30B-SIM — Gap 7

## Golden paths, and the failure matrix that gives them meaning

---

## 1. Provenance

Gap 6 proved that each provider family's contract can be exercised against a
controlled simulator: nine controlled drivers, catalogued, registrable,
testable, each declaring what it cannot do with the missing contract as the
reason. What it did not prove is that a *business event* survives the trip
across those families — that an order becomes money, money becomes a service,
a service becomes a machine with an address and a name, and that every one of
those steps stays consistent when a message arrives twice, arrives late, or
never arrives at all.

That is this gap. Its unit of work is not a provider call but a workflow, and
its unit of evidence is not "the adapter answered" but "the durable state is
right, and stayed right under a fault".

Two rules shape everything below.

**The architecture under test is the product's own.** No golden-path service,
no rehearsal orchestrator, no test-only order processor. Where this document
says a path was exercised, it was exercised through the same controllers,
actions, events, listeners and jobs a customer's purchase goes through. The
only test-only code here is a harness that starts a worker and a store that
lets a controlled simulator remember something between two processes.

**A rehearsal is not a verification.** Everything in this gap runs against
controlled providers and the reference estate. It establishes that the software
is consistent; it establishes nothing whatever about real infrastructure, real
money, or a real registry. §35 states that boundary in full, and the five
real-verification claims remain NONE.

---

## 2. Entry CI

| | |
|---|---|
| Gap 6 code SHA | `e5df55ae51eb63d6c21d219f233aac3f493cd5e6` |
| Run | 174 |
| Conclusion | success, 9 / 9 jobs (browser end-to-end on the second run of that job; §31 of the Gap 6 report records why) |
| Gap 6 documentation SHA | `137d005fe79dc7d42e26e1a61fbc17c511ff6f5f` |
| Run | 175 |
| Conclusion | success, 9 / 9 jobs, first attempt |
| Branch state at entry | `claude/hv-t6hq1p` at `137d005`, working tree clean, no commits after it, no Gap 8 work present |

---

## 3. Starting and ending SHA

| | |
|---|---|
| Branch | `claude/hv-t6hq1p` |
| Starting HEAD | `137d005fe79dc7d42e26e1a61fbc17c511ff6f5f` |
| Ending HEAD, code | recorded in §31 with the CI run that verified it |
| Ending HEAD, document | the commit that adds §31's CI record, which changes no code |

---

## 4. The cross-domain workflow map

This is the audit the rest of the gap was planned from. It was built by reading
the code, not by reading the brief: every entry point, service, record, event,
job and terminal state below is one that exists at `137d005`.

### 4.1 The money chain

| Stage | Entry point | Application service | Durable record | Event | Job | Terminal state |
|---|---|---|---|---|---|---|
| Order placed | `POST /api/v1/orders` | `PlaceOrder` | `orders`, `order_items` | — | — | `OrderStatus::PendingPayment` |
| Invoice issued | same request | `IssueInvoiceForOrder` → `IssueInvoice` | `invoices`, `invoice_lines` | — | — | `InvoiceStatus::Open` |
| Payment started | `POST /api/v1/invoices/{id}/payments` | `StartInvoicePayment` | `payment_attempts` (unique `provider` + `provider_reference`) | — | — | `PaymentAttemptStatus::Pending` |
| Payment authoritative | provider webhook or server-side retrieve | `IngestWebhookEvent` → `RecordPaymentCapture` / `RecordPaymentFailure`, or `ConfirmPaymentFromReturn` | `payment_provider_events` (unique `provider` + `provider_event_id`), `payments` | `PaymentCaptured` | — | `PaymentAttemptStatus::Succeeded` |
| Invoice settled | listener | `SettleInvoiceOnPaymentCaptured` → `SettleInvoice` | `invoices`, `wallet_transactions` for an overpayment | `OrderFinanciallySettled` | — | `InvoiceStatus::Paid` |
| Order fulfilled | listener | `FulfilOrderOnSettlement` | `orders`, `coupon_redemptions`, `subscriptions`, `services` | — | itself, `ShouldQueue` on `payments` | `OrderStatus::Paid` |
| Service created | same listener | `ProvisionOrderedService` | `services` (unique `order_item_id`) | — | — | `ServiceStatus::Provisioning` |
| Build requested | same | `CreateProvisioningJob` | `provisioning_jobs` (unique `idempotency_key`) | — | `RunProvisioningJob` on `provisioning` | `ProvisioningJobStatus::Queued` |

The authoritative-payment rule is structural rather than advisory: nothing on
the customer's side of the wire can reach `SettleInvoice`. The client's return
from a gateway is admitted only through `ConfirmPaymentFromReturn`, which
performs a **server-side retrieve** against the provider before it records
anything, and the webhook path verifies a signature and records the provider's
event id before acting on it.

### 4.2 Provisioning

| Stage | Application service | Provider seam | Durable record | Terminal state |
|---|---|---|---|---|
| Job claimed | `RunProvisioningJob` → `ProvisioningJobStateMachine` | — | `provisioning_jobs`, `provisioning_attempts` (unique `provisioning_job_id` + `attempt_number`) | `Running` |
| Handler dispatched | `ProvisioningHandlerRegistry::get(kind)` | one handler per `ProvisioningJobKind` | — | — |
| VPS built | `CreateVpsHandler` | `ComputeProviderFactory` → `FakeComputeProvider` | `virtual_machines`, `compute_resources` (unique `cluster_id` + `provider_id`) | `ServiceStatus::Active` |
| Address bound | `IpAllocator::reserve()` then `commit()` (IPAM) | — | `ip_addresses`, `ip_assignments` | assigned |
| Hosting account created | `CreateHostingAccountHandler` | `HostingProviderFactory` → `FakeHostingProvider` | `hosting_accounts` | `ServiceStatus::Active` |
| WordPress installed | `InstallWordPressHandler` | the same hosting provider, by architecture | `wordpress_sites` | site recorded; service unaffected |
| Dedicated delivered | `ProvisionDedicatedHandler` | `DedicatedProviderFactory` → `FakeDedicatedProvider` | `dedicated_servers`, `managed_servers` | `ServiceStatus::Active` |
| Domain registered | `RegisterDomainOnPayment` → `RegisterDomainAtRegistrar` | `DomainRegistrarFactory` → `FakeDomainRegistrarProvider` | `domains`, `domain_operations` | registered |
| Name served | `PublishZone`, `PublishRecord` | `DnsProviderFactory` → `FakeDnsProvider` | `dns_zones`, `dns_records` | `DnsState::Active` |
| PTR published | `PublishReverseDnsRecord` | `ReverseDnsProviderFactory` → `FakeReverseDnsProvider` | `ip_addresses.ptr_*` | published |
| Backup taken | `RequestBackup` → `ReconcileRunningBackups` | `BackupProviderFactory` → `FakeBackupProvider` | `backups`, `backup_verifications` | verified true / false / unknown |

`ProvisioningJobKind::createsResource()` is the property that decides how an
unclassified failure is treated — "nothing happened" versus "something may
exist out there" — and it is true for exactly `create_vps`,
`create_hosting_account` and `provision_dedicated`.

### 4.3 The seams that can lose consistency

| Seam | What crosses it | What makes it safe today |
|---|---|---|
| Request → queue | `FulfilOrderOnSettlement`, `RunProvisioningJob`, the DNS and domain jobs | Jobs carry ids, never models, and reload the row when they run |
| Queue → worker process | every job above | `provisioning_jobs.idempotency_key` unique; the job state machine refuses a second claim |
| Provider → platform | task ids, remote states | `compute_resources` unique on `cluster_id` + `provider_id`; `PollProviderTasks` reconciles what a worker did not see |
| Provider event → money | webhooks | `payment_provider_events` unique on `provider` + `provider_event_id`, recorded **before** the effect |
| Reconciliation → desired state | drift | `resource_drifts`, reviewed by a person, never auto-applied |

### 4.4 Existing coverage, measured

The audit's most useful finding is how much of this is already proved, because
it decides what this gap must *not* rebuild.

| Concern | Where it is already proved | Verdict |
|---|---|---|
| Paid order → service + queued build | `tests/Feature/EndToEnd/OrderToProvisionedServiceTest` (5 tests) | Covered, but `Queue::fake`d at the build |
| Purchase → subscription, redelivered webhook, decline, coupon, overpayment | `tests/Feature/EndToEnd/PurchaseToActiveServiceTest` (5 tests) | Covered |
| Whole life of a VPS, hosting account, dedicated server, DNS zone, WordPress order | `tests/Feature/EndToEnd/*` (5 files) | Covered in-process |
| A real worker builds a machine, refuses, is killed mid-build, is redelivered | `tests/Feature/Queue/ARealWorkerConsumesTheQueue` (5 tests) | Covered, compute only |
| A real worker finishes what a customer started; reinstall across processes | `tests/Feature/Queue/AWorkerFinishesWhatTheCustomerStarted` (4 tests) | Covered, compute only |
| A domain purchase survives a real worker | `tests/Feature/Queue/ADomainPurchaseSurvivesARealWorker` (2 tests) | Covered, registrar only |
| Retention sweep, termination, task poller in their own processes | `tests/Feature/Queue/TheNewSweepsRunOutsideThisProcess` (3 tests) | Covered, compute only |
| Orphan adoption, IP exhaustion, capacity failure, stale copies, replay, partial refunds | 2,987 distinct test names across `tests/` | Covered per module |
| Out-of-order event delivery | nothing | **Gap** |
| Hosting, dedicated, backup, DNS and reverse DNS across a worker boundary | nothing, and nothing could | **Gap — the Gap 6 carry** |
| One cross-domain failure matrix with expected order, financial, service and provider state per scenario | nothing | **Gap** |

### 4.5 The gap that blocks the others

Five of the eight controlled simulators keep their state in a private array:
dedicated, hosting, backup, DNS and reverse DNS. Compute, registrar and payment
have an opt-in state file.

That is why every real-worker proof in the repository is about compute or the
registrar. A hosting account created in a request is invisible to the worker
that should suspend it; a zone published by a worker is invisible to the request
that should read it back. The workflows are not untested because nobody wrote
the test — they are untestable across the seam the product actually uses.

So the first piece of work in this gap is one durable controlled-simulation
store, and the golden paths follow it.

---

## 5. Golden architecture

Nothing was created that the application already had. What this gap adds is
listed here in full, so that a reviewer can see the whole test-only surface at
once.

| Added | Kind | Why it is not business architecture |
|---|---|---|
| `ControlledSimulationStore` | one collaborator in `Shared` | It is the generalisation of the state-file mechanism compute, registrar and payment already each had a private copy of. It holds no business rule: it reads and writes one array for whoever owns it, refuses production, and does nothing at all unless a path is configured. |
| `GoldenPathHarness` | a test base class extending the existing `WorkerHarness` | It starts the same `queue:work` process the existing harness starts, and passes the new state paths to it. It orchestrates nothing: every business step in a golden test is a call into the product's own actions or an HTTP request against its own routes. |

The one deliberate absence: no `infra:simulation:rehearse` command was added.
The brief permits one "only if adding a CLI gives genuine operator value", and
it would not — `php artisan test --group=golden-path` is the one command, it is
the command CI runs, and a second entry point into the same suite would be a
thing to keep in step rather than a thing that helps.

---

## 6. The VPS golden path

`TheVpsGoldenPathTest`, four tests, three processes.

| Stage | Where it happens | What is asserted |
|---|---|---|
| The order | `PlaceOrder` → `IssueInvoiceForOrder`, this process | an order in `pending_payment` and an open invoice priced in fils |
| The payment | the controlled gateway signs an event; it is delivered to `POST /webhooks/fake`, the real route, in this process | the endpoint answers 200, **the invoice is still `open` and the order is still `pending_payment`**, and exactly one message is on the `payments` queue |
| The settlement | `queue:work --queue=payments --stop-when-empty`, **process two** | order `paid`, invoice `paid`, subscription started at 9 000 fils, service `provisioning`, a provisioning job whose idempotency key is `order-item:<id>` |
| The build | `queue:work --queue=provisioning --stop-when-empty`, **process three** | job `succeeded`, service `active`, a `virtual_machines` row, one live `ip_assignments` row pointing at the machine and the service, and the hypervisor's own `getVm()` reporting the hostname the plan asked for |

The second row is the one worth reading twice. A client response must never be
what marks an invoice paid, and the assertion that the invoice is *still open*
immediately after a 200 from the webhook endpoint is what proves it is not: the
money moves when a listener on a queue says so, in another process, from a
provider event this platform verified the signature of.

The other three tests are the redelivered webhook (§14), a declined payment
that builds nothing, and the preflight gate (§24).

---

## 7. The hosting golden path

`TheHostingGoldenPathTest`, two tests.

The first takes a shared-hosting purchase through the whole life of an account:
payment on the `payments` queue, provisioning on the `provisioning` queue, an
account at the controlled panel with a package and a slot taken, a WordPress
installation performed by the real `InstallWordPress` job against a
pre-existing site row, a suspension that the panel actually sees, and a
termination that gives the slot back. Every one of those is read back **from the
controlled panel**, not from the platform's own tables, because the platform
believing it suspended an account is not the same fact as the account being
suspended.

The second is the negative twin the product needs more: a hosting plan with no
package configured. The service is left `pending`, no provisioning job is
created at all, and the reason is recorded on the service in
`resources['placement_blocked_reason']` naming the missing hosting package. A
purchase that cannot be placed must stop before it reaches a provider, and say
why in a place an operator can read.

---

## 8. The domain golden path

`TheDomainGoldenPathTest`, two tests.

A domain purchase settles on the `payments` queue and registers on `default` —
two different queues, two different processes — and the registration is read
back through `DomainRegistrarFactory::make('fake')->inspect($name)`: the
registrar's own record, with its nameservers and its expiry.

The second test renews it. `Carbon::setTestNow()` is moved to one month before
the first expiry, the renewal runs, and the new expiry is asserted to be about
a year further out (365 days ± 2, because a renewal is a registry-term
extension and not a calendar-month arithmetic). No sleeping, no real clock.

**`.sy` is not in this path and is not tested by it.** The registrar contract
for `.sy` is unavailable; §34 records it as `NOT_IMPLEMENTED — PROVIDER
CONTRACT UNAVAILABLE`, and nothing in this gap registers, renews, transfers or
prices a `.sy` name.

---

## 9. The dedicated golden path

`TheDedicatedGoldenPathTest`, three tests: a chassis handed to a customer, a
power operation that reaches the BMC, and the duplicate-power weakness.

The third is deliberately not a pass. §22 of the brief asks for the current
weakness to be demonstrated rather than papered over, and it is:
`ChangeDedicatedServerPower::execute(..., Cycle)` is called twice through the
ordinary application path, with `CountingDedicatedProvider` — a decorator over
the real controlled provider that adds nothing but a tally — swapped in behind
the factory. The test asserts **two provider calls**, which is the defect, and
carries a message that says so: *"the known dedicated power gap has been closed
— update this test"*.

Counting is the only assertion that works here. Two resets leave a chassis in
the same state as one, so a test that looked at the power state afterwards
would find `on` and pass while a customer's machine had been interrupted
mid-boot. That is precisely how this gap survived.

Classification: **REAL CODE GAP — CARRIED TO GAP 8** (§33).

---

## 10. What a golden path must not do

`WhatAGoldenPathMustNotDoTest`, five tests. These are the assertions that keep
the four paths above from being theatre.

| Test | The failure it exists to catch |
|---|---|
| no secret-shaped value survives the workflow | A panel password or an admin credential reaching a queue payload, an audit entry, a provisioning attempt, a hosting account row, a service, a notification or a failed job. Two secret-shaped canaries are planted, the Redis message is read **before** the worker runs, and every one of those stores is then searched as JSON. `failed_jobs` is asserted empty as well: a raw secret in a failed-job payload is a secret in a table nobody redacts. |
| no stray outbound request | `Http::preventStrayRequests()` over a whole VPS path. Any real HTTP call fails the test. |
| a retry is bounded | A permanently failing build ends in `needs_review` with at most `provisioning.retry.max_attempts` attempt records and **nothing left on the queue**. |
| drift is reported once | Three detection passes over the same disagreement produce exactly one open drift record. |
| a provider message that quotes its own credential | The controlled drivers quote the request they sent, credentials and all, exactly as the real clients do. Anything stored from a provider's own message — a `failure_reason`, a `last_error`, an attempt record — is asserted to be redacted first. |

---

## 11. The durable controlled-simulation runtime

One pattern, `ControlledSimulationStore`, used by seven simulator families
(compute, dedicated, hosting, backups, DNS, reverse DNS, registrar). Each has
one configuration key — `<family>.fake.state_path` — and one env var behind it.

| Property | How it is met |
|---|---|
| Non-production | `fromConfig()` throws in production, naming the key. Each controlled provider also refuses construction in production (`NoControlledDriverSurvivesProductionTest`, six tests). Two independent refusals, because a durable file of imaginary infrastructure is worth refusing on its own terms. |
| Explicitly enabled | No path configured → `fromConfig()` returns null → the simulator keeps its state in memory, which is the normal case and what the rest of the suite relies on for isolation. |
| Deterministic | The store holds an array; nothing in it is time- or random-dependent. Ids are sequential per file. |
| Resettable | `forget()` deletes the file. |
| Isolated per run | The harness gives every test its own directory under the system temp directory, keyed by pid and a unique token, and removes it in teardown. |
| Safe for parallel CI | Two tests never share a path, and a write is `file_put_contents` to `<path>.<pid>.tmp` followed by `rename()`, which is atomic within a filesystem. A reader sees the old state or the new one, never half of either. |
| Never provider truth | It is read by controlled drivers only. `ProductReadinessEvaluator` was not touched, and §25 records that simulation still cannot produce `READY_TO_SELL`. |
| Safe deserialisation | `unserialize()` with an explicit allowed-class list per family, defaulting to none. An object whose class was not listed comes back incomplete, and the store throws by name rather than letting a fatal error surface somewhere far away. |

The payment simulator keeps its own JSON store, which predates this and which
this gap did not consolidate: it is the one documented exception to "one common
pattern", recorded here rather than quietly left out.

---

## 12. Cross-process proof

`AControlledProviderRemembersAcrossProcessesTest`, five tests, one per family
that had no cross-process story at all before this gap.

| Written by | Read by | What crosses |
|---|---|---|
| a `queue:work` process | this process | a hosting account, listed from the panel |
| a `queue:work` process | this process | a DNS zone and a record in it |
| a `queue:work` process | this process | a PTR, via `publishedFor()` |
| this process | a `SyncDedicatedServer` worker | a chassis power state (`off` → `on`) |
| this process, then two separate `backups:reconcile` processes | this process | a backup settled to an archive id, found by listing the datastore |

Each row is two operating-system processes, not two calls on one PHP object.
The evidence that they are load-bearing is breakage J in §29: with the store
disabled, all five fail.

---

## 13. Real queue execution, and the serialization boundary

No `Queue::fake()` anywhere in the evidence above. Every worker in this gap is
`php artisan queue:work redis --queue=<name> --stop-when-empty --tries=1` in
its own operating-system process, started by the harness, with:

- **A second database connection** (`queue_test`) for the rows the test wrote,
  because a worker in another process cannot see an open transaction. Every
  fixture a worker must read is committed, and cleaned up in teardown.
- **Redis database 15**, flushed per test, so one test's messages cannot be
  another's.
- **A genuine serialization boundary.** `SettleInvoiceOnPaymentCaptured` and
  `FulfilOrderOnSettlement` are both `ShouldQueue` on the `payments` queue, so
  a single `--stop-when-empty` run drains both hops: the second listener reads
  the invoice **from the database**, not from the object the first one held.
  The listeners reload authoritative state; nothing is carried across the
  boundary except an identifier.

That last property is what makes the assertions in §6 meaningful. A workflow
proved inside one process proves that the code composes; a workflow proved
across three proves that the *state* does.

---

## 14. The financial invariant matrix

| Invariant | Where it is asserted | How it is asserted |
|---|---|---|
| A client response never settles an invoice | `TheVpsGoldenPathTest`, main path | invoice still `open` and order still `pending_payment` after the webhook endpoint returns 200 |
| Payment success comes from the provider contract | all four paths | the only thing that moves money is a signature-verified event delivered to `POST /webhooks/{provider}` |
| One financial effect per payment | `TheVpsGoldenPathTest`, redelivery | one transaction; **and** `amount_paid_minor === total_minor` exactly, `amount_refunded_minor === 0`, the succeeded charges attached to the invoice summing to the total, and **zero wallet transactions against the invoice** |
| A declined payment costs nothing | `TheVpsGoldenPathTest`, declined | order unpaid, no subscription, no service, nothing queued on `provisioning` |
| Money is minor units and ISO currency | everywhere | plans priced in fils as integers; `Money::ofMinor()`; no float appears in any assertion in this gap |
| An unplaceable purchase is not charged twice or built | `TheHostingGoldenPathTest`, no package | service `pending`, zero jobs, reason recorded |

The wallet assertion is the one that earns its place. Row counts stayed at one
through two variants of breakage A; the wallet count is what caught the third
(§29).

---

## 15. The idempotency matrix

Every durable key below already existed; this gap exercises them across
process boundaries rather than adding new ones.

| Key | Table | Proved by |
|---|---|---|
| `(provider, provider_event_id)` | `payment_provider_events` | redelivered webhook |
| `(provider, provider_reference)` | `payment_attempts`, `transactions` | redelivered webhook |
| `order_item_id` | `services` | redelivered webhook: one service |
| `idempotency_key` | `provisioning_jobs` | one job per order item, key `order-item:<id>` |
| `(provisioning_job_id, attempt_number)` | `provisioning_attempts` | bounded retry |
| `(cluster_id, provider_id)` | `compute_resources` | duplicate delivery: one machine |
| `(ip_address_id) WHERE released_at IS NULL` | `ip_assignments` | one live assignment per address |
| the job claim under `lockForUpdate` + terminal states | `provisioning_jobs` | duplicate delivery, late completion |

Two findings about the shape of this, both recorded rather than smoothed over:

- **The money path is defended in depth.** Removing the webhook-redelivery
  short-circuit changed nothing; removing the already-captured short-circuit
  changed nothing either. The behaviour is held by a third layer (the unique
  index plus the update-existing branch) and a fourth (settlement recomputes
  rather than increments). A single-layer removal is not observable, which is
  a good property of the code and an awkward one for a breakage exercise —
  §29 says exactly which layer is load-bearing.
- **The job claim is two lines, not one.** Removing the claimability filter is
  absorbed by the state machine's transition table; a duplicate delivery only
  builds a second machine when both go.

---

## 16. The orphan-resource matrix

| Scenario | The resource at risk | What the platform does | Asserted in |
|---|---|---|---|
| Hypervisor refuses the build | the reserved address | keeps the reservation for the retry, and the retry **reuses** it — one address, not one per attempt | `TheFailureMatrixTest`, refusal (two attempts asserted) |
| Build times out | the reserved address | quarantined, not returned to the pool: a machine may be answering on it | compensation policy, `IpamReservationReleaser` |
| Build times out | the possible machine | `needs_review`, never retried | `TheFailureMatrixTest`, timeout |
| Create succeeds | the address | committed only against a machine that exists | `TheVpsGoldenPathTest`, main path |
| Provider disagrees | the machine | drift recorded, **never** automatically repaired | `TheFailureMatrixTest`, drift |
| Termination | the address, the slot | released; hosting slot returned to the package | `TheHostingGoldenPathTest` |

The refusal row is new evidence, and it found the assertion that was missing:
counting `ip_assignments` after a failed build proves nothing, because the
handler never makes an assignment until the machine exists. What can leak is
the *reservation*, and the allocator's lookup of what a job already holds is
the only thing between a retry and a stranded address — which breakage F
demonstrates.

---

## 17. The service-state matrix

| Event | `ServiceStatus` after | Asserted in |
|---|---|---|
| paid, build queued | `provisioning` | `TheVpsGoldenPathTest` |
| build succeeded | `active` | `TheVpsGoldenPathTest`, `TheHostingGoldenPathTest` |
| purchase unplaceable | `pending` | `TheHostingGoldenPathTest` |
| payment declined | no service exists | `TheVpsGoldenPathTest` |
| hypervisor refused (transient) | not `active` | `TheFailureMatrixTest` |
| build timed out | not `active` | `TheFailureMatrixTest` |
| suspension | `suspended`, and the panel agrees | `TheHostingGoldenPathTest` |
| termination | `terminated`, slot returned | `TheHostingGoldenPathTest` |
| **provider task failed after an accepted create** | **`active`** | `TheFailureMatrixTest`, task failure |

The last row is a finding, not a pass. The create was accepted, the handler
wrote the machine and activated the service, and the later task failure moves
the *job* to review and leaves the *service* active. Whether an active service
whose build failed should be demoted — and whether the operation record is the
right place for that news instead — is a product decision. It is carried to
Gap 8 in §33 rather than decided inside a test.

---

## 18. Partial success and compensation

§23 of the brief: a stage that half-worked must not be reported as a whole
failure or a whole success.

| Partial outcome | Reported as | Not reported as |
|---|---|---|
| machine exists, DNS record does not | record `failed` with a redacted reason; **zone still active**; machine untouched | the purchase failed |
| service active, backup failed | backup `failed` with a reason; service still `active` | the service failed |
| create accepted, task failed | job `failed`/`needs_review` with a reason, drift recorded | a second build attempt |
| create timed out | `needs_review`, address quarantined | either success or failure |
| verification failed | backup `failed`, `verified` **not** true | a verified archive |

The DNS row carries a security assertion as well: the controlled zone client
quotes the request it sent, credentials and all, exactly as a real one does,
and the stored `failure_reason` is asserted not to contain the token.

---

## 19. The end-to-end failure matrix

`TheFailureMatrixTest` holds the matrix as data — `REQUIRED`, `ELSEWHERE`,
`HERE` — and a completeness gate that fails **in both directions**: a required
scenario covered nowhere fails, and a claim of coverage for a scenario that is
not required fails too. Eighteen scenarios are required; eleven are proved in
that file, seven by named tests elsewhere.

| Id | Scenario | Outcome the platform must produce | Proved by |
|---|---|---|---|
| `payment.declined` | the gateway refuses the card | order unpaid, nothing built | `TheVpsGoldenPathTest` |
| `payment.duplicate_webhook` | the same event twice | one payment, one service, one machine, no wallet credit | `TheVpsGoldenPathTest` |
| `provision.provider_refuses_before_create` | the hypervisor says no | `transient`, requeued, attempt recorded, address held for the retry | here |
| `provision.partial_create` | the call timed out; a machine may exist | `needs_review`, never retried | here |
| `provision.capacity_exhausted` | no node has room | `capacity`, waits, no refund | here |
| `provision.template_missing` | the image a plan needs | **see §33: no image is passed at all** | here |
| `provision.address_exhausted` | the pool is empty | `capacity`, not permanent | `IpAllocationTest` |
| `provision.duplicate_delivery` | the same message twice | one machine | `ARealWorkerConsumesTheQueueTest` |
| `provision.task_timeout` | the provider task never settles | left for a person, not declared finished | here |
| `dns.mutation_failed_after_create` | the machine exists, its name does not | record `failed`, zone intact, token not leaked | here |
| `backup.failed_after_activation` | the service is active, the backup is not | backup `failed`, service `active` | here |
| `backup.verification_failed` | the archive cannot be read back | backup `failed`, `verified` not true | here |
| `lifecycle.cancellation` | a cancelled service is torn down | retention sweep ends it, a worker destroys the machine | `TheNewSweepsRunOutsideThisProcessTest` |
| `reconciliation.drift` | provider and platform disagree | recorded once, never auto-repaired | here |
| `queue.retry` | a transient failure | rescheduled, not lost | `ARealWorkerConsumesTheQueueTest` |
| `provider.duplicate_terminal_result` | the same completion polled twice | one confirmed build | here |
| `event.out_of_order` | a completion after a cancellation | stays cancelled, no machine | here |
| `production.controlled_driver_refused` | a controlled driver in production | refuses, four controls per driver | `NoControlledDriverSurvivesProductionTest` |

One row moved during this gap, and the move is a finding.
`backup.verification_failed` was credited to a simulator-contract test — which
proves the controlled datastore can *report* a failed verification, a fact
about the simulator and not about the platform. What the platform does with
that answer was asserted nowhere. It is now, along with its positive twin and a
third test for the value nobody had pinned down: an ordinary finished backup
has `verified = null`, and null is asserted as a value rather than treated as
the absence of one. A platform that folded null into the pass bucket would tell
a customer their backups restore on the strength of never having checked.

---

## 20. The error taxonomy

The existing vocabulary, used unchanged. `FailureClass`: `transient`,
`permanent`, `timeout`, `capacity`. No new class was introduced, and in
particular **no `BLOCKED_PROVIDER`**.

| Fault the simulator produces | Class the platform assigns | Retried automatically |
|---|---|---|
| hypervisor refuses out loud | `transient` | yes, bounded |
| no node has room | `capacity` | yes, bounded |
| call stops being answered | `timeout` | **never** |
| provider task reports failure | `permanent` | no, `needs_review` |
| DNS refusal | record `failed` | no |
| DNS indeterminate | record `indeterminate` | no |
| backup refusal | `failed` | no |
| backup indeterminate | poll again, then `needs_review` | bounded by a ceiling |

The Timeout Rule is the load-bearing one: an unknown outcome is a person's
queue and not a retry, because retrying a create that may have worked builds a
second machine. Breakage I removes it and §29 records what happens.

---

## 21. Secret safety

Two secret-shaped canaries — a panel password and a WordPress admin password,
each an unmistakable token — are planted in a real hosting purchase and then
looked for in every place a secret could come to rest:

| Searched | Why it is on the list |
|---|---|
| the raw Redis message, read **before** the worker runs | a queue payload is stored plaintext and survives a crash |
| `provisioning_jobs.payload` | the job record outlives the build |
| `provisioning_attempts` | attempt records keep provider responses |
| `hosting_accounts` | the account row is read by the customer surface |
| `audit_entries` | an audit trail is exported and read by people |
| `services` | `resources` is a free-form document |
| `notifications` | a notification is delivered off-platform |
| `failed_jobs` | **count asserted zero**; a failed job payload is a plaintext copy nobody redacts |

A raw secret in a queue payload is treated as a security defect, not a tidiness
problem. Breakage H removes the redaction in `CreateProvisioningJob` and the
test fails, which is the evidence that the assertion is live rather than
decorative.

---

## 22. Zero external provider traffic

| Control | Where |
|---|---|
| `Http::preventStrayRequests()` over a complete VPS golden path | `WhatAGoldenPathMustNotDoTest` |
| every driver in every test is a controlled one | the reference estate's clusters, nodes, panels, datastores, zones and registrars are all `fake` |
| no real credential is read | the controlled drivers carry their own fixed fixtures; nothing reads a production secret store |
| production refusal | six tests, four controls per driver |

No test in this gap opened a socket to a provider. The only network this suite
touches is PostgreSQL and Redis on localhost.

---

## 23. Operator and customer visibility

`ATerminalFailureIsVisibleToBothSidesTest`. The worst outcome of a
cross-domain workflow is not a failure; it is a failure nobody can see.

| Surface | Must say | Must not say |
|---|---|---|
| the customer's operation record (`GET /api/v1/operations/{id}`) | `needs_review`, `is_terminal: true` | the node hostname, the task handle, the string `UPID:` |
| the operator queue (`GET /api/admin/provisioning/needs-review`) | the job, with `failure_class: timeout` | — |

Both are asserted over the same row, so a leak on one side and silence on the
other are both failures of the same test.

---

## 24. Preflight as the path's preconditions

`infra:preflight --mode=simulation --product=vps --json` is asserted to report
exactly the five checks a VPS build actually needs as `pass` — cluster, nodes,
storage, capacity, template — with `mode_label` of `SIMULATION`. (`mapping.network`
appears only when no pool exists, which is why it is not in the list: the
estate has one.)

This is the gate that keeps the golden paths honest about their own setup. If a
golden test could pass with an estate preflight calls incomplete, the test
would be asserting something about a fixture rather than about the product.

---

## 25. Simulation is not sellability

The same preflight report is asserted to have a **non-empty** readiness
section in which **no check passes**. Dependency and readiness checks stay
blocked in simulation mode, deliberately, and `ProductReadinessEvaluator` was
not touched by this gap.

So: a product whose whole path this gap exercises end to end still cannot
reach `READY_TO_SELL`. That is the intended relationship between the two, and
it is asserted rather than asserted-about.

---

## 26. Determinism: no test in this gap sleeps

| Where waiting could have been | What is used instead |
|---|---|
| waiting for a worker | `--stop-when-empty`: the process exits when the queue is drained, and the test waits on the process |
| waiting for a renewal date | `Carbon::setTestNow()` |
| waiting for a provider task | the poll count the controlled provider keeps, and a second `backups:reconcile` / `compute:poll-tasks` run |
| waiting for a retry ceiling | the attempt records, counted |
| waiting for a state | `waitUntil()` on a bounded observable condition, where a framework wait is genuinely needed |

No fixed sleep is used as a synchronisation device anywhere in the
golden-path group. The one `usleep` in the harness is the 50 ms poll interval
inside `waitUntil()`, which the brief permits: its exit condition is observable
state, and its 30-second deadline is a **failure**, never a pass. A test that
slept and then asserted would be either flaky or slow; this one returns as soon
as the condition holds.

---

## 27. Flake measurement

The policy is measure, do not re-run until green.

| Measured | Result |
|---|---|
| `--group=golden-path`, three consecutive runs from a clean state | §28 |
| the one known browser race | carried, not hidden — see below |

The browser reboot-acknowledgement race identified in Gap 6 is **still
present** and is still carried as a known flake. Gap 6 §31 records its
mechanism (an assertion whose window is a single HTTP round trip, proved
non-deterministic by the same assertion passing repeatedly in one process,
commit and database) and the three local measurements taken before the one
permitted job re-run. Nothing in this gap changed that test, weakened it, or
re-classified it. It is a browser-suite flake, it is measured, and it is
listed in §33 as carried.

---

## 28. Three clean runs

`php artisan test --group=golden-path`, from a clean working tree at the
ending code SHA, three times in succession:

| Run | Tests | Assertions | Result |
|---|---|---|---|
| 1 | 47 | 275 | pass |
| 2 | 47 | 275 | pass |
| 3 | 47 | 275 | pass |

Same counts each time, which is itself part of the evidence: a suite whose
assertion count moves between runs is doing something conditional.

---

## 29. Deliberate breakages, and their positive twins

Ten breakages, each applied **alone**, measured, and restored with
`git checkout --`. The working tree at the ending SHA contains none of them.

| | Breakage | Gate that must fail | Result |
|---|---|---|---|
| A | let a duplicate payment event produce a second financial effect | `TheVpsGoldenPathTest::a_redelivered_webhook_…` | **FAILS** — see below |
| B | let a duplicate provisioning delivery build a second machine | `ARealWorkerConsumesTheQueueTest::a_second_delivery_…` | **FAILS**: two machines |
| C | drop the provider's creation-completion handling | `TheFailureMatrixTest::a_task_that_never_settles_…` | **FAILS**: a failed hypervisor task reported as `succeeded` |
| D | hide a DNS failure after the machine exists | `TheFailureMatrixTest::a_name_that_cannot_be_published_…` | **FAILS**: record `active` with no reason |
| E | mark a backup verified when verification never ran | `TheFailureMatrixTest::a_backup_nobody_verified_…` | **FAILS**: `verified = true` on a backup nobody read back |
| F | leak the address a failed create was holding | `TheFailureMatrixTest::a_hypervisor_that_refuses_…` | **FAILS**: the second attempt takes a second address and strands the first |
| G | remove the store's production guard | `ControlledSimulationStateIsOptInAndNeverProductionTest` | **FAILS**: 8 of 10 |
| H | remove the redaction in `CreateProvisioningJob` | `WhatAGoldenPathMustNotDoTest::no_secret_shaped_value_…` | **FAILS**: canary found in the queue payload |
| I | retry a create whose outcome is unknown | `TheFailureMatrixTest::a_build_that_timed_out_…` | **FAILS**: `queued` instead of `needs_review` |
| J | disable durable controlled-simulation state | `AControlledProviderRemembersAcrossProcessesTest` | **FAILS**: 0 of 5 |

**A needed three attempts, and that is the interesting part of this section.**

| Layer removed | Gate |
|---|---|
| `IngestWebhookEvent`'s redelivery short-circuit | passed — no observable change |
| `RecordPaymentCapture`'s already-captured short-circuit | passed — no observable change |
| `SettleInvoice`'s exclusion of the capture in hand from the sum it is then added to | **FAILED** |

The first two are absorbed by layers beneath them: the unique index on
`(provider, provider_reference)` plus the update-existing branch, and a
settlement that recomputes what an invoice has been paid rather than
incrementing it. The third is the line the invariant actually rests on, and
removing it leaves every row count at one, the invoice paid, and the customer
holding the entire invoice total as wallet credit they never paid for — which
is why §14's wallet assertion exists. Before it was added, all three variants
passed.

**B needed two lines** for the same reason: removing `isClaimable()` from the
job claim is absorbed by the state machine's transition table, and a duplicate
delivery only builds a second machine when success stops being terminal as
well.

Positive twins (§100 of the brief): every gate above has a passing scenario on
the same path — a single webhook that settles once and builds once; a single
delivery that builds one machine; a provider task that completes and confirms
one build; a DNS record that publishes; a verification that passes and is
recorded as passed; a refused build whose retry reuses its address; the store
enabled, with five cross-process reads; a purchase whose secrets are present
in the panel and absent from every store; a transient failure that is retried
and succeeds. Each is in the same file as its negative, so neither can be
deleted without the other becoming obviously unbalanced.

---

## 30. Regression

<!-- REGRESSION TABLE -->

---

## 31. Exact-SHA CI

<!-- CI RECORD -->

---

## 32. What this gap changed in production code

Small, and listed in full.

| File | Change |
|---|---|
| `Shared/Infrastructure/Simulation/ControlledSimulationStore.php` | new: the one durable controlled-simulation store |
| `config/dedicated.php`, `config/hosting.php`, `config/backups.php`, `config/dns.php` | a `fake.state_path` block each (`dns` also `fake_reverse.state_path`), env-driven, unset by default |
| `FakeDedicatedProvider`, `FakeHostingProvider`, `FakeBackupProvider`, `FakeDnsProvider`, `FakeReverseDnsProvider` | each gained an optional store and a `restore()`/`remember()` pair around the state it already kept in memory |
| `FakeComputeProvider`, `FakeDomainRegistrarProvider` | their private hand-rolled state files replaced by the shared store |

Nothing else. No business action, listener, job, state machine, controller,
policy or migration was modified by this gap. The golden paths run against the
same code a purchase runs against, which is the point of §5.

Two things that are deliberately *not* here:

- No change to `ProductReadinessEvaluator` (§25).
- No new error class, no new provider capability, no fabricated contract (§34).

---

## 33. Carried gaps

Five, each with the classification the brief asks for.

| Finding | Classification | Evidence |
|---|---|---|
| **A purchased VPS is built with no OS image at all.** The create path never passes a template reference; the controlled hypervisor records no installed template and the job payload has no `template_reference` key. | **REAL CODE GAP — CARRIED TO GAP 8** | `TheFailureMatrixTest::a_purchased_machine_is_built_with_no_image_at_all_and_that_is_a_carried_gap`, which asserts the absence in both places |
| **A dedicated power operation is sent twice.** Two `Cycle` requests through the ordinary application path reach the BMC twice. Two resets is a defect even where the final power state is identical. | **REAL CODE GAP — CARRIED TO GAP 8** | `TheDedicatedGoldenPathTest`, provider calls counted |
| **A task-failed build leaves the service active.** The create was accepted, the machine row written and the service activated; the later task failure moves the job and not the service. | **PRODUCT DECISION — CARRIED TO GAP 8** | `TheFailureMatrixTest::a_task_that_never_settles_…`, asserted as `active` and annotated |
| **Nothing in the platform starts a backup verification.** `startVerification` exists on the contract and both drivers, `Verifying` has transitions out of it, and no action, job or command puts a row into it — so `verified` is null for every backup the platform has ever taken. | **REAL CODE GAP — CARRIED TO GAP 8** | §19; the reconciliation half is now covered, the initiating half does not exist |
| **The browser reboot-acknowledgement race.** Unchanged from Gap 6, measured there, not hidden here. | **KNOWN FLAKE — CARRIED** | Gap 6 report §31 |

None of these was made to pass. None was deleted, skipped, or re-classified to
look finished.

---

## 34. Contracts that stay unsupported

| Contract | Status | What this gap did about it |
|---|---|---|
| `.sy` registrar | `NOT_IMPLEMENTED — PROVIDER CONTRACT UNAVAILABLE` | nothing. No `.sy` name is registered, renewed, transferred, priced or asserted anywhere in this gap. **`.sy` registration is not tested.** |
| every capability a controlled driver declares it cannot do | unchanged | the declarations are asserted, not implemented around |

Nothing was invented to make a path complete. Where a contract is unavailable,
the path stops at the boundary and says so.

---

## 35. Status, and the boundary this gap does not cross

**What is true.**

| Claim | Status |
|---|---|
| The cross-domain workflows above exist in code | `CODE_COMPLETE` |
| They are covered by tests that fail when the behaviour is removed | `TESTED` |
| They were executed end to end, across real process boundaries, against controlled providers and the reference estate | `RUNTIME_VERIFIED (local controlled end-to-end simulation only)` |

**What is not true, and is not claimed.**

| Claim | Status |
|---|---|
| Real infrastructure | `REAL_INFRA_VERIFIED = NONE` |
| Real payment | `REAL_PAYMENT_VERIFIED = NONE` |
| Real registrar | `REAL_REGISTRAR_VERIFIED = NONE` |
| Real hosting | `REAL_HOSTING_VERIFIED = NONE` |
| Sellable | `READY_TO_SELL = NONE` |

Every provider in this gap is a controlled simulator. No credential, socket,
machine, domain, zone, invoice or fils in it is real. A simulator passing is
evidence that this platform's own state machines, queues, ledgers and
compensations agree with each other — nothing more, and it is worth having for
exactly that. **Nothing here is production verification, and a simulator
passing will never make it so.**

Five findings are carried forward rather than closed (§33), including two real
code gaps that a customer would notice: a VPS built with no operating system,
and a power operation sent to a chassis twice.

---

## 36. Stop

Gap 7 ends here. No real infrastructure was connected, no real credential
used, no money charged, no domain registered, no DNS published, no VM created,
no IaC applied, no production readiness declared, and no Gap 8 work started.
