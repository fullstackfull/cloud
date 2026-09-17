# Phase 30B-SIM — Gap 6

## Stateful provider simulator contract closure

Controlled driver completeness · failure-mode completeness · reference-estate
simulation enablement.

---

## 1. Provenance

This document is the record of Gap 6 of Phase 30B-SIM. It answers one
question, and the question is narrower than it sounds:

> Can Lynomia exercise every provider contract it claims to implement,
> locally and deterministically, without real infrastructure?

Not "does the platform work". Not "is it ready to sell". Whether the
*software* can be driven through every provider contract it has written,
on a laptop, with no credential, no endpoint and no hardware — and where it
cannot, exactly which contract is missing.

Everything below was measured in this repository at the SHA named in §2. No
provider was contacted. No credential was read. No API, error code, endpoint
path, status name, identifier format or retry guarantee was invented: every
simulated behaviour cited in §9 names the interface, adapter, fixture or
document it came from, and where no such source exists the gap is recorded as
`NOT_IMPLEMENTED` with the contract that is missing.

**Nothing in this document is evidence about real infrastructure.** A
simulator answering correctly is evidence about this codebase. The verification
boundary is restated in §33 and it has not moved.

---

## 2. Starting and ending SHA

| | |
|---|---|
| Branch | `claude/hv-t6hq1p` |
| Starting HEAD | `680d36633d6b2e92f1792012bd47ea201883d1f3` |
| Working tree at entry | clean (`git status --short` empty) |
| Commits after Gap 5 at entry | none |
| Ending HEAD | the commit that carries this document — its SHA cannot be written inside itself, so it is reported with the CI run that verified it |

---

## 3. Gap 5 CI gate

| | |
|---|---|
| SHA | `680d36633d6b2e92f1792012bd47ea201883d1f3` |
| Run | 173 |
| Run id | 35163349177 |
| Status | completed |
| Conclusion | success |
| Jobs | 9 / 9 successful |

Verified job by job before this gap began. Gaps 1–5 gates are re-run as part
of the regression in §31.

---

## 4. Provider contract matrix

Read the columns as they are written. `CONTROLLED DRIVER` has two rows for
most families on purpose, because this repository has **two separate
registries** and conflating them is the single largest finding of this audit:

* the **adapter registry** — a per-family factory that resolves the class
  which does the work, keyed by a cluster row's `driver`, a hosting node's
  `panel`, a BMC endpoint's `protocol`, or a `config()` value;
* the **provider registry** — `ProviderCatalogue` plus `provider_instances`,
  which is what the Control Center, connection testing, capability discovery,
  preflight and product readiness all consult.

A family can be fully controlled in the first and entirely absent from the
second. Five of them are.

### 4.1 Compute

| Column | Value |
|---|---|
| FAMILY | Compute |
| PRODUCT/DOMAIN | VPS |
| REAL DRIVER | `proxmox` |
| REAL ADAPTER CLASS | `ProxmoxComputeProvider` (1241 lines) |
| INTERFACE/PORT | `Compute\Domain\Contracts\ComputeProvider` — 18 operations |
| CONTROLLED DRIVER (adapter registry) | `ComputeDriver::Fake` = `fake`, from `compute_clusters.driver`, resolved by `ComputeProviderFactory` |
| CONTROLLED DRIVER (provider registry) | **none** |
| SIMULATOR CLASS | `FakeComputeProvider` (790 lines) |
| STATEFUL? | Yes — machines and tombstones per node; optional cross-process file (`compute.fake.state_path`) |
| PROVIDER-INSTANCE REGISTERED? | **No** |
| IDENTITY TESTER? | `proxmox` → `ProxmoxConnectionTester`. Controlled: none |
| CAPABILITIES | `ProviderCategory::Compute` asks 13: create, start, stop, reboot, resize, reinstall, suspend, unsuspend, console, destroy, templates, task_polling, gpu_passthrough |
| SUCCESS OPERATIONS | All 18 interface methods implemented |
| FAILURE OPERATIONS | `provider-fail` (refusal), `task-fail` (accepted then fails), `timeout` (indeterminate), `undestroyable` (indeterminate destroy), locked-machine refusal on every power operation and on reinstall, unknown machine, empty resize |
| IDEMPOTENCY | Create keyed on the caller's `vmId`; the UPID is `crc32`-derived from (node, type, id) so a retried create yields the same task id. `suspendVm`/`liftSuspension` re-appliable. No provider-side idempotency key exists in the contract |
| ASYNC | Yes, and genuinely: a real UPID with the start time in hex, read back by `getTask()`, so `compute.fake.task_delay_seconds` produces a task that is *Running* for a while |
| RECONCILIATION | `getVm()` returns null after a destroy; `listVms()` sorted; tombstones survive in the shared file |
| CONTRACT SOURCE | `ComputeProvider` interface; `ProxmoxComputeProvider` (UPID field layout, `SUSPENSION_LOCK` shared as one constant, disk growth semantics) |
| STATUS | PARTIALLY_COMPLETE — mature simulator, absent from the provider registry |

### 4.2 Dedicated / BMC

| Column | Value |
|---|---|
| FAMILY | Dedicated / BMC |
| PRODUCT/DOMAIN | Dedicated servers |
| REAL DRIVER | `redfish`, `ilo`, `ipmi` |
| REAL ADAPTER CLASS | `RedfishDedicatedProvider` (903), `IloDedicatedProvider` (197, extends Redfish), `IpmiDedicatedProvider` (523) |
| INTERFACE/PORT | `Dedicated\Domain\Contracts\DedicatedProvider` — 9 operations |
| CONTROLLED DRIVER (adapter registry) | `config('dedicated.provider') === 'fake'` — deliberately not a `bmc_endpoints` value, so no single row can silently unmanage a machine |
| CONTROLLED DRIVER (provider registry) | `fake_bmc` (category `bmc`) |
| SIMULATOR CLASS | `FakeDedicatedProvider` (312 lines) |
| STATEFUL? | Yes, in instance memory only — power state and the one-time PXE flag |
| PROVIDER-INSTANCE REGISTERED? | Yes. The reference estate declares `ref-provider-alpha-bmc` |
| IDENTITY TESTER? | `redfish`/`ilo` → `RedfishConnectionTester`, `ipmi` → `IpmiConnectionTester`, `fake_bmc` → `FakeConnectionTester` (non-production only) |
| CAPABILITIES | `ProviderCategory::Bmc` asks 5: inventory, power_state, power_control, boot_override, firmware |
| SUCCESS OPERATIONS | hardwareHealth, powerState, powerOn, powerOff, gracefulShutdown, reset, setOneTimePxeBoot, bootOrder, firmwareInventory |
| FAILURE OPERATIONS | `timeout` (indeterminate), `provider-fail` (refusal), `unhealthy` (a critical disk in an otherwise healthy chassis) |
| IDEMPOTENCY | Power operations are unconditional at the adapter, which **matches** the real Redfish adapter: `sendReset()` POSTs a `ResetType` with no state guard. No platform idempotency key exists for power — see §28 |
| ASYNC | None. Redfish returns a task only when it cannot finish inline, and the real adapter says so; the fake invents none |
| RECONCILIATION | `powerState()` is the observation; PXE override is consumed by `reset`/`power_on` exactly as firmware consumes it |
| CONTRACT SOURCE | `DedicatedProvider` interface; `RedfishDedicatedProvider::sendReset()` and `setOneTimePxeBoot()` (`BootSourceOverrideEnabled: Once`) |
| STATUS | PARTIALLY_COMPLETE — state is not durable across processes; §28 records the power idempotency verdict |

### 4.3 Shared hosting

| Column | Value |
|---|---|
| FAMILY | Shared hosting |
| PRODUCT/DOMAIN | cPanel / DirectAdmin hosting |
| REAL DRIVER | `cpanel`, `directadmin` |
| REAL ADAPTER CLASS | `CpanelHostingProvider` (786), `DirectAdminHostingProvider` (806) |
| INTERFACE/PORT | `SharedHosting\Domain\Contracts\HostingProvider` — 12 operations |
| CONTROLLED DRIVER (adapter registry) | `HostingPanel::Fake` = `fake`, from `hosting_nodes.panel`, resolved by `HostingProviderFactory` |
| CONTROLLED DRIVER (provider registry) | **none** |
| SIMULATOR CLASS | `FakeHostingProvider` (549 lines) |
| STATEFUL? | Yes, in instance memory only — accounts and WordPress installations per node |
| PROVIDER-INSTANCE REGISTERED? | **No** |
| IDENTITY TESTER? | `cpanel` → `CpanelConnectionTester`, `directadmin` → `DirectAdminConnectionTester`. Controlled: none |
| CAPABILITIES | `ProviderCategory::Hosting` asks 7: create_account, suspend, unsuspend, terminate, sso, change_package, usage |
| SUCCESS OPERATIONS | All 12 interface methods |
| FAILURE OPERATIONS | `provider-fail`, `timeout` (indeterminate), `no-usage` (a panel that answers with no measurements), duplicate account refusal, unknown account, node-level markers on `listAccounts` (the call reconciliation makes), and the WordPress markers `wp-refused`, `wp-timeout`, `copy-refused`, `copy-timeout`, `push-refused`, `push-timeout` |
| IDEMPOTENCY | `createAccount` refuses a duplicate username rather than succeeding twice, which is what both panels do and what the platform depends on |
| ASYNC | None. Both panels answer inline and the real adapters return no task id |
| RECONCILIATION | `listAccounts()` is the sweep; the suspension flag survives suspend/unsuspend with disk usage intact |
| CONTRACT SOURCE | `HostingProvider`, `WordPressInstaller`, `WordPressStagingProvider` interfaces; the licence answer is read from `hosting_nodes.panel_licensed` rather than asserted |
| STATUS | PARTIALLY_COMPLETE — absent from the provider registry; state not durable across processes (the `copyWordPress` docblock already documents working around this) |

