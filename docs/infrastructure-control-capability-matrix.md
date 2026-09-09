# Infrastructure control capability matrix

Every capability the Control Center offers an operator, traced from the screen
to the thing that has to happen — and, for each link, whether it exists and is
under test. Produced at the closure of Phase 30B-P from the code as it is at
that commit, not from the plan.

The question each row answers is the one this phase was held to: **if an
operator does this, does the thing they wanted actually happen, and does the
platform say so when it did not?**

## How to read the columns

| Column | What it records |
| --- | --- |
| **UI** | The operator route under `/admin/control-center`. |
| **API** | The endpoint under `/api/admin`, and the permission on the route. |
| **Action** | The application action the endpoint calls. |
| **Reaches** | What the action reaches at the far end: a table only, a provider tester, the deployment controller, the IaC tree. |
| **Audit** | The `audit_log` action written, by exactly one act. |
| **Failure state** | What the operator sees when it does not work — a refusal code, a state the row moves to, or both. |
| **Tests** | The test file that proves the chain, and the browser spec that drives it. |
| **State** | The classification below. |

### Classification

| State | Meaning |
| --- | --- |
| `RUNTIME_VERIFIED` | Proven end to end against the controlled (fake) provider or controller, through the real API, the real queue, the real database and — where a screen exists — a real browser. |
| `TESTED` | Covered by tests that do not run the whole path (a pure engine, a bridge with its process replaced). |
| `REFUSES` | The capability's job is to say no, and it is tested for saying no. |
| `REAL_INFRA_VERIFIED` | Performed against a real machine or vendor and independently confirmed. **No row carries this.** |

**As of the closing commit of Phase 30B-P, no row is `REAL_INFRA_VERIFIED`.**
Every path below has been proven against `fake`, `fake_bmc` or the fake
deployment controller and never against a real endpoint, because Phase 30B
closed NO GO with none available. The real drivers are catalogued and cannot be
connection-tested (`CatalogueEntry.testable = false`), so no real provider can
reach `ready_for_production`, no real machine can be deployed to, and no product
can be sold. The platform reports this about itself on every screen concerned.

---

## Machines (`Infrastructure`)

| Capability | UI | API | Action | Reaches | Audit | Failure state | Tests | State |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Register a machine | `/machines` | `POST infrastructure/servers` · `infrastructure.manage` | `RegisterServer` | `managed_servers`, always `do_not_touch` | `infrastructure.server.registered` | 422 validation; 409 `endpoint_refused` for an address that is this host, a metadata service or a URL | `OnboardingAServerRefusesToSkipTheLookTest`, `TheControlCenterRefusesTheDangerousInputsTest`; `control-center-machines.e2e.ts` | `RUNTIME_VERIFIED` |
| Classify (raise one rung, lower any) | `/machines` | `POST servers/{id}/classify` · `infrastructure.manage` + `safety.change`; destructive rung also `safety.allow_reimage` | `ClassifyServer` | `managed_servers.safety_class` under a row lock | `infrastructure.server.safety_changed` | 409 `safety_refused` (more than one rung, wrong typed name); 403 without the destructive permission | `TheServerSurfaceSeparatesTwoDecisionsTest`; component + browser tests | `RUNTIME_VERIFIED` |
| Clear for reimage / withdraw clearance | `/machines` | `POST|DELETE servers/{id}/clear-for-reimage` · `safety.allow_reimage` | `ClearForReimage`, `RevokeReimageClearance` | `allow_reimage` with a DB CHECK against the class | `infrastructure.server.reimage_cleared`, `…reimage_clearance_revoked` | 409 unless the class is `reimage_allowed`; clearance withdrawn automatically when the class is lowered | `TheServerSurfaceSeparatesTwoDecisionsTest`; `SafetyGate` 39 unit cases; Ansible `safety_gate` role 10/10 | `RUNTIME_VERIFIED` |
| Attach / detach a credential | `/machines` | `POST|DELETE servers/{id}/credential` · `credential.manage` | `AttachCredential::toServer/fromServer` | `credential_reference_id`; revoked and cross-environment refused | `providers.credential.attached/detached` | 409 `credential_refused` | `ACredentialIsAReferenceAndNeverAValueTest` | `RUNTIME_VERIFIED` |
| Test the connection | `/machines` | `POST servers/{id}/connection-test` · `infrastructure.manage` | `TestConnection::forServer` — driver from the machine's own BMC provider, never the request | `ConnectionTester` for that driver (only `fake_bmc` exists) | `providers.connection.tested` | 409 `safety_refused` on a do-not-touch machine (button disabled with the reason on the screen); 409 `bmc_missing`; 409 `endpoint_refused`; the result row carries `auth_failed`, `network_failed`, `tls_failed`, `indeterminate`… | `AMachineIsReachedThroughItsOwnBmcTest`; browser test walks it | `RUNTIME_VERIFIED` (fake BMC) |
| Discover facts | `/machines` | `POST servers/{id}/discover`, `GET servers/{id}/facts` · `infrastructure.manage` / `.view` | `DiscoverServer` | The tester's `discover()`; `server_facts` with supersession, declared facts never touched | `infrastructure.server.discovered` | Nothing written when the connection was not usable; the screen says so | `AMachineIsReachedThroughItsOwnBmcTest` (9); browser test | `RUNTIME_VERIFIED` (fake BMC) |

