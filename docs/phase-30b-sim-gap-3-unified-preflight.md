# Phase 30B-SIM — Gap 3 of 8

## Unified infrastructure preflight: CLI + Admin, SIMULATION + READ_ONLY_REAL, zero write paths

---

## 1. Provenance

| | |
|---|---|
| Repository | `fullstackfull/cloud` |
| Branch | `claude/hv-t6hq1p` |
| Starting HEAD | `9f18a32bd21d96c19ce63f3b98bffa2561833452` |
| Gap 2 CI gate | Run 168, id 35119047591, SHA `9f18a32` — **completed / success, 9 of 9 jobs** |
| Scope | Gap 3 only. Reference topology, naming standard, simulator contract-gap audit, golden paths and final closure are not started. |

The entry gate was checked before any code was written. Run 168 was `in_progress` at
first look and was re-checked to completion rather than reported as "not green" — the
distinction between a run that failed and a run that had not finished mattered, and the
audit in §3 (which changes nothing) proceeded while it ran.

## 2. What this gap was

Every truth a preflight needs already existed, and none of it was reachable in one
question. An operator asking "why can I not sell a VPS" had to open the provider list,
the credential centre, the licence centre, the cluster inventory and the readiness page,
and hold the answer in their head.

## 3. Current-state audit

Performed before any code. Where the brief's model and the repository disagreed, the
repository decided — twice (§3a, §3b).

| Check | Existing source of truth | Current caller | Reuse? | Gap |
|---|---|---|---|---|
| provider exists / state / environment | `ProviderInstance`, `ProviderState` | `ProviderController`, `AssessProvider` | YES | no preflight caller |
| catalogue claim vs adapter | `ProviderCatalogue` (a list in source) | `ProviderCatalogueController` | YES | — |
| endpoint safety | `EndpointPolicy` | `RegisterProvider`, `RegisterServer`, `TestConnection` | YES | — |
| credential presence / environment | `CredentialReference`, `mayBeTried()`, `SecretResolver::exists()` | `TestConnection`, `CredentialController` | YES | no read-only aggregate |
| provider identity | Gap 2 testers via `ConnectionTesterFactory` | `TestConnection`, **which persists five things** | TESTER YES, ACTION NO | needed the probe without the write |
| capability states | `ProviderCapability`, `CapabilityState` | `AssessProvider`, readiness | YES | — |
| licence | `Licence`, `LicenceState` | `LicenceController`, `RefreshLicenceStates` | YES | — |
| product requirements | `ProductRequirements::for()` | `AssessProduct` | YES | — |
| product verdict | `ProductReadinessEvaluator::evaluate()` (pure) | `AssessProduct` (persists) | EVALUATOR YES | facts assembly was private |
| next action per blocker | `BlockerReason::nextAction()` | `RequirementVerdict::toArray` | **NO — see §21** | returns a translation key |
| machine / safety | `ManagedServer`, `SafetyClass`, `SafetyGate::permits()`, `InfrastructureAction::Read` | `ServerController` | YES | — |
| compute mappings | `ComputeCluster`, `ComputeNode`, `ComputeStorage`, `VmTemplate` + their own scopes | scheduler, `InfrastructureController` | YES | nothing checked the mapping *set* |
| hosting mappings | `HostingNode`, `HostingPackage` | hosting actions | YES | — |
| DNS / rDNS / addresses | `DnsZone`, `Network`, `IpPool` | dns/ipam actions | YES | — |
| registrar catalogue | `DomainTld` | domain actions | YES | — |
| backup dependency | Gap 1 `BackupCollector`, `Backup` | `MetricsRegistry` | YES | — |
| monitoring | `MetricsRegistry::collect()` | `/metrics`, CI validator | YES | **§28 — see the finding** |
| node install preflight | `PreflightHostingNode`, `hosting:preflight` | install automation, stdin JSON | **SEPARATE QUESTION — §29** | — |
| capacity | `CapacityFacts`, node `free*` methods | `AssessProduct` | YES | — |
| audit | `RecordActAtomically`, `AuditAction` | everywhere | YES | needed one new action value |
| RBAC | route permissions, `Permission` enum | `routes/api_admin.php` | YES | — |

### 3a. The backup provider talks to a hypervisor