### 4.4 WordPress

| Column | Value |
|---|---|
| FAMILY | WordPress |
| PRODUCT/DOMAIN | Managed WordPress |
| REAL DRIVER | **none** |
| REAL ADAPTER CLASS | **none.** Neither cPanel nor DirectAdmin adapter implements the WordPress contracts |
| INTERFACE/PORT | `WordPressInstaller` (2 operations), `WordPressStagingProvider` (2 operations) |
| CONTROLLED DRIVER | `FakeHostingProvider`, via the hosting adapter registry |
| SIMULATOR CLASS | `FakeHostingProvider` — the same class, because WordPress is layered on the hosting contract and is not a separate family (§32 of the brief) |
| STATEFUL? | Yes — installations per node and domain |
| PROVIDER-INSTANCE REGISTERED? | **No** |
| IDENTITY TESTER? | None; there is no WordPress endpoint distinct from the panel's |
| CAPABILITIES | `ProviderCategory::WordPressInstaller` asks 7: install, uninstall, version, ssl, staging, clone, push_to_production. **The contracts model four of them.** `uninstall` and `ssl` have no method on any interface in this repository |
| SUCCESS OPERATIONS | installWordPress, wordPressInstallation, copyWordPress, pushWordPressToProduction |
| FAILURE OPERATIONS | refusal and indeterminate for install, copy and push. The install and copy record the installation **before** throwing the indeterminate, which is the honest model: a toolkit that stops answering has usually finished the work |
| IDEMPOTENCY | `pushWordPressToProduction` uses `??=`, so a repeat returns the same installation |
| ASYNC | None in the contract |
| RECONCILIATION | `wordPressInstallation()` answers `exists: false` rather than throwing, which is how reconciliation learns an install did not happen |
| CONTRACT SOURCE | The two interfaces; `ProductRequirements` line 71 for the capability set |
| STATUS | CONTROLLED_ONLY. Real side `NOT_IMPLEMENTED`; `uninstall` and `ssl` are `NOT_IMPLEMENTED — CONTRACT ABSENT` (§27) |

### 4.5 Backup

| Column | Value |
|---|---|
| FAMILY | Backup |
| PRODUCT/DOMAIN | VPS backups, file-level restore |
| REAL DRIVER | `proxmox_backup` |
| REAL ADAPTER CLASS | `ProxmoxBackupProvider` (364) — implements `BackupProvider` only |
| INTERFACE/PORT | `BackupProvider` (8 operations) and `FileLevelBackupProvider` (4 operations) |
| CONTROLLED DRIVER (adapter registry) | `config('billing.providers.backup') === 'fake'`, resolved by `BackupProviderFactory` |
| CONTROLLED DRIVER (provider registry) | **none** |
| SIMULATOR CLASS | `FakeBackupProvider` (302) — implements **both** interfaces |
| STATEFUL? | Yes, in instance memory only — tasks and stored archives |
| PROVIDER-INSTANCE REGISTERED? | **No** |
| IDENTITY TESTER? | `proxmox_backup` → `ProxmoxBackupConnectionTester`. Controlled: none |
| CAPABILITIES | `ProviderCategory::Backup` asks 7: create, restore, delete, verify, retention, file_browse, file_restore |
| SUCCESS OPERATIONS | startBackup, taskState, startVerification, startRestore, listBackups, deleteBackup, listFiles, readFile, startFileRestore |
| FAILURE OPERATIONS | `backup-refused`, `backup-timeout`, `backup-fails` (accepted, runs, then fails), unknown task reported as a refusal rather than "still running", and the file-level refusals: not found, symlink, not a directory, not a file, too large |
| IDEMPOTENCY | None modelled; the contract promises none |
| ASYNC | Yes — `pollsBeforeSettling` (default 1) makes a task report Running before it settles, so a reconciler that polls once is visibly wrong |
| RECONCILIATION | `listBackups()` after a settled task; `created != verified` preserved because `RemoteBackup::verified` stays `null` |
| CONTRACT SOURCE | Both interfaces; `ProxmoxBackupProvider`; `BackupPath` and `BackupFileKind` for the archive tree |
| STATUS | PARTIALLY_COMPLETE — absent from the provider registry; verification and restore have no failure path; the stored archive's `verified` flag never changes; real side has **no** file-level implementation |

### 4.6 DNS (forward)

| Column | Value |
|---|---|
| FAMILY | DNS |
| PRODUCT/DOMAIN | Authoritative DNS for customer names |
| REAL DRIVER | `cloudflare` |
| REAL ADAPTER CLASS | `CloudflareDnsProvider` (332) |
| INTERFACE/PORT | `Dns\Domain\Contracts\DnsProvider` — 9 operations |
| CONTROLLED DRIVER (adapter registry) | `config('billing.providers.dns') === 'fake'`, resolved by `DnsProviderFactory` |
| CONTROLLED DRIVER (provider registry) | **`fake`** — the only controlled driver catalogued in a category that also has a working simulator |
| SIMULATOR CLASS | `FakeDnsProvider` (222) |
| STATEFUL? | Yes, in instance memory only — zones and records |
| PROVIDER-INSTANCE REGISTERED? | Yes. The reference estate declares `ref-provider-alpha-dns` |
| IDENTITY TESTER? | `cloudflare` → `CloudflareConnectionTester`; `fake` → `FakeConnectionTester` (non-production only) |
| CAPABILITIES | `ProviderCategory::Dns` asks 4: create_zone, delete_zone, records, reconcile |
| SUCCESS OPERATIONS | name, zones, findZone, zoneFor, canCreateZones, createZone, deleteZone, records, publish, delete |
| FAILURE OPERATIONS | `dns-refused` and `dns-timeout`, and deliberately on **every** operation including reads — a provider that has stopped answering has stopped answering questions too |
| IDEMPOTENCY | `publish()` keeps the identifier a repeat publish already has: one record, one id, whether written once or three times |
| ASYNC | None in the contract |
| RECONCILIATION | `records()` with type and name filters is what the reconciler reads |
| CONTRACT SOURCE | `DnsProvider` interface; `DnsRecord`/`DnsZone` value objects |
| STATUS | COMPLETE against the contract, except cross-process state |

### 4.7 Reverse DNS

| Column | Value |
|---|---|
| FAMILY | Reverse DNS |
| PRODUCT/DOMAIN | PTR records for allocated addresses |
| REAL DRIVER | `cloudflare_rdns` |
| REAL ADAPTER CLASS | `CloudflareReverseDnsProvider` (232) |
| INTERFACE/PORT | `Ipam\Domain\Contracts\ReverseDnsProvider` — 1 operation (`publish`) |
| CONTROLLED DRIVER (adapter registry) | `config('billing.providers.dns') === 'fake'` — one key drives forward and reverse, so a deployment cannot hold two providers that disagree about the account |
| CONTROLLED DRIVER (provider registry) | **none** |
| SIMULATOR CLASS | `FakeReverseDnsProvider` (98) |
| STATEFUL? | Yes, in instance memory — one hostname per address |
| PROVIDER-INSTANCE REGISTERED? | **No** |
| IDENTITY TESTER? | `cloudflare_rdns` → `CloudflareConnectionTester`. Controlled: none |
| CAPABILITIES | `ProviderCategory::ReverseDns` asks 2: set_ptr, clear_ptr |
| SUCCESS OPERATIONS | publish |
| FAILURE OPERATIONS | `ptr-refused`, `ptr-timeout` |
| IDEMPOTENCY | A repeat replaces rather than appends, which is the idempotence the interface promises |
| ASYNC | None |
| RECONCILIATION | `publishedFor()` / `publishedCount()` are test observers, not contract methods |
| CONTRACT SOURCE | `ReverseDnsProvider` interface |
| STATUS | PARTIALLY_COMPLETE — `clear_ptr` is a capability the interface does not model (§27) |

### 4.8 Registrar / domains

| Column | Value |
|---|---|
| FAMILY | Registrar |
| PRODUCT/DOMAIN | Domain registration, renewal, transfer, redemption |
| REAL DRIVER | `sy_registry` |
| REAL ADAPTER CLASS | `SyRegistryProvider` (177) — a deliberate placeholder. Every capability answers false and every operation refuses, because the `.sy` registry's technical contract is not available to this project. Status `BLOCKED_LICENCE` |
| INTERFACE/PORT | `Domains\Domain\Contracts\DomainRegistrarProvider` — 16 operations |
| CONTROLLED DRIVER (adapter registry) | `FakeDomainRegistrarProvider::NAME` = `fake`, resolved by `DomainRegistrarFactory` per TLD row |
| CONTROLLED DRIVER (provider registry) | **none** |
| SIMULATOR CLASS | `FakeDomainRegistrarProvider` (597) |
| STATEFUL? | Yes, **durably** — `domains.fake.state_path` puts the portfolio in a file so a worker renews what a request bought |
| PROVIDER-INSTANCE REGISTERED? | **No** |
| IDENTITY TESTER? | `sy_registry` is excused with a recorded reason; controlled: none |
| CAPABILITIES | `ProviderCategory::Registrar` asks 13: search, availability, register, renew, transfer, nameservers, contacts, lock, auth_code, redemption, premium, held_names |
| SUCCESS OPERATIONS | checkAvailability, register, renew, inspect, setNameservers, setContacts, setTransferLock, authorisationCode, startTransfer, transferStatus, redeem, heldNames, supports, supportedTlds, redemptionSupport |
| FAILURE OPERATIONS | `-taken`, `-timeout` (registers the name and tells the caller nothing — the case only reconciliation can settle), `-unreachable` (availability itself does not answer), `-premium`, `-unknown`, `-refused`, `-slow` (a transfer that stays pending for ever) |
| IDEMPOTENCY | `register()` is idempotent on the name: a redelivered job gets the existing registration rather than a second term |
| ASYNC | Transfers are the async model: `pending` → `completed` on the second look, or `pending` for ever under `-slow` |
| RECONCILIATION | `seedHolding()` and `forgetHolding()` exist precisely to arrange both halves — the registrar holding a name the platform has no row for, and the reverse |
| CONTRACT SOURCE | `DomainRegistrarProvider` interface and the DTOs it names. **No `.sy` behaviour is modelled or claimed** |
| STATUS | COMPLETE against the generic contract; `NOT_IMPLEMENTED — PROVIDER CONTRACT UNAVAILABLE` for `.sy` |