## Sites (`Infrastructure`)

| Capability | UI | API | Action | Reaches | Audit | Failure state | Tests | State |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Register a datacenter | `/sites` | `POST infrastructure/datacenters` · `infrastructure.manage` | `RegisterDatacenter` | `datacenters` | `infrastructure.datacenter.registered` | 422 duplicate slug or unknown region | `TheOverviewAndTheSiteRegistryTest` | `RUNTIME_VERIFIED` |
| Register a rack | `/sites` | `POST infrastructure/racks` · `infrastructure.manage` | `RegisterRack` | `racks`, one name per datacenter | `infrastructure.rack.registered` | 409 `rack_exists` | same; `control-center-sites.e2e.ts` | `RUNTIME_VERIFIED` |
| Overview | `/` (Control Center index) | `GET infrastructure/overview` · `infrastructure.view` | read model | six tables, counts only | — | — | same; browser test follows an attention link | `RUNTIME_VERIFIED` |

## Providers (`Providers`)

| Capability | UI | API | Action | Reaches | Audit | Failure state | Tests | State |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Catalogue | `/providers` (register form) | `GET providers/catalogue` · `infrastructure.view` | `ProviderCatalogue` (source) | — | — | `testable=false` and `available_here=false` are facts on each entry, not hidden | `TheCatalogueOnlyClaimsWhatExistsTest` names the adapter class per driver | `TESTED` |
| Register a provider | `/providers` | `POST providers` · `provider.manage` | `RegisterProvider` → `AssessProvider` | `provider_instances`; assessed on arrival | `providers.provider.registered` | 409 `provider_refused` (unknown driver, category mismatch, controlled driver in production, machine required or elsewhere); 409 `endpoint_refused` (SSRF policy) | `TheProviderRegistryRefusesToGoLiveOnHopeTest` (20); browser test | `RUNTIME_VERIFIED` |
| Attach credential / licence | `/providers` | `POST|DELETE providers/{id}/credential|licence` · `credential.manage` / `licence.manage` | `AttachCredential`, `AttachLicence` | Revoked / invalid / cross-environment refused at attachment; provider reassessed | `providers.credential.*`, `providers.licence.*` | 409 | credential and licence test files | `RUNTIME_VERIFIED` |
| Test and discover | `/providers` | `POST providers/{id}/connection-test` · `provider.manage` | `TestConnection::forProvider` | `ConnectionTester` for the driver; `provider_capabilities` written | `providers.connection.tested` | 422 `unknown_driver` when no tester exists (every real driver); 409 `endpoint_refused`; result states as for machines; a secret is never resolved for another environment | `TheProviderRegistryRefusesToGoLiveOnHopeTest`; browser test | `RUNTIME_VERIFIED` (fake) |
| Readiness | `/providers`, `/discovery` | on every provider payload; `POST providers/{id}/assess` · `provider.manage` | `AssessProvider` / `ProviderReadiness` | one blocker in dependency order, `next_action` as a translation key | — (state is on the row; changes raise `ProviderReadinessChanged`) | `not_ready` with the blocker named | `ReadinessAnswersTheQuestionAnOperatorAskedTest` (15) | `TESTED` + `RUNTIME_VERIFIED` over HTTP |
| Enable / disable | `/providers` | `POST providers/{id}/enable|disable` · `provider.manage` | `EnableProvider` (reassesses under a lock), `DisableProvider` (reason) | `state`, one enabled per category per environment | `providers.provider.enabled/disabled` | 409 `provider_refused` unless `ready_for_production`; 409 when another is enabled; nothing is ever auto-disabled | registry test; browser test enables and disables the fake BMC | `RUNTIME_VERIFIED` |

## Credentials and licences (`Providers`)

