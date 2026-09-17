# Phase 30B-SIM · Gap 8 — Final software closure

**Approved software scope is code-complete and locally runtime-verified against
controlled providers. Real infrastructure/provider validation has not begun.**

That sentence is the whole finding, and every section below either supports it
or narrows it. Nothing in this document says production ready, production
verified, ready to sell, real infrastructure complete, or 100% operational,
because none of those is true and none of them was tested.

> **Superseded in part by §41.** This document was reviewed after it was
> written, and three of its claims did not survive: WordPress and Domains were
> declared `Complete` without a production-capable adapter for what they
> require, the backup verification initiator was recorded as implemented and
> verified when it was effective only against the controlled simulator, and the
> legal prerequisite's "software half is done" was untrue — the acceptance was
> validated and discarded. §41 corrects each, recomputes the counts, and is the
> current state. Read it before relying on §5, §9, §21 or §38.

---

## 1. Provenance

Gap 8 is the eighth and final closure pass of Phase 30B-SIM. Its job was not to
build a feature. It was to take every item the previous seven gaps, the customer
portal closure, 30B.0 and 30B-P wrote down and did not finish, and give each one
exactly one of four answers: implemented and verified, removed from approved
sellable scope, an external prerequisite with no missing software work, or not
implemented because a trustworthy provider contract does not exist.

The register in §4 was built by reading the actual reports rather than by
trusting any summary of them. Where a report's own wording was the evidence, it
is quoted.

Two things in this gap were **not** carried items. They were found by doing the
work: `vm_templates` had no production write path at all (§7), and the alert
metric-producer gate covered one rule file out of six (§29). Both are recorded
in the register as Gap 8 findings rather than folded into the fixes that
exposed them.

---

## 2. Starting SHAs

| What | SHA |
|---|---|
| Gap 7 functional code | `6d06d31ef979f42b202f631e542f301e89938aff` |
| Gap 7 documentation HEAD, and this gap's starting HEAD | `b46c8d158fdf10e2b4e12e7413b47d8259c892c7` |

The entry gate required checking whether the branch had advanced past
`b46c8d1` before starting, and **not** resetting if it had. It had not:
`git log` showed `b46c8d1` as the tip of `claude/hv-t6hq1p` with no commits
after it, so there was no unreviewed product work to classify and nothing to
separate from this closure.

## 3. Gap 7 CI

Verified independently rather than accepted from the previous report: GitHub
Actions run **176**, conclusion `success`, status `completed`, **9 of 9** jobs
green, on the first attempt, for `6d06d31`. The documentation commit `b46c8d1`
is documentation only and changes no functional code.

---

## 4. Master carried-gap register

Thirty-eight items, read out of the reports themselves. The classification
vocabulary is the one the brief fixes: `FINAL_CODE_BLOCKER`,
`APPROVED_SCOPE_REMOVAL`, `EXTERNAL_REAL_VALIDATION`,
`EXTERNAL_PROVIDER_CONTRACT`, `BUSINESS_LEGAL_PREREQUISITE`,
`NON_BLOCKING_QUALITY_ITEM`, `ALREADY_RESOLVED`. No row says "future" or
"later".

### From Gap 7 (§33 of its report)

| # | Carried item | Classification | Gap 8 outcome |
|---|---|---|---|
| 1 | A purchased VPS is built with no OS image at all | FINAL_CODE_BLOCKER | **IMPLEMENTED AND VERIFIED** — §7 |
| 2 | A dedicated power operation is sent to the BMC twice | FINAL_CODE_BLOCKER | **IMPLEMENTED AND VERIFIED** — §8 |
| 3 | A task-failed build leaves the service active | FINAL_CODE_BLOCKER (product decision) | **DECIDED AND IMPLEMENTED** — §10 |
| 4 | Nothing in the platform starts a backup verification | FINAL_CODE_BLOCKER | **IMPLEMENTED AND VERIFIED** — §9 |
| 5 | The browser reboot-acknowledgement race | FINAL_CODE_BLOCKER (known flake) | **CONTRACT RESOLVED** — §11 |

### From Gap 6 (§32 of its report)

| # | Carried item | Classification | Gap 8 outcome |
|---|---|---|---|
| 6 | `reverse_dns.clear_ptr` — contract absent | FINAL_CODE_BLOCKER | **IMPLEMENTED AND VERIFIED** — §16 |
| 7 | `wordpress_installer.uninstall` — contract absent | APPROVED_SCOPE_REMOVAL | **REMOVED FROM SCOPE** — §17 |
| 8 | `wordpress_installer.ssl` — contract absent | APPROVED_SCOPE_REMOVAL | **REMOVED FROM SCOPE** — §17 |
| 9 | `compute.gpu_passthrough` — contract absent | APPROVED_SCOPE_REMOVAL | **ALREADY OUT OF LAUNCH SCOPE** — §19 |
| 10 | File-level backup on a real adapter | EXTERNAL_PROVIDER_CONTRACT | **NOT_IMPLEMENTED, stated** — §18 |
| 11 | WordPress on a real adapter | EXTERNAL_PROVIDER_CONTRACT | **NOT_IMPLEMENTED, stated** — §17 |
| 12 | `.sy` registrar | EXTERNAL_PROVIDER_CONTRACT | **UNCHANGED, and nothing invented** — §20 |
| 13 | CDN, object storage, email hosting — no adapter | APPROVED_SCOPE_REMOVAL | **PREPARED, gated unsellable** — §6 |
| 14 | Load balancer, certificates, cluster lifecycle — no interface | APPROVED_SCOPE_REMOVAL | **Category only, no product depends on them for launch** — §6 |
| 15 | Dedicated power durable idempotency | FINAL_CODE_BLOCKER | same as #2 — §8 |
| 16 | Durable state for five simulators | ALREADY_RESOLVED | closed by Gap 7's controlled-simulation store |
| 17 | Read-path fault injection on compute | ALREADY_RESOLVED | closed by Gap 7's argument-driven fault markers |

### From Gap 5 (§25 of its report)

| # | Carried item | Classification | Gap 8 outcome |
|---|---|---|---|
| 18 | `.internal` is refused by `EndpointPolicy` in every mode | NON_BLOCKING_QUALITY_ITEM | **DECIDED — stays refused, with the reason in the standard** — §14 |
| 19 | No provider naming constraints are encoded | EXTERNAL_PROVIDER_CONTRACT | **NOT ENCODED, deliberately** — §13 |
| 20 | Region, Datacenter and Site remain three concepts | NON_BLOCKING_QUALITY_ITEM | **Unchanged; no defect follows from it** — §13 |
| 21 | `hosting_nodes.hostname` has no unique index | FINAL_CODE_BLOCKER (open domain question) | **DECIDED AND IMPLEMENTED** — §13 |
| 22 | The public DNS suffix has no consumer | APPROVED_SCOPE_REMOVAL | **REMOVED** — §14 |
| 23 | Per-site suffixes are not modelled | NON_BLOCKING_QUALITY_ITEM | **DECIDED, and the audit now states its own limit** — §14 |

### From Gap 4 (§28 of its report)

| # | Carried item | Classification | Gap 8 outcome |
|---|---|---|---|
| 24 | Only two controlled drivers; backup, registrar and payment unrehearsable | ALREADY_RESOLVED | closed by Gap 6's nine controlled drivers |
| 25 | Repository-wide hostname rename not performed | NON_BLOCKING_QUALITY_ITEM | the naming standard makes the zone configuration; no rename is required to onboard |

### From the Customer Portal final closure review (§21 of its report)

| # | Carried item | Classification | Gap 8 outcome |
|---|---|---|---|
| 26 | Dedicated power idempotency — no durable operation row | FINAL_CODE_BLOCKER | same as #2 — §8 |
| 27 | 27 dynamically-composed translation keys, not proven dead | NON_BLOCKING_QUALITY_ITEM | unchanged; the parity gate covers the static keys |
| 28 | 3 `FUTURE_PREPARED` operator hooks | NON_BLOCKING_QUALITY_ITEM | unchanged, by decision, and not customer-visible |
| 29 | In-app dirty-form SPA route change is not guarded | NON_BLOCKING_QUALITY_ITEM | unchanged; closing it needs a Data Router migration and no half-fix was introduced |
| 30 | No loading skeletons | NON_BLOCKING_QUALITY_ITEM | unchanged; every screen has a `Loading` state |
| 31 | Handwritten frontend response types | NON_BLOCKING_QUALITY_ITEM | unchanged, with the bounded drift gate |
| 32 | Dark-theme path without a product toggle | NON_BLOCKING_QUALITY_ITEM | **not reopened and not marked NOT_IMPLEMENTED** |
| 33 | `@vitest/mocker` GHSA-82fw-gwwq-j7x9 | NON_BLOCKING_QUALITY_ITEM | re-run, still 2 moderate, development-only — §22 |
| 34 | Self-service account deletion and export | BUSINESS_LEGAL_PREREQUISITE | **ticket-only at launch; not a code gap** — §21 |
| 35 | AT-5, AT-8, AT-9, AT-13, AT-16, AT-18 | NON_BLOCKING_QUALITY_ITEM / BUSINESS_LEGAL_PREREQUISITE | AT-8 is the legal prerequisite in §21 |
| 36 | Legal Terms of Service and Acceptable Use Policy | BUSINESS_LEGAL_PREREQUISITE | **documents outstanding; the software half is now done** — §21 |