`ProxmoxBackupProvider` builds a `ProxmoxConnection` and calls PVE endpoints. Recorded
in Gap 2 and carried here: the backup dependency check asks about a PVE cluster with a
PBS datastore attached, not about a Backup Server's own API.

### 3b. A BMC is reached by two roads that carry different things

`TestConnection::forServer()` resolves the **machine's** credential and dials the
machine's BMC address. `forProvider()` resolves the **provider row's** credential and
dials the row's endpoint. Both are real paths. The first version of the provider chain
looked only at the provider row, which reported "no credential" for a machine whose
credential is recorded exactly where the platform expects it — a false failure, and the
most annoying kind, because the operator can see the credential on the screen. The chain
now takes an optional `throughMachine` and probes through the same door the real
connection test uses (§8, §17).

## 4. Reused architecture — what was not rebuilt

No second provider model, no second readiness engine, no second capability registry, no
second credential system, no second Admin infrastructure section, no second
connection-test framework, no second endpoint policy, no second monitoring engine.

Added: nine small value types under `Domain/Preflight`, one orchestrator, three check
chains, one CLI command, one HTTP endpoint with its request and resource, one audit
action value, one rate limiter, and one extracted service (§7).

## 5. The unified service

```
CLI  ──┐
       ├──▶ InfrastructurePreflightService ──▶ ProviderChain   ──▶ ProbeProvider ──▶ Gap 2 testers
Admin ─┘                                  ├──▶ MappingChain    ──▶ inventory models' own scopes
                                          ├──▶ DependencyChain ──▶ MetricsRegistry, Backup
                                          └──▶ AssessProduct::verdictFor() ──▶ ProductReadinessEvaluator
```

One service owns orchestration. Presentation belongs to the CLI and the controller, and
`OnePreflightServiceAnswersTheCliAndTheScreenTest` compares the two outputs field for
field so they cannot drift into telling an operator two different things.

## 6. Modes

Exactly two, `PreflightMode::Simulation` and `PreflightMode::ReadOnlyReal`. No AUTO, no
FULL, no WRITE. The mode has **no default** — at the service, in the CLI (`--mode` is
required and refusing to guess is exit code 2), and in the HTTP contract (`mode` is a
required field). `PreflightMode::mayEvidenceReality()` is the method the rest of the
platform asks, so a future mode cannot be added without somebody answering that question
for it.

Simulation does not dial real providers at all: a real driver's row reports
`not_tested` with the reason, and `Http::assertNothingSent()` proves it.

## 7. Read-only enforcement

Structural, not conventional. Six mechanisms:

1. **`ProbeProvider` was extracted from `TestConnection`.** The action writes five places
   — the provider row's state and timestamps, every capability row, the credential's
   state, a `connection_tests` record, an audit entry — all correct for an operator
   pressing a button and all wrong for a diagnosis. The probe is the read half; the
   recording half is not injected into the preflight, so no check can reach it. This is a
   deduplication: without it, preflight would have re-implemented the endpoint policy
   call, the credential resolution rule and the controlled-driver guard, and the copies
   would have drifted on the first change to either.
2. **`AssessProduct::verdictFor()`** computes a verdict without persisting one.
   `persist()` is private.
3. **`SafetyGate::permits()`**, never `assert` — a preflight reports a refusal, it does
   not raise one.
4. **`Probe` has no `post()`** and never will.
5. **No mutating contract, job, deployment action or IaC bridge is a constructor
   dependency** of the service or any chain.
6. **`NoPreflightCodePathCanWriteTest`** asserts the negative three ways: a source scan
   of the whole preflight namespace for 22 write verbs; a reflection pass proving
   `TestConnection` is not a dependency; and a pin on the one collaborator that *can*
   persist, asserting the only method called on it is `verdictFor`.

The endpoint writes exactly one thing — the audit entry recording that a run happened —
and that is pinned separately.

## 8. Check dependency graph

The provider chain is a chain, and it stops:

```
configuration → machine → credential → endpoint policy → identity → capabilities → licence
```

Each link runs only if the one before it passed. Everything below a break is recorded
`not_tested` **naming the link that stopped it**. One missing credential reference
produces one blocker and four honest "we did not find out", instead of five findings
where four are derivative — because five findings for one cause is how an operator learns
to skim the report.

Asserted directly: `a_provider_with_no_credential_cannot_pass_and_nothing_below_it_is_guessed_at`
checks each downstream finding's status *and* that its summary names
`provider.credential`, and that the report holds exactly one blocker.

