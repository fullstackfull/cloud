# Phase 30B-P — Infrastructure & Provider Control Center

**Status: IN PROGRESS. This phase is not closed.**

This is the authoritative report for Phase 30B-P and is updated as the phase
advances. It records what has been built to the full closure standard, what has
been found and fixed on the way, and — precisely — what has not been done. It
does not claim `REAL_INFRA_VERIFIED` for anything; that classification is
reserved for Phase 30B resuming against real systems.

| | |
|---|---|
| Branch | `claude/hv-t6hq1p` |
| HEAD at time of writing | see the commit that introduced this revision of the file |
| Backend tests | 2708 / 2708 passing locally |
| PHPStan | 0 errors |
| Frontend unit tests | 81 / 81 (18 files) |
| Browser E2E | 133 tests in 23 files (28 cover Control Center screens) |
| OpenAPI operations | 211, generator and committed document in agreement |

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
| Registrar / .SY / Hosting / WordPress / DNS / Payment / SMTP / BMC / PBS onboarding | NOT STARTED | Drivers are catalogued (`sy_registry`, `cpanel`, `directadmin`, `cloudflare`, `stripe`, `ipmi`, `redfish`, `ilo`, `proxmox_backup`). None has a connection tester, so none can reach `ReadyForProduction`. SMTP has no catalogue entry and no adapter. |
| RBAC | COMPLETE for what exists | Eight new permissions (`deployment.approve` sits outside infrastructure-admin, so an approval is always a distinct decision (`readiness.declare` is held by no operator role by default, only by the super-admin gate, so a sellability declaration is always somebody's); `infrastructure-admin` holds all but `AllowReimage` and `DeploymentApprove`; every admin route names `permission:`; customer and support-role refusals tested. |
| Audit | COMPLETE for what exists | Twenty new actions (nine for the execution chain added) (`product_readiness.{changed,declared_sellable,sellability_withdrawn}` added; assessment audits only on change, so a sweep that concludes what the last one concluded writes nothing), each written by exactly one act via `RecordActAtomically`; `EveryAuditActionIsRecordedSomewhereTest` green. |
| Security | **COMPLETE** | `EndpointPolicy` (Shared) decides where the control plane may open a connection, checked at registration and again at use: loopback, link-local, unspecified, multicast, `.internal`/`.local`/`localhost` names and the cloud metadata literals refused everywhere, by literal and by what a name resolves to; a provider that is not on our hardware is refused a private address; real drivers HTTPS only, no userinfo; controlled drivers `fake://<marker>` only and never in production; a machine address is a host, never a URL. 21 unit cases + 6 adversarial HTTP tests (`TheControlCenterRefusesTheDangerousInputsTest`): SSRF strings on provider and machine registration, a row written straight to the table refused before a socket opens, a staging credential never resolved for a production target (at attachment and at use, with the secret absent from the response and the audit log), a destructive plan needing the typed name and still refused on a machine nobody cleared, an approval that cannot be moved to another fingerprint. With the earlier findings: no endpoint returns a secret; fake driver and fake controller refused in production; overrides refused unless declared and shaped; no operator text reaches argv; CI refused by the bridge. |
| Observability | **COMPLETE** | `ControlCenterCollector`: `lynomia_managed_servers{classification,state}`, `lynomia_provider_readiness{category,readiness}`, `lynomia_provider_state{state}`, `lynomia_providers_enabled_not_ready`, `lynomia_credentials{state}`, `lynomia_licences{state}`, `lynomia_product_readiness{product,state}`, `lynomia_deployments{state,kind}` — every combination at zero, no identifying label, one round trip (the query budget rose by exactly one, from 36 to 37). Every audited act already carries its context; the metrics are what pages. 4 feature tests including that every series the alert file names is one the collector emits. |
| Browser E2E | **COMPLETE** | 28 Control Center browser tests across eight specs (overview+sites, credentials, licences, machines, providers, discovery, readiness, plans+deployments) in English and Arabic, on seeded fixtures, run in CI. |
| Architecture Gates | COMPLETE | All green, including two new gates (`ANewRecordKnowsItsOwnState`, `TheModulesAreNamedForWhatTheyOwn`, which now also asserts `ProductReadiness` exists as its own module) and one corrected gate (`NoDeadMethods` layer scope). `EveryDomainEventIsConsumedTest` covers `ProviderReadinessChanged`. |
| Clean Room | NOT STARTED (for this phase) | |
| CI | **RED through run 99; fixed in the commit carrying this revision** | Runs 95–98 failed at "Check code style" in both backend jobs (five files from the rename commit with reordered imports; `pint --dirty` never re-checks committed files). Run 99 (`5c1a2a2`) got past style and failed one test: `TheModulesAreNamedForWhatTheyOwnTest` asserted `src/Modules/ProductReadiness` exists — it existed locally as three empty, untracked directories, so the gate passed on one machine and failed in CI. The scaffold is deleted and the test now asserts only modules that have code, plus that readiness is not folded into Infrastructure or Providers. All other seven jobs green on run 99. Run 100 (`38a2a45`): both backend jobs green (2570 tests), one browser E2E failure — `wallet-credit.e2e.ts:23`, reproduced on a re-run, not reproducible locally in isolation or in full-suite order. Root cause in the spec: the credit dialogue's confirm button is enabled while the quote is loading and its handler silently does nothing without a quote; the spec asserted `toHaveCount(0)` on a warning (true of an empty dialogue) and clicked, so on a slow runner the click was swallowed. Spec now waits for the quote to render and scopes the button to the dialog. The silently inert button is itself a small UI defect, recorded in H. |

**Closed: 28 of 40 exit conditions in the brief's sense** (Infrastructure
Overview, Datacenter/Rack Registry, Server Registry,
Server Detail, Server Onboarding, Safety Enforcement, Connection Framework,
Discovery Framework, Provider Registry, Provider Catalog, Licence Center,
Credential References, Capability Discovery, Requirement Engine, Readiness
Engine, Dependency View, Software Profiles, Desired State, Plan Engine,
Approval, Deployment Jobs, Deployment Controller Bridge, IaC Bridge, Browser
E2E for the Control Center, Security, Drift, Monitoring Integration,
Observability, plus the architecture gates). The rest are partial or not
started.

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

## E. Constraints honoured

- No secret, password, private key, token, auth code or registrant PII in Git,
  in any database field, in any API response or in any log line. Enforced by
  `$hidden`, `TestTarget::__debugInfo`, the resolver reading process
  environment only, and response-content assertions.
- Every machine defaults to `DO_NOT_TOUCH` in the database **and** on the model.
- No UI action can upgrade classification (there is no UI yet; the API refuses
  more than one rung per call and requires a typed name for the destructive rung).
- No custom secrets manager.
- No automatic `REAL_INFRA_VERIFIED`; the word does not appear in the new code.
- Controlled `fake` driver refused in production at registration and at
  construction.
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

## G. Deliverables not yet produced

| Path | Status |
|---|---|
| `docs/phase-30b-p-infrastructure-provider-control-center.md` | this file, interim |
| `docs/infrastructure-control-capability-matrix.md` | **not produced** — deliverable of 30B-P.8, meaningless before the readiness and deployment engines exist |
| `docs/product-readiness-matrix.md` | **not produced** — same |

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
10. Clean room, both matrices, closing questions.

## I. Closing questions (to be answered at closure)

Reserved. Not answered until the conditions above are closed.