### Found by Gap 8 itself

| # | Finding | Classification | Gap 8 outcome |
|---|---|---|---|
| 37 | `vm_templates` had no production write path — no endpoint, no discovery, no command | FINAL_CODE_BLOCKER | **IMPLEMENTED AND VERIFIED** — §7 |
| 38 | The alert metric-producer gate covered one rule file of six | FINAL_CODE_BLOCKER (unsafe deferred architecture) | **GATE WIDENED** — §29 |

**Items found: 38. Items resolved or given a final classification: 38.**
Eleven were real code work; seven were scope truth; six are external contracts;
two are business or legal; twelve are non-blocking quality items each stated
with what closing it would take.

---

## 5. Approved product scope

Derived from `ProductReadiness\Domain\Enums\Product::softwareState()`, which is
the repository's own answer rather than a list in a document.

| Product | Software state |
|---|---|
| VPS | `Complete` |
| Dedicated | `Complete` |
| Shared hosting | `Complete` |
| WordPress | `Complete` |
| Domains | `Complete` |
| DNS | `Complete` |
| Backups | `Complete` |

Seven products are in approved software scope. Every one of them is
`CODE_COMPLETE` and `RUNTIME_VERIFIED` against controlled providers only. None
is `READY_TO_SELL`, and §39 says why that is not a formality.

## 6. Disabled / future product scope

| Product | Software state | Why it cannot look sellable |
|---|---|---|
| CDN | `Prepared` | no adapter, and `EveryPreparedCategoryHasAContractTest` forbids writing one — including a fake |
| Object storage | `Prepared` | as above |
| GPU compute | `Prepared` | as above, and `compute.gpu_passthrough` is a contract the compute interface does not model |
| Email hosting | `Prepared` | as above |
| Managed Kubernetes | `ReadinessOnly` | no contract at all; the category exists so a product can name what it will need |

Three architecture gates make this scope truth rather than a claim:
`PreparedProductsCannotBeSoldOnHopeTest`, `NothingIsSellableOnHopeTest` and
`EveryDeclaredCapabilityHasAConsumerTest`. **No fake adapter was written to
make any matrix green**, which is the one shortcut that would have made every
number in this document worthless.

---

## 7. VPS template fix

**The defect.** `CreateVpsHandler` composed a build request that named no
template, so the platform asked the hypervisor to clone nothing. Gap 7 proved
the absence in two places at once — the controlled hypervisor recorded no
installed template and the job payload had no `template_reference` key.

**The chain, traced rather than assumed.** The decision now travels the same
route the Gap 4 mapping architecture already uses for clusters, address pools
and storage classes:

```
Plan::placement_constraints.template_slug        (product configuration)
  → ProvisionOrderedService::templateFor()       (resolution at placement)
      · the declared slug, if the plan names one
      · else the single installable template the cluster has
  → provisioning_jobs.payload                    (durable: reference, slug, id, architecture)
  → CreateVpsHandler                             (validated before the build)
      · a payload with no reference is refused, permanently
  → PlacementRequest                             (architecture carried, not defaulted silently)
```

**Nothing is hardcoded.** No file in the chain mentions `debian-stable`,
Ubuntu, or a VMID. The slug comes from approved product configuration; the
provider reference comes from the row an operator recorded; the architecture
comes from the template and falls back to `x86_64` only when the template does
not say.

**Durable through serialisation.** Four keys are written into the job payload,
so an order retried weeks later builds the image the order bought rather than
whatever the cluster happens to hold that day.

**Validated before the build.** A build that reaches the handler with no
reference returns `FailureClass::Permanent` with
`vps.create_image_unavailable` and a message naming the plan or the cluster as
the thing that needs an image. It does not start a clone of nothing and it
does not retry.

**And the second half, which was not a carried item.** `vm_templates` had no
production write path: no admin endpoint, no discovery sweep, no console
command — a factory and the browser seeder. A real cluster onboarded through
the Control Center would have had zero images, so the refusal above would have
fired on every VPS order, and the only ways forward would have been raw SQL or
a code change. Onboarding infrastructure this platform already models must not
require a code change, so this was a blocker and not a rough edge.

`POST /api/admin/infrastructure/templates` records an image, `GET` lists them
with the count that decides whether a VPS can be built at all, and `DELETE`
withdraws one. Recorded rather than discovered, because the hypervisor knows
which of its guests are templates and does not know which of them this
platform may sell, what to call them in two languages, or whether the
operating system needs a licence. Two properties make it usable by a person:
recording the same cluster and slug twice is a correction rather than a 409,
and withdrawing an image is not a one-way door.

**Proof.** Golden: `TheVpsGoldenPathTest` asserts the controlled hypervisor
recorded the template the plan named. Negative: `CreateVpsHandlerTest` asserts
the permanent refusal and its code. Write path:
`RecordingTheImagesAClusterMayInstallTest`, 17 tests including the
authorisation split and seven bodies the API refuses.

## 8. Dedicated power idempotency

**The defect.** `ChangeDedicatedServerPower` talked to the BMC and then
recorded what it had done, so a retried POST rebooted a customer's machine
twice. Two resets is a defect even where the final power state is identical.

**Where the claim happens now.** In the control plane, in the database, before
any BMC call. `dedicated_power_operations` carries a unique `idempotency_key`
built from the machine, the action and the caller's `Idempotency-Key` header,
and **the insert is the claim** — no read-then-write, no advisory lock, no
cache. On PostgreSQL the insert is wrapped in its own transaction so a losing
racer rolls back to a savepoint instead of poisoning the caller's.

**The key distinguishes what it must.** Machine, action, and the caller's own
request identity — so two intentional reboots remain two reboots, and only a
replay of the *same* request is deduplicated. The header was already required
for every other mutating dedicated action; the API's behaviour is unchanged
apart from the replay.

**What a replay gets.** A settled request returns the first outcome. A replay
while the first is still in flight is refused with
`DedicatedOperationRefusedException::becauseTheSameRequestIsStillInFlight()`
rather than guessed at.

**Proof.** `ConcurrentDedicatedPowerTest` drives two real database connections
with no `RefreshDatabase` transaction, so the interleaving is the real one.
`DedicatedServerPowerEndpointTest` covers the replay through HTTP.
`TheDedicatedGoldenPathTest` counts provider calls and now expects one.

## 9. Backup verification orchestration

**The defect.** The platform could poll a verification, record its outcome and
report it to a customer, and nothing ever asked a datastore to read an archive
back. `startVerification` existed on the contract and on both drivers,
`Verifying` had transitions out of it, and no action, job or command put a row
into it — so `verified` was null for every backup the platform had ever taken.

**The trigger, derived from existing intent.** `VerifyStoredArchives` sweeps
for a backup that succeeded, has an archive id, and that nobody has read back.
No new flag: the intent was already in the row.

**Durable, idempotent and retry-safe.** Asking removes the row from the scope —
`Succeeded → Verifying` is what makes the sweep safe to run every five minutes
— and `verification_attempts` bounds a datastore that refuses, so a broken
archive is asked about a few times rather than for ever.

**Three-value truth preserved and, for the first time, completely written.**
Null is nobody has looked, true is it was read back, false is it could not be.
`ReconcileBackup` now writes that third value, which it previously left null —
collapsing the worst answer into the neutral one, so an archive checked and
found unreadable looked exactly like one nobody had got around to checking.

**Proof.** Golden:
`TheFailureMatrixTest::a_stored_archive_is_sent_for_verification_and_comes_back_verified`.
Idempotency: `running_the_verification_sweep_twice_starts_one_verification`.
Failure: `an_archive_the_datastore_cannot_read_back_is_never_reported_as_verified`
and `a_datastore_that_refuses_verification_is_asked_a_bounded_number_of_times`.

## 10. Partial-create / service-state decision

**The question, stated.** A provisioning job that creates a provider resource
and fails leaves a service the platform is billing for and a resource that may
or may not exist. What should the customer be told?

**The decision.** `ServiceStatus::Active` means *this service is paid for and
the platform intends to serve it* — it does not mean *its delivery finished*.
That is why no fifth enum state was invented: the model could already express
the condition, and a new status would have been a second vocabulary for
customers to learn.

What changed is the customer-facing projection. `CustomerServiceState` now
distinguishes the two reviews it has to express:

* a **provisioning** review eclipses the states that have not delivered yet
  (Pending, Provisioning, Failed);
* a **delivery** review eclipses everything except Terminated and an
  already-reviewed service, because a machine whose creation failed is in
  doubt whatever the service row says.

`ProvisioningJobKind::createsResource()` names the three delivering kinds in
one place instead of a list repeated at each call site.

**The policy, over the four provider-resource cases.**