## 9. Result model

`PreflightReport` carries mode, scope, target, both timestamps, duration, overall status,
counts, findings, blockers, warnings, blocker reasons, verification levels, real
verification claims and next actions. Each `PreflightFinding` carries id, category,
status, target, summary, evidence class, blocker reason, next action, duration and
verification level.

Two decisions inside it:

- **Aggregation lives on the report, not in the orchestrator.** One blocking finding
  makes the whole report blocking — no percentage, no severity weighting that lets eleven
  passes outvote one missing credential. It is on the value object because the
  orchestrator is where somebody would be tempted to special-case it.
- **Real claims are a list of check ids, not a flag.** There is no field saying the estate
  is verified, because no such fact exists.

A run in which nothing was established reports `not_tested`, not `pass`.

### Status vocabulary

`pass`, `fail`, `blocked`, `warning`, `not_applicable`, `not_tested`. No OK, no BAD, no
MAYBE. `not_tested` is never a pass; it is the honest shape of "we do not know", and it
exists as a distinct case so an absent answer cannot read like a good one.

### Evidence class

Every check says where its answer came from: `configuration` (our own records),
`simulation` (a controlled provider), `real_read` (a real endpoint, identity-proven,
read-only), `none`. Only `real_read` can support a REAL_ claim. Without this field the
report's own summary could not tell "storage mapping present" (our database) from
"provider identity: Proxmox" (a fake said so) from "provider identity: Proxmox" (a real
cluster said so) — and the summary is the thing people quote.

## 10. CLI

`php artisan infra:preflight --mode=simulation|read-only-real [--provider= | --product= | --site= | --machine=] [--json]`

Exit codes: **0** no blocking finding, **1** at least one blocking finding, **2** the
invocation itself was wrong (no mode, unknown mode, two scopes at once). All four measured
(§23). A warning does not fail: an expiring licence is worth saying and is not worth
stopping a pipeline over, and if it were it would be a blocker.

`--json` prints the report and nothing else, no ANSI, in the same structure the Admin API
returns. The mode is printed before the first check line, and asserted to be — a
simulation header that scrolled off is a simulation report somebody quotes as proof.

Real output, estate scope, simulation mode, against the development database:

```
 SIMULATION — the whole estate
 [BLOCKED] provider.none — the estate: No provider is registered, so nothing can be provisioned through anything.
 [BLOCKED] dependency.backup_provider — backups: No backup provider is configured…
 [PASS]    dependency.monitoring — monitoring: The metrics registry produces 60 series famil(ies).
 …
 Mode: SIMULATION
 Checks: 28   Passed: 1   Failed: 2   Blocked: 24   Warnings: 0
 Verification: CODE_COMPLETE, TESTED, RUNTIME_VERIFIED
 Real infrastructure verified: NONE
```

## 11. Admin integration

`POST /api/admin/infrastructure/preflight`, on the existing Infrastructure section — no
new Admin area, and no new page: the **Readiness** screen already answers "why can I not
sell this", and the preflight is the detailed form of that answer, so a `PreflightPanel`
was added there. A second screen would have meant two places to look.

- The frontend judges nothing. Whether a finding blocks, what to do about it and whether
  anything may claim real verification are the service's answers, rendered.
- The frontend never contacts a provider and holds no credential.
- Every run is fresh. There is no cache and no "recent result" shortcut: showing a stored
  success as a newly executed run is the one thing a diagnostic must not do.
- Typed response model in `controlCenterQueries.ts`; the UI chrome carries English and
  Arabic labels.
- **Known limitation, stated plainly:** the report's *prose* — every summary and every
  next action — is English in both the CLI and the screen. Localising it would mean a
  translation key per finding with interpolated counts, which is a body of work in its own
  right and beyond this gap. The chrome is localised; the diagnostic text is not.

### Permissions

The route requires `infrastructure.view`, which is what a simulation run needs. A
`read_only_real` run sends real credentials to real endpoints — the same act as pressing
"test connection" — so the controller additionally requires `provider.manage` for that
mode. The second half cannot be middleware: the permission depends on the mode, and
middleware does not see the body. Read-only preflight confers no write capability: the
service cannot write, whoever calls it.

### Rate limiting