### 4.9 Payment

| Column | Value |
|---|---|
| FAMILY | Payment |
| PRODUCT/DOMAIN | Card payment, refund, webhook settlement |
| REAL DRIVER | `stripe` |
| REAL ADAPTER CLASS | `StripePaymentProvider` (523) |
| INTERFACE/PORT | `Payments\Domain\Contracts\PaymentProvider` |
| CONTROLLED DRIVER (adapter registry) | `FakePaymentProvider::NAME` = `fake`, resolved by `PaymentProviderRegistry` (lookup by the name persisted in `transactions.provider`) |
| CONTROLLED DRIVER (provider registry) | **none** |
| SIMULATOR CLASS | `FakePaymentProvider` (674) plus `ControlledGatewayController` (225) |
| STATEFUL? | Mostly a pure function of the reference — the outcome, amount and customer are encoded into it so a worker that never saw the request still answers correctly. Gateway decisions are durable via `payments.fake.state_path` |
| PROVIDER-INSTANCE REGISTERED? | **No** |
| IDENTITY TESTER? | `stripe` → `StripeConnectionTester`. Controlled: none |
| CAPABILITIES | `ProviderCategory::Payment` asks 4: charge, refund, webhook, currencies |
| SUCCESS OPERATIONS | createPaymentIntent, retrievePayment, refund, verifyWebhookSignature, parseWebhookEvent, supportsCurrency, plus `emitWebhook`/`signPayload` as test seams |
| FAILURE OPERATIONS | Four decline codes selected by the last two minor units (card_declined, insufficient_funds, expired_card, processing_error); unsupported currency; and every signature failure path — no header, unparseable, wrong signature, stale timestamp |
| IDEMPOTENCY | The refund reference is derived from the caller's idempotency key, not from the amount, so two deliberate refunds of one amount are two refunds and a replay is one |
| ASYNC | `requires_action` → redirect or client confirmation → gateway decision → signed webhook. Both next-action shapes are configurable so both portal branches are exercised |
| RECONCILIATION | `retrievePayment()` is the server-side truth; a gateway decision recorded in the state file outranks the reference |
| CONTRACT SOURCE | `PaymentProvider` interface; `StripePaymentProvider`; the signature scheme mirrors the documented `t=,v1=` HMAC header and 300-second tolerance |
| STATUS | COMPLETE against the contract; absent from the provider registry |

### 4.10 Transactional email

| Column | Value |
|---|---|
| FAMILY | Email (relay) |
| REAL DRIVER | `smtp` |
| REAL ADAPTER CLASS | `LaravelMailTransport` (39) |
| INTERFACE/PORT | `TransactionalEmailProvider` — 1 operation (`send`) |
| CONTROLLED DRIVER | None, and none is wanted — see §27 |
| SIMULATOR CLASS | None. The framework's own `Mail::fake()` is the seam the suite already uses |
| STATUS | OUT_OF_SCOPE_BY_CONTRACT. The relay is a deployment setting (`MAIL_HOST`) reached over SMTP, in some deployments the `log` driver. It has no endpoint to dial, is already excused from having an identity tester with a recorded reason, and is not a stateful infrastructure provider. Keeping it out of provider simulation is the decision, not an omission |

### 4.11 Monitoring

| Column | Value |
|---|---|
| FAMILY | Monitoring |
| REAL DRIVER | None catalogued |
| INTERFACE/PORT | `MetricsCollector` — 16 internal collectors |
| CONTROLLED DRIVER | Not applicable: the category exists so a product can name what it needs; the collectors are this application's own registry endpoint, not a provider it dials |
| STATUS | OUT_OF_SCOPE_BY_ARCHITECTURE |

### 4.12 IPAM

| Column | Value |
|---|---|
| FAMILY | IPAM |
| PRODUCT/DOMAIN | Address allocation for VPS and dedicated provisioning |
| REAL DRIVER | Not a provider. `Network`, `Subnet`, `IpPool`, `IpAddress` are this platform's own tables; `SeedSubnetAddresses` expands them and `IpamReservationReleaser` returns them |
| EXTERNAL PORT | Only `ReverseDnsProvider` (§4.7) |
| CONTROLLED DRIVER | None, and none should exist (§34 of the brief) |
| STATUS | INTERNAL_STATE. Allocation, release, exhaustion and collision prevention are exercised through the real models, which is what the provisioning flows use |

### 4.13 Prepared seats with no adapter

| Family | Interface | Real adapter | Simulator | Status |
|---|---|---|---|---|
| CDN | `CdnProvider` | none | none | `NOT_IMPLEMENTED`. `EveryPreparedCategoryHasAContractTest` **forbids** any implementation of this contract, fake included |
| Object storage | `ObjectStorageProvider` | none | none | `NOT_IMPLEMENTED`, same gate |
| Email hosting | `EmailHostingProvider` | none | none | `NOT_IMPLEMENTED`, same gate |
| Load balancer | none | none | none | `NOT_IMPLEMENTED — CONTRACT ABSENT` |
| Certificates | none | none | none | `NOT_IMPLEMENTED — CONTRACT ABSENT` |
| Cluster lifecycle | none | none | none | `NOT_IMPLEMENTED — CONTRACT ABSENT` |

Writing a simulator for the first three would break an existing architecture
test that exists to stop an adapter being written from documentation. That
test is the source; this gap does not overrule it.

### 4.14 Non-category provider seams

| Seam | Interface | Real | Controlled | Notes |
|---|---|---|---|---|
| Deployment | `DeploymentController` | `AnsibleDeploymentController` | `FakeDeploymentController` (118) | Resolved by `DeploymentControllerFactory` from config; not a `ProviderCategory` |
| Provisioning | `ProvisioningHandler` | 12 real handlers | `FakeProvisioningHandler` (146) | An engine seam, not a provider |
| Reservations | `ResourceReservationReleaser` | `IpamReservationReleaser`, `NodeCapacityReleaser` | `FakeResourceReservationReleaser` (96) | Internal |
| Site reachability | `SiteProbe` | `HttpSiteProbe` | `FakeSiteProbe` (59) | Internal |
| Connection testing | `ConnectionTester` | 9 real testers | `FakeConnectionTester` (307) | Answers for `fake` and `fake_bmc` |

---

## 5. Existing simulator inventory

Measured, not remembered. Every one of these was read in full for this audit.

### 5.1 `FakeComputeProvider` — 790 lines

* **State model.** `machines[node][providerId] => RemoteVmState`, plus a
  tombstone map so absence can be asserted. Optional cross-process file at
  `compute.fake.state_path`, written to a neighbour and renamed so a reader
  sees the old fleet or the new one and never half of either.
* **Operations.** All 18 of `ComputeProvider`.
* **Errors.** `ComputeProviderException::requestFailed` with
  `indeterminate: true` where the outcome is unknown;
  `unexpectedResponse` for a task id that is not a UPID.
* **Fault injection.** Hostname markers: `provider-fail`, `task-fail`,
  `timeout`, `undestroyable`. Plus `ComputeProviderFactory::swap()` for
  behaviour configuration cannot express.
* **Idempotency.** Create keyed on the caller's `vmId`; the UPID is derived
  from the request rather than random.
* **Async tasks.** Real UPIDs with the start time in hex; `getTask()` reads it
  back, so the polling branch of provisioning is genuinely executed.
* **Observability.** Machines carry `raw['fake' => true]`; nodes carry
  `capabilities: ['fake' => true]`.
* **Known gaps.** No fault injection on the read paths (`listVms`,
  `listNodes`, `getTask`) except by swapping the adapter — so
  "what does the inventory sweep do when the cluster is down" is reachable
  only from a test that replaces the object. `listNodes()` is configuration,
  not simulator state, so node capacity does not move when machines are
  created. Not registered in the provider catalogue.

### 5.2 `FakeDedicatedProvider` — 312 lines

* **State model.** `power[endpointId] => PowerState` (default `Off`, which is
  what a freshly racked machine is) and `pxeArmed[endpointId] => bool`. Memory
  only.
* **Operations.** All 9 of `DedicatedProvider`.
* **Errors.** `DedicatedProviderException::requestFailed`, with
  `indeterminate: true` for the timeout.
* **Fault injection.** Address markers `timeout`, `provider-fail`,
  `unhealthy`, plus `DedicatedProviderFactory::swap()`.
* **Idempotency.** Operations are unconditional, matching the real adapter.
* **Async tasks.** None, matching the real adapter.
* **Observability.** MAC derived from the endpoint id in the
  locally-administered range; serial derived from the endpoint id; `Once`
  stated in the PXE metadata exactly as the real adapter states it.
* **Known gaps.** State dies with the process, so power state and the
  one-time PXE flag cannot be observed across a request and a worker. This is
  the measurable half of §28.