| Case | What the platform knows | What the customer is told |
|---|---|---|
| Create succeeded, resource exists | delivery complete | the service's own status |
| Create failed before the provider was reached | nothing was created | under review |
| Create accepted, provider task failed | a resource may exist in some half-built state | under review |
| Create succeeded, a later operation failed | the resource exists; that operation did not | the service's own status, and the operation's failure |

No customer is told "fully active" or "healthy" after a terminal provisioning
failure. Nothing is destroyed and no address is released on a guess: the job
goes to `needs_review`, which puts it in front of a person.

**Proof.** `ThePartialCreatePolicyTest`, 11 tests, with the four-case table in
its docblock. `ServiceIndexEndpointTest` holds the other side — a failed
*reboot* must not make a running service look broken, which is the mistake the
first version of this fix made.

## 11. Browser race resolution

**The defect, and its mechanism.** Three specs asserted that the operations
channel said "Reboot requested" after a press. That assertion both passed and
failed inside a single CI run, in the same process against the same database.
The cause is in the product, not the browser: the acknowledgement is announced
under the operation's id and **replaced under that same id** the moment the
watcher's first read comes back terminal — deliberately, because two toasts
for one reboot is worse — and the browser suite runs the queue inline, so the
work is usually already finished when that first read lands. The window in
which those words exist is one HTTP round trip.

**The contract was changed, which the brief permits, rather than the assertion
retried.** The promise the product actually makes is: *the channel always says
where this operation stands, in the lifecycle's own words, and never a bare
"Success" that would be a claim about a machine rather than a report about an
operation.* `expectOperationReported()` accepts all seven titles
`WatchedOperations` can announce, because a helper that accepted six would be
the same race moved somewhere less visible.

The exact acknowledgement sentence is still asserted **exactly**, in
`watching-what-was-started.test.tsx`, where the read is a controlled fake and
the timing belongs to the test. A browser cannot assert it without racing the
server, and a test that races is not evidence about the product.

**No arbitrary sleeps and no `waitForTimeout` were added.** Proven by
repetition rather than by argument: the five race-sensitive wave-4 specs ran
**10 times in isolation — 50 passed**, and the three wave-5 session specs ran
**10 times — 30 passed**. Eighty consecutive passes, zero intermittent
failures.

## 12. Harness safety

`WorkerHarness` truncates a whole schema in teardown, on a second connection
it is handed. It now refuses to unless the target names itself a test
database — checked before a single table is touched.

**`APP_ENV` alone is not the check, and could not be.** `phpunit.xml` sets
`DB_DATABASE=lynomia_test` while `.env` sets `DB_DATABASE=lynomia`: the
environment name and the database are different facts, and the first version
of this guard compared the target against `env('DB_DATABASE')` and therefore
refused everything. The guard is a name-pattern check on the resolved target,
mirroring the insistence the browser suite already has about its own `e2e`
database.

**Proven without truncating anything.** `TheHarnessRefusesAnUnsafeDatabaseTest`
drives the guard by reflection against a refused name, so the refusal is
demonstrated rather than the destruction. Four tests.

## 13. Naming final decisions

| Gap 5 item | Decision |
|---|---|
| `hosting_nodes.hostname` uniqueness | **Unique among the nodes that still hold accounts.** Two serving panels under one name are two rows the platform believes are different machines and one name that reaches one of them — which reconciliation then reports as an orphan on one node and a missing account on the other. And a retired chassis must keep the name its replacement now uses, because that is what a hardware swap is. A **partial** unique index over `HostingNodeStatus::holdsAccounts()`, enforced in the database because two operators onboarding the same replacement in the same minute are two connections and an `exists()` on each of them both answers no. The SQL predicate and the PHP method are held in step by a test. |
| Provider-specific naming rules | **Not encoded, deliberately.** Proxmox node and storage limits, WHM and DirectAdmin hostname rules and registrar account id formats are contracts nobody here has read, and inventing them would assert a provider contract this repository does not have. The generic rule validates; a provider that refuses a name refuses it with its own message, classified and surfaced. |
| Region / Datacenter / Site as three concepts | Unchanged. Naming rules were written for the rows that exist and no defect follows from the model having three. |

## 14. DNS / private-endpoint policy

| Item | Decision |
|---|---|
| `.internal` | **Stays refused in every mode.** ICANN reserved it for private use in 2024, so an operator whose estate lives under it cannot configure this platform *by name*. The refusal is not weakened: `EndpointPolicy` already permits a private **address** for a machine on the management network, which is the case that matters, and admitting a name class the platform cannot resolve would widen an SSRF guard to fix a naming inconvenience. Recorded in `docs/infrastructure-naming-standard.md` as a decision with its reason. |
| Private management endpoints | **Already implemented and verified.** A machine endpoint may be private, because that is where it is; a provider that is not on our hardware is refused a private-range address. |
| `public_dns_suffix` | **Removed.** Declared in config, validated by `DnsSuffix`, documented in the standard, read by nothing. A setting that changes nothing is worse than a missing one, because an operator who fills it in has been told a lie about what the platform does with it. |
| Per-site suffixes | **Not modelled, and the audit now says so.** The platform holds one internal suffix, so a multi-site estate whose sites resolve under their own zones has correct names that the one-authority audit reports. What was wrong was the finding's wording, which offered only two readings and made a legitimate name look like a mistake. It now names the third and says that answering it would be a per-datacenter column. A report is not where an operator should have to read their tooling's limits. |
| Internal DNS suffix ownership | Unchanged and unset by default. A real internal zone is a fact about somebody's network; "not configured" is a reportable state with a next action, which is a better answer than a name that resolves nowhere. |

## 15. Admin / operational configuration ownership

Four owners, and the rule is which question each answers.

| Owner | What it owns | Examples |
|---|---|---|
| **Git / IaC** | structural topology — what exists and how it is wired | `infrastructure/` inventories, Ansible profiles, `resources/reference-topology/topology.php`, Prometheus rules |
| **Admin / Control Center** | operational control — which of the modelled things is enabled, approved, or offered right now | provider instances and their enablement, credential *references*, licences, desired state, plan approval, datacenters, racks, GPU devices, **and now the images a cluster may install** |
| **Secret resolver** | secret values, and only their names are stored | `credential_references.backend_reference` names an environment variable; `ControllerEnvironmentSecretResolver` reads it. The table carries `backend`, `backend_reference` and a non-reversible `masked_hint` — never a value |
| **Reference topology** | simulation only | every row stamped `development`, `reachable: false`, refused as a production zone |

Configuration files, by owner, all 30 of them: framework and deployment
concerns (`app`, `cache`, `database`, `filesystems`, `logging`, `mail`,
`queue`, `session`, `horizon`, `sanctum`, `cors`, `auth`, `permission`) are
the deployment's; platform operating policy (`backups`, `billing`, `compute`,
`dedicated`, `dns`, `domains`, `hosting`, `provisioning`, `payments`,
`security`, `support`, `teams`, `monitoring`, `geography`, `console_gateway`,
`infrastructure`, `legal`) is the operator's, set by environment variable and
never edited in business code; `services` holds provider endpoints and the
names of their secrets.

270 environment keys are referenced across those files. **No provider secret
value is stored in the database** — the credential registry stores references,
and `CredentialResource` publishes a hint of at most four characters of a
public identifier.

## 16. Provider-contract boundaries

| Contract | Status |
|---|---|
| `reverse_dns.clear_ptr` | **IMPLEMENTED.** `withdrawReverseDns()` recorded an intent nothing executed, so a released address kept answering for the customer who had given it back. `ReverseDnsProvider::clear()` is the contract, implemented against Cloudflare and the controlled simulator, and `WithdrawReverseDnsRecords` is its caller on a ten-minute cadence. `clear_ptr` therefore leaves the controlled driver's unsupported list, which is what the capability gate is for. |
| `wordpress_installer.uninstall` / `.ssl` | **WITHDRAWN AS REQUIREMENTS** — §17 |
| `compute.gpu_passthrough` | unchanged; the GPU product is `Prepared` — §19 |
| file-level backup on a real adapter | unchanged — §18 |
| WordPress on a real adapter | unchanged — §17 |
| `.sy` registrar | unchanged — §20 |
| load balancer, certificates, cluster lifecycle | no interface; no approved launch product depends on them |

## 17. WordPress final scope

`uninstall` and `ssl` were asked of a WordPress installer by
`ProductRequirements` and used by no approved product flow: a customer
removing a site terminates the service, and TLS is the control panel's own,
issued by the panel and never requested through this contract.

They were not "not implemented yet" — they were **mis-specified**, and the
honest fix is to stop asking. They leave
`ProviderCategory::WordPressInstaller` as well as the requirement, because
`EveryDeclaredCapabilityHasAConsumerTest` refuses a declared capability with no
consumer, which is exactly how this was found.

The remaining WordPress capability set is `install`, `version`, `staging`,
`clone`, `push_to_production`. A **real** adapter still does not exist —
neither panel adapter implements either WordPress contract — which is
`EXTERNAL_PROVIDER_CONTRACT` and is why WordPress is `CODE_COMPLETE` and not
`REAL_HOSTING_VERIFIED`.