`throttle:preflight`, keyed on the caller **and the mode**: 60/minute for simulation,
6/minute for real. The limit protects the providers, not this platform — an operator
clicking a button twenty times must not become a burst at a hypervisor that has customers
on it.

### History

Nothing new is persisted. A preflight composes from evidence that is already recorded —
`connection_tests`, capability rows, readiness rows, licence rows — and the run itself is
recorded in the audit log. A heavy persistence model for a value that is cheap to
recompute, and must be recomputed to be honest, would have been the wrong trade.

## 12. Provider identity reuse

The Gap 2 testers are called through `ProbeProvider`. Preflight inherits all of it:
TCP connect is not proof, a TLS handshake is not proof, HTTP 200 is not proof, and another
product's schema is not proof. `a_verified_handshake_with_nothing_behind_it_cannot_pass`
carries the 30B.0 finding into this layer, and
`another_products_response_cannot_pass_as_this_ones` serves WHM's own answer to the
Proxmox driver.

The Gap 2 distinctions survive into the report with a different next action for each:
`IdentityMismatch` says *correct the endpoint, and do not rotate the credential — it was
never judged*; `AuthFailed` says rotate it; `CredentialMalformed` says store it in the
form the driver sends; `TlsFailed` says add the authority to the trust store and do not
disable verification; `PermissionInsufficient` says fix the role, the credential is fine.
Nothing maps to "provider unavailable".

Known exceptions reported honestly: the mail relay has no endpoint to dial, and the `.sy`
registry's contract is unavailable, so both report `NOT_IMPLEMENTED` rather than
"connected".

## 13. Credential checks

Reported only as `PRESENT`, `MISSING`, `REVOKED` or `ENVIRONMENT_MISMATCH`. Never a
value, never a masked value, never a length. Presence is asked through
`SecretResolver::exists()`, which answers without putting the value in a local.

The cross-environment refusal is reported before anything is dialled, and it is a refusal
to *resolve*: a staging token is not tried against production, not even to see what
happens. `no_report_ever_contains_the_credential_value` and
`the_response_carries_no_secret_and_no_credential_reference` search the whole serialised
report.

## 14. Mapping checks

Per product family, through the models' **own** scopes and methods — `scopeSchedulable`,
`canHost`, `scopeInstallable`, `acceptsPlacement`, `freeCpuCores`, `freeGib` — rather than
a second set of placement rules, because a preflight that decided for itself which nodes
are eligible would answer differently from the scheduler that actually places the machine,
and the discrepancy would only show up on the order that failed.

- **Compute:** cluster accepting placement, eligible node, active storage, capacity,
  installable template, an active address pool. The address check is asked whatever the
  template check found, and it counts active pools rather than pool rows. A pass there
  is less than an address the allocator can hand out; `MappingChain::addressFinding()`
  lists what `IpAllocator::reserve()` asks that the check does not.
- **Dedicated:** a registered machine cleared for reimaging, and a controller bound to it.
  No simulated hardware satisfies this in either mode.
- **Hosting:** an active node and a mapped package.
- **Domains:** a catalogued TLD that is enabled and open to registration.
- **Prepared products** (CDN, object storage, GPU, email hosting, Kubernetes): reported
  `not_applicable` — they have no adapter, and inventing a mapping check for a thing with
  no provider would train an operator to ignore findings.

Capacity is reported in three states, not two: **unknown** is a real and common answer — a
cluster that has never been reconciled reports no capacity at all, and calling that
"insufficient" would send an operator to buy hardware they already have.

## 15. Licence checks

Read from the licence centre, never inferred from a provider API answer. A panel that
answers its API is a panel whose licence is serving *now*; the expiry date and the renewal
are recorded facts. `active` passes, `expiring` warns and does not block, everything else
blocks on `BLOCKED_LICENSE` with "no credential change will fix this".

## 16. Hardware and network checks

Hardware: registered, classified, cleared, with a controller bound. `SafetyGate::permits`
is asked rather than asserted, so a machine nobody has cleared for a read is reported as
off limits rather than ending the run — the classification is the estate owner's decision,
not a fault of the provider.

Network: `EndpointPolicy` plus the provider identity tester. No socket success is ever
converted into evidence. Both directions are tested: a metadata-service endpoint is
blocked, **and** a private address for a provider on our own hardware is accepted — because
a Proxmox cluster is on the management network, and a preflight that refused private
addresses would refuse every correct deployment.

## 17. Backup and monitoring checks