### 5.3 `FakeHostingProvider` — 549 lines

* **State model.** `accounts[nodeKey][username] => RemoteAccount` and
  `installations[nodeKey][domain] => WordPressInstallation`. Memory only.
* **Operations.** All 12 of `HostingProvider`, all 2 of `WordPressInstaller`,
  all 2 of `WordPressStagingProvider`.
* **Errors.** `HostingProviderException::requestFailed`, `indeterminate` for
  the timeout markers.
* **Fault injection.** Username markers `provider-fail`, `timeout`,
  `no-usage`; node hostname markers for `listAccounts`; domain markers
  `wp-refused`, `wp-timeout`, `copy-refused`, `copy-timeout`,
  `push-refused`, `push-timeout`.
* **Idempotency.** Duplicate `createAccount` refused; `pushWordPressToProduction`
  returns the same installation on a repeat.
* **Async tasks.** None, matching both panels.
* **Observability.** Usage derived deterministically from the username, so a
  worker that never saw the create reports the same figures; the SSO URL is
  shaped like a real one so anything that logs it is caught by the same
  redaction rules.
* **Known gaps.** No durable state — and the class already documents working
  around it: `copyWordPress()` deliberately does not require the source to be
  in this process's memory, "which is every copy a browser asks for". Not
  registered in the provider catalogue.

### 5.4 `FakeBackupProvider` — 302 lines

* **State model.** `tasks[taskId] => {failing, polls, request}` and
  `stored[datastore|vmid] => RemoteBackup[]`, plus a constant archive tree for
  the file-level paths. Memory only.
* **Operations.** All of `BackupProvider` and all of
  `FileLevelBackupProvider`.
* **Errors.** `BackupProviderException::refused`/`timedOut`;
  `BackupFileRefusedException` in five shapes.
* **Fault injection.** Notes markers `backup-refused`, `backup-timeout`,
  `backup-fails`; the public `pollsBeforeSettling` knob; three marker paths
  inside the archive tree.
* **Idempotency.** Not modelled; the contract promises none.
* **Async tasks.** Yes, and the default of one poll before settling means a
  reconciler that polls once is visibly wrong.
* **Observability.** `created != verified` is preserved — a completed backup
  is stored with `verified: null`.
* **Known gaps.** `startVerification()` and `startRestore()` cannot fail and
  check no markers. A verification task that succeeds does not mark the stored
  archive verified, so "snapshot exists but unverified" and "verification
  failed" — both named in the brief and both represented in the domain — are
  not reachable through the simulator. Task state dies with the process, and
  an unknown task is refused, so a worker polling a task a web request started
  gets a refusal rather than a status.

### 5.5 `FakeDnsProvider` — 222 lines

* **State model.** `zones[name] => DnsZone`, `records[zoneId][type|name] =>
  DnsRecord`, an incrementing id. Memory only.
* **Operations.** All 9 of `DnsProvider`, plus `withZone()` to arrange what
  the account holds without creating it — because holding a zone and being
  allowed to create one are different capabilities.
* **Errors.** `DnsProviderException::refused`/`timedOut`. The refusal quotes a
  credential-shaped string back, which is what a real zone client does, and is
  what makes redaction testable rather than assumed.
* **Fault injection.** Name markers `dns-refused`, `dns-timeout`, applied to
  reads as well as writes.
* **Idempotency.** A repeat publish keeps the existing record id.
* **Async tasks.** None in the contract.
* **Known gaps.** No durable state, so a reconciliation job in another process
  starts with an empty account.

### 5.6 `FakeReverseDnsProvider` — 98 lines

* **State model.** `published[address] => hostname`. Memory only.
* **Operations.** The one the interface has.
* **Errors / fault injection.** `ptr-refused`, `ptr-timeout`, with a
  credential-shaped provider message.
* **Idempotency.** A repeat replaces.
* **Known gaps.** No durable state. `clear_ptr` is asked about as a capability
  and modelled by no interface method.

### 5.7 `FakeDomainRegistrarProvider` — 597 lines

* **State model.** `held[name] => {expires_at, nameservers, locked,
  registered_at}` and `transfers[name] => {state, expires_at}`, durable via
  `domains.fake.state_path`, written beside and renamed.
* **Operations.** All 16 of `DomainRegistrarProvider`.
* **Errors.** `DomainRegistrarException::refused`/`indeterminate`.
* **Fault injection.** Seven name markers, each a distinct commercial case.
* **Idempotency.** `register()` returns the existing registration for a name
  already held.
* **Async tasks.** Transfer state progression, including one that never
  finishes.
* **Observability.** `seedHolding()` / `forgetHolding()` arrange both
  directions of reconciliation drift.
* **Notable honesty.** `setContacts()` accepts and stores **nothing**: a fake
  keeping a registrant's address in a file on a laptop would be the leak the
  module's encryption exists to prevent.
* **Known gaps.** None against the generic contract.

### 5.8 `FakePaymentProvider` — 674 lines (+ `ControlledGatewayController` 225)

* **State model.** Chiefly none: the outcome, amount and customer are encoded
  into the returned reference so a worker answers correctly without having
  seen the request. The only mutable state is the gateway decision file at
  `payments.fake.state_path`.
* **Operations.** The whole `PaymentProvider` contract, plus `emitWebhook()`,
  `signPayload()`, `record()`, `authorisationUrlFor()`, `nextActionShape()`.
* **Errors.** `PaymentProviderException`, `UnsupportedCurrencyException`, and
  four decline codes.
* **Fault injection.** The last two minor units of the amount select the
  decline; `declineAmount()` lets a test state its intent instead of hardcoding
  a magic number.
* **Idempotency.** Refund reference derived from the idempotency key.
* **Async tasks.** The full authorisation round trip, in both next-action
  shapes.
* **Observability.** Webhooks are genuinely HMAC-signed with a real timestamp
  tolerance, so the signature check — the most security-critical branch in the
  module — is executed by the suite rather than reasoned about.
* **Known gaps.** None against the contract; absent from the provider
  catalogue.

### 5.9 Smaller controlled seams

| Class | Lines | State | Notes |
|---|---|---|---|
| `FakeConnectionTester` | 307 | none | Nine endpoint markers select a `ConnectionState`; refuses construction in production **and** refuses any production target wherever it is built |
| `FakeDeploymentController` | 118 | none | Config-resolved; reports `fake` as its own name |
| `FakeProvisioningHandler` | 146 | none | Engine seam with `CONFIG_KEY = 'fake'` |
| `FakeResourceReservationReleaser` | 96 | counters | Internal |
| `FakeSiteProbe` | 59 | none | Internal |
| `StubbornBackupProvider` (test) | — | none | A double that keeps failing, in `tests/Feature/Backups/Doubles` |
| `ControlledConsoleUpstream` (test) | — | none | A real socket for the console gateway proof |

Total controlled provider code in `src/`: **4,916 lines** across 25 files
(including guards and exceptions). Forty-seven test files name one of the
eight family simulators directly.

---

## 6. Real adapter inventory

| Driver | Class | Lines | Interface | Identity tester |
|---|---|---|---|---|
| `proxmox` | `ProxmoxComputeProvider` | 1241 | `ComputeProvider` | `ProxmoxConnectionTester` |
| `proxmox_backup` | `ProxmoxBackupProvider` | 364 | `BackupProvider` | `ProxmoxBackupConnectionTester` |
| `cpanel` | `CpanelHostingProvider` | 786 | `HostingProvider` | `CpanelConnectionTester` |
| `directadmin` | `DirectAdminHostingProvider` | 806 | `HostingProvider` | `DirectAdminConnectionTester` |
| `cloudflare` | `CloudflareDnsProvider` | 332 | `DnsProvider` | `CloudflareConnectionTester` |
| `cloudflare_rdns` | `CloudflareReverseDnsProvider` | 232 | `ReverseDnsProvider` | `CloudflareConnectionTester` |
| `sy_registry` | `SyRegistryProvider` | 177 | `DomainRegistrarProvider` | excused, with a recorded reason |
| `stripe` | `StripePaymentProvider` | 523 | `PaymentProvider` | `StripeConnectionTester` |
| `smtp` | `LaravelMailTransport` | 39 | `TransactionalEmailProvider` | excused, with a recorded reason |
| `redfish` | `RedfishDedicatedProvider` | 903 | `DedicatedProvider` | `RedfishConnectionTester` |
| `ilo` | `IloDedicatedProvider` | 197 | `DedicatedProvider` | `RedfishConnectionTester` |
| `ipmi` | `IpmiDedicatedProvider` | 523 | `DedicatedProvider` | `IpmiConnectionTester` |

Two real adapters are **not** complete implementations of the platform's own
contracts, and the matrix says so rather than the catalogue implying
otherwise:

* `ProxmoxBackupProvider` does not implement `FileLevelBackupProvider`. File
  browsing and file-level restore exist in the platform's contract and in the
  simulator, and in no real adapter.
* No real adapter implements `WordPressInstaller` or
  `WordPressStagingProvider`.

---

## 7. Controlled driver inventory

### 7.1 Measured at entry (re-measuring the Gap 4 finding)

Gap 4 recorded that the catalogue has exactly two controlled drivers. Measured
again at `680d366`, from the source rather than from the note:

```php
public function controlledDrivers(): array
{
    return ['fake', 'fake_bmc'];
}
```

| Catalogue driver | Category | Backing class per the catalogue test | Reference estate row |
|---|---|---|---|
| `fake` | `dns` | `FakeConnectionTester` | `ref-provider-alpha-dns` |
| `fake_bmc` | `bmc` | `FakeConnectionTester` | `ref-provider-alpha-bmc` |

