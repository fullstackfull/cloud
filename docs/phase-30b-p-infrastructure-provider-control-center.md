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
| Backend tests | 2617 / 2617 passing locally |
| PHPStan | 0 errors |
| Frontend unit tests | 72 / 72 (14 files) |
| Browser E2E | 125 tests in 20 files (20 cover Control Center screens) |
| OpenAPI operations | 184, generator and committed document in agreement |

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
| `Providers` | provider instances, catalogue, credential references, licences, capabilities, connection tests, readiness |
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
| Infrastructure Overview | NOT STARTED | No overview screen or aggregate endpoint. |
| Datacenter/Rack Registry | PARTIAL | Tables pre-existed (`datacenters`, `racks`); `racks` extended with `power_notes`/`network_notes`. No registry actions, API or screen for this phase. |
| Server Registry | **COMPLETE** | `RegisterServer`, `ServerController::{index,show,store}`, `ServerResource`, OpenAPI, audit `infrastructure.server.registered`, 13 + 8 feature tests; **Machines screen** at `/admin/control-center/machines` (en/ar): list with classification and connection in words, register form that says the machine arrives do-not-touch. |
| Server Detail | **COMPLETE** | The Machines screen's detail panel: classification and its reason (or "never classified"), reimage clearance, credential, connection test with its steps, discovery, and the current facts with their source. Controls are disabled by `safety.permits.*` and the refusal is written on the panel before the click. |
| Server Onboarding | **COMPLETE** | Register → classify (one rung up, typed name for the destructive rung, reason) → attach credential → test → discover, all from the Machines screen; the browser suite walks register → classify and test → discover on the seeded machines. No wizard: the ladder is the wizard, one rung per decision. |
| Safety Enforcement | COMPLETE (backend) | `SafetyGate` pure function, 39 unit cases; `ClassifyServer` (one rung up, any distance down, typed name for destructive rung, row lock, reason); `ClearForReimage` / `RevokeReimageClearance` under separate permissions; DB CHECK `allow_reimage = false OR safety_class = 'reimage_allowed'`; connection test gated on `Read`; refusals are 409 carrying classification/attempted/would-permit. Ansible `safety_gate` role proves the same rule on the IaC side (10/10). The screen offers only the rungs the API would accept and disables test/discover on a do-not-touch machine (component- and browser-tested). |
| Connection Framework | COMPLETE (backend) | `ConnectionTester` contract, `ConnectionTesterFactory` (bind, not singleton; verifies `driver()`), `FakeConnectionTester` with nine failure markers and a production guard; `TestConnection::{forServer,forProvider}`; `connection_tests` table with exactly-one-subject CHECK; audit `providers.connection.tested`. **A machine's driver is now derived from its own BMC provider** (`ManagedServer::bmc()`), never from the request; a machine with no BMC provider is refused with `bmc_missing`. Surfaced on the Machines and Providers screens with the steps walked. **Only the `fake`/`fake_bmc` drivers have a tester** — see F. |
| Discovery Framework | **COMPLETE** | `ConnectionTester::discover()`; `DiscoverServer` writes `server_facts` (supersedes changed values, supersedes discovered keys no longer reported, never touches declared facts, writes nothing on an unusable connection), sets `last_discovery_at`, audit `infrastructure.server.discovered`; `POST servers/{id}/discover`, `GET servers/{id}/facts`; 9 feature tests (`AMachineIsReachedThroughItsOwnBmcTest`); facts with source on the Machines screen; browser-tested. |
| Provider Registry | COMPLETE (backend) | `RegisterProvider` / `EnableProvider` / `DisableProvider` / `AssessProvider`; 8 routes; `ProviderResource`; enable reassesses readiness inside the transaction under a row lock; partial unique index one-enabled-per-(category, environment) translated to a 409; disable writes one column and cascades to nothing; audit `providers.provider.{registered,enabled,disabled}`; 20 feature tests including the end-to-end walk; **Providers screen** at `/admin/control-center/providers` (en/ar): readiness with the blocker and its next action in words, credential and licence attachment, test-and-discover with steps, capabilities, enable only when ready-for-production, disable with a reason; component tests and 4 browser tests. |
| Provider Catalog | COMPLETE (backend) | `ProviderCatalogue` in source (12 drivers), `CatalogueEntryResource` with `testable` and `available_here`; `TheCatalogueOnlyClaimsWhatExistsTest` names the adapter class per driver; the register form on the Providers screen offers only `available_here` drivers, shows the summary, and asks for endpoint/machine only where the entry needs them. |
| Requirement Engine | PARTIAL | Per-driver requirements (`needsEndpoint/Credential/Licence/Server`) live on `CatalogueEntry` and drive readiness. No cross-product requirement matrix. |
| Licence Center | **COMPLETE** | `RecordLicence` (state from the calendar: pending before start, active, expiring ≤30 days, expired; a key posted under any name is refused and pointed at the credential centre), `AttachLicence` (invalid and cross-environment refused at attachment), `RenewLicence` (clears an invalidation, recomputes, reassesses dependents at once; an expired renewal is refused), `InvalidateLicence` (the operator's only override, one direction only), `RefreshLicenceStates` (nightly at 00:10 and on demand; each transition audited; invalid/not-required never touched). 8 routes, 6 audit actions, `licences:refresh` command, OpenAPI; 16 feature tests including time-travel through expiring→expired with the provider blocked at the right step; **Licences screen** (en/ar), component tests, 5 browser tests. |
| Credential References | **COMPLETE** | `RecordCredentialReference` (reference shape enforced; a value-shaped reference is refused with "rotate it now"; any unexpected field such as `secret`/`password` is refused by name and never echoed), `AttachCredential` to provider or machine (revoked and cross-environment refused at attachment, not only at use), `RevokeCredential` (reason, row lock, every dependent provider reassessed, nothing switched off), `MarkCredentialRotated` (state drops to configured/missing, last test forgotten, dependents reassessed). `SecretResolver::exists()` answers presence without ever holding the value. 9 routes; `CredentialResource` never carries `backend_reference`; 5 audit actions; OpenAPI; 21 feature tests; **Credentials screen** at `/admin/control-center/credentials` (en/ar) with component tests and 5 browser tests. |
| Capability Discovery | **COMPLETE** | Provider discovery inside `TestConnection::forProvider` writes `provider_capabilities`; machine discovery is its own action (`DiscoverServer`); readiness refuses `ReadyForProduction` until capabilities exist. **Discovery screen** at `/admin/control-center/discovery` (read-only, en/ar): every provider with its observed capabilities or "never asked", every machine with its last look or "never looked at". Capability states have their own vocabulary (`admin.providers.capabilityStates`) so a provider's "unsupported" cannot read as the catalogue's "not sold". Real caller paths: the Machines screen (BMC), the Providers screen (test-and-discover). |
| Software Profiles | NOT STARTED | Tables and models only. |
| Desired State | NOT STARTED | Tables and models only. |
| Plan Engine | NOT STARTED | Table has `fingerprint`; nothing computes one. |
| Approval | NOT STARTED | Table has `approved_fingerprint`; nothing checks it. |
| Deployment Jobs | NOT STARTED | Table has one-in-flight-per-server partial unique index; no handler. |
| Deployment Controller Bridge | NOT STARTED | |
| IaC Bridge | NOT STARTED | Phase 30B `infrastructure/` tree is the source of truth and is validated in CI; nothing in the control plane calls into it yet. |
| Readiness Engine | PARTIAL | `ProviderReadiness` complete for providers (15 unit cases: dependency-ordered single blocker, `mayServe`, licence environment, untestable driver, discovery required). No product-level readiness, no `READY_TO_SELL`. |
| Dependency View | NOT STARTED | |
| Drift | NOT STARTED (in this phase) | Pre-existing compute/hosting drift untouched; no infrastructure-level drift. |
| Monitoring Integration | NOT STARTED | |
| Registrar / .SY / Hosting / WordPress / DNS / Payment / SMTP / BMC / PBS onboarding | NOT STARTED | Drivers are catalogued (`sy_registry`, `cpanel`, `directadmin`, `cloudflare`, `stripe`, `ipmi`, `redfish`, `ilo`, `proxmox_backup`). None has a connection tester, so none can reach `ReadyForProduction`. SMTP has no catalogue entry and no adapter. |
| RBAC | COMPLETE for what exists | Seven new permissions; `infrastructure-admin` holds all but `AllowReimage` and `DeploymentApprove`; every admin route names `permission:`; customer and support-role refusals tested. |
| Audit | COMPLETE for what exists | Eight new actions, each written by exactly one act via `RecordActAtomically`; `EveryAuditActionIsRecordedSomewhereTest` green. |
| Security | PARTIAL | No endpoint returns a secret (tested); cross-environment credential never resolved (tested); fake driver refused in production (tested). Not yet done: SSRF guard on provider endpoints, IaC input injection guards. |
| Observability | NOT STARTED | No metrics for the control centre. |
| Browser E2E | PARTIAL | 20 Control Center browser tests across five specs (credentials, licences, machines, providers, discovery) in English and Arabic; the plans/deployments/readiness screens do not exist yet. |
| Architecture Gates | COMPLETE | All green, including two new gates (`ANewRecordKnowsItsOwnState`, `TheModulesAreNamedForWhatTheyOwn`) and one corrected gate (`NoDeadMethods` layer scope). |
| Clean Room | NOT STARTED (for this phase) | |
| CI | **RED through run 99; fixed in the commit carrying this revision** | Runs 95–98 failed at "Check code style" in both backend jobs (five files from the rename commit with reordered imports; `pint --dirty` never re-checks committed files). Run 99 (`5c1a2a2`) got past style and failed one test: `TheModulesAreNamedForWhatTheyOwnTest` asserted `src/Modules/ProductReadiness` exists — it existed locally as three empty, untracked directories, so the gate passed on one machine and failed in CI. The scaffold is deleted and the test now asserts only modules that have code, plus that readiness is not folded into Infrastructure or Providers. All other seven jobs green on run 99. Run 100 (`38a2a45`): both backend jobs green (2570 tests), one browser E2E failure — `wallet-credit.e2e.ts:23`, reproduced on a re-run, not reproducible locally in isolation or in full-suite order. Root cause in the spec: the credit dialogue's confirm button is enabled while the quote is loading and its handler silently does nothing without a quote; the spec asserted `toHaveCount(0)` on a warning (true of an empty dialogue) and clicked, so on a slow runner the click was swallowed. Spec now waits for the quote to render and scopes the button to the dialog. The silently inert button is itself a small UI defect, recorded in H. |

**Closed: 11 of 40 exit conditions in the brief's sense** (Server Registry,
Server Detail, Server Onboarding, Safety Enforcement, Connection Framework,
Discovery Framework, Provider Registry, Provider Catalog, Licence Center,
Credential References, Capability Discovery, plus the architecture gates). The
rest are partial or not started.

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
5. Requirement Engine, product readiness, `READY_TO_SELL`, blocker propagation, dependency view.
6. Profiles → desired state → plan (fingerprint) → approval (invalidated on plan change) → deployment jobs → controller bridge → IaC bridge, with timeout/indeterminate states under the Timeout Rule.
7. SSRF and IaC-input guards; observability.
8. Control Center frontend (en/ar, RTL/LTR) and browser E2E for the surfaces still to come: infrastructure overview, plans, deployments, readiness.
9. ~~Pre-existing, found while chasing CI: the invoice credit dialogue's confirm button was enabled before the quote had loaded.~~ Fixed: `ConfirmDialog` takes `ready`, and the credit dialogue passes the same condition its handler checks. Component-tested.
10. Clean room, both matrices, closing questions.

## I. Closing questions (to be answered at closure)

Reserved. Not answered until the conditions above are closed.
