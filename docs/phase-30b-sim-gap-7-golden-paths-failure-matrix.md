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
| Address bound | `AllocateAddress` (IPAM) | — | `ip_addresses`, `ip_assignments` | assigned |
| Hosting account created | `CreateHostingAccountHandler` | `HostingProviderFactory` → `FakeHostingProvider` | `hosting_accounts` | `ServiceStatus::Active` |
| WordPress installed | `InstallWordPressHandler` | the same hosting provider, by architecture | `wordpress_sites` | site recorded; service unaffected |
| Dedicated delivered | `ProvisionDedicatedHandler` | `DedicatedProviderFactory` → `FakeDedicatedProvider` | `dedicated_servers`, `managed_servers` | `ServiceStatus::Active` |
| Domain registered | `RegisterDomainOnPayment` → `RegisterDomainAtRegistrar` | `RegistrarFactory` → `FakeDomainRegistrarProvider` | `domains`, `domain_operations` | registered |
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