The finding is **confirmed, and it is narrower and sharper than "two
drivers"**:

1. The list is a **hand-written literal** that nothing derives. It cannot
   disagree with the catalogue — `TheCatalogueOnlyClaimsWhatExistsTest`
   catches that — but nothing connects it to the existence of a simulator.
2. Both controlled entries are backed, *in the catalogue's own adapter map*,
   by `FakeConnectionTester` — a connection tester, not a provider adapter. The
   eight family simulators are invisible to the provider registry.
3. The reference estate declares **five** provider dependencies —
   `dns`, `bmc`, `backup`, `registrar`, `payment` — and only two of them name
   a `satisfied_by` provider. Three dependencies in the shipped reference
   topology cannot be satisfied by any row, for exactly one reason: the
   catalogue has no controlled driver in those categories.
4. Compute and hosting are controlled in the *adapter* registry by the
   reference estate (`ref-cluster-alpha-1` has `driver: fake`,
   `ref-hosting-alpha-1` has `panel: fake`), so their execution paths are
   rehearsable — while provider identity, capability discovery, preflight and
   readiness for those two families have nothing to point at.

So the accurate statement of the gap is not "there are only two fakes". It is:

> Eight mature stateful simulators exist and are reachable through the
> per-family adapter registries. Two of the eleven provider categories have a
> controlled driver in the provider registry. Nothing in the codebase derives
> the second list from the first.

### 7.2 After this gap

Nine, derived from one enum rather than listed in a method:

| Catalogue driver | Category | Simulator behind it | Reference estate row |
|---|---|---|---|
| `fake` | `dns` | `FakeDnsProvider` | `ref-provider-alpha-dns` |
| `fake_bmc` | `bmc` | `FakeDedicatedProvider` | `ref-provider-alpha-bmc` |
| `fake_compute` | `compute` | `FakeComputeProvider` | `ref-provider-alpha-compute` |
| `fake_hosting` | `hosting` | `FakeHostingProvider` | `ref-provider-alpha-hosting` |
| `fake_wordpress` | `wordpress_installer` | `FakeHostingProvider` | `ref-provider-alpha-wordpress` |
| `fake_backup` | `backup` | `FakeBackupProvider` | `ref-provider-alpha-backup` |
| `fake_rdns` | `reverse_dns` | `FakeReverseDnsProvider` | `ref-provider-alpha-rdns` |
| `fake_registrar` | `registrar` | `FakeDomainRegistrarProvider` | `ref-provider-alpha-registrar` |
| `fake_payment` | `payment` | `FakePaymentProvider` | `ref-provider-alpha-payment` |

`fake` keeps its name and its DNS category. Provider rows carry the string, the
reference topology names it, and renaming it would be a migration bought for
tidiness.

Seven new entries, **zero new simulators**. Every one of them is a class that
existed at `680d366` and was reachable through its family's factory the whole
time.

---

## 8. Reuse versus new code decisions

| Decision | Ruling | Reason |
|---|---|---|
| A second compute/hosting/backup simulator | **No** | Eight mature stateful simulators exist. The brief forbids a `FakeComputeProviderV2` and it would be wrong anyway: the existing ones already encode contract behaviour that took phases to get right |
| A simulator-only provider registry | **No** | The reference estate must rehearse through the same `ProviderCatalogue` / `ProviderInstance` / `ConnectionTesterFactory` path real adapters use. A parallel registry would test the simulator instead of the platform |
| Simulators for CDN, object storage, email hosting | **No** | An existing architecture test forbids any implementation of those three contracts. That decision is the source and this gap does not overrule it |
| A mail relay simulator | **No** | No endpoint, not a stateful provider, already excused with a recorded reason |
| An IPAM provider fake | **No** | IPAM is this platform's own state |
| A new fault-injection seam | **No** | Every simulator already selects failures deterministically from the request — a hostname, a username, a record name, a domain, archive notes, an amount. Standardising that into a new mechanism would rewrite eight working classes to arrive where they already are |
| Deriving the controlled driver list from the catalogue | **Yes** | §7.1 finding 1 |
| Controlled catalogue entries for families that already have a simulator | **Yes** | §7.1 findings 2–4 |
| Honest per-driver capability reporting | **Yes** | `FakeConnectionTester` reports every capability of a category as `Supported`. For `dns` that happens to be true. For richer categories it would not be, and the readiness engine reads those answers |
| Durable state for the memory-only simulators | **Yes, opt-in, off by default** | Three simulators already have exactly this via `*.fake.state_path`; the pattern is established and the hosting simulator documents working around its absence |

---

## 9. Contract sources

Every simulated behaviour this gap adds or completes, and where it comes
from. A behaviour with no source is a failure of this phase, so the table is
the gate rather than the documentation of one.

| Behaviour | Where it came from |
|---|---|
| Nine controlled catalogue entries, one per family with a simulator | The nine simulator classes themselves, and `ProviderCategory` for the category each serves |
| `needsEndpoint: true` on every controlled driver | `EndpointPolicy::assertProviderEndpoint()`, whose controlled branch admits `fake://` plus `[a-z0-9-]` and refuses it in production. A row with no endpoint would skip the policy |
| `needsCredential: true` on every controlled driver | `ProviderReadiness::assess()`, which blocks a provider with no credential reference. The rehearsal has to exercise that path, and nothing behind a controlled driver reads the value |
| `needsLicence: false` on every controlled driver, hosting included | There is no licence to hold for a simulator. The licence path is still rehearsed through `hosting_nodes.panel_licensed`, which `FakeHostingProvider::licenceStatus()` reads |
| `compute.gpu_passthrough` reported Unsupported | `ComputeProvider` has no device-assignment operation; `CreateVmRequest` carries no device and `ResizeVmRequest` cannot add one |
| `wordpress_installer.uninstall` reported Unsupported | Neither `WordPressInstaller` nor `WordPressStagingProvider` has a removal operation |
| `wordpress_installer.ssl` reported Unsupported | No WordPress contract reads or issues a certificate; SSL status is a hosting-category answer on `AccountUsage` |
| `reverse_dns.clear_ptr` reported Unsupported | `ReverseDnsProvider`'s own contract states it has "no `remove()` that a customer can reach" |
| `FakeComputeProvider` records `installed_template` on create | `CreateVmRequest::$templateReference`, and the key the class already uses on reinstall |
| `FakeBackupProvider::startVerification()` can refuse, time out and fail | `BackupProvider::startVerification()`, and `ProxmoxBackupProvider::verificationVerdict()`, which reports three answers from a storage listing — never verified, verified, verified and failed |
| `FakeBackupProvider` writes the verdict onto the archive | `RemoteBackup::$verified` being nullable, and the same real adapter method |
| `FakeBackupProvider::startRestore()` can refuse, time out and fail | `BackupProvider::startRestore()`; the failure classes are the ones `BackupProviderException` already distinguishes |
| Seven reference provider rows and one reference backup host | `ReferenceKind::Provider` and `ReferenceKind::Machine`, the existing loader, and `ProviderCategory::needsServer()`, which decides which rows need a machine |
| Every fault selected from the request | The eight simulators' existing markers, unchanged |

Nothing on this list required inventing an endpoint path, a response field, a
provider status name, an error code, a rate limit, an id format, a capability
semantic, a retry guarantee, a payment semantic or a registrar semantic.

---

## 10. Compute

`FakeComputeProvider` implements all 18 operations of `ComputeProvider` and was
the most complete simulator in the repository before this gap. Two things
changed and neither is a rewrite:

* it is now catalogued as `fake_compute`, so a provider row, a connection test,
  capability discovery, a preflight and the readiness engine can all point at
  it — where previously only a `compute_clusters.driver` column could;
* `createVirtualMachine()` records the template it installed from, under the
  same `raw` key the reinstall already used. Before, the request carried a
  template reference and the machine that came back had forgotten it, so
  "this provider can install from a staged image" was a claim nothing could
  observe.

**State machine.** The states are `PowerState` and `RemoteTaskStatus`, the
repository's own enums, and no state name was invented. A machine moves
absent → stopped or running on create, between running and stopped through the
power operations, and back to absent on destroy, where a tombstone makes the
absence assertable. A locked machine refuses every power operation and the
reinstall, exactly as Proxmox does — which is what makes suspension
enforcement rather than bookkeeping.

**Failure cases, all grounded in the contract.** Provider refusal, task
failure after acceptance, indeterminate create, indeterminate destroy, locked
machine, unknown machine, empty resize. There is no "capacity exhausted"
marker and there should not be: capacity is the platform's own state (§23 of
this report), and the simulator inventing a capacity error would model a
Proxmox behaviour nobody here has read.

**Async.** Real UPIDs with the start time in hex. `compute.fake.task_delay_seconds`
makes a task genuinely report Running for a while, so the polling branch of
provisioning executes.

**Gap remaining.** No fault injection on the read paths — `listVms()`,
`listNodes()`, `getTask()` — except by swapping the adapter through the
factory's `swap()` seam, which the existing tests use. Recorded in §32.

---

## 11. Dedicated / BMC

`FakeDedicatedProvider` implements all 9 operations of `DedicatedProvider`.
Catalogued as `fake_bmc` since Phase 30B-P, and the reference estate has bound
a provider row to a classified machine since Gap 4.

What this gap verified rather than changed:

* the fake's power semantics **match** the real adapter's. `RedfishDedicatedProvider::sendReset()`
  POSTs a `ResetType` with no state guard, and the fake's `setPower()` writes
  the resulting state with no guard either. Neither refuses an invalid
  transition, because the real controller does not;
* the one-time PXE override is armed, appears first in the boot order, and is
  consumed by the next reset or power-on — which is what "one-time" means, and
  what a test asserting a machine does not reinstall itself every reboot
  checks;