## 18. Backup final scope

The verification initiator closes the last software gap in the backup
lifecycle (§9). What remains is external: `ProxmoxBackupProvider` does not
implement `FileLevelBackupProvider`, so the file-level path runs against the
controlled simulator only. The contract and the simulator exist; the real
adapter needs the provider's file-restore API read rather than guessed.

Nothing was written from documentation. **A backup is not verified until an
archive has actually been read back**, and that sentence is now enforced by a
sweep rather than asserted in a report.

## 19. GPU / product scope

`compute.gpu_passthrough` is a capability `ComputeProvider` does not model. The
GPU compute product that needs it is `Prepared`, not `Complete`, so it cannot
reach `ReadyForProduction`, cannot be marked sellable, and is not offered to a
customer. That is scope truth reached by leaving the product disabled — not by
writing an adapter that pretends the capability exists.

The only remaining `gpu_passthrough` mention in `ControlledDriver` is the
Compute family's own "unsupported" reason, which is a statement about a
simulator rather than a gap in a product.

## 20. Registrar / `.sy` boundary

```
.sy registrar: NOT_IMPLEMENTED — PROVIDER CONTRACT UNAVAILABLE
```

Unchanged, and deliberately untouched. No `.sy` name is registered, renewed,
transferred, priced or asserted anywhere. No endpoint, authentication scheme,
contact requirement, term limit or redemption rule was guessed at, and **no web
material was searched to infer a protocol**. What is missing is a document from
the registry.

## 21. Legal prerequisite

The registration screen asks a customer to accept a terms of service and an
acceptable use policy. **This repository does not author legal terms**, and the
documents remain a `BUSINESS_LEGAL_PREREQUISITE`.

What was missing was the software half. Publishing the documents has to be an
operator setting two variables rather than somebody editing a component and
shipping a portal build, so `legal.terms_url` and `legal.aup_url` are
configuration, they travel with the rest of what a registration form may
offer, and the screen links whichever exists. Both start unset, and unset
renders as no link — not an empty line and not a placeholder page, because a
URL pointing at something nobody has written would read as a prerequisite that
had been met.

The endpoint is unauthenticated and its answer becomes an anchor in every
visitor's browser, so a value that is not an `http` or `https` URL reads as
unpublished.

Account export and deletion stay **ticket-only at launch** (BD-13). That is a
deliberate product decision, recorded as such, and **not a code gap**.

---

## 22. Security and dependency audit

Re-run in this gap rather than quoted from an earlier report.

| Check | Result |
|---|---|
| `composer audit` | **No security vulnerability advisories found.** |
| `npm audit` | **2 moderate**, both `@vitest/mocker` / `vitest` (GHSA-82fw-gwwq-j7x9). Development-only; not shipped. The fix is `vitest` 5, a major upgrade, and **`npm audit fix --force` was not run** — a blind major bump of the test runner during a closure pass would invalidate every number in this document. |

## 23. TODO / stub audit

```
TODO / FIXME / XXX / HACK across apps/ and packages/ : 0
dd() / var_dump() / print_r() in shipped code            : 0
console.log in shipped frontend code                     : 0
```

`BlockerReason::NotImplemented` is the readiness engine's honest vocabulary for
a provider category with no adapter, not an executable placeholder.
**Unknown executable stubs in approved scope: zero.**

## 24. Route and RBAC audit

Read off the live route table, not asserted.

| Measure | Count |
|---|---|
| Routes total | 275 |
| API routes | 248 |
| API routes behind authentication | 241 |
| Guest-only (`RedirectIfAuthenticated`) | 4 — login, register, password forgot, password reset |
| Deliberately open | 3 |
| API routes with no throttle | **0** |
| Admin routes | 104 |
| Admin routes with no permission middleware | **0** |

The three open routes, each with what stands in for a session: the email
verification link (`ValidateSignature` — a signed URL), the second factor of
login (its own throttle bucket; the user is by definition not authenticated
yet), and the public registration options (throttled reference data that
discloses nothing about anybody).

Non-API routes: Horizon behind `Authenticate` and its own gate,
`sanctum/csrf-cookie`, the framework health route, the provider webhook
(signature-verified before the body is parsed), and the framework's
signed-URL storage routes, which `abort_unless(hasValidSignature())` before
touching a disk.

## 25. Secret audit

Secret **values** live in the process environment and nowhere else. The
credential registry stores a backend name, a backend reference and a
non-reversible hint of at most four characters of a public identifier.

`SecretRedactor` is applied on every provider error path that reaches a log, a
job payload, a drift record or an API response, and three gates hold it:
`DefaultLogStackIsRedactedTest`, `ExceptionChainSecretRedactionTest` and
`RedactionFailsClosedWhenPcreAbortsTest` — the last because a redactor that
fails open is worse than none.

## 26. SSRF audit

`EndpointPolicy` is the single authority, and it answers differently for the
two cases that genuinely differ: a provider that is not on our hardware is
refused a private-range address; a machine on the management network may have
one, because that is where it is. Forbidden outright in every mode: `localhost`
and the `.localhost`, `.local`, `.internal` and `.localdomain` suffixes, the
cloud metadata literals, loopback, link-local, multicast and reserved ranges,
and every shape that is really a different field (a URL, a port, user
information, a path, a bracketed literal).

`ProviderAdaptersRefuseOffHostRedirectsTest`,
`PanelSuppliedSsoUrlIsValidatedTest`,
`HostingNodeRedirectCannotExfiltratePanelPasswordTest` and
`PxeBootUrlStaysOnTheProvisioningVlanTest` cover the adapter side.

## 27. Provider identity audit

`EveryRealDriverHasAnIdentityTesterTest` holds the rule that a real driver must
be able to prove what it is talking to, and
`EveryControlledDriverIsBackedByASimulatorTest` the rule that a controlled one
must be backed by something that behaves.
`AControlledDriverThatSaysItDidSomethingDidItTest` is the one that matters most:
a simulator that reports success must have changed its own state.

## 28. Production isolation

| Boundary | Enforced by |
|---|---|
| A controlled driver cannot serve a production row | `NoControlledDriverSurvivesProductionTest`, and the registry refuses to construct a fake provider in production |
| The reference estate cannot be production | `ProductionRefusesTheReferenceEstateTest`; every row stamped `development`, every hostname refused as a production zone |
| The controlled payment gateway cannot settle anything | three independent guards, and no path from its endpoints to a paid invoice that does not go through a signed webhook |
| Simulation vs `READ_ONLY_REAL` | `PreflightMode` names the boundary; a simulation report is labelled `SIMULATION` and a controlled provider is blocked in both modes for a production row |
| Controlled simulation state | `ControlledSimulationStateIsOptInAndNeverProductionTest` |

## 29. Observability and the metric-producer contract

**A gap Gap 8 found in its own gate.** `EveryBackupAlertMetricHasAProducerTest`
existed because six alerts in `backups.yml` once read series nothing produced —
silent, because a rule over an absent series evaluates to an empty vector and
an empty vector is indistinguishable from a rule that is passing. Everything
true of that file was true of the other five, and nothing held them to it. A
gate one sixth as wide as the bug class it guards is itself an unsafe deferred
architecture item.

`EveryAlertMetricHasAProducerTest` now reads **every** rule file off the
directory and asks a *booted application's* registry — not the collector
source, which is the distinction that hid the original bug for two phases,
since every collector can be correct and exported by nothing when its provider
is never loaded.

Current state: **every `lynomia_*` series read by any rule in `backups.yml`,
`business.yml`, `control-center.yml`, `platform.yml` and `recording.yml` has a
producer.** 16 collectors, 60 exported families, 28 series read by a rule,
0 orphan alerts.

`EveryAlertNamesARunbookThatExistsTest` and
`TheApplicationActuallyRegistersItsCollectorsTest` hold the other two halves.

## 30. Scheduled and background workflows

| Measure | Result |
|---|---|
| Scheduled commands | 26, every one resolving to a registered command |
| Console commands never scheduled | 8, all operator or CI tools — `console-gateway:serve`, `hosting:preflight`, `infra:naming:audit`, `infra:preflight`, `ipam:capacity`, `openapi:generate`, `perf:profile`, `perf:token` |
| Queue jobs with no dispatcher | **0** |
| Orphan background workflows | **0** |

Two entries are new in this gap: `backups:verify` on `2-59/5 * * * *` —
offset from `backups:reconcile` on purpose, so a sweep and a poll do not
contend — and `ipam:withdraw-reverse-dns` every ten minutes.

---

## 31. Golden path final matrix

Every approved product's golden path, as it stands at the end of this gap.
Counts are the tests in each file, all passing in three consecutive isolated
runs (§35).

