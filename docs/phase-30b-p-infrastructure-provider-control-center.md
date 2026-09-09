# Phase 30B-P — Infrastructure & Provider Control Center

**Status: CLOSED. Verdict: the Control Center is software-complete to the
closure standard on this build; nothing is `REAL_INFRA_VERIFIED`; no product is
`READY_TO_SELL`, and the platform says so about itself on every screen
concerned.**

> A scope addendum was carried out after this closure and is recorded in
> [`docs/phase-30b-p-scope-addendum.md`](phase-30b-p-scope-addendum.md). This
> report is unchanged by it.

This is the authoritative report for Phase 30B-P. It records what was built to
the full closure standard, what was found and fixed on the way, what the clean
room and CI observed, and — precisely — what this build cannot claim. It does
not claim `REAL_INFRA_VERIFIED` for anything; that classification is reserved
for Phase 30B resuming against real systems, and the only path to it is the one
this report names in section I.

| | |
|---|---|
| Branch | `claude/hv-t6hq1p` |
| Last commit of code | `ba0f4ab` — the commits after it are this report, the two matrices and the clean-room record |
| Backend tests | 2708 / 2708 passing locally, in CI and in the clean room |
| PHPStan | 0 errors locally and in CI's Static analysis job; **not executed in the clean room**, where the toolchain install (`composer install --working-dir=tools/phpstan`) was blocked by the proxy (section J) |
| Frontend unit tests | 81 / 81 (18 files) |
| Browser E2E | 133 tests in 23 files (28 cover the ten Control Center screens) |
| OpenAPI operations | 211, generator and committed document in agreement |
| Control Center screens | 10, English and Arabic, every rendered state under the translation gate |

---

## A. The closure standard applied

Every exit condition is held to the same standard, in the user's words:

> model / state → authorization → API/action → actual application path
> → queue/handler/provider/IaC bridge where applicable → UI/operator surface where required
> → audit → observable state → failure state → tests → no dead capability

Nothing below is marked complete because a model, endpoint, interface or mock
exists. Where a capability is listed as complete, every link of that chain has
been walked and is under test. Where a link is missing, the row says which.

## B. Module structure (decided, applied, guarded)

Three bounded concerns, not one:

| Module | Owns |
|---|---|
| `Infrastructure` | datacenters, racks, servers, facts, safety classification, software profiles, desired state, deployment plans/approvals/jobs |
| `Providers` | provider instances, catalogue, credential references, licences, capabilities, connection tests, provider readiness |
| `ProductReadiness` | the requirement matrix, the dependency model between products, the product readiness ladder, the sellability declaration |
| `Shared` | `DeploymentEnvironment`, `BlockerReason`, `ReadinessState` — vocabulary all three speak |

`ControlCenter` is the admin navigation area that composes them. It is not a
module and `TheModulesAreNamedForWhatTheyOwnTest` fails the build if one is
created. The same test refuses `Estate` as a namespace, class, table, route,
migration or translation key; the module was briefly called that and the name
had already reached the API boundary once (`estate.guidance.*` as `next_action`)
before being caught.

## C. Exit conditions — actual state

Legend: **COMPLETE** = full chain built and tested · **PARTIAL** = some links built, named gap · **NOT STARTED**.