* the MAC is derived from the endpoint id in the locally-administered range, so
  a PXE authorisation granted to a MAC stays valid across calls.

**Safety classification is preserved and not bypassed.** A provider bound to a
`do_not_touch` machine is hardware-blocked by `ProviderReadiness` before
anything is dialled, and the reference estate deliberately keeps one machine at
that classification so the refusal is part of the modelled estate.

§28 carries the durable power idempotency verdict.

---

## 12. Hosting

`FakeHostingProvider` implements all 12 operations of `HostingProvider`, and it
is also the only implementation of both WordPress contracts. Catalogued as
`fake_hosting`.

Contract-relevant conflicts it already models, unchanged by this gap: an
account that already exists is refused rather than silently created twice; an
account that does not exist is refused; a node hostname carrying a marker makes
`listAccounts()` — the call the reconciler makes — fail, which is how "what
does the sweep do when the panel is down" becomes answerable; a panel that
answers with no measurements at all returns an `AccountUsage` whose every
figure is null, so a sync writing zeroes over a customer's real figures is
visible.

No panel-specific error was invented. The licence answer is read from
`hosting_nodes.panel_licensed` rather than asserted, so the unlicensed path —
scheduler exclusion, a permanent failure for that node, an operator alert — is
reachable.

---

## 13. Backup

`FakeBackupProvider` implements `BackupProvider` and `FileLevelBackupProvider`.
Catalogued as `fake_backup`. This is the family where this gap closed the most
contract surface, and every piece of it is grounded in the real adapter:

* **verification can fail.** `startVerification()` reads the archive id for
  the markers, so a refusal, an indeterminate outcome and a task that runs and
  fails are all reachable. Before, verification could only succeed;
* **the verdict is written onto the archive.** A settled verification sets
  `RemoteBackup::$verified` to true or false, which is exactly what
  `ProxmoxBackupProvider::verificationVerdict()` reports from a storage
  listing: three answers, because never verified is not the same as verified
  and failed;
* **restore can fail.** `startRestore()` reads the same markers. A restore that
  cannot fail would make every path the platform has for a failed restore
  unreachable;
* **created is still not verified.** A completed backup is stored with
  `verified: null` and stays that way until something verifies it. That
  invariant was already right and is now asserted directly.

`retention` is carried out through `deleteBackup()`, which the simulator
honours; no separate retention operation exists on the contract.

The real side's gap is recorded rather than papered over:
`ProxmoxBackupProvider` does not implement `FileLevelBackupProvider`, so file
browsing and file-level restore exist in the platform's contract and in the
simulator and in no real adapter.

---

## 14. DNS

`FakeDnsProvider` implements all 9 operations of `DnsProvider` and needed no
completion. Catalogued as `fake` — the name it has always had.

Two properties worth restating because they are unusual and correct: the
markers apply to **reads** as well as writes, because a provider that has
stopped answering has stopped answering questions too and the platform's most
dangerous moment is believing a silence; and a repeat publish keeps the
identifier the first one got, so the account holds one record whether it was
written once or three times.

Reverse DNS (`fake_rdns`, `FakeReverseDnsProvider`) implements the single
operation its contract has. `clear_ptr` is asked about by three products and
modelled by no interface method — recorded in §32 rather than simulated.

---

## 15. Registrar

Two contracts, and this gap keeps them apart.

**The platform's generic registrar contract** —
`DomainRegistrarProvider`, 16 operations — is fully implemented by
`FakeDomainRegistrarProvider`, which is now catalogued as `fake_registrar`.
Availability, registration, renewal, transfer, expiry, nameservers, contacts,
lock, auth code, redemption, premium quotes and held names are all reachable,
with seven distinct commercial failure markers including the two that cost
money: a name already taken, and a registry that went quiet after the request
was sent.

**The `.sy` registry's technical contract** is not available to this project.
`SyRegistryProvider` remains a placeholder whose every capability answers false
and whose every operation refuses, and nothing in this gap changed that or
modelled any part of it. Classification: `NOT_IMPLEMENTED — PROVIDER CONTRACT
UNAVAILABLE`.

A controlled registrar existing does not claim the `.sy` API is understood, and
the reference row's own note says so.

**Time.** The registrar's date arithmetic runs on Carbon, the framework clock,
and a renewal moves the expiry from the expiry rather than from today. No
`sleep()` appears anywhere in the simulators; the one place a delay is modelled
is the compute task, and it is modelled by encoding a start time in the task id
and comparing, not by waiting.

---

## 16. Payment

`FakePaymentProvider` implements the whole `PaymentProvider` contract and was
already the most carefully built simulator here. Catalogued as `fake_payment`;
nothing about its behaviour changed.

What it proves, and what this gap re-verified rather than rebuilt:

* the outcome travels in the reference, so a queue worker that never saw the
  request answers correctly — asserted directly in the new stateful gate with
  a second provider instance standing in for the worker;
* four decline codes selected by the amount, deterministic and named;
* webhooks are genuinely HMAC-signed with a real timestamp tolerance, so the
  signature check runs for real. Existing coverage:
  `WebhookEndpointTest`, `IngestWebhookEventTest` (duplicate and replayed
  events), `PaymentConfirmationIsServerSideOnlyTest` (the Wave 2 trust
  boundary: a browser saying "paid" is not evidence),
  `ConcurrentCaptureTest` and `NoSecondCollectionWhileACaptureIsUnappliedTest`
  (one payment, not two), `IssueRefundTest` and `RetryingARefusedPaymentTest`;
* the refund reference is derived from the caller's idempotency key, so two
  deliberate refunds of one amount are two refunds and a replay is one.

No part of Stripe's API is emulated. What is simulated is the platform-facing
contract.

---

## 17. WordPress and the provider relationship

WordPress is **not** a separate provider family in this repository, and this
gap did not make one. It is layered on the hosting contract: `WordPressInstaller`
and `WordPressStagingProvider` take a `HostingNode`, and the only class that
implements either is the hosting simulator.

So `fake_wordpress` is catalogued as its own driver — because
`ProviderCategory::WordPressInstaller` is its own category with its own
capability questions, and a product requirement names it — and it resolves to
the same object `fake_hosting` does. The new consumer gate asserts exactly
that.

The honest finding is on the real side and on the contract:

* no real adapter implements either WordPress contract;
* the category asks seven capability questions and the contracts answer four.
  `uninstall` and `ssl` have no method anywhere in the repository, and the
  WordPress product requires both. The controlled driver reports them
  unsupported, so the product stays unsellable with that as its exact reason.

---

## 18. IPAM

No provider fake, by decision and per the brief.

Addressing is this platform's own state: `Network`, `Subnet`, `IpPool` and
`IpAddress` rows, with `SeedSubnetAddresses` expanding a subnet and
`IpamReservationReleaser` returning an allocation. Allocation, release,
exhaustion and collision prevention are exercised against those models — which
is what the provisioning flows use — and `IpAllocator` issues
`SELECT ... FOR UPDATE SKIP LOCKED` precisely because correct allocation is a
database-level concern. A fake external IPAM provider would test something this
platform does not have.

The only external port in the addressing story is reverse DNS, which has a
controlled driver (§14).

---

## 19. Async and task behaviour

| Family | Does the real contract return a handle? | What the simulator does |
|---|---|---|
| Compute | Yes — a UPID for every mutation | A real UPID with the start time in hex; `getTask()` decodes it, so a configured delay produces a genuinely running task, and the outcome is encoded in the id so a worker that never saw the create still answers |
| Backup | Yes — a task id per job | `pollsBeforeSettling` (default 1) makes every task report running before it settles; a verification writes its verdict on settling |
| Registrar | Transfers only | `pending` → `completed` on the second look, or `pending` for ever under the slow marker |
| Payment | The authorisation round trip | `requires_action` → gateway decision → signed webhook, in either next-action shape |
| Dedicated | Redfish returns a task only when it cannot finish inline | None, matching the real adapter. Inventing one would model a controller behaviour nobody read |
| Hosting | No | None. Both panels answer inline |
| DNS / reverse DNS | No | None |

No wall-clock waiting anywhere: the compute delay is a comparison against an
encoded timestamp, and every other progression is driven by the number of
times the caller asks.

---

## 20. Idempotency

| Operation | Platform idempotency key? | Provider idempotency? | Local dedup? | Safe retry? | Simulator matches? |
|---|---|---|---|---|---|
| VPS create | Yes — the provisioning engine's unique key | None in the contract | The `vmId` the platform allocated | Yes, while the id is the same | Yes: create is keyed on `vmId` and the UPID is derived from the request |
| VPS destroy | Yes | None | Tombstone | Only when the outcome was known | Yes, and the undestroyable marker models the case where it was not |
| VPS suspend / lift | Yes | None — the contract requires the adapter to be idempotent | The lock value | Yes | Yes: re-applying the lock is not an error and doubles nothing |
| Dedicated reinstall | Yes — `DedicatedIdempotencyKey`, scoped to machine and operation | None | The engine's unique key column | Yes | N/A — the engine, not the simulator |
| Dedicated power | **No** | None | **None** | Not retried at all: a timeout is reported as indeterminate and stops | Yes, faithfully — see §28 |
| Hosting create account | Yes | None | The panel refuses a duplicate username | Yes: the second attempt is refused, not silently doubled | Yes |
| Backup start | Yes | None | None | Only when the outcome was known | Yes |
| DNS publish | No | The record identity is the key | Type and name | Yes | Yes: a repeat keeps the id |
| Reverse DNS publish | No | One record per address, per the contract | Address | Yes | Yes: a repeat replaces |
| Domain register | Yes — `RegistrationRequest::$idempotencyKey`, for registrars that honour one | Unknown for `.sy`; not claimed | The platform's own guard, plus the registrar holding the name | Never on an indeterminate outcome | Yes: registering a held name returns the existing registration |
| Domain renew | Yes | None | None | Never on an indeterminate outcome | Yes |
| Payment intent | Yes — the caller's key | Real gateways honour one; not emulated | The invoice's pending attempt | Yes | Yes: the reference encodes the request |
| Payment refund | Yes | As above | The refund reference | Yes | Yes: the reference derives from the key, so two deliberate refunds are two |