| Product | Where the golden path lives | Tests | Status |
|---|---|---|---|
| VPS | `Simulation/TheVpsGoldenPathTest` | 4 | PASS |
| Dedicated | `Simulation/TheDedicatedGoldenPathTest` | 5 | PASS |
| Shared hosting | `Simulation/TheHostingGoldenPathTest` | 2 | PASS |
| Domains | `Simulation/TheDomainGoldenPathTest` | 2 | PASS |
| WordPress | `EndToEnd/TheWholeLifeOfAWordPressOrderTest` + three `SharedHosting` lifecycle tests | — | PASS |
| Payment | the money chain inside the VPS, hosting and domain paths, plus `tests/Feature/Payments` (17 files) | — | PASS |
| Backups | `Simulation/TheFailureMatrixTest` verification group (7) + `tests/Feature/Backups` | — | PASS |

Two of those rows are stated as they are rather than as the brief's list
implies. **There is no WordPress golden-path file in the Simulation suite**,
and there is no payment one either: WordPress is covered by its end-to-end
order test and three lifecycle tests, and payment is the chain every other
golden path runs through — order, invoice, signed webhook, capture,
settlement, fulfilment. Naming a Simulation file that does not exist would
have been the easier sentence and a false one.

What changed in this gap:

* **VPS** — the machine the controlled hypervisor records now carries the
  template the plan named. Before Gap 8 it carried none, and the golden path
  asserted the absence.
* **Dedicated** — three tests are new and they are the positive twins of the
  power fix: one key reaches the controller once, two different keys are two
  intentional reboots and both execute, and a request with no key at all still
  executes.

`WhatAGoldenPathMustNotDoTest` (5 tests) holds the rules the paths themselves
must obey, so a golden path cannot be made to pass by stubbing the thing it is
supposed to exercise.

## 32. Failure matrix final status

`Simulation/TheFailureMatrixTest`: **19 tests, all passing.**

Three of its rows changed meaning in this gap, and each was updated with the
reason written into the test rather than edited quietly:

| Row | Before Gap 8 | After |
|---|---|---|
| A purchased machine is built with no image at all | asserted the defect, annotated as a carried gap | the build is refused permanently with `vps.create_image_unavailable` |
| A task that never settles leaves the service `active` | asserted `active` | the service reads `under_review`, because delivery is in doubt |
| A stored archive nobody has read back | `verified` stayed null for ever, with nothing to start a verification | the sweep asks, and the archive comes back `Verified` or `verified = false` |

The rest of the matrix is unchanged and still passing: provider timeouts that
must not be retried, a refused capture that must not provision, a redelivered
webhook that must produce one of everything, a cluster that vanished between
payment and build, a datastore that refuses, and a verification that failed
being distinguished from one nobody has run.

## 33. Deliberate breakage

Ten breakages, each applied alone, its gate run, and the file restored with
`git checkout --`. Every file touched was committed first, so the restore is
exact rather than hand-written. **No break was committed.**

| # | Break | Gate | Result |
|---|---|---|---|
| A | The resolved template is not carried into the provisioning payload | VPS golden path + order-to-service | **9 tests, 2 failed** |
| B | The power idempotency key is made unique per request | `tests/Feature/Dedicated` + dedicated golden path | **160 tests, 3 failed** — *"the retry reached the controller a second time"* |
| C | The verification sweep selects nothing | failure matrix, verification group | **7 tests, 4 failed** — archives stay `Succeeded` |
| D | The delivery-review eclipse is removed | `tests/Feature/Provisioning` | **129 tests, 4 failed** — `active` where the policy says `under_review` |
| E1 | The transient acknowledgement asserted exactly, as before | race subset × 10 | **50 passed — the failure did not reproduce** |
| E2 | The acknowledgement asserted *after* the outcome has landed | same spec × 3 | **3 failed, every time** |
| F | The harness guard's call site is deleted | harness refusal gate | **1 failed — and only after the gate was strengthened** |
| G | CDN declared `Complete` | readiness + architecture | **110 tests, 3 failed** |
| H | A controlled provider counts toward real readiness | readiness + controlled-driver production gate | **56 tests, 3 failed** — `ready_for_production` where `ready_for_test` is the ceiling |
| I | `.example` removed from the reserved-TLD refusal | security + simulation + naming | **232 tests, 6 failed** |
| J | `SecretRedactor::redactString()` returns its input | three redaction gates | **5 tests, 4 failed** — the BMC password reached the structured log |

Two of these are worth more than a row.

**E did not reproduce, and that is reported rather than papered over.**
Restoring the literal flaky assertion and running the race-sensitive subset ten
times produced fifty passes and no failures. The assertion is known to have
failed in CI — Gap 6 measured it and Gap 7 carried it — but a run in which a
race does not fire is not evidence, in either direction, and claiming a break
that was not witnessed would be exactly the kind of sentence this document
exists not to contain. So the *mechanism* was broken instead, deterministically:
asserting the acknowledgement after the outcome has landed fails three times out
of three, which proves the property that made the original assertion unsafe —
one toast id, replaced on the first terminal read. The case for changing the
contract rests on that proof plus the eighty clean passes of the fixed version,
not on a flake summoned to order.

**F found a hole in the gate it was supposed to validate.** Deleting the
guard's call site left all four existing tests passing, because every one of
them reaches the guard directly by reflection: as a gate they covered the
method and not the danger, and the teardown could have truncated an arbitrary
database with the guard sitting there unused and the suite green. There is no
safe runtime proof — the only way to watch the teardown refuse is to point it
at a database it must refuse, and if the call were missing that observation
*is* the truncation. So the gate now reads the source and asserts the order:
the guard is asked, and the truncate comes after it. A guard called after the
statement is an audit, not a guard. With that in place the break fails as it
should.

## 34. Positive twins

Each negative has a legitimate positive beside it, in the same file, so a fix
that refused everything would fail as loudly as one that refused nothing.

| Negative | Positive twin |
|---|---|
| A build naming no image is refused permanently | a build whose plan names a staged image is built from it, and the hypervisor records which |
| A replayed power request never reaches the chassis twice | two different idempotency keys are two intentional reboots and **both execute**; a request with no key still executes |
| A datastore that refuses verification is asked a bounded number of times | a stored archive is sent for verification once and comes back `Verified` |
| Running the sweep twice starts one verification | a second archive in the same sweep is asked about independently |
| A terminal delivery failure is never reported as active | a **failed reboot** does not make a running service look broken — the mistake the first version of the state fix made |
| A database that does not name itself a test database is refused truncation | the test database is truncated, and the harness hands back an empty schema |
| A prepared product cannot be sold or declared ready | the seven approved products are available in controlled simulation |
| A controlled provider cannot reach `ReadyForProduction` | it does reach `ReadyForTest`, which is what rehearsal is for |
| A reference `.example` name is refused as a production zone | the same name is a perfectly good suffix for the reference topology |
| A recorded image with no provider reference is not installable | one with a reference is, and placement finds it |
| Two serving hosting nodes cannot share a hostname | a retired node may keep the name its replacement now uses |
| A secret-shaped provider error is redacted everywhere | the operator still gets the failure class, the code and a message they can act on |

## 35. Regressions

Local, from a clean state, serialised. **No two database test runs overlapped**
— an earlier session had made that mistake and it produced failures that were
about the harness rather than the platform.

**Full backend regression**

| Run | Tests | Passed | Assertions | Duration | Result |
|---|---|---|---|---|---|
| 1 (before the last fix) | 3646 | 3645 | 142 356 | 666 s | **1 failure** |
| 2 (clean, after it) | **3647** | **3647** | **142 471** | 660 s | **PASS** |

Run 1's single failure was a real defect and not a flake, and it is worth
recording what it was: `AdminSurfaceTest` caught the new template-withdraw
route answering **404 where every other administrative route answers 403**.
`SubstituteBindings` runs with the `api` group, before the permission
middleware a route appends, so a route-model-bound parameter resolves — or
404s — before anybody checks whether the caller was allowed to ask, and a
customer could then tell an administrative endpoint that exists from one that
does not. Fixed by resolving the id in the controller, which is what every
other admin route already did.

**Golden suite, three clean isolated passes** (each preceded by its own
`migrate:fresh`, so they are three isolated runs rather than three passes over
one warm schema)

| Run | Tests | Assertions | Duration | Result |
|---|---|---|---|---|
| 1 | 151 / 151 | 677 | 181.0 s | PASS |
| 2 | 151 / 151 | 677 | 175.0 s | PASS |
| 3 | 151 / 151 | 677 | 180.1 s | PASS |

Identical test and assertion counts all three times, which is the property
worth having: nothing in the golden suite is skipping conditionally or
branching on leftover state.

**Browser suite, complete**

378 tests, **364 passed, 14 skipped**, 33.0 minutes, exit 0, across all four
projects — desktop, phone, narrow 360, Arabic.

All 14 skips are one file. `e2e/captures.e2e.ts` holds two blocks parameterised
over seven widths and skips itself unless `CAPTURES=1`, with the reason in the
file: the images exist so a person can look, and the layout and overflow
assertions they would duplicate live in `e2e/narrow/`, `e2e/mobile/`,
`e2e/arabic/` and `wave-3.e2e.ts`, which run on every pass. The one defect the
width work found at 360px was invisible in every image of every affected
screen. **No unexplained skip.**

**Race-sensitive subset, repeated in isolation:** wave-4's five specs ×10 =
**50 passed**; wave-5's three session specs ×10 = **30 passed**. Eighty
consecutive passes, zero intermittent failures.