| Exit condition | State | What exists / what is missing |
|---|---|---|
| Infrastructure Overview | **COMPLETE** | `GET infrastructure/overview`: counts by state for machines, providers, credentials, licences, products, deployments and sites, and an `attention` block (deployments waiting for a person, enabled providers no longer ready, credentials missing, licences expiring or expired, infrastructure drift open, machines never classified). **Overview screen** at `/admin/control-center` (en/ar): what needs a person first, each a link to the screen that deals with it; a quiet estate says so. Component tests; browser test follows a link from the attention list. |
| Datacenter/Rack Registry | **COMPLETE** | `RegisterDatacenter` (region, unique slug, name, facility), `RegisterRack` (one name per datacenter → 409 `rack_exists`; row, units, power and network notes as free text for a person, never handed to anything that executes); `GET regions|datacenters|racks` with rack and machine counts; audit `infrastructure.{datacenter,rack}.registered`; OpenAPI; **Sites screen** at `/admin/control-center/sites` (en/ar); the machine register form places a machine in a datacenter, rack and unit. 3 feature tests, 3 browser tests. |
| Server Registry | **COMPLETE** | `RegisterServer`, `ServerController::{index,show,store}`, `ServerResource`, OpenAPI, audit `infrastructure.server.registered`, 13 + 8 feature tests; **Machines screen** at `/admin/control-center/machines` (en/ar): list with classification and connection in words, register form that says the machine arrives do-not-touch. |
| Server Detail | **COMPLETE** | The Machines screen's detail panel: classification and its reason (or "never classified"), reimage clearance, credential, connection test with its steps, discovery, and the current facts with their source. Controls are disabled by `safety.permits.*` and the refusal is written on the panel before the click. |
| Server Onboarding | **COMPLETE** | Register → classify (one rung up, typed name for the destructive rung, reason) → attach credential → test → discover, all from the Machines screen; the browser suite walks register → classify and test → discover on the seeded machines. No wizard: the ladder is the wizard, one rung per decision. |
| Safety Enforcement | COMPLETE (backend) | `SafetyGate` pure function, 39 unit cases; `ClassifyServer` (one rung up, any distance down, typed name for destructive rung, row lock, reason); `ClearForReimage` / `RevokeReimageClearance` under separate permissions; DB CHECK `allow_reimage = false OR safety_class = 'reimage_allowed'`; connection test gated on `Read`; refusals are 409 carrying classification/attempted/would-permit. Ansible `safety_gate` role proves the same rule on the IaC side (10/10). The screen offers only the rungs the API would accept and disables test/discover on a do-not-touch machine (component- and browser-tested). |
| Connection Framework | COMPLETE (backend) | `ConnectionTester` contract, `ConnectionTesterFactory` (bind, not singleton; verifies `driver()`), `FakeConnectionTester` with nine failure markers and a production guard; `TestConnection::{forServer,forProvider}`; `connection_tests` table with exactly-one-subject CHECK; audit `providers.connection.tested`. **A machine's driver is now derived from its own BMC provider** (`ManagedServer::bmc()`), never from the request; a machine with no BMC provider is refused with `bmc_missing`. Surfaced on the Machines and Providers screens with the steps walked. **Only the `fake`/`fake_bmc` drivers have a tester** — see F. |
| Discovery Framework | **COMPLETE** | `ConnectionTester::discover()`; `DiscoverServer` writes `server_facts` (supersedes changed values, supersedes discovered keys no longer reported, never touches declared facts, writes nothing on an unusable connection), sets `last_discovery_at`, audit `infrastructure.server.discovered`; `POST servers/{id}/discover`, `GET servers/{id}/facts`; 9 feature tests (`AMachineIsReachedThroughItsOwnBmcTest`); facts with source on the Machines screen; browser-tested. |
| Provider Registry | COMPLETE (backend) | `RegisterProvider` / `EnableProvider` / `DisableProvider` / `AssessProvider`; 8 routes; `ProviderResource`; enable reassesses readiness inside the transaction under a row lock; partial unique index one-enabled-per-(category, environment) translated to a 409; disable writes one column and cascades to nothing; audit `providers.provider.{registered,enabled,disabled}`; 20 feature tests including the end-to-end walk; **Providers screen** at `/admin/control-center/providers` (en/ar): readiness with the blocker and its next action in words, credential and licence attachment, test-and-discover with steps, capabilities, enable only when ready-for-production, disable with a reason; component tests and 4 browser tests. |
| Provider Catalog | COMPLETE (backend) | `ProviderCatalogue` in source (12 drivers), `CatalogueEntryResource` with `testable` and `available_here`; `TheCatalogueOnlyClaimsWhatExistsTest` names the adapter class per driver; the register form on the Providers screen offers only `available_here` drivers, shows the summary, and asks for endpoint/machine only where the entry needs them. |
| Requirement Engine | **COMPLETE** | Two layers. Per-driver requirements (`needsEndpoint/Credential/Licence/Server`) on `CatalogueEntry` drive provider readiness. `ProductRequirements` is the cross-product matrix in source: each product's own requirements (provider category + the capabilities its code actually calls, narrower than the category's question set) plus the shared requirements every product carries (payment `charge`/`webhook`, email `send`). `Product::dependsOn()` is the dependency model (wordpress → shared_hosting, backups → vps); a dependency caps its dependent with a dependency blocker naming it. Blocker propagation: `AssessProvider`/`EnableProvider`/`DisableProvider` raise `ProviderReadinessChanged` when something moved, `ReassessProductsWhenAProviderChanges` re-evaluates every product in the same transaction, and the nearest candidate's own blocker becomes the product's (a revoked credential reads as "blocked: credentials" on the product row). 17 unit cases on the pure evaluator. |
| Licence Center | **COMPLETE** | `RecordLicence` (state from the calendar: pending before start, active, expiring ≤30 days, expired; a key posted under any name is refused and pointed at the credential centre), `AttachLicence` (invalid and cross-environment refused at attachment), `RenewLicence` (clears an invalidation, recomputes, reassesses dependents at once; an expired renewal is refused), `InvalidateLicence` (the operator's only override, one direction only), `RefreshLicenceStates` (nightly at 00:10 and on demand; each transition audited; invalid/not-required never touched). 8 routes, 6 audit actions, `licences:refresh` command, OpenAPI; 16 feature tests including time-travel through expiring→expired with the provider blocked at the right step; **Licences screen** (en/ar), component tests, 5 browser tests. |
| Credential References | **COMPLETE** | `RecordCredentialReference` (reference shape enforced; a value-shaped reference is refused with "rotate it now"; any unexpected field such as `secret`/`password` is refused by name and never echoed), `AttachCredential` to provider or machine (revoked and cross-environment refused at attachment, not only at use), `RevokeCredential` (reason, row lock, every dependent provider reassessed, nothing switched off), `MarkCredentialRotated` (state drops to configured/missing, last test forgotten, dependents reassessed). `SecretResolver::exists()` answers presence without ever holding the value. 9 routes; `CredentialResource` never carries `backend_reference`; 5 audit actions; OpenAPI; 21 feature tests; **Credentials screen** at `/admin/control-center/credentials` (en/ar) with component tests and 5 browser tests. |
| Capability Discovery | **COMPLETE** | Provider discovery inside `TestConnection::forProvider` writes `provider_capabilities`; machine discovery is its own action (`DiscoverServer`); readiness refuses `ReadyForProduction` until capabilities exist. **Discovery screen** at `/admin/control-center/discovery` (read-only, en/ar): every provider with its observed capabilities or "never asked", every machine with its last look or "never looked at". Capability states have their own vocabulary (`admin.providers.capabilityStates`) so a provider's "unsupported" cannot read as the catalogue's "not sold". Real caller paths: the Machines screen (BMC), the Providers screen (test-and-discover). |
| Software Profiles | **COMPLETE** | `SoftwareCatalogue` in source: 12 components, each bound to an Ansible role in `infrastructure/ansible/roles` with its risk, reboot flag, verification and the override keys it accepts; 8 profiles, each bound to a playbook. `EveryComponentNamesARoleThatExistsTest` fails the build for a role or playbook not in the tree, a profile that installs a dependent before its dependency, or an override key that could carry syntax. `SoftwareCatalogueSeeder` mirrors it into the tables (idempotent; retired profiles are deactivated, never deleted). `GET infrastructure/profiles`; no write endpoint exists. Rendered on the Plans screen. |
| Desired State | **COMPLETE** | `AssignDesiredState` (profile must exist and be active; overrides validated by `OverrideRules` — only `component.key` pairs the profile's components declare, values one printable line free of shell/template syntax, refused by name otherwise; server moves to Profiled), `ClearDesiredState` (revokes standing approvals, audited automatic). `PUT/DELETE servers/{id}/desired-state`; audit `infrastructure.desired_state.{assigned,cleared}`; on the Plans screen with override fields generated from the profile's declared keys. |
| Plan Engine | **COMPLETE** | `PlanEngine`, pure: desired profile + validated overrides + current facts + classification → changes (install per component with no `software.<key>.present` fact), unchanged, blockers (`safety_class`, `reimage_clearance`, `licence`, `dependency_order`), risk = worst of the changes, `required_safety_class` from risk, reboot, destructive, `fingerprint` = SHA-256 over profile + ordered changes + configuration. `PlanDeployment` keeps the same row when the fingerprint is unchanged and writes a new one — revoking every standing approval on the machine, audited automatic — when it is not. `POST/GET servers/{id}/plan`; audit `infrastructure.plan.computed`; on the Plans screen with changes, risk, blockers and fingerprint. |
| Approval | **COMPLETE** | `ApproveDeploymentPlan` records the fingerprint as it stands, under `deployment.approve` (not held by infrastructure-admin); refuses a plan with blockers, a superseded plan, and the person who planned it (four eyes); a destructive plan needs the machine's name typed. `RevokeDeploymentApproval` by a person or by the plan changing. Checked again by the worker before touching the machine: a revoked approval or one for another fingerprint fails the run as `fingerprint_mismatch` with nothing started (tested with a re-plan between queueing and running). Audit `infrastructure.plan.{approved,approval_revoked}`; on the Plans screen with the fingerprint in the dialogue. |
| Deployment Jobs | **COMPLETE** | `RequestDeployment` (apply needs a current applicable plan + standing approval + the safety gate for the plan's risk; verify needs the Read gate only; one in flight per machine via the partial unique index → 409; a machine with an indeterminate or needs-review run is refused until resolved) → `RunDeploymentJob` (one try; claim under lock; re-checks plan, approval and gate; apply → verify → facts written as Derived, `last_deployment_at`/`last_verification_at`, server → Managed; indeterminate on a deadline, NeedsReview when verification fails, Failed with a class otherwise). `ResolveDeployment` (a person states completed/failed with a reason), `CancelDeployment` (only before it starts), `DetectStaleDeployments` (`deployments:detect-stale` every five minutes, one server: Applying/Verifying past the stale deadline → Indeterminate, never restarted). Audit `infrastructure.deployment.{requested,finished,resolved,cancelled}`. 16 feature tests over HTTP including timeout, replay, verify-failure, cancel, stale and RBAC; **Deployments screen** (en/ar) with steps, the waiting-for-a-person badge and the resolve dialogue; component tests; browser test walks assign → plan → refused self-approval → approval by a second person → run → completed → listed with steps. |
| Deployment Controller Bridge | **COMPLETE (fake proven, real refuses-tested)** | `DeploymentController` contract (`apply`, `verify`), bound by `DeploymentControllerFactory` from `config/infrastructure.php`. `FakeDeploymentController` rehearses every outcome from the machine's `fake://` marker (connected, apply-fails, apply-transient, apply-timeout, verify-fails, verify-timeout, unavailable) and refuses to exist in production. |
| IaC Bridge | **COMPLETE (never run against a real machine)** | `AnsibleDeploymentController`: an argument list, never a shell string — `ansible-playbook -i inventories/<env> playbooks/<profile playbook> --limit <machine name> --extra-vars @<json file>`, `--check --diff` for verify; the only variables handed over are the validated overrides, via a temporary file, so no operator text reaches argv. Refuses where `CI` is set, without `INFRASTRUCTURE_IAC_PATH`, for a playbook not in the tree, for a host that is not a hostname. Exit 4 → transient, other non-zero → permanent, process deadline → indeterminate. 9 unit tests with the process replaced. The `infrastructure/scripts/check-ci-cannot-apply.py` guard is untouched and CI still cannot apply. **Not `REAL_INFRA_VERIFIED`**: what is tested is what it refuses and what it would run. |
| Readiness Engine | **COMPLETE** | Providers: `ProviderReadiness` (15 unit cases). Products: `ProductReadinessEvaluator`, a pure function over `ProviderFacts`, climbing `not_ready → ready_for_test → ready_for_real_validation → ready_for_production`; a controlled (`fake*`) provider carries a requirement to `ready_for_test` and never higher, whatever environment or state its row is in (tested at the evaluator and over HTTP with a fake enabled in production). `ready_to_sell` is unreachable by the engine: `DeclareProductSellable` requires `ready_for_production` right now (reassessed under a lock), a reason and a reference to the validation evidence, under a permission no operator role holds by default; `AssessProduct` withdraws the declaration automatically and audits it when the evidence goes. Nothing here is `REAL_INFRA_VERIFIED` and nothing claims to be. 10 feature tests; **Readiness screen** at `/admin/control-center/readiness` (en/ar) with the ladder, the blocker-to-next-rung, the requirement rows, the declaration dialogue; component tests; 3 browser tests. |
| Dependency View | **COMPLETE** | `GET readiness/dependencies`: each product, what it leans on, and the provider carrying each requirement, from the same assessed rows the ladder reads; rendered on the Readiness screen by edge. |
| Drift | **COMPLETE** | `DetectInfrastructureDrift`: a Managed machine whose current facts no longer show a component its profile wants is recorded through the Provisioning module's `RecordDrift` as `infrastructure` / `managed_server` / `spec_mismatch` — one open row per machine, occurrences counted, in the same drift queue (`GET /admin/drift`), on the same `lynomia_resource_drift_open` metric and under the same runbook as compute and hosting drift. Runs after every clean verify and nightly (`infrastructure:detect-drift`, 00:40, one server). A machine never brought to its profile is work, not drift. 3 feature tests. |
| Monitoring Integration | **COMPLETE** | The `monitoring` component and profile in the software catalogue bind the observability stack to its playbook, so the control plane can plan and run it like any other role. `infrastructure/monitoring/prometheus/rules/control-center.yml` (5 alerts: `DeploymentWaitingForAPerson`, `EnabledProviderNotReady`, `CredentialMissing`, `LicenceExpiring`, `LicenceExpired`), each with a runbook that exists (`deployment-indeterminate`, `provider-not-ready`, `credential-missing`, `licence-expiry`); the Control Center Grafana dashboard (`lynomia-control-center`, 8 panels) generated by the existing builder; `validate-monitoring.py` and `validate-runbooks.py` pass. |
| Registrar / .SY / Hosting / WordPress / DNS / Payment / SMTP / BMC / PBS onboarding | **BLOCKED (not this build's to close)** | Drivers are catalogued (`sy_registry`, `cpanel`, `directadmin`, `cloudflare`, `stripe`, `ipmi`, `redfish`, `ilo`, `proxmox_backup`). None has a connection tester, so none can reach `ReadyForProduction`. SMTP has no catalogue entry and no adapter. |
| RBAC | **COMPLETE** | Eight new permissions. `deployment.approve` and `safety.allow_reimage` sit outside infrastructure-admin, and `readiness.declare` is held by no operator role at all (only the super-admin gate), so approving a plan, clearing a wipe and declaring a product sellable are each a distinct person's decision. Every admin route names `permission:`; customer, support, NOC and infrastructure-admin refusals tested per surface. |
| Audit | **COMPLETE** | Thirty-four new actions across the three modules, each written by exactly one act through `RecordActAtomically` inside the act's own transaction; assessments audit only on change. `EveryAuditActionIsRecordedSomewhereTest` green. No context field ever carries a secret (tested against the environment's own value). |
| Security | **COMPLETE** | `EndpointPolicy` (Shared) decides where the control plane may open a connection, checked at registration and again at use: loopback, link-local, unspecified, multicast, `.internal`/`.local`/`localhost` names and the cloud metadata literals refused everywhere, by literal and by what a name resolves to; a provider that is not on our hardware is refused a private address; real drivers HTTPS only, no userinfo; controlled drivers `fake://<marker>` only and never in production; a machine address is a host, never a URL. 21 unit cases + 6 adversarial HTTP tests (`TheControlCenterRefusesTheDangerousInputsTest`): SSRF strings on provider and machine registration, a row written straight to the table refused before a socket opens, a staging credential never resolved for a production target (at attachment and at use, with the secret absent from the response and the audit log), a destructive plan needing the typed name and still refused on a machine nobody cleared, an approval that cannot be moved to another fingerprint. With the earlier findings: no endpoint returns a secret; fake driver and fake controller refused in production; overrides refused unless declared and shaped; no operator text reaches argv; CI refused by the bridge. |
| Observability | **COMPLETE** | `ControlCenterCollector`: `lynomia_managed_servers{classification,state}`, `lynomia_provider_readiness{category,readiness}`, `lynomia_provider_state{state}`, `lynomia_providers_enabled_not_ready`, `lynomia_credentials{state}`, `lynomia_licences{state}`, `lynomia_product_readiness{product,state}`, `lynomia_deployments{state,kind}` — every combination at zero, no identifying label, one round trip (the query budget rose by exactly one, from 36 to 37). Every audited act already carries its context; the metrics are what pages. 4 feature tests including that every series the alert file names is one the collector emits. |
| Browser E2E | **COMPLETE** | 28 Control Center browser tests across eight specs (overview+sites, credentials, licences, machines, providers, discovery, readiness, plans+deployments) in English and Arabic, on seeded fixtures, run in CI. |
| Architecture Gates | COMPLETE | All green, including two new gates (`ANewRecordKnowsItsOwnState`, `TheModulesAreNamedForWhatTheyOwn`, which now also asserts `ProductReadiness` exists as its own module) and one corrected gate (`NoDeadMethods` layer scope). `EveryDomainEventIsConsumedTest` covers `ProviderReadinessChanged`. |
| Clean Room | **COMPLETE** | Section J: a fresh clone, nothing reused, every gate the working copy runs except PHPStan, whose toolchain install the proxy blocked; the same numbers on everything that ran. |
| CI | **GREEN** | Every run since 101 green on all jobs; runs 95–100 were red and each cause is in section K, none re-run into green without a code change. |

**Closed: 38 of 40 exit conditions.** Every condition this build can close is
closed to the full chain: Infrastructure Overview, Datacenter/Rack Registry,
Server Registry, Server Detail, Server Onboarding, Safety Enforcement,
Connection Framework, Discovery Framework, Provider Registry, Provider Catalog,
Requirement Engine, Licence Center, Credential References, Capability
Discovery, Software Profiles, Desired State, Plan Engine, Approval, Deployment
Jobs, Deployment Controller Bridge, IaC Bridge, Readiness Engine, Dependency
View, Drift, Monitoring Integration, Security, RBAC, Audit, Observability,
Browser E2E, Architecture Gates, Clean Room, CI. Two are not this build's to
close and are marked so rather than counted: the onboarding of the nine real
providers (each needs a real endpoint, which is what Phase 30B lacked) and, with
it, `REAL_INFRA_VERIFIED` for anything.

## D. Findings — fixed, and kept under regression test

1. **Initial state only the database knew.** `RegisterServer` left
   `connection_state` to the column default; `create()` returned null; the
   store response rendered a blank state. Measured before fixing: 25 defaulted
   state columns across the codebase, zero models declaring them. 27 models now
   declare the same initial state their table does; `ANewRecordKnowsItsOwnState`
   is driven from the live schema and recognises state columns by enum cast,
   which is how it later caught `managed_servers.safety_class` — a
   `ManagedServer` built in memory had a **null classification**, and the object
   is what the safety gate reads.
2. **`mayServe` deadlocked connection testing.** A credential becomes `Valid`
   only by being tested; requiring `Valid` to test meant nothing could ever be
   tested. Split into `mayBeTried` (state relaxes, environment does not) and
   `mayServe` (unchanged). Both now have production callers.
3. **Refused enable rolled back its own reassessment**, so the operator was
   told "not ready" and shown "ready". Reassessed outside the failed
   transaction, refusal re-thrown.
4. **Cross-module HTTP reach** — `ServerController` imported another module's
   concern and resource. Concern moved to `Lynomia\Http\Concerns`; connection
   test moved to `Providers`.
5. **`can:` vs `permission:`** on seven routes; only the latter is what the
   RBAC gate reads.
6. **`NoDeadMethods` mis-scoped.** `LAYERS` said `'Providers'` meaning the
   adapter directory; the new module of that name matched every file in it.
   Nine false positives (models, controllers). Scoped to
   `Infrastructure/Providers`. The two survivors were real and deleted.
7. **`estate.guidance.*` translation keys** were about to cross the API into the
   frontend permanently. Renamed to `controlCenter.guidance.*` and guarded.
8. **Secret resolver used `env()`**, which returns null after `config:cache`.
   Now `getenv()`, with a test.
9. **CI red for four runs** without being noticed locally — see C. The gate that
   should have caught it (`pint --test` on the whole tree) is what CI runs;
   local practice was `--dirty`. Local practice is now `pint --test` before push.
10. **The invoice credit dialogue's confirm button was pressable before its
    quote had loaded**, and its handler silently did nothing — found through a
    browser test that failed twice in CI and never locally. `ConfirmDialog`
    takes `ready`; the dialogue passes the same condition its handler checks.
11. **The browser seed wrote providers straight to the table**, so they carried
    no readiness verdict — a state the API never produces. The seeder now
    assesses what it writes, the way registration does.
12. **`sync()` on a ULID-keyed pivot wrote no primary key.** `profile_components`
    needed a pivot model with `HasUlids`; the seeder found it on its first run.
13. **A raise site the event gate could not see.** `ProviderReadinessChanged`
    was raised through a named constructor; `EveryDomainEventIsConsumedTest`
    looks for `new` at the raise site. Inlined, so the gate proves each raise.
14. **Two vocabularies for one word.** The status catalogue's `unsupported` is a
    TLD not sold; a capability's `unsupported` is a provider that said no. The
    capability states got their own namespace, and the gate covers it.
15. **The metrics query budget was exactly spent** when the control-centre
    collector arrived. Six group-bys became one `UNION ALL`, and the budget rose
    by one with the reason on it.
16. **The endpoint refusal fired before the more specific ones**, so an operator
    registering a Proxmox provider on a machine from another environment read
    "not HTTPS" instead of "machine elsewhere". Reordered: the endpoint policy
    is the last refusal, never the loudest.

## E. Constraints honoured

- No secret, password, private key, token, auth code or registrant PII in Git,
  in any database field, in any API response or in any log line. Enforced by
  `$hidden`, `TestTarget::__debugInfo`, the resolver reading process
  environment only, and response-content assertions.
- Every machine defaults to `DO_NOT_TOUCH` in the database **and** on the model.
- No UI action silently upgrades classification: the Machines screen offers
  exactly the rungs the API would accept (one up, any down), the destructive rung
  needs the machine's name typed, and the API refuses anything else regardless
  of what a screen sends. Clearing for a wipe is a second decision under a
  second permission, and it is withdrawn automatically when the class is lowered.
- No custom secrets manager.
- No automatic `REAL_INFRA_VERIFIED`; the word does not appear in the new code.
- Controlled drivers (`fake`, `fake_bmc`) and the fake deployment controller
  refused in production at registration and at construction; and a controlled
  provider counts for `ready_for_test` and nothing above it, so no fake can make
  a product sellable.
- `mayBeTried` and `mayServe` stayed distinct; production serving rules were not
  weakened to make connection testing possible.
- No PXE or DHCP on an unknown network: the `pxe` component is catalogued High,
  and nothing in this phase changed the Phase 30B guard on where it may run.
- Never infer that an idle-looking machine is disposable: nothing in the
  platform lowers or raises a classification but a person, one rung at a time.
- No parallel infrastructure system: the execution chain bridges to
  `infrastructure/ansible` as it is; the catalogue names its roles and a gate
  checks they exist.
- CI cannot apply infrastructure (`check-ci-cannot-apply.py`, green).

## F. The honest constraint

Eleven catalogued drivers have adapters. Two controlled ones (`fake`, `fake_bmc`) have a connection tester.
A tester is written against a real endpoint and Phase 30B closed NO GO with no
real endpoint. Consequences, all deliberate and all on the surface:

- `CatalogueEntry.testable` is false for every real driver.
- `ProviderReadiness` reports a configuration blocker naming the driver.
- No real provider can reach `ReadyForProduction`, so none can be enabled.
- No product can be declared `READY_TO_SELL` on this build.

This is the platform telling the truth about itself, and it will stay this way
until the first real endpoint exists.

## G. Deliverables

| Path | Status |
|---|---|
| `docs/phase-30b-p-infrastructure-provider-control-center.md` | this file, final |
| `docs/infrastructure-control-capability-matrix.md` | produced at closure: every Control Center capability from screen to bridge, with its audit action, its failure state, its tests and its classification; no row `REAL_INFRA_VERIFIED` |
| `docs/product-readiness-matrix.md` | produced at closure: the ladder, the requirement matrix, where every product stands (all `not_ready`, with the first blocker and what would move it), and what each rung costs a real provider |
| `docs/openapi.yaml` | 211 operations, regenerated at every commit and gate-checked against the resources |
| `docs/runbooks/{deployment-indeterminate,provider-not-ready,credential-missing,licence-expiry}.md` | the four runbooks the Control Center alerts point at |
| `infrastructure/monitoring/prometheus/rules/control-center.yml`, `infrastructure/monitoring/grafana/dashboards/control-center.json` | five alerts and one dashboard, validated |

## H. Remaining blockers to closure, in order

1. ~~CI green on a pushed HEAD.~~ Done at `fb2dd68`, run 101.
2. ~~Credential References.~~ Done (CI run 102 green); the provider end-to-end walk now attaches through the endpoint.
3. ~~Licence Center.~~ Done.
4. ~~Capability Discovery as its own surface; derive `driver` for server tests from the BMC provider instead of the request.~~ Done (Licence Center CI run 103 green); Machines, Providers and Discovery screens shipped with it.
5. ~~Requirement Engine, product readiness, `READY_TO_SELL`, blocker propagation, dependency view.~~ Done: the `ProductReadiness` module, its screen and its tests. `READY_TO_SELL` exists as a state and nothing on this build can reach it, which is the correct answer for a build with no real provider.
6. ~~Profiles → desired state → plan (fingerprint) → approval (invalidated on plan change) → deployment jobs → controller bridge → IaC bridge, with timeout/indeterminate states under the Timeout Rule.~~ Done, against the fake controller; the Ansible bridge is tested for what it refuses and what it would run, and has never touched a machine.
7. ~~SSRF and IaC-input guards; observability.~~ Done.
8. ~~Control Center frontend (en/ar, RTL/LTR) and browser E2E.~~ Done: ten screens under `/admin/control-center`, every state a screen shows translated in both languages under the gate, 28 browser tests.
9. ~~Pre-existing, found while chasing CI: the invoice credit dialogue's confirm button was enabled before the quote had loaded.~~ Fixed: `ConfirmDialog` takes `ready`, and the credit dialogue passes the same condition its handler checks. Component-tested.
10. ~~Clean room, both matrices, closing questions.~~ Done: sections G, I and J.

## I. Closing questions

Each answered from the code and the evidence as they are at the closing
commit, with the exact blocker for every NO.

> **Can an operator register a machine and have anything happen to it without
> a person deciding, twice?**

**NO, by construction.** A machine arrives `do_not_touch` in the database and
on the model; nothing connects to it, looks at it or changes it until somebody
raises the classification, one rung per call, with a reason. A wipe needs a
second decision under a second permission and the machine's name typed, and
that clearance is withdrawn the moment the class is lowered. The screen offers
only what the API would accept and disables the rest with the reason written
beside it. *Tested at the gate (39 cases), over HTTP, in the browser, and by the
Ansible `safety_gate` role on the IaC side.*

> **Can a plan run that was not the plan somebody approved?**

**NO.** An approval is for one fingerprint, over the ordered changes and their
configuration and nothing else. A re-plan that changes anything is a new row and
revokes every standing approval on the machine, audited as automatic. The worker
compares the fingerprint again under a lock before touching the machine and ends
the job as `fingerprint_mismatch` with nothing started. The planner may not
approve their own plan. *Tested with a re-plan between queueing and running, and
with the fingerprint edited in the row.*

> **Can a run that timed out be retried into duplicate destructive work?**

**NO.** A run that outlives its deadline is `indeterminate` — never failed, never
succeeded — and a machine with an indeterminate or needs-review run is refused
every further run until a person resolves it with a reason. A worker that
vanished mid-playbook is found by the five-minute sweep and marked the same way.
Nothing restarts either. *Tested over HTTP against the fake controller; not
against a playbook that timed out for real reasons — `BLOCKED_HARDWARE`.*

> **Can an operator's text reach a playbook, a shell or the network?**

**NO.** Overrides are refused unless every key is one a component in the
profile declares and every value is one printable line free of shell and
template syntax; the Ansible bridge builds an argument list, limits to the
machine's registered name, and hands the validated overrides over in a JSON
file; the playbook and the host are validated against the tree and a hostname
shape; CI is refused by the bridge and by `check-ci-cannot-apply.py`. A
provider endpoint or a machine address that is this host, a metadata service,
a private range a cloud provider has no business with, plain HTTP or a URL with
a credential in it is refused at registration and again before any socket
opens. *Tested: 21 policy cases, 6 adversarial HTTP tests, 9 bridge tests.*

> **Can a secret reach a database row, an API response, a log line or an
> audit row?**

**NO.** The platform stores the name of a variable on the deployment
controller, never its value; a value-shaped reference or any unknown field is
refused with "rotate it now" and never echoed; the resolver reads the process
environment only, and refuses to resolve a credential for another environment
so a staging token never enters memory against production. *Tested against the
environment's own value, which appears in no response and no audit context.*

> **Can a fake provider make a product sellable?**

**NO.** A controlled driver carries a requirement to `ready_for_test` and never
higher, whatever environment its row says and whatever state an operator put it
in. `ready_to_sell` is a person's declaration under a permission no operator
role holds, refused below `ready_for_production` after a reassessment under a
lock, and withdrawn automatically the moment any requirement falls. *Tested at
the evaluator and over HTTP with a fake enabled in production.*

> **Does the platform know when a provider it enabled stops being ready, and
> does it say so?**

**Yes.** A credential revoked or rotated, a licence lapsed or invalidated, a
connection that stops answering — each reassesses the provider, raises
`ProviderReadinessChanged`, and re-evaluates every product in the same
transaction. Nothing switches the provider off, by design; the overview lists
it, `lynomia_providers_enabled_not_ready` counts it, and `EnabledProviderNotReady`
alerts on it with a runbook. *Tested: the credential is revoked through its own
endpoint, nothing mentions a product, and the product is downgraded with its
declaration withdrawn.*

> **Can operators see every indeterminate or needs-review deployment?**

**Yes in the software.** They are first on the Deployments screen, first on the
overview, counted on `lynomia_deployments`, and `DeploymentWaitingForAPerson`
fires on any that persist for ten minutes, pointing at
`docs/runbooks/deployment-indeterminate.md`. *No Prometheus has scraped a real
control plane, so the alert has never fired — `BLOCKED_HARDWARE`.*

> **Is any real provider ready for production? Is any product ready to sell?**

**NO, and NO.** Eleven real drivers are catalogued with `testable=false`; each
reports a configuration blocker naming the missing tester; none can reach
`ready_for_production`; every product is `not_ready` on the first blocker in
dependency order (`docs/product-readiness-matrix.md`). *Blocker for every one:
a connection tester written against a real endpoint — `BLOCKED_HARDWARE` for
Proxmox, PBS, the panels and the BMCs; `BLOCKED_NETWORK` then
`BLOCKED_CREDENTIALS` for Cloudflare and the payment provider;
`BLOCKED_LICENCE` for cPanel and DirectAdmin; no registrar selected.*

> **Has anything in this phase been verified against real infrastructure?**

**NO.** Nothing was, nothing claims to have been, and the word
`REAL_INFRA_VERIFIED` appears in the new code only in comments that say it is
not reached. The Ansible bridge has never run a playbook; what is proven is what
it refuses and the exact argument list it would run.

> **Can CI apply infrastructure?**

**NO.** `check-ci-cannot-apply.py` is unchanged and green, and the bridge
refuses to run where `CI` is set.

> **Is the platform ready to sell anything on this build?**

**NO.** The control centre is complete; the estate it controls does not exist
yet. That is the correct answer for a build with no real provider, and it is
the answer the platform gives on its own Readiness screen.

## J. Clean room

Run from `git clone --branch claude/hv-t6hq1p` of the repository at `ba0f4ab`
into a directory of its own — no reuse of the working copy, no existing
database, no untracked env file, no manual keys, no hidden fixtures. The
commits after `ba0f4ab` are this report and the two matrices.

| Step | Command | Result |
| --- | --- | --- |
| PHP dependencies | `composer install --prefer-dist` | OK — 196 packages downloaded, 129 cloned from the source cache where the proxy refuses a dist download (as in every previous clean room) |
| Keys | `php artisan key:generate` for `.env` and `--env=testing` | OK |
| Node dependencies | `npm ci` at the workspace root, once | OK |
| Databases | `lynomia_cr30bp`, `_test`, `_e2e` | created empty by `psql` |
| Migrations | `php artisan migrate --force` | 50 migrations from an empty database, including the four of this phase |
| Seed | `php artisan db:seed --force` | roles and permissions; the software catalogue (12 components, 8 profiles); 3 products, 9 plans, 32 prices; 2 compute nodes, 61 addresses, 1 hosting node, 5 dedicated servers |
| Backend suite | `php vendor/bin/phpunit` | **2 708 passed, 92 944 assertions**, 355 s — the working copy's and CI's numbers exactly |
| Style | `vendor/bin/pint --test` on the whole tree | passed |
| Static analysis | `composer install --working-dir=tools/phpstan` | **not run**: the install fails on `Could not authenticate against github.com` for two packages the proxy will not serve as dist, as recorded in the two previous phases' clean rooms. PHPStan passes with 0 errors in the working copy and in CI's Static analysis job on every green run in section K. The gap is in this environment's network, not in the repository. |
| Typecheck | `npm run typecheck` | OK |
| Lint | `npm run lint` | OK |
| Frontend unit | `npm run test` | 81 passed in 18 files |
| Production build | `npm run build` | OK |
| OpenAPI | `php artisan openapi:generate` then `git diff --exit-code docs/openapi.yaml` | up to date: 211 operations, no diff |
| OpenAPI validation | `npm run openapi:lint` | valid, 4 warnings |
| Scheduler | `php artisan schedule:list` | 23 commands registered, including `licences:refresh`, `deployments:detect-stale` and `infrastructure:detect-drift` |
| Queue worker | `php artisan queue:work redis --stop-when-empty` | started, ran the seeded zone publication, drained, exited 0 |
| Infrastructure validators | `validate-monitoring.py`, `validate-runbooks.py`, `check-ci-cannot-apply.py`, `test_validate_inventory.py`, `test_safety_gate.sh` | all pass (69 rules over 49 exported and 7 declared series; 59 artisan invocations in runbooks all defined; 38 CI steps, none applies; 15/15; 10/10) |
| Browser suite | `npx playwright test` against a fresh `lynomia_cr30bp_e2e` | **133 passed** (5.9 min), the 28 Control Center specs among them |

Nothing in the clean room needed a fix this time. The one thing it could not
run is the one thing it has never been able to run here, and it is named
above rather than omitted.

## K. GitHub Actions, observed

Pushed and observed, not assumed. Every red run on this branch during the phase
is listed with its cause; none was re-run into green without a code change.

| Run | Commit | Conclusion | Cause / note |
|---|---|---|---|
| 95–98 | rename commits | red | "Check code style" in both backend jobs: five files with reordered imports from the rename; `pint --dirty` never re-checks committed files. Fixed with whole-tree `pint --test`. |
| 99 | `5c1a2a2` | red | `TheModulesAreNamedForWhatTheyOwnTest` asserted a `ProductReadiness` directory that existed only as empty untracked directories on one machine. The scaffold was deleted and the gate asserts only modules with code (it asserts `ProductReadiness` again now that the module exists). |
| 100 | `38a2a45` | red ×2 | `wallet-credit.e2e.ts`: the credit dialogue's confirm button pressable before the quote loaded; spec waits for the quote, and the button is now disabled until then (finding 10). |
| 101 | `fb2dd68` | green | |
| 102 | `44386af` | green | Credential Center |
| 103 | `6bf7e19` | green | Licence Center |
| 104 | `23c9601` | green | BMC-derived driver, discovery, three screens |
| 105 | `a225438` | green | ProductReadiness module |
| 106 | `82c0d10` | green | execution chain |
| 107 | `8932849` | green | endpoint policy and adversarial tests |
| 108 | `ad8e179` | green | metrics, alerts, runbooks, dashboard, infrastructure drift |
| 109 | `ba0f4ab` | green | overview and site registry — the last commit of code |
| 110 | `1c01aa5` | green | this report and the matrices; documents only, no code changed after `ba0f4ab` |
| 111–112 | `1c01aa5`, `618729a` | green | the same two commits run again on the pull-request trigger |
| 113 | `618729a` | green | the PHPStan wording in the summary, the Clean Room row and this table; documents only |

## L. Verdict

The Control Center is complete to the closure standard: for every capability
the brief named, the model, the authorization, the API, the application path,
the bridge where one exists, the operator surface, the audit row, the
observable state, the failure state and the tests exist and are green, in the
working copy, in CI and in a clean room. Three bounded modules own it and a
gate keeps them apart.

What it controls does not exist yet. No real provider can be connection-tested
because no real endpoint was available to write a tester against; therefore no
real provider is ready, no machine has been deployed to, and no product can be
sold. The platform reports exactly this about itself — on the catalogue, on
every provider row, on the readiness ladder and on the overview — and it will
keep reporting it until Phase 30B resumes against a real machine and the first
tester is written. That is the next phase's first task, and this report does
not pretend otherwise.