| Capability | UI | API | Action | Reaches | Audit | Failure state | Tests | State |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Record a credential reference | `/credentials` | `POST credentials` · `credential.manage` | `RecordCredentialReference` | `credential_references` (name of a variable, never a value); presence asked of `SecretResolver::exists()` | `providers.credential.recorded` | 422 with "rotate it now" for a value-shaped reference or any unknown field; state `missing` when the controller has nothing behind it | `ACredentialIsAReferenceAndNeverAValueTest` (21); `control-center-credentials.e2e.ts` (5) | `RUNTIME_VERIFIED` |
| Revoke / mark rotated | `/credentials` | `POST credentials/{id}/revoke|rotated` · `credential.manage` | `RevokeCredential`, `MarkCredentialRotated` | dependents reassessed; nothing switched off | `providers.credential.revoked/rotated` | `revoked` is final; rotated drops to `configured`/`missing` | same | `RUNTIME_VERIFIED` |
| Record / renew / invalidate a licence | `/licences` | `POST licences`, `…/renew`, `…/invalidate` · `licence.manage` | `RecordLicence`, `RenewLicence`, `InvalidateLicence` | `licences`; state from the calendar | `providers.licence.recorded/renewed/invalidated` | a key posted under any name is refused and pointed at the credential centre; an expired renewal is refused; there is no control that makes an expired licence active | `ALicenceFollowsTheCalendarAndNotTheOperatorTest` (16); `control-center-licences.e2e.ts` (5) | `RUNTIME_VERIFIED` |
| Nightly licence sweep | `/licences` (Refresh) | `POST licences/refresh` · `licence.manage`; `licences:refresh` at 00:10 | `RefreshLicenceStates` | each transition audited, providers reassessed | `providers.licence.state_changed` | — | same, with time travel | `RUNTIME_VERIFIED` |

## Products (`ProductReadiness`)

| Capability | UI | API | Action | Reaches | Audit | Failure state | Tests | State |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Assess a product / all | `/readiness` | `GET|POST readiness/products[/{product}][/assess]` · `infrastructure.view` / `provider.manage` | `AssessProduct`, `AssessAllProducts`; `ProductReadinessEvaluator` (pure) | `product_readiness`; re-run in the same transaction whenever a provider's readiness moves | `product_readiness.changed` (on change only) | the blocker to the next rung, propagated from the nearest provider | `AProductIsOnlyAsReadyAsItsWeakestRequirementTest` (17), `NothingIsSellableOnHopeTest` (10); `control-center-readiness.e2e.ts` (3) | `RUNTIME_VERIFIED` |
| Declare / withdraw sellable | `/readiness` | `POST|DELETE readiness/products/{product}/sellable` · `readiness.declare` | `DeclareProductSellable`, `WithdrawProductSellability` | the one rung the engine cannot reach | `product_readiness.declared_sellable`, `…sellability_withdrawn` | 409 `readiness_refused` below `ready_for_production` (a controlled provider never reaches it); withdrawn automatically, audited, when the evidence goes | same | `REFUSES` (on this build, always) |
| Dependency view | `/readiness` | `GET readiness/dependencies` · `infrastructure.view` | read model | — | — | — | same | `RUNTIME_VERIFIED` |

## The execution chain (`Infrastructure`)