Backup: provider configured and in service, the four alert-critical series actually
produced, and verification state observable — with the middle case called out, because
snapshots that exist and have never been verified are the failure that looks exactly like
success until somebody needs a restore. No preflight in either mode claims a restore has
been verified.

Monitoring: asked of the registry that serves `/metrics`, at runtime. This is where §28
found the defect in §22.

## 18. Readiness integration

`ProductReadinessEvaluator` is consulted through `AssessProduct::verdictFor()`. There is
no `canSellVps()` here and there will not be one — the ladder, the dependency recursion,
the capability gate and the sellability declaration live in the evaluator, and a second
opinion would eventually disagree with the one the order pipeline enforces.

`ready_to_sell` is never reached by a preflight and never by the engine: it is a person's
declaration. The report says so in as many words.

Global runs report the five sold families separately and never average them. One family
being sellable while another is blocked is the normal state of a platform being brought
up, and a single aggregate green would hide it.

## 19. Security

| property | how |
|---|---|
| customer cannot run one | plain user → 403 |
| unauthenticated cannot run one | → 401 |
| read-only in real mode needs the dial permission | `infrastructure.view` alone → 403 on `read_only_real`, 200 on `simulation`; granted directly rather than via a seeded role so the assertion cannot be skipped |
| endpoints still go through `EndpointPolicy` | metadata address blocked; the chain stops |
| no secret in the report | whole serialised report searched |
| no secret in the audit entry | context decoded and searched |
| no credential reference variable in the response | asserted |
| a fake cannot answer a production row | blocked in **both** modes; see §21 |
| cross-environment credential refused | before resolution |
| no raw provider error or stack trace exposed | exception *class* recorded, message discarded |

## 20. False-pass prevention and positive twins

Thirty tests in `APreflightSaysExactlyWhatIsInTheWayTest`. Every refusal has a twin
proving the correct arrangement is accepted, because a preflight that refused everything
would pass every false-pass test in the file — and an operator who has seen it refuse a
correct configuration twice stops reading it.

| cannot pass | positive twin |
|---|---|
| no credential | a correct reference reaches the tester |
| credential from another environment | — (refusal is the property) |
| backend holds nothing behind the reference | — |
| revoked credential | — |
| identity mismatch | the product's own answer passes and earns a claim |
| verified handshake with nothing behind it | as above |
| another product's response | as above |
| endpoint the policy refuses | a private address for a provider on our own hardware is accepted |
| licensed product with no licence | an active licence passes; an expiring one only warns |
| missing storage mapping | a mapped storage pool passes |
| template with no provider reference | a template with one passes |
| simulation claiming real verification | — |
| controlled provider on a production row | a controlled provider on a staging row still rehearses |
| unexercised capability becoming REAL_INFRA_VERIFIED | — |

## 21. Deliberate breakage

Each applied, the suite run, the change reverted.

| # | breakage | result |
|---|---|---|
| A | credential prerequisite bypassed, so identity runs without one | **1 failure** — `a_provider_with_no_credential_cannot_pass_and_nothing_below_it_is_guessed_at` |
| B | `ProbeProvider`'s production-row guard removed | **passed — the gate was vacuous. See below.** |
| B2 | the preflight's own production-row refusal removed | **1 failure** — `a_controlled_provider_cannot_satisfy_a_production_row` |
| C | required storage mapping check removed | **1 failure** — `a_product_with_no_storage_mapping_cannot_pass` |
| D | aggregation averages instead of blocking | **10 failures** across credential, identity and mapping tests |
| E | a write added to the orchestrator ("keep the screen up to date") | **2 failures** — the API no-write test and the source scan |
| F | `MonitoringServiceProvider` unregistered again | **2 failures** — both new collector-registration tests |

### Breakage B is the most valuable result in this gap

Removing the guard changed nothing, and the suite noticed nothing. The reason: the
real-mode branch returned a soft **warning** for a controlled driver — "nothing about this
is evidence about real infrastructure" — and returned *before* probing, so the guard was
never reached. That reads as reasonable and was wrong twice over: a warning does not
block, so a production row answered by a fake could carry a passing report; and the early
return made the guard unreachable.

Fixed by refusing a controlled driver on a production row **before the mode is consulted
at all**, and the assertion now runs in both modes. B2 proves the new refusal is load
bearing, and the `ProbeProvider` guard is independently covered by Gap 2's
`a_staging_credential_is_never_tried_against_production` — verified by removing it and
watching that test fail.