Where only platform-side idempotency exists, the table says so. No provider
guarantee is claimed for any driver.

---

## 21. Retry

The platform's rule is older than this gap and this gap does not touch it: an
**indeterminate** outcome is never retried automatically. Every provider
exception in the repository carries `isIndeterminate()`, every simulator
advertises a marker that produces one, and the new fault gate asserts the
classification for seven families at once.

What the simulators make provable is that a retry cannot double anything where
the contract relies on idempotency: a repeated create keyed on the same id is
one machine, a repeated registration of a held name is one term (asserted
after a renewal, so a second term would be visible as the year it costs), a
repeated publish is one record, a repeated refund with one key is one refund.

No automatic retry was added anywhere. The simulator's ability to support one
is not a reason to have one.

---

## 22. Reconciliation and drift

| Family | Can the simulator produce drift? | How |
|---|---|---|
| Compute | Yes | A machine destroyed at the provider while the platform still has a row: `getVm()` answers null, which is a legitimate answer and not an exception |
| Hosting | Yes | `listAccounts()` is the sweep; an account terminated at the panel disappears from it. A node marker makes the sweep itself fail, which is the outage case |
| Backup | Yes | `listBackups()` after a settled task; an archive deleted at the datastore disappears |
| DNS | Yes | `records()` with type and name filters is what the reconciler reads |
| Registrar | Yes, in both directions | `seedHolding()` puts a name in the registrar the platform has no row for; `forgetHolding()` takes away a name the platform believes it holds |
| Payment | Yes | `retrievePayment()` is the server-side truth, and a gateway decision recorded in the state file outranks the reference — which is how a browser authorisation becomes visible to a later retrieve |
| Dedicated | Yes | `powerState()` disagreeing with what the platform recorded |

No drift resolution policy was invented. What each family's reconciler does
about a disagreement is existing platform behaviour, covered by existing tests
(`HostingReconciliationTest` and the domain and compute reconciliation suites
among them).

---

## 23. Capacity

Capacity is **platform state, not simulator state**, and that is the honest
answer rather than a limitation.

The scheduler decides where a machine goes from `compute_nodes` rows,
overcommit ratios, memory headroom and the allocations already committed —
none of which a hypervisor is asked about. `FakeComputeProvider::listNodes()`
reports the shape of a fleet from configuration, so it can make a node look
full or offline, but it does not own the arithmetic the scheduler runs.

So the four properties the brief asks about are exercised where they live, and
were before this gap: `NodeCapacityReservationTest`,
`IdempotentCapacityReservationTest` and `NodeSchedulerTest` cover capacity
decreasing on commit, returning on release, a failed create leaking no
reservation, and a retry not double-consuming. `NodeCapacityReleaser` and
`IpamReservationReleaser` are the release paths, and
`FakeResourceReservationReleaser` is the seam that lets a test make a release
fail.

Making the simulator a second source of capacity truth would have created a
disagreement between two models of the same fact, which is the failure Gap 5
spent a whole phase ending.

---

## 24. Fault injection

One seam, and it is the one the eight simulators already had: **the outcome is
selected from the request**. A hostname, a username, a record name, a
hostname in an address, archive notes, an archive id, a domain name, an
amount. No new mechanism was built, and the reason is that a new one would
have meant rewriting eight working classes to arrive where they already were.

What this gap added is enforcement. `EveryFaultASimulatorAdvertisesIsOneThePlatformDistinguishesTest`
takes each family's advertised markers and asserts the failure they produce is
one the platform distinguishes — a refusal or an indeterminate outcome — and
that the same request produces the same failure in a second instance that
never saw the first.

There is no randomness in any simulator: no `rand()`, no probability, no
clock-dependent branch except the compute task delay, which is a comparison
against a timestamp encoded in the task id.

---

## 25. Production isolation

Five independent controls, none of them new, now asserted once per controlled
driver so that a tenth driver is covered the day it is added:

1. **Registration refuses** a controlled driver on a production installation —
   checked against the running installation, not the row's own environment.
2. **Every simulator refuses its own constructor** in production. A container
   binding overridden at runtime and a `driver` column naming the fake both
   avoid a boot-time check; neither avoids a constructor.
3. **The boot guard refuses to start** a production deployment configured for
   a fake provider, and refuses one configured for a driver this build does
   not contain.
4. **The tester is not registered** in production, refuses to be constructed
   there, and refuses any production provider row wherever it is built.
5. **Readiness refuses to count a rehearsal.** A controlled provider with every
   capability supported, enabled, and at its readiness ceiling still cannot
   make a product production-ready or sellable — asserted for every driver
   against the most flattering row one could have.

And the preflight: a production row on a controlled driver is blocked in
**both** modes, never merely warned about.

---

## 26. Simulation and reference integration

The reference estate now points at a controlled provider for every dependency
it declares. Seven provider rows and one backup host were added to
`resources/reference-topology/topology.php`; the existing loader wrote them
with no change, because it was already generic over `ReferenceKind::Provider`.

What the loader still does not write: a credential, a licence, a capability
row or a connection test. A reference provider is therefore blocked on
credentials, which is the honest state of a provider nobody has contacted.
Gap 4's four refusals are intact — it will not run on a production
installation, will not load a topology claiming to be production, stamps every
row `development`, and every provider it writes uses a controlled driver.

**The measurement.** `php artisan infra:preflight --mode=simulation` against a
freshly migrated and seeded database:

*As shipped (no credential references):* every family reaches
`provider.configuration` and, where it runs on hardware, `provider.machine`;
all nine then block at `provider.credential` with "MISSING: no credential
reference is attached to this provider", and the next action says to record one
in the credential centre. No family is blocked because the catalogue lacks a
controlled driver — which is the finding this gap set out to close.

*With a controlled credential reference attached, as an operator would:* all
nine reach `provider.identity` and pass it — Connected — with 45 passing
checks, 0 warnings, and the report labelled `SIMULATION`, `REFERENCE TOPOLOGY`,
`Verification: CODE_COMPLETE, TESTED, RUNTIME_VERIFIED`,
`Real infrastructure verified: NONE`, `Ready to sell: NONE`.

Products remain `not_ready`, each with an exact reason: the providers are
`draft` because nobody has enabled them, and no product could pass anyway —
see §33.

The credential reference names an environment variable and carries no secret.
Nothing behind a controlled driver resolves one: simulation requires zero real
credentials, and no test in this gap reads a Stripe secret, a Cloudflare token,
a Proxmox token, a BMC password or a registrar password.

---

## 27. Mail relay

Out of provider simulation, deliberately, and the reason is a property of the
world rather than of this codebase.

The relay is a deployment setting — `MAIL_HOST` — reached over SMTP by the
framework's own mailer, and in some deployments it is the `log` driver. There
is no endpoint to dial, so there is nothing for an identity tester to
establish; `smtp` is already excused from having one with a recorded reason.
It holds no state, so there is nothing for a stateful simulator to hold. The
seam the suite already uses is `Mail::fake()`, which is the framework's and is
better than anything this gap could have written.

One consequence is worth stating plainly, because it is load-bearing for §33:
every product carries the shared `email` requirement, no controlled driver can
satisfy it, and `smtp` cannot be connection-tested. **No product can become
sellable on simulation alone.** That is structural, not incidental.

---

## 28. Dedicated power durable idempotency

The brief asks three questions and here are the three answers, measured.

**A. Is durable idempotency implemented elsewhere?**

For dedicated **reinstall**, yes. `DedicatedIdempotencyKey::for()` scopes the
caller's key to the machine and the operation, hashes the caller's half to a
fixed length, and hands it to the provisioning engine, whose key column is
unique platform-wide. A redelivered request reaches the job that already
exists.

For dedicated **power**, no. `ChangeDedicatedServerPower` takes no idempotency
key, is deliberately synchronous, and the HTTP layer's `Idempotency-Key`
mechanism — which reinstall uses — is not applied to it.

**B. Does the current fake expose the same semantics as the real adapter?**

Yes, at the operation level. `RedfishDedicatedProvider::sendReset()` POSTs a
`ResetType` to `ComputerSystem.Reset` with no state guard and no invalid-transition
refusal; `FakeDedicatedProvider::setPower()` writes the resulting state with no
guard either. Neither returns a task for a power change, because Redfish
returns one only when it cannot finish inline and the real adapter says so.

One difference, and it is about durability rather than semantics: the fake's
power state and PXE flag live in instance memory, so they do not survive a
process boundary. The pattern for fixing that exists in this repository —
`compute.fake.state_path`, `domains.fake.state_path`, `payments.fake.state_path`
— and was not applied here, because no proof in this gap needs it and the brief
says not to add filesystem state unnecessarily. Recorded in §32.

**C. Can a repeated client request cause duplicate disruptive operations?**

**Yes.** Two identical power requests produce two BMC operations. For `on` and
`off` the second is harmless — the machine is already in the state asked for —
but `cycle` reads the power state and then resets, so a double submission can
reset a running machine twice, and a reset during an install is an interrupted
install.