**Everything else**

| Gate | Result |
|---|---|
| Pint | clean |
| PHPStan (level 6, no baseline) | **0 errors** |
| Frontend unit (vitest) | 81 files, **446 tests passed** |
| `tsc -b` | 0 |
| `eslint --max-warnings 0` | 0 |
| Production build | ok (chunk-size advisory only) |
| `openapi:generate` | 252 operations |
| `openapi:lint` | valid, 6 warnings — the same 6 as before this gap |
| `composer validate --strict` | PASS |
| `composer audit` | no advisories |
| `npm audit` | 2 moderate, `@vitest/mocker`/`vitest`, development-only |
| Migrations from empty, and reversible | PASS — all 58 roll back to an empty schema |
| Inventory / monitoring / runbook validators | PASS (4 of 4) |
| Production guards | PASS (4 of 4) |

PostgreSQL 16 is what this machine has, so both local backend runs are 16.
**PostgreSQL 18 is covered by the CI matrix and by nothing local**, and §36
says which job proved it.

## 36. Exact-SHA CI

**GitHub Actions run 179 — `7d6a284bc2cb313f0114110269fe8b4bd5f3c32e` —
status `completed`, conclusion `success`, 9 of 9 jobs, run attempt 1.**

| Job | Result |
|---|---|
| Backend (PHP 8.4, **PostgreSQL 16**) | success |
| Backend (PHP 8.4, **PostgreSQL 18**) | success |
| Static analysis | success |
| Frontend | success |
| API description | success |
| Browser end-to-end | success |
| Security checks | success |
| Infrastructure validation | success |
| Production guards | success |

Both backend matrix jobs ran `php artisan test` against a database CI builds
empty, on two PostgreSQL majors, and each job re-verified that migrations run
from empty and are reversible before running a test. The browser job passed on
the first attempt, with no re-run — worth saying because Gap 7's browser job
needed a second run, and that re-run was the reboot-acknowledgement race this
gap resolved.

One honest note on the run history. Run **178** on `0fa3116` shows
`cancelled`: the push of `7d6a284` superseded it while it was still executing.
No job in 178 failed, and nothing was re-run to get a better answer — the
exact-SHA evidence for this gap is run 179 on the ending code SHA, which is
the run above.

---

## 37. Remaining external prerequisites

Not code gaps, and deliberately listed apart from them.

**`REAL_INFRA_PREREQUISITES`**

* A reachable Proxmox cluster with a credential, and the images staged on it.
* A reachable Proxmox Backup Server datastore.
* Reachable BMCs (Redfish, iLO or IPMI) on a management network.
* A reachable cPanel or DirectAdmin node with a licence.
* A real Cloudflare account and zone for forward and reverse DNS.
* A provisioning VLAN for PXE, isolated as the runbooks require.

**`BUSINESS_LEGAL_PREREQUISITES`**

* Terms of service and acceptable use policy, written and reviewed. The
  software half is done (§21); the documents are not this repository's to write.
* A payment-provider account and the commercial agreement behind it.
* A registrar relationship for each TLD to be sold.
* The account export and deletion process as a support procedure (BD-13).

**`LICENCE_PREREQUISITES`**

* cPanel or DirectAdmin licences.
* CloudLinux and LiteSpeed, where the hosting product offers them.
* Proxmox subscription, if the enterprise repository is to be used.

## 38. Final software verdict

```
SOFTWARE_CODE_COMPLETE = YES
```

For the approved software scope in §5, and for nothing else. The five counts
the standard requires:

| Count | Value |
|---|---|
| Approved-scope real code gaps | **0** |
| Product decisions unresolved | **0** |
| Known flakes | **0** |
| Unknown executable stubs | **0** |
| Unsafe deferred architecture items | **0** |

None of those zeros was reached by reclassifying a defect. Eleven items in the
register were real code work and were implemented; the seven scope changes are
each a capability removed from what the platform *asks for*, with the
repository's own reason, enforced by a gate that fails if the capability is
declared and unused. The twelve non-blocking quality items are non-blocking
because of what they are — a missing loading skeleton, a dirty-form guard that
needs a router migration, a development-only advisory — not because calling
them non-blocking was convenient.

`KNOWN FLAKES = 0` deserves its own sentence, because the honest answer is
narrower than the number. The assertion that flaked no longer exists: the
contract it tested was wrong, and the browser now asserts what the product
actually promises. Eighty consecutive isolated passes support that. What
would be dishonest is to claim the *race* was removed — it was not, and it
cannot be, because one toast id replaced on the first terminal read is the
correct design. What was removed is a test that raced it.

And the sentence this whole document is arranged to let me write accurately:

> Approved software scope is code-complete and locally runtime-verified against
> controlled providers. Real infrastructure/provider validation has not begun.

## 39. Real-verification boundary

Unmoved, and nothing in this gap could have moved it.

```
REAL_INFRA_VERIFIED      = NONE
REAL_PAYMENT_VERIFIED    = NONE
REAL_REGISTRAR_VERIFIED  = NONE
REAL_HOSTING_VERIFIED    = NONE
READY_TO_SELL            = NONE
```

Every provider exercised in this gap is a controlled simulator. No credential,
socket, machine, domain, zone, invoice or fils in it is real. A simulator
passing is evidence that this platform's own state machines, queues, ledgers
and compensations agree with each other — worth having for exactly that, and
nothing more. It is not evidence about a datacentre, a registry, a bank or a
customer's website, and no sentence in this document should be quoted as if it
were.

## 40. The next real-infrastructure phase

Real infrastructure validation, as a trusted-environment rerun of Phase 30B.
It has not begun and must not begin without review. Nothing in this gap
connected real infrastructure, added a real credential, made a real Proxmox
call, powered real hardware, ran an IaC apply, registered a domain, moved
money, published DNS or performed a real restore. No `REAL_*` status was
changed and `READY_TO_SELL` was not set.


---

---

## 41. Final truth-closure patch

This section was written after §1–§40 and corrects them. It exists because the
verdict in §38 was re-proved rather than re-read, and three claims did not
survive that. Where this section and an earlier one disagree, this one is
current.

### 41.1 The question §5 did not ask

§5 listed seven products as `Complete` and said every one of them was
code-complete. The software state is supposed to answer a question, and the
question is: **does this repository contain a production-capable adapter for
what the product requires?** Not a class, not a catalogue row, not a driver
name — something a customer's order could actually run through.

Asked properly, two of the seven failed.

**WordPress.** The product requires an installer that can `install` and report
a `version`. `WordPressInstaller` is implemented by `FakeHostingProvider` and
by nothing else. Both real panel adapters implement `HostingProvider` only, and
neither they nor their connection classes mention WordPress, wp-toolkit,
Softaculous or Installatron anywhere. `InstallWordPressHandler` had always said
so out loud — it refuses with `wordpress.panel_cannot_install` — so every
WordPress order on a real panel already failed before this patch. The product
was complete in every respect except the one that matters.

**Domains.** The product requires a registrar that can search, register, renew
and transfer. Two drivers exist: the controlled fake, and `sy_registry` — a
real, non-controlled class implementing the entire registrar contract, whose
`supports()` answers false for every capability, whose `supportedTlds()` is
empty, and whose every operation throws. This is the case worth naming,
because the obvious version of this check does not catch it: "is there a
non-controlled driver in this category?" reads `sy_registry` and calls Domains
ready to sell.

Both are now `Prepared`. Neither lost any software — models, contracts,
orchestration, simulators and tests all stay, and the controlled simulation
still walks both lifecycles end to end. What changed is the claim.

`ProductSellability` now refuses any product whose software state is not
`Complete`, in every environment, ahead of both the production exemption and
the readiness row — so neither a rehearsal nor an Admin declaration of
`ready_to_sell` can reach past it.

### 41.2 The gate that makes it structural

`EveryCompleteProductHasARealAdapterTest` derives the invariant from
`ProductRequirements` against `ProviderCatalogue`: every `Complete` product's
requirements must be satisfiable by at least one non-controlled catalogue entry
that can perform every required capability. Nothing is listed per product.

For that to mean anything, each catalogue entry now declares which of its
category's capabilities its adapter can actually perform — read off the adapter
and written down beside `needsLicence`, because the alternatives are worse: a
connection test answers per credential against a live endpoint and cannot be
asked at build time, and implementing the interface answers a different
question, as `sy_registry` demonstrates. An empty list is a meaningful entry:
the platform has an adapter for that vendor and there is currently nothing it
can do.

Three things the gate refuses, each asserted:

* a controlled driver counting as evidence — the products that only a
  simulator could carry are named, and they are exactly `wordpress` and
  `domains`;
* a non-controlled entry that declares nothing satisfying a requirement —
  asserted in isolation on a synthetic entry, which every weaker form of the
  check passes;
* a declaration disagreeing with its adapter — `sy_registry` declares nothing
  and its `supports()` answers false for every capability; `proxmox_backup`
  does not declare `verify` and its `supportsVerification()` is false.