## 22. Findings this gap produced

### 22.1 The application registered none of its metric collectors — fixed

**The most serious finding of this phase.** `MonitoringServiceProvider` was not in
`bootstrap/providers.php`. It was registered only *inside* the monitoring tests, each of
which calls `$this->app->register(MonitoringServiceProvider::class)` in its own setup.

Measured, in a normally booted application:

```
families: 2
 - lynomia_metrics_collect_duration_seconds
 - lynomia_metrics_collector_up
any backup series? NO
```

Two families is what a registry with **zero** collectors produces, because they are how it
describes itself. So a booted application exported nothing from any of the sixteen
collectors, and every alert rule in `infrastructure/monitoring` read a series that nothing
produced — and a rule over an absent series does not fail, it evaluates to an empty vector,
which on a dashboard and in an alert list is indistinguishable from a rule that is passing.

Gap 1 wrote the backup collector and proved it produces the six series the backup alerts
read. That was true and it was not sufficient: the collector was correct, it was named in
the provider, and the provider was never loaded. Gap 1's architecture test checked that the
provider's *source* mentions `BackupCollector::class` — which it did.

After registering it:

```
families: 60
backup series: lynomia_backup_collector_last_run_timestamp_seconds, lynomia_backup_deletion_total,
lynomia_backup_file_restores_total, lynomia_backup_last_success_timestamp_seconds,
lynomia_backup_retention_total, lynomia_backup_task_last_status,
lynomia_backup_unverified_snapshots, lynomia_backup_verify_last_run_timestamp_seconds,
lynomia_backup_verify_last_status
```

`TheApplicationActuallyRegistersItsCollectorsTest` now asks the application, registering
nothing and arranging nothing, and breakage F proves it catches the original defect.

This is the difference between reading code and measuring it, and it is the whole argument
for §28 existing as a runtime check.

### 22.2 A translation key printed as an operator instruction — fixed

`BlockerReason::nextAction()` returns a translation *key* on purpose, for the Control
Center to render in the operator's language. `PreflightFinding::blocked()` defaulted to it,
so `infra:preflight` printed `controlCenter.guidance.dependency` where an operator expected
an instruction. A raw key is worse than a vague sentence: it looks like a bug in the tool
and says nothing.

Found by running the command, not by testing it. The next action is now a required
argument and always a sentence, which also produces better advice — "Run the preflight
against shared_hosting and clear its blockers first" beats "Make the provider this one
depends on ready first". `no_next_action_is_a_translation_key` is the gate.