**Verdict: this remains a real code gap, and it is carried to Final Software
Closure rather than fixed here.** Fixing it means deciding whether power
becomes a keyed operation, whether the engine claims it, and what a customer
sees while one is in flight — a subsystem redesign, which §23 of the brief
explicitly says not to undertake on this gap's evidence. What this gap adds is
that the question is now answered with measurements instead of deferred again,
and that no test freezes the current behaviour as correct.

---

## 29. Deliberate breakage

Eight breakages, each applied alone, run, and restored. Every one of them made
a gate fail, and two of them made a gate fail that should have failed and did
not — which is the whole reason the exercise exists.

| | Breakage | Gate | Result |
|---|---|---|---|
| A | `fake_backup` filtered out of the catalogue's controlled entries | `TheCatalogueOnlyClaimsWhatExistsTest`, `EveryRealDriverHasAnIdentityTesterTest` | **4 failures.** The adapter map outlived the catalogue; a tester was registered for a driver the catalogue did not know; the driver resolved to the fake tester without being controlled; it was not covered by the HTTP tester rule |
| B | `FakeDnsProvider::publish()` returns the record and stores nothing | stateful gate | **1 failure.** "Failed asserting that actual size 0 matches expected size 1" |
| C | The fake tester reports every capability Supported | capability gate | **3 failures** — `fake_compute` on `gpu_passthrough`, `fake_wordpress` on `uninstall`, `fake_rdns` on `clear_ptr` |
| D | The controlled-driver check removed from `RegisterProvider` | production gate | **9 failures**, after the gate was strengthened. See below |
| E | The compute refusal moved to after the machine is stored | stateful gate | **1 failure.** A refused build left a machine behind |
| F | The registrar's "already held" branch removed | stateful gate | **1 failure**, after the gate was strengthened. See below |
| G | A `flushEdgeCache()` method added to `FakeDnsProvider` | parity gate | **1 failure.** "offers operations no contract names" |
| H | The `READ_ONLY_REAL` + controlled branch removed from `ProviderChain` | mode isolation gate | **1 failure.** The identity check passed with real-read evidence |

**Two gates were not good enough, and the breakages are what found them.**

*D* first produced only 4 failures out of 9. Five drivers still raised
`ProviderRefused` — for a different reason: a provider that runs on hardware we
manage is refused first for naming no machine, and the endpoint policy refuses
a `fake://` address in production. Both are correct controls and neither is the
one being tested, so the gate now asserts the message and not only the
exception class. With that, all nine fail.

*F* first produced no failures at all. The assertion compared the expiry of two
registrations made in the same second, and both computed "a year from now" and
agreed — so a simulator with no idempotency at all passed. The gate now renews
the holding first, giving it an expiry a fresh registration could not produce,
so a second term is visible as the year it would silently cost the customer.

All eight breakages are restored. The working tree after restoration is the
tree that was committed, and the full suite was run on it.

---

## 30. Positive twins

Every negative gate has one, because a gate that passes by refusing everything
proves nothing.

| Negative | Positive twin |
|---|---|
| A controlled driver is refused in production | The same driver resolves in simulation, passes its identity check, and its evidence is labelled `Simulation` with `RuntimeVerified` |
| An unsupported capability keeps its product unsellable | A supported capability satisfies its requirement: the DNS family, whose contract answers every question its category asks, reaches the rehearsal rung rather than nothing at all |
| A failed create leaves no machine, no account, no record, no PTR, no holding | A successful one is held, listed, read back, and gone again after a destroy |
| A redelivered registration produces one holding | Two different names produce two holdings |
| `READ_ONLY_REAL` refuses to treat a fake as evidence | `SIMULATION` resolves it and says so |
| A simulator may not invent an operation | Every arrangement seam it legitimately has is named with what it is for, and the gate fails if one disappears |

---

## 31. Tests

| | At Gap 5 | At Gap 6 |
|---|---|---|
| Backend tests | 3,429 | **3,529** |
| Assertions | — | 141,823 |
| Result | pass | **pass, 3,529 / 3,529** |
| Pint | clean | clean |
| PHPStan | 0 errors | **0 errors** |
| OpenAPI | up to date | up to date, 249 operations (no contract change) |

The hundred new tests are in five files:

* `tests/Architecture/EveryControlledDriverIsBackedByASimulatorTest.php` — 5
  tests: parity in both directions, the consumer rule in both directions, and
  the allow-list staleness rule.
* `tests/Feature/Simulation/AControlledDriverIsReachableThroughTheOrdinaryFactoriesTest.php`
  — 9 tests: every controlled driver resolved through the factory the product
  code uses, plus the gate that a tenth driver must be covered here.
* `tests/Feature/Simulation/AControlledDriverSaysOnlyWhatItCanDoTest.php` — 20
  tests: capability honesty per driver through the real `TestConnection`
  action, the reason rule, and the readiness consequences in both directions.
* `tests/Feature/Simulation/NoControlledDriverSurvivesProductionTest.php` — 38
  tests: four production controls per driver, plus mode isolation and the
  production-row case in both modes.
* `tests/Feature/Simulation/AControlledDriverThatSaysItDidSomethingDidItTest.php`
  — 18 tests: mutate-then-read and fail-then-read for every family.
* `tests/Feature/Simulation/EveryFaultASimulatorAdvertisesIsOneThePlatformDistinguishesTest.php`
  — 10 tests: refusal versus indeterminate for seven families, the verification
  verdict, the decline codes, and determinism.

Per family, the brief's minimum — one positive lifecycle, one configuration
failure, one runtime failure, one idempotency case, one state readback, one
production guard — is met by these files together with the existing per-family
suites they deliberately do not duplicate.

---

## 32. Exact remaining code gaps

Each of these is a statement about a missing contract or a deliberate
deferral, not a to-do list item somebody forgot.

| Gap | Classification | What is missing | Where it belongs |
|---|---|---|---|
| `reverse_dns.clear_ptr` | `NOT_IMPLEMENTED — CONTRACT ABSENT` | `ReverseDnsProvider` has no clear operation, deliberately. Three products require the capability | A contract decision: either the interface gains a clearing operation with a policy for who may reach it, or the requirement is withdrawn |
| `wordpress_installer.uninstall` | `NOT_IMPLEMENTED — CONTRACT ABSENT` | No WordPress contract has a removal operation. Removal happens today by terminating the hosting account | Gap 8 |
| `wordpress_installer.ssl` | `NOT_IMPLEMENTED — CONTRACT ABSENT` | No WordPress contract reads or issues a certificate | Gap 8 |
| `compute.gpu_passthrough` | `NOT_IMPLEMENTED — CONTRACT ABSENT` | `ComputeProvider` models no device assignment. The GPU compute product requires it | Gap 8, or the product stays unsellable |
| File-level backup on a real adapter | `NOT_IMPLEMENTED` | `ProxmoxBackupProvider` does not implement `FileLevelBackupProvider`. Contract and simulator exist; no real adapter does | Whoever writes the real file-level path |
| WordPress on a real adapter | `NOT_IMPLEMENTED` | Neither panel adapter implements either WordPress contract | As above |
| `.sy` registrar | `NOT_IMPLEMENTED — PROVIDER CONTRACT UNAVAILABLE` | The registry's protocol, endpoints, authentication, contact requirements, term limits and redemption rules | Unchanged: a document from the registry |
| CDN, object storage, email hosting | `NOT_IMPLEMENTED` | No adapter, and an architecture test forbids writing one — including a fake — because an adapter written from documentation is worse than an empty seat | Unchanged |
| Load balancer, certificates, cluster lifecycle | `NOT_IMPLEMENTED — CONTRACT ABSENT` | No interface at all; the categories exist so a product can name what it will need | Unchanged |
| Dedicated power durable idempotency | Real code gap, carried | No idempotency key, no dedup, and `cycle` can reset twice | Gap 8 — §28 |
| Durable state for five simulators | Deferred, with the pattern in hand | Dedicated, hosting, backup, DNS and reverse DNS keep state in memory, so a rehearsal cannot span a web request and a worker. Compute, registrar and payment already have opt-in state files | Gap 7, where a golden path first needs it |
| Read-path fault injection on compute | Deferred | `listVms()`, `listNodes()` and `getTask()` cannot be made to fail from the request; only the factory's `swap()` seam can | Gap 7 if a failure matrix needs it |

---

## 33. The real-verification boundary

Nothing in this gap moved it, and nothing in this gap could.

```
REAL_INFRA_VERIFIED      = NONE
REAL_PAYMENT_VERIFIED    = NONE
REAL_REGISTRAR_VERIFIED  = NONE
REAL_HOSTING_VERIFIED    = NONE
READY_TO_SELL            = NONE
```

What Gap 6 establishes is narrower and worth stating precisely:

* **CODE_COMPLETE** for the nine controlled drivers, their catalogue
  registration, their capability declarations and the gates around them.
* **TESTED** — 3,529 backend tests, 100 of them new, eight deliberate
  breakages proven and restored.
* **RUNTIME_VERIFIED, local controlled simulation only** — the reference estate
  loads, every declared dependency points at a controlled provider, and
  `infra:preflight --mode=simulation` reaches and passes the identity check for
  all nine families with the report labelled `SIMULATION`.

And three structural reasons a simulation can never become a sale, none of them
added for reassurance:

1. The readiness engine refuses to count a controlled provider towards
   production readiness, whatever its capabilities say.
2. Every product carries the shared `email` requirement, and no controlled
   driver can satisfy it (§27).
3. A production row answered by a controlled driver is blocked in both
   preflight modes, and registration refuses one on a production installation
   at all.

A simulator answering correctly is evidence about this codebase. It is not
evidence about a datacentre, a registry, a bank or a customer's website, and
no sentence in this document should be quoted as if it were.

---