| Capability | UI | API | Action | Reaches | Audit | Failure state | Tests | State |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Software profiles | `/plans` | `GET infrastructure/profiles` · `infrastructure.view` | `SoftwareCatalogue` (source) | — | — | — | `EveryComponentNamesARoleThatExistsTest` (every role and playbook exists in `infrastructure/ansible`) | `TESTED` |
| Assign / clear desired state | `/plans` | `PUT|DELETE servers/{id}/desired-state` · `infrastructure.manage` | `AssignDesiredState`, `ClearDesiredState`; `OverrideRules` | `desired_states`; standing approvals revoked on clear | `infrastructure.desired_state.assigned/cleared` | 409 `override_refused` for an undeclared key or a value with shell or template syntax; 409 `profile_unknown` | `APlanIsApprovedByFingerprintAndRunOnceTest`; `TheControlCenterRefusesTheDangerousInputsTest` | `RUNTIME_VERIFIED` |
| Compute a plan | `/plans` | `POST|GET servers/{id}/plan` · `infrastructure.manage` / `.view` | `PlanDeployment`; `PlanEngine` (pure) | `deployment_plans`; same fingerprint keeps the row and its approval, a different one revokes every standing approval | `infrastructure.plan.computed`, `…approval_revoked` (automatic) | blockers `safety_class`, `reimage_clearance`, `licence`, `dependency_order` on the plan; `is_applicable=false` | same; component + browser tests | `RUNTIME_VERIFIED` |
| Approve / revoke | `/plans` | `POST plans/{id}/approve`, `DELETE plans/{id}/approval` · `deployment.approve` | `ApproveDeploymentPlan`, `RevokeDeploymentApproval` | `deployment_approvals` with the fingerprint as it stood | `infrastructure.plan.approved/approval_revoked` | 409 `four_eyes` (the planner), `plan_superseded`, `plan_not_applicable`; 422 `confirmation_required` without the typed name on a destructive plan; 403 for infrastructure-admin | same; browser test needs two accounts | `RUNTIME_VERIFIED` |
| Request a run | `/plans` | `POST servers/{id}/deployments` · `deployment.run` | `RequestDeployment` | `deployment_jobs` (one in flight per machine), `RunDeploymentJob` dispatched | `infrastructure.deployment.requested` | 409 `not_approved`, `in_flight`, `unresolved` (a run waiting for a person blocks the machine), `safety_refused` | same | `RUNTIME_VERIFIED` |
| Run: apply → verify | `/deployments` | worker | `RunDeploymentJob` (one try; re-checks plan, approval and gate under a lock) | `DeploymentController::apply/verify`; facts written as `derived`; machine → `managed` | `infrastructure.deployment.finished` | `failed` with a class; `indeterminate` on a deadline (never retried); `needs_review` when verification fails; `fingerprint_mismatch` ends the job with nothing started | same (timeout, replay, verify-failure, cancel, stale); browser test watches a run complete | `RUNTIME_VERIFIED` (fake controller) |
| Deployment controller: fake | — | — | `FakeDeploymentController` | markers on the machine's management address | — | refuses to exist in production | `TheBridgeRefusesToRunWhereItMustNotTest` | `RUNTIME_VERIFIED` |
| Deployment controller: Ansible | — | — | `AnsibleDeploymentController` | `ansible-playbook -i inventories/<env> playbooks/<profile> --limit <machine> --extra-vars @file`, `--check --diff` to verify | — | refuses where `CI` is set, without `INFRASTRUCTURE_IAC_PATH`, for a playbook not in the tree, for a host that is not a hostname; exit 4 → transient, other → permanent, deadline → indeterminate | same (9, process replaced) | `TESTED` — **never run against a machine** |
| Resolve / cancel | `/deployments` | `POST deployments/{id}/resolve|cancel` · `deployment.run` | `ResolveDeployment`, `CancelDeployment` | a person's statement; only an unstarted run cancels | `infrastructure.deployment.resolved/cancelled` | 409 `not_waiting`, `not_cancellable` | same; component test | `RUNTIME_VERIFIED` |
| Stale sweep | — | `deployments:detect-stale` every 5 min, one server | `DetectStaleDeployments` | applying/verifying past the deadline → `indeterminate` | `infrastructure.deployment.finished` (stale) | never restarted | same | `RUNTIME_VERIFIED` |
| Infrastructure drift | `/admin/drift` (existing queue) | `GET drift` · `drift.view`; `infrastructure:detect-drift` nightly | `DetectInfrastructureDrift` → Provisioning `RecordDrift` | `resource_drifts` (`infrastructure`/`managed_server`/`spec_mismatch`), `lynomia_resource_drift_open` | — (drift rows are their own record) | one open row per machine, occurrences counted | `AManagedMachineThatStopsMatchingItsProfileIsDriftTest` | `RUNTIME_VERIFIED` |

## Observability

| Series / alert | Source | Runbook | Test |
| --- | --- | --- | --- |
| `lynomia_managed_servers`, `lynomia_provider_readiness`, `lynomia_provider_state`, `lynomia_providers_enabled_not_ready`, `lynomia_credentials`, `lynomia_licences`, `lynomia_product_readiness`, `lynomia_deployments` | `ControlCenterCollector`, one query, every combination at zero | — | `TheControlCenterIsObservableTest` |
| `DeploymentWaitingForAPerson` | `control-center.yml` | `docs/runbooks/deployment-indeterminate.md` | every series the rules name is one the collector emits (tested); `validate-monitoring.py` |
| `EnabledProviderNotReady` | same | `docs/runbooks/provider-not-ready.md` | same |
| `CredentialMissing` | same | `docs/runbooks/credential-missing.md` | same |
| `LicenceExpiring`, `LicenceExpired` | same | `docs/runbooks/licence-expiry.md` | same |
| Control Center dashboard | `lynomia-control-center` (8 panels), generated by `build-dashboards.py` | — | — |

## What no row can say

- No `REAL_INFRA_VERIFIED`. No real provider has answered a connection test,
  no real machine has been discovered, deployed to or verified, and no
  Prometheus has scraped a real control plane.
- No real driver is connection-testable; each is catalogued with
  `testable=false` and its readiness names that as a configuration blocker.
- The Ansible bridge has never run a playbook. What is proven is what it
  refuses and the exact argument list it would run.