A second, smaller error came out of the same fix: the requirement advice initially reused
the provider-screen wording for `BlockerReason::Dependency` ("make the product this one
depends on ready first"), which is wrong on a requirement — there, Dependency means the
provider that should satisfy it is absent, not that another product is in the way.

### 22.3 A resource that forwarded its shape past the drift gate — fixed

`PreflightReportResource` first delegated to the report's own `toArray()`. Shorter, and
wrong: the API suite reads published field names out of the resource class itself, so a
resource that forwards publishes a shape nothing can check. The gate caught it. Fields are
now literal, and the nested counts moved onto `PreflightReport::counts()` so the shallow
gate can see one key instead of seven.

### 22.4 A dead check that could never fire — removed

The first template check looked for installable templates carrying no provider reference.
`VmTemplate::scopeInstallable` already requires one, so the branch was unreachable.
Replaced with a check that counts active-but-unreferenced templates separately, which tells
the operator which of two different things to do.

### 22.5 Failure isolation demonstrated by accident

The first estate run hit an unmigrated development database. `QueryException` per product
family became five findings naming each family, and the run completed with the other
answers intact — §49 proven in the wild rather than only in a test.

### 22.6 `hosting:preflight` is a different question, and stays separate

Recorded as a decision, not an omission. It judges facts **collected on a target machine**
— its operating system, what is already listening, what DNS says about the machine's own
name, what the vendor says about its licence — arriving as JSON from a collector, and
answers "may a panel be installed on this host". The command deliberately does not gather
them: gathering and judging in one place would need root on the judged machine and would
hold both the panel licence key and shell access. It runs as part of install automation,
before the machine is in the estate.

Unified preflight answers "what prevents this configured provider or product from being
used". Neither can answer the other's question, and one command pretending to answer both
would be worse at each. The CLI is untouched, and the hosting mapping section names the
node gate as a separate prerequisite so there are not two sources of truth for one
question.

## 23. Performance

`APreflightsDatabaseWorkStaysBoundedTest` measures one provider against eight. Network
calls grow with provider count necessarily — each has to be asked. Database queries must
not: a chain lazily loading each provider's credential, licence, machine and capability
rows would cost four extra queries per provider, and the slowdown would arrive exactly
when the estate got big enough to matter. Rows are eager-loaded once at the top of each
run and threaded through; the allowance is **1 query per additional provider** and a second
test proves the counter is measuring something.

A whole-run deadline of 120 seconds bounds a global real-mode run; targets past it are
recorded `not_tested` naming the budget rather than dialled. No concurrency: this codebase
has no convention for concurrent outbound HTTP, and a preflight is the wrong place to
introduce one.

## 24. Tests

| check | command | result |
|---|---|---|
| Preflight suites | `--filter='NoPreflightCodePathCanWrite\|APreflightSaysExactlyWhatIsInTheWay\|OnePreflightServiceAnswersTheCliAndTheScreen\|APreflightsDatabaseWorkStaysBounded'` | 51 tests, passed |
| New collector-registration gate | `--filter='TheApplicationActuallyRegistersItsCollectors'` | 2 tests, passed |
| Focused regression (providers, infrastructure, readiness, endpoint policy, credentials, licences, monitoring, backups, security, architecture) | `--filter='Providers\|Infrastructure\|Readiness\|EndpointPolicy\|Credential\|Licence\|Monitoring\|Backup\|Security\|Preflight\|Architecture'` | 896 tests, 117,648 assertions, passed |
| **Whole backend suite** | `php artisan test` | **3,243 tests, 139,889 assertions, passed** |
| Code style | `vendor/bin/pint --test` | passed |
| Static analysis | `phpstan analyse` | 0 errors |
| OpenAPI | `php artisan openapi:generate` + `npm run openapi:lint` | 249 operations, valid (6 pre-existing warnings) |
| Frontend types | `npx tsc -b` | clean |
| Frontend lint | `npx eslint . --max-warnings 0` | clean |
| Frontend unit | `npx vitest run` | 81 files, 443 tests, passed |

Gap 1 and Gap 2 gates remain green: the backup producer gate, the orphan-metric gate in
both directions, the 98-case identity negative matrix, the positive identity suite, the
secret/body leak suite and the endpoint-policy bypass regressions all pass unchanged.

## 25. Real-validation boundary

- `CODE_COMPLETE` — the service, both modes, three check chains, the CLI, the endpoint,
  the Admin panel, the write guard.
- `TESTED` — 51 preflight tests, 896 in focused regression, 3,243 across the backend; six
  deliberate breakages each caught by a named gate, one of which exposed a vacuous gate.
- `RUNTIME_VERIFIED` — both modes executed against a real database; the monitoring defect
  in §22.1 and the key leak in §22.2 were found by running the thing, not by reading it.

Not claimed:

- `REAL_INFRA_VERIFIED` — **NONE.** No real Proxmox cluster, Backup Server, hosting node,
  BMC or chassis was contacted. READ_ONLY_REAL is CODE_COMPLETE and was exercised against
  faked HTTP, SDK and process layers. 30B.0's `BLOCKED_CREDENTIALS`, `BLOCKED_HARDWARE`
  and `BLOCKED_NETWORK` are unchanged.
- `REAL_PAYMENT_VERIFIED` — **NONE.**
- `REAL_REGISTRAR_VERIFIED` — **NONE.**
- `REAL_HOSTING_VERIFIED` — **NONE.**
- `READY_TO_SELL` — **NONE.** The readiness engine does not emit it and no preflight can;
  it is a person's declaration.

A preflight that runs correctly is not evidence that an estate exists to be preflighted.
What this gap establishes is that the question "what exactly prevents this from being
used" now has one answer, that the answer cannot be produced by a socket opening, that
producing it cannot change anything, and that a green simulation cannot be mistaken for a
working datacenter.

## 26. Exit criteria

| criterion | status |
|---|---|
| Gap 2 CI exact SHA | GREEN — run 168, 9/9 jobs |
| Existing 30B-P architecture | PRESERVED |
| Unified `InfrastructurePreflightService` | COMPLETE |
| Duplicate readiness engine | ZERO |
| Duplicate provider configuration model | ZERO |
| CLI `infra:preflight` | COMPLETE |
| Admin uses same service | YES — endpoint and Readiness-page panel |
| SIMULATION mode | COMPLETE |
| READ_ONLY_REAL mode | CODE_COMPLETE |
| Write-capable mode | ABSENT |
| Write methods reachable from preflight | ZERO |
| Config checks | COMPLETE |
| Credential checks | COMPLETE |
| `EndpointPolicy` | REUSED |
| Identity testers | REUSED |
| Capability checks | COMPLETE |
| Required mapping checks | COMPLETE |
| Licence checks | COMPLETE |
| Hardware checks | COMPLETE where applicable |
| Backup dependency | COMPLETE |
| Monitoring dependency | COMPLETE — and it found a live defect |
| Product readiness integration | COMPLETE |
| Simulation satisfying production | IMPOSSIBLE |
| Secret exposure | ZERO |
| Root-cause blocker reporting | COMPLETE |
| Exact next actions | COMPLETE |
| False-pass gates | GREEN |
| Positive twins | GREEN |
| Deliberate breakage | PROVEN (6, including one that exposed a vacuous gate) |
| Focused regression | GREEN |

One item is delivered narrower than the brief allows for and is stated rather than
glossed: the preflight report's diagnostic prose is English in both surfaces (§11).

---

## Appendix — files

**New** — `apps/control-plane/src/Modules/Infrastructure/`

| file | lines |
|---|---|
| `Domain/Preflight/PreflightMode.php` | 73 |
| `Domain/Preflight/PreflightScope.php` | 44 |
| `Domain/Preflight/PreflightRequest.php` | 38 |
| `Domain/Preflight/CheckStatus.php` | 66 |
| `Domain/Preflight/CheckCategory.php` | 28 |
| `Domain/Preflight/EvidenceClass.php` | 65 |
| `Domain/Preflight/VerificationLevel.php` | 36 |
| `Domain/Preflight/PreflightFinding.php` | 198 |
| `Domain/Preflight/PreflightReport.php` | 273 |
| `Application/Preflight/InfrastructurePreflightService.php` | 613 |
| `Application/Preflight/Checks/ProviderChain.php` | 748 |
| `Application/Preflight/Checks/MappingChain.php` | 494 |
| `Application/Preflight/Checks/DependencyChain.php` | 276 |
| `Http/Controllers/PreflightController.php` | 126 |
| `Http/Requests/RunPreflightRequest.php` | 38 |
| `Http/Resources/PreflightReportResource.php` | 90 |

**New elsewhere**

- `src/Modules/Providers/Application/Services/ProbeProvider.php` (232) — the extracted read half
- `app/Console/Commands/InfrastructurePreflightCommand.php` (230)

**New tests**

- `tests/Architecture/NoPreflightCodePathCanWriteTest.php`
- `tests/Architecture/TheApplicationActuallyRegistersItsCollectorsTest.php`
- `tests/Feature/Infrastructure/APreflightSaysExactlyWhatIsInTheWayTest.php`
- `tests/Feature/Infrastructure/OnePreflightServiceAnswersTheCliAndTheScreenTest.php`
- `tests/Feature/Infrastructure/APreflightsDatabaseWorkStaysBoundedTest.php`

**Modified**

- `bootstrap/providers.php` — registers `MonitoringServiceProvider` (§22.1)
- `src/Modules/Providers/Application/Actions/TestConnection.php` — now the recording half
- `src/Modules/ProductReadiness/Application/Actions/AssessProduct.php` — `verdictFor()`
- `src/Modules/Audit/Domain/Enums/AuditAction.php` — `InfrastructurePreflightRun`
- `src/Providers/RateLimitServiceProvider.php`, `config/security.php` — the preflight limiter
- `routes/api_admin.php`, `resources/openapi/{operations,schemas}.php`, `docs/openapi.yaml`
- `tests/Architecture/EveryStateAScreenShowsIsTranslatedTest.php` — registers `CheckStatus`
- `apps/web/src/lib/controlCenterQueries.ts`, `apps/web/src/features/controlCenter/ReadinessPage.tsx`
- `apps/web/src/i18n/locales/{en,ar}.json`, `apps/web/src/lib/statusVocabulary.ts`