Declaring WordPress `Complete` again fails the build.

### 41.3 The backup verification claim, corrected

Register item 4 in §4 and §9 recorded "Nothing in the platform starts a backup
verification" as **IMPLEMENTED AND VERIFIED**. That was true of the controlled
simulator and false of production, and the report did not say so.

Proxmox Backup Server verifies on its own schedule and exposes no endpoint that
starts one. `ProxmoxBackupProvider::supportsVerification()` answers false and
`startVerification()` refuses. So `VerifyStoredArchives` — the sweep Gap 8
added — would have called an impossible operation on every archive on the only
backup adapter that can run in production. The attempt limit bounded it to
three refusals per archive rather than an unbounded loop, which is why nothing
looked wrong; but each refusal raised the attempt counter and wrote the
provider's refusal into `failure_reason`, the column a person reads as the
verdict on that archive. Every backup the platform would ever take would have
carried a sentence about verification failing.

Two corrections, and the second is the product truth:

* the sweep now asks `supportsVerification()` before it calls, and leaves such
  an archive untouched — no attempt counted, no failure reason, counted as
  skipped rather than failed, so a healthy platform reports no standing
  failures;
* the required capability is no longer provider-triggered verification. What a
  customer is owed is the **verdict** — an archive is known to have been read
  back, known to have failed, or not yet checked — and that is now
  `verification_verdict`, required, with `verify` optional. The verdict was
  already computed by `listBackups()` and read by nobody;
  `ReconcileBackupInventory` now adopts it onto the row, records `true` and
  `false` but never `null`, and neither rewrites a verdict already known nor
  erases one when the datastore stops reporting it.

Backups remains `Complete`, and now on a capability its production adapter
actually has.

### 41.4 The legal prerequisite, corrected

§21 said the software half was done: publishing the documents would be an
operator setting two variables, and the acceptance was recorded against the
account. `config/legal.php` said so too.

The last part was untrue. `accepts_terms` appeared in exactly one place in the
repository — a validation rule — and was discarded the moment it passed.
Nothing was stored, so nothing could be produced afterwards. And because the
URLs were optional, an account could be created on the strength of a customer
agreeing to two document names that linked nowhere, because the documents did
not exist.

Registration now fails closed: no published documents, no accounts, 503 with
no field to correct, refused ahead of every side effect including the
duplicate-address notification. A document is published only with a revision
beside it — a URL without a version is a page whose acceptance nobody can pin
to a revision, and a version without a URL is a revision nobody can read — and
the acceptances are written in the same transaction as the account, one row per
document, append-only, with the version read from the server every time.

One finding from doing it: the first version of the refusal put the list of
unpublished documents in the exception context, with a comment claiming it was
logged and never rendered. A domain exception's context is rendered as
`error.details`, so an unauthenticated endpoint answered every visitor with
`{"unpublished_documents":["terms","aup"]}`. The test asserting the response
body names nothing is what caught it.

Still not written here: the documents. Publishing remains four configuration
values and unset remains the default. The difference is that unset now refuses.

### 41.5 The agent instruction files

Not mentioned anywhere in §1–§40, and worth recording because it had been in
the tree since the first commit. `apps/control-plane/CLAUDE.md` and
`AGENTS.md` were byte-identical copies of a scaffolding stub describing no part
of this application, instructing an agent to pipe a remote script into a shell
to install PHP and then to add a package and run its installer "before making
application changes".

Both now carry the same guidance about this repository, and a gate keeps them
identical, refuses dependency mutation and remote piped installers, and
separately requires the text still name the facts an agent gets wrong without
being told — because every prohibition in it is satisfied by an empty file.
`composer install` and `npm ci` are deliberately not banned, and neither is
`artisan <something>:install`, because `horizon:install` and `migrate:install`
are real commands here.

### 41.6 Final product matrix, derived from code

Generated from `Product::softwareState()`, `ProductRequirements` and
`ProviderCatalogue` rather than restated. "Production-capable adapter" means a
non-controlled catalogue entry declaring every capability the requirement
names.

| Product | Software state | Requirement | Required capabilities | Production-capable adapter | Simulator |
| --- | --- | --- | --- | --- | --- |
| `vps` | complete | `compute` | `create`, `start`, `stop`, `reboot`, `resize`, `reinstall`, `suspend`, `unsuspend`, `console`, `destroy`, `templates`, `task_polling` | `proxmox` | `fake_compute` |
|  |  | `reverse_dns` | `set_ptr`, `clear_ptr` | `cloudflare_rdns` | `fake_rdns` |
| `dedicated` | complete | `bmc` | `inventory`, `power_state`, `power_control`, `boot_override`, `firmware` | `ipmi`, `redfish`, `ilo` | `fake_bmc` |
|  |  | `reverse_dns` | `set_ptr`, `clear_ptr` | `cloudflare_rdns` | `fake_rdns` |
| `shared_hosting` | complete | `hosting` | `create_account`, `suspend`, `unsuspend`, `terminate`, `sso`, `change_package`, `usage` | `cpanel`, `directadmin` | `fake_hosting` |
| `wordpress` | prepared | `wordpress_installer` | `install`, `version` | **none** | `fake_wordpress` |
| `domains` | prepared | `registrar` | `search`, `availability`, `register`, `renew`, `transfer`, `nameservers`, `contacts`, `lock`, `auth_code`, `premium`, `held_names` | **none** | `fake_registrar` |
| `dns` | complete | `dns` | `create_zone`, `delete_zone`, `records`, `reconcile` | `cloudflare` | `fake` |
| `backups` | complete | `backup` | `create`, `restore`, `delete`, `retention`, `verification_verdict` | `proxmox_backup` | `fake_backup` |
| `cdn` | prepared | `cdn` | `enable`, `disable`, `purge_all`, `purge_urls`, `cache_rules`, `development_mode`, `tls_status` | **none** | none |
|  |  | `dns` | `records` | `cloudflare` | `fake` |
| `object_storage` | prepared | `object_storage` | `create_bucket`, `delete_bucket`, `list_buckets`, `quota`, `usage`, `issue_access_key`, `revoke_access_key`, `endpoint`, `versioning`, `lifecycle` | **none** | none |
| `gpu_compute` | prepared | `compute` | `create`, `start`, `stop`, `reboot`, `reinstall`, `suspend`, `unsuspend`, `console`, `destroy`, `templates`, `task_polling`, `gpu_passthrough` | **none** | none |
|  |  | `reverse_dns` | `set_ptr`, `clear_ptr` | `cloudflare_rdns` | `fake_rdns` |
| `email_hosting` | prepared | `email_hosting` | `create_mail_domain`, `delete_mail_domain`, `create_mailbox`, `delete_mailbox`, `reset_mailbox_password`, `change_quota`, `create_alias`, `delete_alias`, `create_forwarder`, `delete_forwarder`, `webmail`, `usage`, `suspend`, `unsuspend`, `terminate`, `dkim` | **none** | none |
|  |  | `dns` | `records` | `cloudflare` | `fake` |
| `managed_kubernetes` | readiness_only | `cluster_lifecycle` | `create_cluster`, `delete_cluster`, `node_pools`, `upgrade` | **none** | none |
|  |  | `load_balancer` | `create`, `delete`, `members`, `health_checks` | **none** | none |
|  |  | `certificates` | `issue`, `renew`, `revoke` | **none** | none |
|  |  | `monitoring` | `scrape`, `alerting` | **none** | none |

Shared by every product:

| Requirement | Required capabilities | Production-capable adapter | Simulator |
| --- | --- | --- | --- |
| `payment` | `charge`, `refund`, `webhook`, `currencies` | `stripe` | `fake_payment` |
| `email` | `send` | `smtp` | none |

`smtp` has no controlled simulator because mail is faked by the framework in
tests; every other category with a real adapter has one.

### 41.7 Counts

| Product software state | Count | Products |
|---|---|---|
| `Complete` | **5** | vps, dedicated, shared_hosting, dns, backups |
| `Prepared` | **6** | wordpress, domains, cdn, object_storage, gpu_compute, email_hosting |
| `ReadinessOnly` | **1** | managed_kubernetes |

Five, not the seven §5 claimed. Every one of the five has a production-capable
adapter for each of its own requirements and for both shared ones.

The five counts §38 requires, recomputed:

| Count | Value |
|---|---|
| Approved-scope real code gaps | **0** |
| Product decisions unresolved | **0** |
| Known flakes | **0** |
| Unknown executable stubs | **0** |
| Unsafe deferred architecture items | **0** |

`Approved-scope real code gaps = 0` means something narrower than it did in
§38, and the narrowing is the point: the approved launch scope is now the five
products above rather than seven, so two products left the scope instead of
their gaps being closed. That is a smaller claim, and it is the true one.

### 41.8 The verdict, restated

```
SOFTWARE_CODE_COMPLETE = YES
```

For the approved launch software scope — the five `Complete` products — and
for nothing else. Said in full, because the short version is the thing this
patch exists to prevent:

> The approved launch software scope is code-complete and locally
> runtime-verified against controlled providers. It is not all of Lynomia's
> products: six more are built and outside that scope, one exists only as a
> readiness row, and no real provider has been verified for any of them.

What must not be said is "all Lynomia products are code-complete". Six
products have software and no production-capable adapter for what they need,
and saying otherwise is the claim §5 made.

### 41.9 The real-verification boundary is unmoved

```
REAL_INFRA_VERIFIED      = NONE
REAL_PAYMENT_VERIFIED    = NONE
REAL_REGISTRAR_VERIFIED  = NONE
REAL_HOSTING_VERIFIED    = NONE
READY_TO_SELL            = NONE
```

Nothing in this patch touched any of them, and nothing in it could. A
production-capable adapter existing is not a provider verified — the first is a
fact about this repository, the second needs a credential, an endpoint and a
real operation, and none of that has happened.

`.sy` remains `NOT_IMPLEMENTED — PROVIDER CONTRACT UNAVAILABLE`. No reseller
API was invented, no protocol was guessed, and `sy_registry` stays catalogued
with an empty capability list because the adapter genuinely exists and has
nothing to call.

### 41.10 Deliberate breakages

Thirteen, run in one pass, each restored before the next. The positive twin
is the same five suites unmutated: 85 passed before the first mutation and 85
after the last, with an empty `git diff` at the end.

| | Breakage | Caught by | Result |
|---|---|---|---|
| A | WordPress declared `Complete` again | `EveryCompleteProductHasARealAdapterTest` — the invariant, and the simulator-only list | **observed** |
| B | `sy_registry` declares the registrar capabilities it cannot perform | the same gate, plus the entry/adapter agreement | **observed** |
| C | `proxmox_backup` claims it can be asked to `verify` | the backup entry/adapter agreement | **observed** |
| D | a catalogue entry claims a capability its category never declares | the declaration-hygiene gate | **observed** |
| E | the software-state guard removed from `ProductSellability` | three prepared-product assertions | **observed** |
| F | the rehearsal's production check removed | the two production refusals | **observed** |
| G | the rehearsal's prepared-only check removed | the readiness-only assertion | **observed** |
| H | `VerifyStoredArchives` stops asking `supportsVerification()` | the untouched-archive assertion | **observed** |
| I | a known verdict may be erased by a later listing | the verdict-erasure assertion | **observed** |
| J | registration stops failing closed | three legal assertions | **observed** |
| K | the refusal carries the unpublished-document list again | the response-names-nothing assertion | **observed** |
| L | the scaffolding stub restored to both instruction files | eleven instruction assertions | **observed** |
| M | a legal acceptance becomes editable | the append-only assertion | **observed** |

Every one was observed. Two are worth naming as having been *sharpened* after
a first attempt passed, because a mutation that does not fail is a statement
about the test rather than about the code:

* the null-verdict guard in `ReconcileBackupInventory` survived its first
  mutation. The case the test covered was already caught by the
  unchanged-verdict check beside it, which meant the guard was load-bearing
  only in a case nothing asserted: a row that already has a verdict and a
  datastore that stops reporting one. That test was added, and breakage I then
  failed as it should;
* the `WordPressInstaller` implementor check in the real-adapter gate passed
  by finding nothing, because no provider class is autoloaded in an
  architecture test. It reads the source now, and asserts the scan is
  non-empty before asserting what it found.

### 41.11 Regression

Run on the code this section describes, after `migrate:fresh` on the test
database so the new `legal_acceptances` migration was applied from nothing.

| Check | Result |
|---|---|
| Backend suite | **3707 passed**, 142,770 assertions |
| Pint | passed |
| PHPStan | 0 errors |
| Frontend unit and component | **446 passed**, 81 files |
| ESLint | 0 errors, 0 warnings |
| TypeScript | no errors |
| Vite build | succeeded |
| OpenAPI description | valid |

Two failures reached the branch before this and are worth recording rather
than quietly fixing. The wider suite found both, and both came from running
only the directories I had touched:

* `registration.unavailable` had no sentence in either language, so the
  customer error catalogue gate failed;
* `docs/openapi.yaml` is generated, and a test asserts the committed file is
  what the generator produces. I edited the output. The edits are in
  `resources/openapi/` now and the file is regenerated.

Three pushes carried that red backend job, and the cancellations from each
superseding push hid it — the last green exact-SHA run before the fix was
`6386c6f`. The gate worked; the process around it did not, and running the
whole suite before pushing is the correction.

A third followed, from the same root cause one layer out. With both of those
fixed, run 185 on `f44a19c` was green in eight of nine jobs and the browser
suite failed: two of 364 specs, journeys A and B of `money.e2e.ts`, both at
`getByRole('button', { name: /create account/i }).click()`. The button was
disabled, correctly — that job prepares its environment with
`cp .env.example .env`, and `.env.example` now carries the four legal keys
empty, so registration was closed in the very environment whose first act is
to register a customer.

The fix is in `playwright.config.ts`, not in the workflow: the suite starts
the API, so the suite says what that API needs, and a developer running it
locally gets the same answer as CI rather than depending on their own `.env`.
The refusal itself is still asserted against unset configuration in the
feature suite, so publishing documents for the rehearsal costs nothing in
coverage.

Worth naming as a pattern rather than three accidents: every one of the three
was a place my change reached that I had not run. The backend suite found the
first two and CI found the third, which is the order of expense, and the
correction in each case is the same — run the gate that covers what the change
touches, not the directory the change is in.
---

## Appendix — final product matrix

> Superseded by §41.6, which derives this from `Product::softwareState()`,
> `ProductRequirements` and `ProviderCatalogue` rather than restating it. The
> table below counts WordPress and Domains as approved scope; they are
> `Prepared`.

No global "complete" is claimed anywhere in this document, and this table is
why: the answer differs per product, and the blocker differs with it.

| Product | Software | Controlled runtime | Real provider | Sellable | Exact blocker |
|---|---|---|---|---|---|
| VPS | CODE_COMPLETE | RUNTIME_VERIFIED (controlled) | NONE | NOT READY TO SELL | no real Proxmox cluster, credential, or image staged on it |
| Dedicated | CODE_COMPLETE | RUNTIME_VERIFIED (controlled) | NONE | NOT READY TO SELL | no real BMC on a management network |
| Shared hosting | CODE_COMPLETE | RUNTIME_VERIFIED (controlled) | NONE | NOT READY TO SELL | no licensed cPanel or DirectAdmin node |
| WordPress | CODE_COMPLETE | RUNTIME_VERIFIED (controlled) | NONE | NOT READY TO SELL | neither panel adapter implements the WordPress contract — EXTERNAL_PROVIDER_CONTRACT |
| Domains | CODE_COMPLETE | RUNTIME_VERIFIED (controlled) | NONE | NOT READY TO SELL | no registrar relationship; `.sy` contract unavailable |
| DNS | CODE_COMPLETE | RUNTIME_VERIFIED (controlled) | NONE | NOT READY TO SELL | no real Cloudflare account or zone |
| Backups | CODE_COMPLETE | RUNTIME_VERIFIED (controlled) | NONE | NOT READY TO SELL | no real PBS datastore; the file-level real adapter is NOT_IMPLEMENTED |
| CDN | PREPARED | not applicable | NONE | NOT READY TO SELL | no adapter, and a gate forbids writing one |
| Object storage | PREPARED | not applicable | NONE | NOT READY TO SELL | no adapter, same gate |
| GPU compute | PREPARED | not applicable | NONE | NOT READY TO SELL | `compute.gpu_passthrough` is not modelled by any interface |
| Email hosting | PREPARED | not applicable | NONE | NOT READY TO SELL | no adapter, same gate |
| Managed Kubernetes | READINESS_ONLY | not applicable | NONE | NOT READY TO SELL | no contract at all |

## Appendix — exact counts

> Superseded by §41.7 for the product counts. The external-contract list below
> is still accurate, with one clarification: item 3 is the reason WordPress is
> `Prepared` rather than a gap within an approved product, and item 1 is the
> reason Domains is.

```
APPROVED-SCOPE REAL CODE GAPS         0
PRODUCT DECISIONS UNRESOLVED          0
KNOWN FLAKES                          0
UNKNOWN EXECUTABLE STUBS              0
UNSAFE DEFERRED ARCHITECTURE ITEMS    0

EXTERNAL_PROVIDER_CONTRACT_GAPS       6
```

The six external provider contracts, each out of approved implemented provider
scope and none of them becoming code-complete by being listed:

1. `.sy` registrar — the registry's protocol, endpoints, authentication,
   contact rules, term limits and redemption rules.
2. File-level backup on a real adapter — the provider's file-restore API.
3. WordPress on a real adapter — neither control panel adapter implements
   either WordPress contract.
4. CDN — no adapter, and a gate forbids writing one from documentation.
5. Object storage — as above.
6. Email hosting — as above.

Provider-specific naming constraints (Proxmox node and storage limits, WHM and
DirectAdmin hostname rules, registrar account id formats) are a seventh kind of
absent contract, recorded in §13 rather than counted here: no product is
blocked on them, because the generic rule validates and a provider that refuses
a name refuses it with its own message.
