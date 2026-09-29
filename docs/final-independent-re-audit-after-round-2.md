# Final independent re-audit after round two

**Candidate:** `462382f5659e163886dc76a764a6cd131e017f3d` on
`claude/session-014hzcec3ombgztszvbjlwcv-vbzsx9` — round two's 36 findings
rebuilt from the ledger after the original branches were lost, each
independently verified, and merged. **Brief:** `docs/round-2-briefs/final-re-audit.md`.
**Specification:** `docs/final-independent-multi-agent-audit-round-1.md`.

**Suite at the candidate:** backend 5,170 / 5,170 passed (167,817 assertions;
JSON `tests == passed`, junit 624 testsuites all `skipped="0"`, exit 0);
frontend vitest 627 / 627, `tsc` and `eslint --max-warnings=0` clean; every
infrastructure validator and self-test green (the Ansible safety-gate self-test
needs `ansible-core` on `PATH`: 10/10 with it, 5/10 without, identically on
`main`). PHPStan was not run: `tools/phpstan/vendor` is absent.

## How it was run

Five band re-auditors (A–E, as the brief assigns them) and one auditor for the
cross-cutting *"what round two introduced"* assignment, each in its own
worktree detached at the candidate, with its own Postgres database and Redis
index, measuring before reading the ledger. Every finding reported as
reproducing, fully or partially, was handed to a separate **skeptic** told to
refute it; **all eleven were confirmed** (one, F-26, narrowed). The
five-product matrix was then derived from the bands' results by a seventh
agent, not copied from the audit. Every probe and mutation was restored
against the bytes taken before; every worktree ended clean.

## SOFTWARE_CODE_COMPLETE — adjudication

**`SOFTWARE_CODE_COMPLETE = NO`.**

The evidence, not rounded up:

- **36 of 47 findings do not reproduce** at the candidate, each with the
  mechanism that prevents it and, for nearly all, an oracle that went red under
  mutation.
- **11 findings still reproduce**, each confirmed by an independent skeptic:
  **F-42 reproduces**; **F-01, F-02, F-05, F-07, F-09, F-16, F-20, F-23, F-26
  and F-38 partially reproduce.** Four of them are money or data defects a
  customer can reach today — F-01 (plan changes mint spendable wallet credit
  and deliver the dearer plan for the cheaper invoice), F-05 (an order
  cancelled between settlement and fulfilment keeps the money and delivers
  nothing), F-07 (plans with no node, an offline cluster or an inactive pool
  take money and renew) and F-09 (a running restore of an archive older than
  12 hours is quarantined on its first poll, and a second restore over the
  same disks is accepted).
- **Six of the eleven were closed before round two began** (F-01, F-02, F-05,
  F-07, F-09, F-16) and were carried as `CLOSED` on the ledger's word. The
  re-audit is the first time they were measured again, and they did not hold.
- **Round two introduced defects of its own** — 28 recorded below, including
  a new money defect in F-01's repair, a service that keeps renewing after an
  operator ends it (F-18 × F-19), gates that cannot go red for the failure
  their names promise, and tests that assert current rather than intended
  behaviour.
- **All five products remain `No`** in the re-derived matrix.

The frozen statuses do not move and nothing here moves them:

```
30B.0-E                 = NOT READY
REAL_INFRA_VERIFIED     = NONE
REAL_PAYMENT_VERIFIED   = NONE
REAL_REGISTRAR_VERIFIED = NONE
REAL_HOSTING_VERIFIED   = NONE
READY_TO_SELL           = NONE
```

WordPress remains `Prepared`; F-45 not reproducing changes nothing about it.

## Verdict per finding

The four verdicts are the brief's. *Question 4* asks whether the defect is
prevented by something that would notice it coming back (an oracle, named) or
only by the current state of the code. The full reasoning, reproductions and
mutations for every row are in the appendix.

| Finding | Band | Verdict | Question 4 — what holds it |
|---|---|---|---|
| F-01 | A | **PARTIALLY REPRODUCES** | The no-invoice regression is guarded by an oracle: APlanChangeSettlesItsMoneyTest and PlanChangeEndpointTest go red (9 tests). The flip-flop credit mint, the cheaper-invoice resize and the non-atomic commit have no oracle. Each probe passed silently against th… |
| F-02 | B | **PARTIALLY REPRODUCES** | The closed half is guarded by an oracle: AnOperatorCanBuildTheEstateFromNothingTest and AFreshDeploymentBecomesConfigurableTest build every row over HTTP with no factory. The reproducing half has no oracle, and the flagship test cannot fail for it. AFreshDeplo… |
| F-03 | B | DOES NOT REPRODUCE | There are oracles: AFreshDeploymentCanEstablishItsFirstOperatorTest, AFreshDeploymentBecomesConfigurableTest (bootstrap, then second operator over HTTP), and OperatorAndRoleManagementTest::the_permissions_no_role_holds_are_grantable_rather_than_dead, which der… |
| F-04 | C | DOES NOT REPRODUCE | An oracle, not just the current code: RecordingHostingProvider (a decorator recording what the panel was HANDED) in AHostingOrderReachesThePanelWithWhatItNeedsTest / NamingTheDomainAHostingBuildWillServeTest / TheHostingGoldenPathTest, driven through POST /api… |
| F-05 | A | **PARTIALLY REPRODUCES** | The void path is guarded by an oracle: ACancelledOrderCollectsNoMoneyTest goes red under mutation. The settle-to-fulfil window has none. ACancelledOrderCollectsNoMoneyTest::an_order_that_has_been_paid_cannot_be_cancelled settles under QUEUE_CONNECTION=sync, so… |
| F-06 | A | DOES NOT REPRODUCE | Oracle: FiniteCapacityIsClaimedNotGuessedTest. It uses real multi-process races with a positive control on the refusal codes, and a lock_timeout test (55P03) that proves the claim itself blocks. It went red under mutation. Unguarded interaction: capacity is cl… |
| F-07 | A | **PARTIALLY REPRODUCES** | The package case is guarded by an oracle: MoneyDoesNotMoveForSomethingUndeliverableTest and ABlockedServiceIsSeenAndBilledToNobodyTest go red. No oracle covers an inactive declared cluster or pool, a missing hosting node, or renewal of a FAILED, never-delivere… |
| F-08 | A | DOES NOT REPRODUCE | Two oracles. (1) CI: tests/Feature/Queue/EveryQueueOutlivesItsLongestJobTest (supervisor timeout below retry_after, env overlays, a zero timeout refused) and EveryRetriedPaymentsListenerWaitsBetweenAttemptsTest (a job's own $timeout past the clock is refused o… |
| F-09 | C | **PARTIALLY REPRODUCES** | The wrong-task-id half is held by an oracle: TheBackupRestoreTruthBoundaryTest (two-task TwoTaskDatastore double; mutations m2/m3 go red). The half that reproduces has no oracle: every restoring/verifying fixture uses BackupFactory::succeeded() (started_at = n… |
| F-10 | C | DOES NOT REPRODUCE | Oracle on both sides: tests/Feature/Backups/TheBackupRestoreTruthBoundaryTest (API + action) and apps/web/src/features/backups/__tests__/what-a-backup-row-says.test.tsx. The web test feeds is_restorable from its own fixture, so the web<->API link is held by th… |
| F-11 | C | DOES NOT REPRODUCE | Oracle: DnsProviderContractTestCase, one abstract suite run against the Cloudflare adapter over tests/Support/CloudflareZoneSimulator (an HTTP-level model) and against FakeDnsProvider; the simulator no longer shares the adapter's fault, since the same mutation… |
| F-12 | C | DOES NOT REPRODUCE | Oracle: tests/Feature/Dedicated/DecommissioningGivesTheAddressBackTest (plus HeldQuarantineClockTest / HeldQuarantineReportTest for the held-clock half). Residual by design: an address stays quarantined without a clock until an operator returns or retires the … |
| F-13 | C | DOES NOT REPRODUCE | Oracle: ARealProxmoxClusterCanCarryVpsTest, which derives the required set from ProductRequirements and checks it against the map (so a newly required capability absent from the map is red), and parses infrastructure/ansible/group_vars/proxmox.yml for the role… |
| F-14 | C | DOES NOT REPRODUCE | Oracle: AnUnreadableDirectAdminNodeIsNotSoldTest (end to end: the node is recorded unlicensed and not scheduled) and DirectAdminLicenceAnswerTest. The read-validation half is held by one unit test; one mutation, one red test. |
| F-15 | D | DOES NOT REPRODUCE | Oracle, not edit: AnIndeterminateCreateIsNotRetriedIntoASecondMachineTest (29 red under mutation A), RepointingAReservedIdentityTest, TheReviewListShowsTheLastAttemptsFindingTest, TheSimulatorsOwnLostAnswerIsNotRetriedIntoASecondMachineTest. Limit: every oracl… |
| F-16 | B | **PARTIALLY REPRODUCES** | Proxy oracle. AMiscapitalisedEnvironmentCannotDisarmTheProductionGuardsTest evaluates config/app.php's expression in isolation. Mutation: reverting line 47 to `env('APP_ENV','production')` turns 7 of 27 red, so it guards that edit. It never asks `app()->enviro… |
| F-17 | B | DOES NOT REPRODUCE | There is an oracle. Mutation: restoring the raw-header key (`'account:'.$request->header('X-Lynomia-Customer')`) turns 5 of 22 red across TheInvitationLimiterCannotBeRotatedByAHeaderTest and OneAddressWaitsOutTheInvitationCooldownTest (an_account_that_has_spen… |
| F-18 | C | DOES NOT REPRODUCE | Oracle: tests/Feature/Admin/TerminatingAHostingAccountTest and EndingAHostingAccountThroughEitherDoorTest pin the controller gate, the action guard and the retention predicate separately, so each layer goes red alone. |
| F-19 | B | DOES NOT REPRODUCE | Partly guarded by an oracle. Mutation 1: short-circuiting MoveTheOrderWithWhatItBought::handle turns 6 of 47 red (AnOrderFollowsWhatItBoughtTest, AServiceEndsBecauseNothingWasBuiltForItTest). Mutation 2: removing the completed_at stamp turns 2 of 151 red. Gap:… |
| F-20 | D | **PARTIALLY REPRODUCES** | Rendering half: oracle (whether-the-disk-is-already-gone.test.tsx, rendered pages in both locales, plus Tailwind-compiled visibility check). The false-label half is held in place by that same oracle - it asserts current behaviour and would go red on the correc… |
| F-21 | D | DOES NOT REPRODUCE | Oracle: registration-that-could-not-ask.test.tsx (rendered page) and the structural gate src/lib/__tests__/a-control-waits-for-its-answer.test.ts, which reads all portal sources with the TS compiler and refuses any control whose gate reaches `.data` live befor… |
| F-22 | B | DOES NOT REPRODUCE | There are oracles on each closed leg. Changing the ResourceDriftOpen expr to `lynomia_open_drift_total > 0` makes validate-monitoring.py exit 1 with 3 FAIL lines. Re-adding a `ruler:` with alertmanager_url and no rule mount makes it exit 1 with 'wires the rule… |
| F-23 | E | **PARTIALLY REPRODUCES** | Machine transition targets: EveryStateAMachineCanEnterHasAProducerTest, an oracle I made go red. Enum cases in general and machine-enum cases that are not transition targets: no oracle. The translation gate still enforces strings for states that cannot occur. |
| F-24 | D | DOES NOT REPRODUCE | Oracle for all three limbs: simulator-behaviour tests named above. DNS is the only limb with a shared contract suite run against both simulator and real adapter (DnsProviderContractTestCase -> TheDnsSimulatorKeepsTheRecordContractTest and CloudflareAdapterKeep… |
| F-25 | D | DOES NOT REPRODUCE | Oracle: ConfiguringACatalogueDoesNotMakeAnythingSellableTest (a Feature test, not one of tests/Architecture) catches a new kind; PreparedProductsCannotBeSoldOnHopeTest catches removal of the softwareState gate. The architecture test adds only the join. Note th… |
| F-26 | C | **PARTIALLY REPRODUCES** | The children half is held by an oracle: TheReservedZonesTest plus ClaimingAZoneTest. The empty-default half is watched only by ReservedZonesCheck, which warns when nothing is reserved and passes when only the exact platform hosts are derived. It says 'Each cov… |
| F-27 | A | DOES NOT REPRODUCE | Oracle: ErrorDetailsAreOnlyWhatTheCallerAlreadyKnowsTest. It covers the engine, both real routes, a reviewed INVENTORY of every publishing() declaration in the tree, and a scanner over every composer autoload root. It went red under mutation. CustomerErrorCata… |
| F-28 | D | DOES NOT REPRODUCE | Oracle: TheConsoleSocketIsVerifiedOnItsClustersTermsTest (end-to-end through resolver, factory, adapter, gateway socket) and two unit tests. |
| F-29 | D | DOES NOT REPRODUCE | Oracle: EverySpellingOfARefusedAddressIsStillThatAddressTest, EveryRoadToADialledAddressAsksThePolicyTest. Disclosed and unguarded by design: WhmConnection::forNode, DirectAdminConnection::forNode, ComputeProviderFactory::proxmox, BackupProviderFactory and Con… |
| F-30 | D | DOES NOT REPRODUCE | Oracle: TheQueueDashboardIsGatedByCapabilityTest (route set read from the router, so a new Horizon route is covered). It authenticates with actingAs; whether an operator's real session reaches Horizon's `web` group was not exercised. |
| F-31 | B | DOES NOT REPRODUCE | There is an oracle. With that mutation, 12 of 39 go red across TheBalancerIsTrustedFromTheEnvironmentFileTest, CallersBehindOneBalancerKeepTheirOwnLimitsTest, NoConfigurationTrustsEveryCallerTest, APhpBuiltWithoutIpv6StillServesItsBalancerTest and TheTrustedPr… |
| F-32 | E | DOES NOT REPRODUCE | TheHostingPackageBehindAPlanIsChosenNotStumbledOnTest (behaviour) and OnlyOneResolverChoosesAHostingPackageForAPlanTest (a token-level gate against a second resolver; its docblock says it cannot see relations, raw SQL or variable-carried builders). The index i… |
| F-33 | E | DOES NOT REPRODUCE | OverlappingBlocksAreRefusedTest, OneRealAddressReachesOneCustomerTest, RegisteringOverlappingBlocksIsSerialisedTest (behaviour), plus TheSubnetRegistrationLockHasOneOwnerTest for the lock site. |
| F-34 | E | DOES NOT REPRODUCE | AnOperatorCanClearATimeoutQuarantineTest (behaviour). The three still-unwritten ReleaseReason cases, including the Abuse policy branch, are watched by no oracle. That is F-23's open half. |
| F-35 | D | DOES NOT REPRODUCE | Oracle: APreflightNeverDropsACheckSilentlyTest::a_band_that_does_not_block_holds_every_check_its_chain_can_emit (a general rule, not a list). Nothing asserts that a pass of mapping.network implies an allocatable address; by design it asks only 'an active pool … |
| F-36 | E | DOES NOT REPRODUCE | ReconcilingDomainsInProductionTest (runs the command with the app in production). It also pins that the fake still refuses construction in production and that a failed scheduled run is logged. |
| F-37 | E | DOES NOT REPRODUCE | AnAbandonedPowerClaimIsAnsweredTest (lease), TheApplicationActuallyRegistersItsCollectorsTest (booted-app series), EveryAlertMetricHasAProducerTest / validate-monitoring.py (rules and metrics). |
| F-38 | E | **PARTIALLY REPRODUCES** | The fixed gates are held by their self-test scripts (test_validate_inventory.py, test_check_ci_cannot_apply.py and others), which CI runs and which require a red for each case. The tofu-validate limb has no oracle. It is held by a comment that admits the gap. |
| F-39 | E | DOES NOT REPRODUCE | infrastructure/scripts/validate-runbook-alerts.py (CI step 'Runbooks name alerts that exist') and its self-test test_validate_runbook_alerts.py (62/62). |
| F-40 | E | DOES NOT REPRODUCE | LayeringTest reads the AGENTS.md sentences and checks them against its own rules. TheAgentInstructionsDescribeThisRepositoryTest keeps the two files identical. |
| F-41 | E | DOES NOT REPRODUCE | ThePhpunitPinsHoldAgainstAnExportedVariableTest (classification, force, <server> mirror, and a child-process measurement through PHPUnit's handler) and TheTestSuiteRefusesToDropAnythingButATestDatabaseTest (9 methods, including each condition refusing on its o… |
| F-42 | D | **REPRODUCES** | None. No configuration holds a timeout budget, and nothing notices load sensitivity; the required CI step (`npm run test --workspace=apps/web -- --run`) runs on defaults. The ledger records F-42 as PARTIAL, consistent with this. |
| F-43 | D | DOES NOT REPRODUCE | Oracle: ConsolePermitDeadlineTest (driver-independent by construction: TTL of an hour, success controls one second inside the deadline, mocked repository to prove nothing is written on refusal) and ConsolePermitConcurrencyTest's Redis case. |
| F-44 | D | DOES NOT REPRODUCE | Closed by an edit only. No test or architecture gate detects an expected date computed from now() against an application-written date without a frozen clock - proven by the new instance round two introduced in DecommissioningGivesTheAddressBackTest (F-12's fil… |
| F-45 | E | DOES NOT REPRODUCE | TheInstallerIsHandedARealAdministratorPasswordTest plus the simulator's refusal of the placeholder or an empty credential (F-24 limb 3). Both go red on the audit's defect. |
| F-46 | E | DOES NOT REPRODUCE | EveryNotificationTypeHasAProducerTest (declared set from reflection, producers by token, copy in both locales) and AnAccountHolderHearsAboutTheirOwnAccountTest (behaviour). Stated blind spot: a producer site that is never reached. |
| F-47 | E | DOES NOT REPRODUCE | Behavioural tests: DecommissioningGivesTheAddressBackTest and DedicatedInventorySweepTest. The architecture gate does NOT notice the writer's removal while the guard call that names the state remains (mutation A). It notices only when every mention goes. |

## The five-product matrix, re-derived

Derived from the band reports, the skeptic confirmations and the round-two
sweep, not from the audit's lists. A finding counts against a product only when
a band measured it on that product's path, or its mechanism plainly sits there.

| Product | Audit said | Now | What blocks it |
|---|---|---|---|
| **VPS** | No: F-01, F-02, F-13, F-15, F-20 | **No** | **F-01** (plan-flap mints wallet credit; paying the cheapest proration invoice delivers the dearest plan; the plan move is not atomic). **F-02** (no production writer of `ip_addresses`: every VPS build on an operator-built estate is refused `ipam.pool_exhausted`). **F-20** ("The rebuild did not run" still shown for a rebuild that erased the disk, and pinned by a test). **F-07** (checkout accepts a VPS plan whose constraints name an offline cluster and an inactive pool). Cross-cutting **F-05**. F-13 and F-15 do not reproduce. |
| **Dedicated** | No: F-02, F-03, F-12 | **No** | **F-02** (no allocatable addresses; `OsInstallProfile` has no production writer yet `ProvisionDedicatedHandler` does `findOrFail` on it; a failed build strands the chassis in `provisioning`). Cross-cutting **F-05**. F-03 and F-12 do not reproduce. |
| **Shared Hosting** | No: F-02, F-04, F-07, F-14, F-18, F-32 | **No** | **F-07** (a plan with a package and zero hosting nodes is accepted, paid, built into a failed service and renewed). **I-1**, new in round two (F-18 × F-19: an operator-ended hosting service keeps renewing). Cross-cutting **F-05**. F-04, F-14, F-18 and F-32 do not reproduce. F-02's reach here could not be established. |
| **DNS** | No: F-11, F-26 | **No** | **F-26** (`DNS_RESERVED_ZONES` still defaults to empty; `www.` and `mail.` under the platform's domain are claimable while preflight reports `pass`). Also round two's `ReservedZonesCheck` wording, and **I-3** (a misconfigured reserved list is answered to the customer as their own `dns.invalid_name`). F-11 does not reproduce — against the programme's own Cloudflare simulator. |
| **Backups** | No: F-09, F-10 | **No** | **F-09** (`giveUpIfOverdue` measures from the archive's `started_at`, not `restore_started_at`: a running restore of any archive older than 12 h is quarantined on its first poll, a second restore over the same disks is accepted, and the archive can never be restored again). The same mechanism strands archives in the scheduled `backups:verify` sweep (unnumbered). `Backups` depends on `Vps`. F-10 does not reproduce. |

Cross-cutting, kept out of the columns: **F-05** sits on the shared order path
of VPS, Dedicated and Shared Hosting; **F-16** (`--env=Production` on an artisan
process disarms every production guard, including `ProductSellability::enforced()`)
applies to every product for artisan and worker processes; **F-42** (the required
vitest gate red on 4 of 4 parallel runs) is a defect in the evidence estate. None
changes a cell, because no cell would otherwise be Yes.

**What the code declares, and why it does not move the matrix.**
`Product::softwareState()` returns `Complete` for all five products; neither it
nor `ProductSellability` changed in round two. `Complete` is a source
declaration that predates round two and is checked only by a test asserting a
complete product has an order action and a real adapter class. It does not mean
`SOFTWARE_CODE_COMPLETE`, and it is not evidence any defect above is closed. The
tree misleads here the same way `AFreshDeploymentBecomesConfigurableTest` and
`mapping.network` do: each is green while VPS and Dedicated cannot be built on
an estate an operator creates.


## What round two introduced

Recorded by the bands inside their own findings and by the cross-cutting sweep (band X). Each was measured by a mutation or a probe; the appendix carries the evidence. None is numbered: the namespace is F-01..F-47.

- **[A]** F-01 repair: the downgrade wallet credit (ApplyPlanChange::creditTheCustomer, round-two code) is priced against a plan move whose upgrade invoice is still unpaid. Plan flapping mints spendable wallet credit (162.000 KWD after 3 loops, 0 paid in), and PayInvoiceFromWallet spends it. The route's own comment (routes/v1/billing.php:113-115) names this exact flapping as the reason for its throttle (10/min), which does not stop it.
- **[A]** F-01 repair: ResizeOnPlanChangeSettlement queues a resize to the subscription's CURRENT plan when ANY proration invoice for it is paid. Paying a 0.667 KWD invoice delivered the 90.000 KWD plan's resources while the 53.333 KWD invoice stayed open.
- **[A]** F-01 repair is not atomic. ChangeSubscriptionPlan commits the plan move in its own transaction, and the invoice or credit is written after it. A failed invoice write leaves the plan moved and no invoice (probe: 500, recurring=90000, invoices=0), and a retry is refused as SamePlan. That is the audit's original F-01 state.
- **[A]** False docblock: routes/v1/billing.php:38-42 still says 'No renewal or plan-change route ... Neither is published here', 75 lines above POST {subscription}/plan. The audit named this file's self-contradiction; the header half was left.
- **[A]** Interaction F-01 x F-06: a plan change onto a plan with stock_limit=0 and per_customer_limit=1 answers 200 and moves the subscription. PlanCapacity counts only order_items, and QuotePlanChange has no capacity refusal. (Probe: `STOCK0 plan change status=200 sub_on_large=true`.)
- **[A]** Test asserting sync-queue behaviour rather than production behaviour: ACancelledOrderCollectsNoMoneyTest::an_order_that_has_been_paid_cannot_be_cancelled settles with fulfilment running inline, so it cannot see the settle-to-fulfil window through which F-05's terminal state still reproduces.
- **[A]** Docblock overclaim: LocalPlacementFeasibility / OrderPricing::assertDeliverable say they refuse 'what this platform already knows it cannot place ... a cluster, an IP pool'. A declared cluster_id or ip_pool_id is returned without checking that it is active or exists, and hosting feasibility ignores whether any hosting node exists.
- **[B]** F-19 × F-23: the producer gate (EveryStateAMachineCanEnterHasAProducerTest) cannot go red for OrderStatus::ProvisioningFailed losing its only writer. It counts a membership and priority array literal in KeepTheOrderInStepWithItsServices:147 as production. A mutation removing both real writers left 345/345 green. This is an oracle that cannot be made red by the failure its name promises, for this state.
- **[B]** F-02: AFreshDeploymentBecomesConfigurableTest ('brought to the point where it could sell') has a subject narrower than its name. It stops at LocalPlacementFeasibility, which counts no IP address rows and gates no Dedicated plan. It is green while every VPS and Dedicated build on the estate it builds is refused ipam.pool_exhausted. Dedicated also needs an OsInstallProfile that nothing can write.
- **[B]** F-16: the docblock on AMiscapitalisedEnvironmentCannotDisarmTheProductionGuardsTest, and config/app.php:32-38, claim the guards compare config('app.env'). They compare $app['env'], which `--env=` overrides (measured). This is a docblock claiming a property the code does not have. Provenance: F-16 was closed before the branch-per-finding regime, and the shallow graft at 31a1501 hides whether this text is round two's.
- **[B]** F-03 (codified behaviour): OperatorAndRoleManagementTest::the_last_privileged_operator_cannot_be_stripped asserts as a 'positive control' that a NOC operator holding role.manage can remove Super Admin from a super-admin (200). That test pins current behaviour, a lesser operator demoting the top authority, as intended. See the observations. Provenance cannot be established through the shallow graft.
- **[C]** ReservedZonesCheck (new in round two, F-26) returns PASS with the text 'N name(s) reserved ... Each covers itself, every parent of it and everything beneath it' when DNS_RESERVED_ZONES is empty and only the exact APP_URL/FRONTEND_URL hosts are derived. Measured: www.lynomia.example and mail.lynomia.example are claimable with preflight=pass. The gate's subject is narrower than its name, since it never asks whether the registrable domain is reserved, although config/dns.php's own comment says to list it 'above all'.
- **[C]** F-13 raised VPS's Compute requirement to include `inventory_sync`, so VPS readiness now needs Sys.Audit+Datastore.Audit for a capability it did not need before. It is consistent and pinned (only_vps_requires_inventory_sync_directly_and_gpu_compute_inherits_it), and it is another privilege that real Proxmox semantics must confirm. Recorded as an interaction, not a defect.
- **[C]** F-14's read-validation control (identifying field required when a read carries no error field) is pinned by exactly one unit test (mutation m11 turns 1 of 786 red). It can go red, but it is thin.
- **[C]** Not introduced by the rebuild (Backups src/ is byte-identical 864ff94..462382f apart from one exception file), but it lives in round-two-accepted code: the F-09 fix, by polling the real restore task, made ReconcileBackup::giveUpIfOverdue reachable for restores, and that function measures from the archive's started_at. Result: a running restore of any backup older than 12h is quarantined on its first poll with a false reason, and a second restore is accepted (see F-09). The restore and verification test fixtures all use an archive taken minutes before the operation, so no test can go red for this.
- **[D]** F-44's shape re-introduced by round two (F-12, commits e0aa0ab/db4bcc6): tests/Feature/Dedicated/DecommissioningGivesTheAddressBackTest.php::returning_the_machine_to_stock_starts_the_clock (line 211) and ::retiring_a_machine_starts_the_clock_and_records_whose_word_it_is (line 244) assert `now()->addDays(7)->toDateString()` against `quarantined_until` written inside the HTTP request, with no freezeTime anywhere in the file, its trait or TestCase. Reproduced by parking the clock at 2026-10-01 23:59:59.500 and stepping to 00:00:00.500 at the `update "ip_addresses" set "quarantined_until"` statement: both red, '-2026-10-09 +2026-10-08'. A real midnight-UTC crossing during either test fails it. Probe reverted; sha256 69aca039... matches.
- **[D]** F-21's structural gate interacts badly with F-42: src/lib/__tests__/a-control-waits-for-its-answer.test.ts 'finds the gates to check' parses the whole portal with the TypeScript compiler (1.2-1.8 s in isolation, 2.4 s once under a mutation run) inside vitest's default 5,000 ms budget; it timed out in all four of four parallel full runs. Round two added a new, reliably load-sensitive test to the required vitest gate.
- **[D]** A test asserting current rather than intended behaviour (F-20): whether-the-disk-is-already-gone.test.tsx 'says the disk is gone, next to the state that says the rebuild did not run' asserts `getByText(en.vps.reinstallState.failed)` for a rebuild with data_destroyed=true. Measured: making the state label truthful for (failed, destroyed) turns 2 of 20 red. The oracle pins the misleading sentence in place.
- **[D]** Oracle scope smaller than its name (F-35 x F-02): mapping.network passes '1 of 1 registered address pool(s) are active' for an operator-built estate in which IpAllocator::reserve throws IpPoolExhausted (0 address rows after registering a /24 through POST /api/admin/infrastructure/ip-pools/{pool}/subnets). The docblock discloses it; nothing but prose connects the green line to the fact that no VPS can be given an address. Likewise AnOperatorCanBuildTheEstateFromNothingTest::an_operator_creates_a_customer_allocatable_pool_and_its_subnet asserts isCustomerAllocatable() and never allocates.
- **[E]** EveryStateAMachineCanEnterHasAProducerTest cannot be made red by removing the writer of the state it names as its own worked example (F-47). A state named as an argument to assertCanTransition() counts as a producer, because an unrecognised callee is treated as a producer. With RetireDedicatedServer's `forceFill(['status' => Retired])` replaced by `save()`, the gate stayed 3/3 green. The docblock discloses the conservative default in general terms, but not that the most common shape in the tree, a guard call next to the write, hides every write. Today no state is guard-only: my reflection probe over the classifier found 62 destinations with a real producer and 2 with none. So this is latent, and behavioural tests caught the mutation.
- **[E]** check-ci-cannot-apply.py's docstring measurement is stale after F-39 added two CI steps (an F-38 and F-39 interaction). The docstring says the script 'prints 1 workflow file and 44 run steps' and that 11 script-file steps include 'nine of the Python validators and self-tests'. The script actually prints '1 workflow file(s), 46 run step(s) inspected', and ci.yml has 11 `run: python3 infrastructure/scripts/` steps. No test pins these counts. This is a docblock asserting a measured property the tree no longer has.
- **[E]** EveryStateAScreenShowsIsTranslatedTest still demands en/ar strings for InvoiceStatus::Uncollectible and ProvisioningJobStatus::Cancelled. The sibling gate documents both as unreachable in UNPRODUCED, so the two gates disagree about the same two states, and nothing reconciles them. That is the audit's F-23 headline, kept.
- **[X]** I-1 (F-18 x F-19, touching F-07's billing rule): a Shared Hosting service that an operator has ended keeps renewing, and nothing checks for it. Probe (a copy of EndingAHostingAccountThroughEitherDoorTest's fixture, a live account bought through checkout, operator holding both service.terminate and hosting_account.manage), verbatim: `PROBE before: account=active service=active order=active sub=active` / `PROBE hosting door: http=200 destroyed=yes` / `PROBE after: account=terminated service=active order=active sub=active` / `PROBE service door afterwards: http=202 code= service=terminated order=terminated sub=active` / `PROBE renewal after both doors: next_invoice_at=2026-10-26 12:54:41 considered=1 renewed=1 skipped=0 failed=0 invoices=["01m3ewgeash75zddsm2m4jm0r5"]`. Two defects. (a) F-18's door (DELETE /api/admin/hosting-accounts/{id}, force) deletes the site at the panel and leaves the service `active`. F-19 derives the order's status from the service, so the order also reads `active` while the site is gone. (b) F-19 now routes hosting through the service door, and forced, that door ends an ACTIVE hosting service. The service and the order both go to `terminated`, the subscription stays `active`, and RenewDueSubscriptions issues a renewal invoice for it. The only skip in RenewSubscription is F-07's (Pending plus placement_blocked_reason). Its docblock says 'Every other state is left alone, because each has a reason to keep billing'. It names Provisioning, Active, Suspended and Failed, and never Terminated. No oracle: EndingAHostingAccountThroughEitherDoorTest asserts only the status code, the error code and whether the account was destroyed. ABlockedServiceIsSeenAndBilledToNobodyTest covers only the blocked-Pending case. Before round two the service door refused a hosting service by accident, so this is newly reachable for Shared Hosting.
- **[X]** I-2 (F-41 x every suite that flushes Redis): phpunit.xml now gives REDIS_DB a default of 0 and applies it before dotenv, so a REDIS_DB set in .env.testing never reaches a run. REDIS_PORT is not in the phpunit block, so it still comes from .env.testing. Probe at 462382f with DB_DATABASE exported and .env.testing holding REDIS_PORT=6380 and REDIS_DB=12: `PROBE resolve(15)=0 config.redis.default.database=0 port=6380 env(REDIS_DB)='0'`. The same probe under the pre-round-two phpunit.xml (git show c36f188b:apps/control-plane/phpunit.xml, via vendor/bin/phpunit -c): `PROBE resolve(15)=12 config.redis.default.database=12 env(REDIS_DB)='12'`. So every checkout that isolated itself through .env.testing, as the worktree setup script does, now meets every other such checkout on index 0 of its own port. WorkerHarness and ConsolePermitConcurrencyTest flushdb that index before every test. Index 0 is also config/database.php's default for the application, which the phpunit.xml comment admits. The oracle cannot see this. ASuiteThatFlushesRedisKnowsWhichIndexItOwnsTest::both_suites_fall_back_to_their_own_index_when_nothing_names_one asserts 15 by unsetting the variable at runtime, which a php artisan test run never does. In a real run, 'nothing names one' resolves to 0.
- **[X]** I-3 (F-26 x F-27): with one malformed entry in dns.reserved_zones, every customer's zone claim is refused, as F-26 intends, but the refusal blames the customer. Probe with dns.reserved_zones=['lynomia.test','internal_panel.corp-secret.example'] and a customer claiming the valid name unrelated.test, verbatim: `PROBE status=422 body={"error":{"code":"dns.invalid_name","message":"That is not a valid DNS name.","request_id":"01M3EWDAJBSRPP9ZWRD6X53XXB"}}`. F-27 holds: nothing from the configuration reaches the body. But a platform misconfiguration answers as a 4xx validation error under the same code and sentence as a genuinely malformed name, so neither the portal nor the customer can tell the two apart. ClaimingAZoneTest::one_entry_in_the_reserved_list_that_is_not_a_name_refuses_every_claim deliberately asserts only assertNotSame(201). Its comment calls this 'a validation error about a name the customer did not type' and declines to pin the wording, so the known-wrong answer is left in place with no oracle.
- **[X]** I-4 (F-22, against F-38's rule): two new checks in validate-monitoring.py pass when their subject is empty. The F-38 header in .github/workflows/ci.yml lists validate-monitoring.py among the validators 'each refusing its own empty subject'. (a) loki_ruler_problems reads the fixed path loki/loki-config.yml through _yaml_mapping, which returns {} for a missing file. Mutation: rename the file to loki/loki.yml, repoint the two compose lines, and restore the pre-F-22 wired ruler (alertmanager_url with /loki/rules and no rule files). Result: `8 rule file(s), 77 rule(s), 62 exported by the control plane, 1 declared by a collector contract`, exit 0. Control: the same ruler appended to loki/loki-config.yml at the original path gives `FAIL loki/loki-config.yml wires the ruler to http://alertmanager:9093 and mounts no rule files ...`. (b) alertmanager_model_problems returns [] when there is no compose service named `alertmanager`. Mutation: set the image to prom/alertmanager:v0.31.0 (an unmodelled version) and the check fires (1 problem). Rename the service key to alertmanager-primary as well, and validate-monitoring exits 0. Both files were restored and sha256 matched: loki-config.yml 6e8d725e..., compose 9f74865c....
- **[X]** I-5 (F-38 x F-39): a false docblock in the F-38 header of .github/workflows/ci.yml. It says 'By sites there are twelve' and names four validators that each refuse an empty subject. validate-runbook-alerts.py, added by F-39, runs in the same Infrastructure job and also refuses an empty subject. Probe against an empty rules directory: `no rule files under .../rules`, exit=1. F-38 merged (94fc6c2) before F-39 (e7813f5), and the count was not revisited.
- **[X]** I-6 (F-39 x F-22/F-37): a stale measurement in a docstring. validate-runbook-alerts.py says the 59 citations it read on 2026-09-26 were 'exactly the code spans ... league/commonmark 2.10.0 ... found there'. At 462382f the gate reads 61: `32 file(s), 61 citation(s) of 51 defined alert(s), 68 alert(s) in 8 rule file(s)`. At 853892f, where the sentence was written, the same gate read 59 (`31 file(s), 59 citation(s) of 49 defined alert(s), 66 alert(s) in 7 rule file(s)`). The F-22/F-37 integration (07e730e) added a rule file, an alert page and two citations. It regenerated the README counts block but left the cross-check sentence as it was, so nothing shows the check was re-run for the two new citations.

## Unnumbered observations

- **[A]** RefuseAWorkerThatWouldRunAJobTwice is inert whenever APP_ENV=testing, because Laravel does not raise CommandStarting while running unit tests (stated in AppServiceProvider). Measured: REDIS_QUEUE_RETRY_AFTER=90 with queue:work --timeout=120 starts (exit 0) under APP_ENV=testing and is refused (exit 1) under APP_ENV=staging. Not a production hole, but the runtime half of the F-08 control is never exercised by any test run.
- **[A]** Throttle bucket names on the billing routes are crossed: POST subscriptions/{id}/cancel uses the 'plan-quote:' prefix, and POST invoices/{id}/wallet-credit uses 'subscription-cancel:'. Wallet-credit spends and subscription cancels therefore do not share or separate buckets the way the names say. I did not measure whether any two routes actually share a bucket.
- **[A]** ChangePlanRequest accepts client-supplied `units` (1-1000), which ChangeSubscriptionPlan uses for pricing. QuotePlanChange gates and chooses target resources at the subscription's current unit count, so the quote the customer was shown and the change executed can price different unit counts. Not probed to a loss.
- **[B]** Unnumbered (authorization): ChangeOperatorRoles checks only the roles being granted, never the ones being removed. A non-super-admin holding role.manage can therefore strip super-admin from any super-admin except the last. Probe: support+role.manage operator C, `PUT /api/admin/operators/{B}/roles {roles:[support]}` gives 200 and 'B super-admin now: false'.
- **[B]** Unnumbered (authorization): SetRolePermissions checks only the permissions in the new set that the actor lacks, then calls syncPermissions, which replaces the whole set. A role.manage holder can empty any non-super role, including permissions they do not hold themselves. Probe: C (support) `PUT /api/admin/roles/infrastructure-admin/permissions {permissions:[]}` gives 200 with remaining [].
- **[B]** Unnumbered (audit integrity): InviteOperator creates the user and records OperatorInvited in one atomic act, then runs ChangeOperatorRoles outside it. When the role grant is refused, the user row and the audit entry remain. Probe: C invites d@ with infrastructure-admin, gets 422 rbac.role_not_yours_to_grant, with 'users +1 OperatorInvited audit +1'.
- **[B]** Unnumbered (operator bootstrap): BootstrapFirstOperator uses firstOrNew by email. On a deployment with no super-admin, it promotes an existing customer login with that address to super-admin and replaces its password. This follows from reading the code; I ran no probe for it.
- **[B]** Unnumbered (dedicated capacity): when ProvisionDedicatedHandler fails IP reservation (ipam.pool_exhausted, FailureClass::Capacity), the reserved chassis is left in `provisioning`. The probe's next job then got `dedicated.no_matching_hardware`. With an order_id, a retry re-uses the held machine, so whether the engine releases it in the real order path was not established.
- **[B]** F-17 residue, not a reproduction: the invitation budget is per acting account (30/hour), so total mail scales with the number of accounts a person can register. That is bounded by the register limiter (5 per 10 minutes per IP), not by any per-person budget.
- **[B]** F-19: OrderStateMachine allows Refunded only from Paid, ProvisioningFailed, ManualReview and Active. A full refund while an order is queued_for_provisioning, provisioning or suspended is logged by RecordRefundOnTheOrder and not recorded on the order. The capacity hold then persists until the service terminates.
- **[C]** Backups, verification path (same mechanism as F-09's reproducing half, outside F-09's wording): `backups:verify` is scheduled; VerifyStoredArchives moves a Succeeded row to Verifying without resetting started_at; on the first reconcile poll while PBS is still verifying, giveUpIfOverdue measures from the archive's started_at. Probe: backup taken 3 days ago, verification running -> `PROBE-VERIFY state=needs_review restorable=false reason=the provider task was still unfinished after 12 hours; the platform has stopped tracking it BackupNeedsReview=0`. NeedsReview has no outgoing transitions (BackupState::transitions: NeedsReview => []), so a readable archive becomes permanently non-restorable in the portal, with no customer notification, as a side effect of a routine sweep. This bears on the Backups product directly.
- **[C]** The quarantine reason string 'the provider task was still unfinished after %d hours' is false whenever the operation (restore or verification) started less than max_poll_hours ago. It is a claim in code that the code does not satisfy. The RestoreServiceBackup comment that 'Restoring is not a legal destination from itself — deliberately, so that a re-entrant call cannot restart the clock' refers to a clock (restore_started_at) that nothing reads.
- **[C]** By reading, not probed: DirectAdminHostingProvider::parse returns a read's fields without the identifying-field check whenever an `error=0` field is present. `error=0` alone for CMD_API_SHOW_USERS gives listAccounts() = [], and ReconcileHostingNodes would then record Critical MissingAtProvider drift for every live account on the node. This is a false alert, not a sale or a destruction.
- **[C]** Provenance: F-09 and F-10 were closed before the branch-per-finding regime (ledger: 'Accepted closed at entry'). Their code predates 864ff94 and was not touched by the rebuild. Only tests/Feature/Backups/BackupLifecycleTest.php (8 lines) and BackupNotConfiguredException changed in Backups between 864ff94 and 462382f.
- **[C]** Implication for the matrix (not my assignment, from my band only): Backups is still blocked by F-09's reproducing half and the verification quarantine observation. DNS is still touched by F-26's empty-default half. Shared Hosting (F-04, F-14, F-18), Dedicated (F-12) and VPS-Compute (F-13) show no reproduction in-tree from my band.
- **[D]** No production writer of allocatable addresses (bears on F-02, band B; blocks VPS on a real estate): RegisterSubnet creates a subnet and zero ip_addresses rows; SeedSubnetAddresses has exactly one caller in src/, LoadReferenceTopologyForSimulation (refuses production); no admin route or console command seeds a subnet. Measured through the operator's own routes: 0 rows, IpAllocator::reserve -> IpPoolExhaustedException 'IP pool "p4" has 0 allocatable address(es) left; 1 were requested.' CreateVpsHandler classifies that as FailureClass::Capacity, so every VPS order on an operator-built estate waits and retries forever while infra:preflight's mapping.network reports pass.
- **[D]** Compute simulator convenient case left in place (F-24 class, not one of its three named limbs): FakeComputeProvider::createVirtualMachine at an occupied VMID silently replaces the machine. Probe: create 12345 'strangers-box' (2 vCPU), then create 12345 'our-box' (1 vCPU) -> 'second create at occupied id ACCEPTED', getVm -> name=our-box vcpu=1. A real cluster is expected to refuse (unverified here). F-15's own test compensates by counting creates at the double's door ('the simulator puts a second create under the same id on top of the first, so the fleet alone cannot see one'). The handler's docblock puts derived-id collisions at ~5% for 100 machines, so this is a case the simulator should refuse.
- **[D]** Hosting simulator still judges no domain or contact address (F-24 residue / F-04, band C): FakeHostingProvider::createAccount stores primaryDomain unexamined; DnsName::problemWith accepts 'abc.hosting.invalid', 'localhost', 'x.test' and 'foo.example' (probe: all NULL). The only observation point is the RecordingHostingProvider test decorator. CreateHostingAccountHandler no longer invents the placeholder, so this is a gap in the oracle, not a live defect in this band.
- **[D]** EndpointPolicy accepts 5f00::/16 (IANA SRv6 SIDs, RFC 9602, not globally reachable) on every road, public included. Outside the seven audited families; low impact, operator-only surface.
- **[D]** Ledger inconsistency, recorded after measuring: docs/round-2-remediation-ledger.md:1391 still states F-15 `OPEN` ('round six ... REJECTED ... round seven dispatched'), while docs/round-2-rebuild-progress.md:30 records F-15 'UPHELD WITH RESERVATIONS (round 6)' and the candidate commit says all findings are in. The coordinator's own record contradicts itself on a High finding.
- **[D]** Input for the five-product matrix from band D only (not my assignment): VPS - F-15 does not reproduce; F-20 partially reproduces (false 'did not run' label beside the correct warning); plus the zero-allocatable-address observation, which on its own prevents any VPS build on an operator-built estate. Dedicated - no band-D finding blocks it, but DecommissioningGivesTheAddressBackTest carries the F-44 clock shape. Shared Hosting, DNS, Backups - no band-D finding blocks them; F-24's DNS limb is held by a shared contract suite, the hosting limb by simulator-only tests.
- **[D]** Mutation-summary caveat of my own: my helper printed only phpunit 'failed' counts; the F-43 run also carried 4 'errors' (TypeError on a null deadline, a Mockery expectation), which were read from the JSON and are reported above. Earlier mutation counts in this report may undercount errors; none of them was used to call anything green.
- **[E]** TestDatabaseGuard's fourth condition is `str_contains(strtolower($name), 'test')`, the same rule WorkerHarness uses at line 290. So it admits any name containing the substring: latest, contest, attestation. Measured: DB_DATABASE=lynomia_latest on a RefreshDatabase test (NotificationsCannotBeTurnedAgainstACustomerTest) passed the guard. Laravel's migrate then CREATED the missing PostgreSQL database and migrated it, and the test passed 4/4. `php artisan db:show` afterwards showed database lynomia_latest, owner lynomia, 71 migrations, 0 users, 0 customers. So the guard would also let migrate:fresh drop a real database whose name happened to contain 'test'.
- **[E]** LEFTOVER FROM MY PROBE, ACTION NEEDED: the PostgreSQL database `lynomia_latest` (owner lynomia, a fresh empty schema) was created by the probe above. My attempt to DROP it was refused by the permission system, so it still exists and the coordinator must drop it. It holds no data. The repository worktree itself is clean.
- **[E]** infrastructure CI: the 'Fail on unresolved placeholders' and 'fake provider' steps use `if git grep …` and `if … | xargs -r grep …`. grep's exit 2 (error) is treated as 'no match' and passes. The fake-provider scan also word-splits `$templates` unquoted. Neither is exercised by any self-test. These are minor.
- **[E]** The F-37 lease (15 minutes) settles a still-running slow BMC call as Indeterminate, and the in-flight settle() then overwrites it unconditionally. The action's docblock documents this and chooses it on purpose. Recorded, not disputed.
- **[E]** PaymentMethodKind::BankTransfer and ::Wallet have zero references in src/app/database/routes (this tree has no ApplePay or GooglePay cases). Nothing notices. This is the audit's 'Also recorded' item, and it remains.
- **[X]** Pre-existing (unnumbered): a forced VPS termination at DELETE /api/admin/services/{id} skips both the retention window and the 'still in service' check in TerminateVpsService::execute (`if (! $force) { assertTheRetentionWindowHasElapsed }`). Nothing in ServiceController, EndOfService or TerminateVpsService touches the subscription, and RenewSubscription skips only blocked-Pending services. Reading the code, a forced end of an active VPS leaves a renewing subscription, and the c36f188 code had the same structure. I measured this for Shared Hosting only (I-1); for VPS it is inferred from the code, not measured.
- **[X]** Gap in the F-41 guard (unnumbered; nothing breaks today): TestDatabaseGuard::DESTROYING_TRAITS lists RefreshDatabase, DatabaseMigrations and DatabaseTruncation. tests/Support/LeavesNothingCommitted truncates every table in the public schema in tearDown, and the guard does not count it. Probe: `TestDatabaseGuard::destroys(class_uses_recursive(new class { use LeavesNothingCommitted; }))` returns `false`. All three classes that use it today (ConfigurationVanishingMidPaymentTest, ConcurrentOrderPlacementTest, FiniteCapacityIsClaimedNotGuessedTest) also use RefreshDatabase, so the guard fires for them. That protection comes only from how those classes are written today; no oracle covers it.
- **[X]** Checked and holding, recorded so they are not re-examined. F-27's details engine: publishing() is final and protected, $published is private, and names from NEVER_PUBLISHED are dropped at read time; the two formerly leaking customer routes assert the whole body, not only details. F-17's cooldown is per account, so retry_at discloses nothing about another account. The inventory of every publishing() site is scalar and caller-owned. The payload-written-once census states its own gaps in detail. CriticalDriftReachesAnOperatorTest works out both the Alloy path and the Laravel path from their sources. Makefile sets SHELL := /bin/bash, so its `set -euo pipefail` does not hit dash. The Proxmox tester's new inventory_sync requirement for VPS is intended and documented. F-43's ConsolePermitDeadlineTest does not depend on the cache driver, so F-41 pinning CACHE_STORE costs it nothing.
- **[X]** Worth knowing though not a defect: the fail-closed test for reserved zones, and the preflight's dns.reserved_zones report, are the only places the misconfiguration in I-3 is visible to an operator. A customer sees only a 422.

## Could not establish

In those words, everything the re-auditors could not establish:

- **[A]** Whether any of the 24 error codes with no catalogue entry (drift.*, infrastructure.*, monitoring.invalid_metric, provisioning.adoption_*/repoint_*/retry_*, rbac.*) can be raised on a customer route, where the getMessage() fallback would publish the exception's own sentence. The static scan only matched `->as('x.y')` and `return 'x.y'` forms.
- **[A]** The real-world width of the F-05 settle-to-fulfil window under production queue latency. It is reproduced deterministically by holding InvoicePaid, not by timing a real worker.
- **[A]** Whether a PENDING service with no placement_blocked_reason (the F-07 hosting-no-node case before the job fails) or a FAILED service is ever terminated or refunded by an operator path that stops renewal. Only RenewSubscription's own decision was measured.
- **[A]** PHPStan: not run (not installed).
- **[B]** COULD NOT ESTABLISH whether shared hosting can actually be built on an operator-created hosting node. Local feasibility passes, but I did not run CreateHostingAccountHandler against an operator-built node, credential and licence.
- **[B]** COULD NOT ESTABLISH whether a real order-driven Dedicated build releases a chassis stranded by an IP-capacity failure. The probe ran the handler directly with no order_id.
- **[B]** COULD NOT ESTABLISH provenance, round two or earlier, for code in the grafted base commit 31a1501 (the repository is shallow). That code includes the F-02, F-03 and F-16 remediations and the Rbac actions behind the observations.
- **[B]** COULD NOT ESTABLISH end-to-end delivery of ResourceDriftOpen to a pager. promtool and amtool are not installed, and no real Alertmanager or Prometheus was run. The routing evidence is validate-monitoring.py's model of Alertmanager v0.28.1.
- **[B]** PHPStan: not run (not installed).
- **[C]** F-13: whether ProxmoxConnectionTester::PRIVILEGES matches real Proxmox privilege checks. The tester's own docblock lists privileges it does not ask for (VM.PowerMgmt for create start=1, VM.Config.CDROM for the cloud-init drive, SDN.Use for a bridged NIC). No real cluster may be contacted, so VPS reaching ReadyForProduction on a real cluster is established only against an HTTP double fed the ansible role.
- **[C]** F-11: whether CloudflareZoneSimulator's model of Cloudflare (`name=` exact-match semantics, per_page ceiling, PUT replace semantics, total_pages) matches Cloudflare. The contract suite proves the adapter against this programme's model, not against the provider.
- **[C]** F-14: DirectAdmin's real CMD_API_LICENSE field names and state words (status/state, expires/expiry/expire_date, 'active'/'valid'/'licensed'). An adapter that refuses anything else fails closed, but whether a real licensed node would ever read as licensed could not be established.
- **[C]** F-04: WHM createacct / DirectAdmin CMD_API_ACCOUNT_USER behaviour on the values now sent. The handler and a recording decorator were exercised; no panel was.
- **[C]** PHPStan: not run (not installed). apps/web tsc and eslint: not run; only `npx vitest run src/features/backups` (2 files, 13/13 passed) was run for the web side.
- **[D]** Whether real Proxmox reports a machine at the reserved VMID while or after an abandoned qmcreate, whether it refuses a create at an occupied VMID, and whether ProxmoxComputeProvider::isMissingResource (404, or any 5xx whose body contains 'does not exist') can read a non-machine error as 'no machine there'. F-15's protection rests on those semantics; no real cluster was contacted.
- **[D]** Whether F-42 fires on the actual CI runner. Measured here: 4 of 4 parallel runs red, a single run green at load 17. The CI runner's core count and load have never been observed.
- **[D]** Whether an operator's real browser session (not actingAs) reaches Horizon through its `web` middleware group; the F-30 oracle authenticates with actingAs only.
- **[D]** Whether the Arabic F-20 sentence says the right thing: the test and I checked only that it exists, is rendered, is in Arabic script and differs from the English.
- **[D]** Whether the audit's d89e227 tree already carried ConfiguringACatalogueDoesNotMakeAnythingSellableTest and the softwareState-first branch of ProductSellability::maySell: d89e227 is not an object in this repository, so F-25's 'containment rests on a ValueError' could not be compared against the audited code, only disproved against the candidate.
- **[D]** PHPStan: not run (not installed).
- **[E]** Whether OpenTofu 1.8.7's `tofu validate` exits 0 or non-zero on a directory with no .tf files. No tofu binary is installed here. Either answer leaves the step not validating the configuration.
- **[E]** A mutation of phpunit.xml (removing one force attribute) and of the TestCase guard. The permission system refused those commands after I tried to drop the probe database, so the pins oracle was established by reading its assertions and by the hostile-export runs, not by watching it go red.
- **[E]** Dropping the probe-created database lynomia_latest: refused by the permission system.
- **[E]** PHPStan: not run (not installed).
- **[X]** COULD NOT ESTABLISH that I covered the whole diff. It is 480 files and 62,526 insertions, and I did not read every one. I examined every interaction the dispatch names (F-04 x F-15, F-17 x F-27, F-26 x F-27, F-19 x F-18/F-23, F-22 x F-37 x F-39, F-38 x F-22, F-41 x the suites that run tests), then sampled the new architecture gates, CI steps and validators for empty subjects, plus the modified pre-existing tests. The frontend changes other than test-setup.ts, and most of the provider-adapter changes (Cloudflare, Proxmox, DirectAdmin), were not examined for interactions.
- **[X]** COULD NOT ESTABLISH that the VPS side of I-1 behaves at runtime as its code reads: I did not measure it.
- **[X]** COULD NOT ESTABLISH whether renaming the compose service in I-4(b) would still let Prometheus reach Alertmanager at http://alertmanager:9093 without a network alias. The mutation shows only that the validator stops checking. It does not show a working deployment in that shape. No Docker was run, per the standing constraints.
- **[X]** COULD NOT ESTABLISH anything with PHPStan: not run (not installed). The whole backend suite was not run, per the brief.

- **[coordinator]** PHPStan has never run in this programme and did not run here (`tools/phpstan/vendor` absent).
- **[matrix]** Whether Shared Hosting builds on an operator-created node; whether money moves before feasibility for a Dedicated plan; whether a real order-driven Dedicated build releases a chassis stranded by an IP-capacity failure; the VPS forced-termination renewal (inferred from code only); real-provider behaviour behind F-04, F-11, F-13, F-14 and F-15 (all under the frozen `REAL_*` statuses); and band X did not cover the whole ~480-file diff, so "nothing new blocks it" could not have been established for any product.

---

# Appendix — the band reports and skeptic findings, verbatim


## Band A

## Band A — money and the order/payment seam (F-01, F-05, F-06, F-07, F-08, F-27)

Tree: `462382f5659e163886dc76a764a6cd131e017f3d`, detached. The cheap check passes: `tests/Support/RedisIndexForThisRun.php` and `2026_04_15_000004_*` are present. Database `lynomia_test_ra`, Redis 6380/db 2. Every probe file was temporary and has been deleted. Every mutation was restored against its sha256. `git status --porcelain` is empty at the end; only gitignored setup files (.env, .env.testing, caches) remain. Nothing was committed or pushed.

The ledger records F-01, F-05 and F-07 as `CLOSED`. On this tree all three partially reproduce.

### F-01 — PARTIALLY REPRODUCES

**What no longer reproduces.** A plan change is no longer free. `ApplyPlanChange::settleTheDifference` does two things:
- For an upgrade, it issues an invoice carrying `InvoiceItemKind::Credit` and `::Proration` lines.
- For a downgrade, it posts wallet credit.

Mutation: replacing the call with `$invoice = null` turns 9 of 86 tests red (`APlanChangeSettlesItsMoneyTest`, `PlanChangeEndpointTest`).

**What reproduces: an upgrader can still be under-charged.** Measured with the temporary `RaProbeF01Test`. The fixture is VPS small at 9.000 KWD and large at 90.000 KWD, 20 days into a 30-day period.

1. **Flip-flop mints credit.**
   - Three rounds of small→large→small, paying nothing:
     ```
     WALLET before=0 after=162000
     INVOICES=[{"status":"open","total_minor":54000} ×3]
     ```
   - Then one more upgrade, paid from the wallet:
     ```
     WALLETPAY status=200 inv={"status":"paid","total_minor":54000}
     AFTER WALLET PAY wallet=108000 RESIZE JOBS=1 payload={"vcpu":8,"disk_gib":160,"memory_mib":16384,...}
     TX=[{"kind":"charge","provider":"wallet","status":"succeeded","amount_minor":54000}]
     ```
   - No real money came in. The customer has the large machine and 108.000 KWD of spendable credit.
   - Cause: `ChangeSubscriptionPlan` moves `plan_id` and `recurring_amount_minor` before the upgrade invoice settles. The downgrade credit is then priced at a plan that was never paid for, and `QuotePlanChange` has no refusal for an open proration invoice.
2. **Paying the cheap invoice delivers the dear plan.**
   - small→mid gives invoice 667; mid→large gives invoice 53,333. Only the 667 invoice is settled:
     ```
     PAID only I1 total=667 RESIZE JOBS=1 payload={"vcpu":8,"disk_gib":160,"memory_mib":16384,...}
     ```
   - `ResizeOnPlanChangeSettlement` resizes to the subscription's *current* plan, not to the plan the paid invoice bought.
3. **Not atomic.**
   - An exception thrown from `Invoice::creating` during an upgrade:
     ```
     INVOICE-FAIL status=500
     INVOICE-FAIL sub_on_large=true recurring=90000 invoices=0
     ```
   - The plan move had already committed in its own transaction. A retry is refused as SamePlan (409). The proration is lost for good, which is the audit's original F-01 state.

**Question 4.** Only the no-invoice regression has an oracle (the 9 red tests above). The credit mint, the resize to the current plan and the non-atomic commit have no oracle: all three probes passed silently against the current code.

**Docblock.** `routes/v1/billing.php:38-42` still reads *"No renewal or plan-change route … Neither is published here"*, 75 lines above `POST {subscription}/plan`.

### F-05 — PARTIALLY REPRODUCES

**What no longer reproduces.** `CancelOrder::withdrawTheInvoice` voids collectible invoices inside the cancel transaction. A capture that arrives afterwards is credited to the wallet (`CompensateUncollectableCapture`). Mutation: removing the void turns `ACancelledOrderCollectsNoMoneyTest` red.

**What reproduces: the audit's terminal state, through the window between settlement and fulfilment.**
- `SettleInvoice` marks the invoice paid.
- The order leaves `pending_payment` only when the *queued* `FulfilOrderOnSettlement` job runs.
- `CancelOrder` decides cancellability from the order alone and never looks at its invoice.

Measured with the temporary `RaProbeF05Test` (settle with `InvoicePaid` held back, then cancel over HTTP, then deliver `InvoicePaid`):
```
AFTER SETTLE invoice=paid order=pending_payment
CANCEL HTTP 200 {"data":{...,"status":"cancelled",...
AFTER CANCEL invoice=paid order=cancelled
FULFIL THREW Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException: Order cannot transition from "cancelled" to "paid".
END invoice=paid paid=9000 refunded=0 order=cancelled services=0 wallet_tx=0 refunds=0
```
With `tries=5` on the payments queue, the job ends in `failed_jobs`. The money is captured, nothing is provisioned and nothing is refunded. A customer can open this window on purpose: pay from the wallet, which is instant, then cancel before the payments worker runs.

**Question 4.** The void path has an oracle. The window has none. `an_order_that_has_been_paid_cannot_be_cancelled` settles under `QUEUE_CONNECTION=sync`, so fulfilment runs inline and the window cannot exist inside the test.

### F-06 — DOES NOT REPRODUCE

**Mechanism.** `PlanCapacity::claim()`, called inside `PlaceOrder::persist`'s transaction, locks each plan row with `FOR UPDATE` in sorted order, then re-reads the live counts.

**Measurement.**
- `FiniteCapacityIsClaimedNotGuessedTest` passes 9/9. It races 8 real PHP processes behind a `pg_advisory_lock` barrier: 1 accepted, 7 refused with `checkout.out_of_stock` / `checkout.per_customer_limit`.
- Mutation (drop `lockForUpdate`): 4 tests red, `actual size 6 matches expected size 1` and `actual size 2 matches expected size 1`. File restored, sha256 identical.

**Question 4.** Guarded by an oracle: real multi-process races, positive controls on the refusal codes, and a `lock_timeout` 55P03 test proving the claim itself blocks.

**Interaction.** A plan change bypasses capacity entirely: `STOCK0 plan change status=200 sub_on_large=true` against `stock_limit=0`.

### F-07 — PARTIALLY REPRODUCES

**What no longer reproduces.**
- A hosting plan with no package is refused at checkout (`checkout.not_deliverable`) and again when a payment starts.
- `placement_blocked_reason` now has three readers: the renewal skip, the `GET /admin/services` index, and the stripping from the customer resource.

Mutation (drop `assertDeliverable`): 6 tests red.

**What reproduces: the class "money moves before feasibility and renews forever"** for local rows the feasibility check does not read. Measured with the temporary `RaProbeF07Test`:
- **(a) Shared hosting with a package and zero `hosting_nodes`:**
  ```
  CHECKOUT ACCEPTED order=pending_payment
  invoice=paid order=manual_review service={"status":"failed",...} jobs=[{"status":"needs_review","kind":"create_hosting_account"}] subs=[{"status":"active","auto_renew":true}]
  RENEWAL result=RenewalPlan (billed)
  ```
- **(b) VPS whose `placement_constraints` name an offline cluster and an inactive pool:** the same outcome, `RENEWAL result=RenewalPlan (billed)`.

Causes:
- `soleTarget()` trusts a declared id without checking that it is active or exists.
- `hosting()` never checks that a node exists.
- `RenewSubscription::undeliveredService` skips only services that are PENDING and carry a blocked reason, so a FAILED service that was never delivered keeps renewing.

**Question 4.** The package case has oracles. There is no oracle for any of the above. The `LocalPlacementFeasibility` docblock claims more than the code checks.

### F-08 — DOES NOT REPRODUCE

**Live measurement.** Real Redis (6380/db 2) and real `queue:work` processes. One dispatch of a 100-second job, two workers at `--timeout=120 --tries=5`, a 200-second window.
- Shipped clock (retry_after 180):
  ```
  12:59:31 start pid=32150 attempt=1
  13:01:11 end pid=32150
  starts=1
  ```
- The audited clock (90):
  ```
  13:02:51 start pid=4781 attempt=1
  13:04:21 start pid=4782 attempt=2
  13:04:31 end pid=4781
  13:05:51 start pid=4781 attempt=3
  ... starts=3
  ```

**Mechanism.** Three connections with separate clocks:

| connection | retry_after | supervisor timeout |
|---|---|---|
| redis | 180 | 120 (payments/default), 90 (notifications) |
| redis-provisioning | 5760 | 5700 |
| redis-infrastructure | 1860 | 1800 |

**Question 4.** Guarded by two oracles:
- **CI:** `EveryQueueOutlivesItsLongestJobTest` and `EveryRetriedPaymentsListenerWaitsBetweenAttemptsTest`. The second includes a job's own `$timeout` past the clock and requires a backoff.
- **Runtime:** `RefuseAWorkerThatWouldRunAJobTwice` refused `REDIS_QUEUE_RETRY_AFTER=90 queue:work --timeout=120` (exit 1 under `APP_ENV=staging`).

Caveats:
- The guard is inert under `APP_ENV=testing` (the same invocation exits 0), because Laravel raises no `CommandStarting` event in unit tests.
- Both oracles are configuration proxies. The live run above is what shows they correspond to behaviour.

### F-27 — DOES NOT REPRODUCE

**Mechanism.** `error.details` is now `$e->publishedContext()`:
- only keys a class declared through `publishing()`;
- keys naming the platform are dropped however they are spelled;
- nothing is invented.

**Measurement.**
- Both audited customer routes pass at HEAD. DNS zone claim with Cloudflare and no token: the body names neither `cloudflare`, `services.cloudflare` nor `api_token`. Unknown registrar on the nameservers route: the driver is not named.
- Mutation (render `context()` instead): 9 of 23 tests red, e.g. `error.details was published: {"order_id":…,"node":"kw-node-07.estate.lynomia.internal","configuration_key":"services.hosting.whm_token_for_node_seven"}`.

**Question 4.** Guarded by an oracle: `ErrorDetailsAreOnlyWhatTheCallerAlreadyKnowsTest`. It covers the engine, the real routes, a reviewed inventory of every declaration, and an autoload-root scanner.

**Residual.** The sentence falls back to `getMessage()` for codes with no catalogue entry. 24 of 227 scanned codes have none, all apparently operator-side. Whether one reaches a customer route is not established (below).

### What round two introduced or left in this band

1. **The downgrade credit mints money.** `ApplyPlanChange::creditTheCustomer` prices the credit against an upgrade that is still unpaid. Flapping between plans mints spendable wallet credit. The route's own comment names flapping as the reason for its throttle, and the throttle does not stop it.
2. **Settlement resizes to the current plan.** `ResizeOnPlanChangeSettlement` resizes to whatever plan the subscription is on, not to what the paid invoice bought.
3. **The plan-change repair is not atomic.** An invoice failure puts F-01's original state back.
4. **False docblock.** `routes/v1/billing.php:38-42` still denies that the plan-change route exists.
5. **F-01 × F-06.** A plan change ignores `stock_limit` and `per_customer_limit`.
6. **A test that asserts sync-queue behaviour.** It hides the settle-to-fulfil window that F-05 still reproduces through.
7. **Feasibility docblock overclaims** (F-07).

**Unnumbered observations**
- The F-08 runtime guard is off whenever `APP_ENV=testing`.
- Throttle bucket prefixes are crossed: cancel uses `plan-quote:`, wallet-credit uses `subscription-cancel:`.
- Client-supplied `units` prices the plan change, while the quote and the resource target use the current unit count. Not probed to a loss.

### Green proof

- Command: `php artisan test tests/Feature/{Billing,Orders,Subscriptions,Payments,Queue,Api} tests/Feature/Provisioning/ABlockedServiceIsSeenAndBilledToNobodyTest.php`
- JSON: `{"result":"passed","tests":564,"passed":564,"assertions":13876}` (no `skipped` key).
- JUnit: 72 testsuites, all `skipped="0"`.
- `ARTISAN-TEST EXIT=0`.
- PHPStan: not run.

### Could not establish

- Whether any of the 24 uncatalogued codes can reach a customer route through the `getMessage()` fallback.
- How wide the F-05 window is under real production queue latency. It was reproduced by holding `InvoicePaid` back, not by timing a real worker.
- Whether any operator path stops renewal for a FAILED, never-delivered service.
- PHPStan: not run.

#### Skeptic on F-01: confirmed — PARTIALLY_REPRODUCES

I could not refute it. I ran this at 462382f in worktree /home/user/cloud/.claude/worktrees/wf_98b6ec76-8e4-3 (DB lynomia_test_sk010, redis db 32), and the cheap check passed: RedisIndexForThisRun.php and 2026_04_15_000004_* are both present. I used my own temporary probe, apps/control-plane/tests/Feature/Billing/SkProbeF01Test.php, a new file that is now deleted. It goes through POST /api/v1/subscriptions/{id}/plan with VPS plans small 9,000, mid 10,000 and large 90,000 minor units, monthly, 10 days into the period. Result: tests=4 passed=4, no skipped key.

What the audit says F-01 is (row at line 198): no invoice is issued; the Proration/Credit kinds exist only in a DTO nothing consumes; upgraders are under-charged; downgraders are under-refunded; it repeats every period. The remediation note at line 676 adds "gate the resize on settlement".

Clauses that no longer reproduce:
- An upgrade now issues an open invoice for 54000.
- ApplyPlanChange::invoiceTheDifference writes Credit and Proration lines.
- A downgrade credits the wallet (WalletLedger Adjustment). It is over-credited, not under-refunded, so "under-refunds downgraders" does not reproduce either.

Clauses that do reproduce, measured by me:

(1) "Under-charges upgraders", by flip-flop. Three rounds of small->large->small, 1 s apart, all returned 200.
- Output: `WALLET [0,162000]`, `INVOICES [open 54000 x3]`, `SUB [true(on small),9000]`.
- A final upgrade followed by POST /invoices/{id}/wallet-credit gave `WALLETPAY [200,"paid",54000]`, `AFTER wallet 108000`, `RESIZE [[8,160]]`, `TX [["charge","wallet",54000]]`.
- The large machine is delivered with no external money, and 108 KWD of spendable credit is left over.
- Mechanism: ChangeSubscriptionPlan writes plan_id and recurring_amount_minor inside its own transaction before any invoice is paid. The downgrade's credit is then prorated from the unpaid 90000 recurring. QuotePlanChange::refusals has no refusal for an open proration invoice.
- Nothing reaches those open invoices. SweepSubscriptionLifecycle dunns only PastDue/Suspended subscriptions, and unpaid proration invoices never move a subscription into either status.
- Correction to the re-auditor: under frozen time (same-second requests), only the first round credits. `FROZEN wallet [0,54000]`, because the wallet idempotency key is subscription + pre-change plan_id + timestamp. The mint still happens once per timestamp, and repeats whenever the clock moves.

(2) "Gate the resize on settlement" only half holds. I ran small->mid (invoice 667), then mid->large (invoice 53333), then settled only the 667 invoice.
- Output: `CHEAP RESIZE [{vcpu:8,disk_gib:160,memory_mib:16384,...}]`, and invB is still "open".
- ResizeOnPlanChangeSettlement resizes to subscription->plan (the current plan), not to the plan the paid invoice bought. Its docblock says this on purpose ("the current plan is the right answer anyway"). That is exactly the upgrader under-charge: the large machine for 0.667 KWD.

(3) Not atomic. An Invoice::creating hook that throws gave `ATOMIC [500,true,90000,0]` and a retry of `[409,"subscription.plan_change_refused"]`. That is F-01's original end state: plan and recurring moved, no invoice, and no audit_entries row, so the retroactive-billing trail is lost too. Caveat: this path needs a failure injected into invoice issue and is not customer-drivable, so I count it as a lesser half.

Conclusion: the "no invoice / dead declaration" half is closed. The "under-charges upgraders, repeatable" consequence still reproduces by (1) and (2), through new remediation code rather than the original discard. It could be argued that mechanism (1) is a new defect. But the audit clause it produces, "under-charges upgraders", is F-01's own text, and (2) violates the audit's stated remediation "gate the resize on settlement". So PARTIALLY_REPRODUCES stands.

Question 4 (is anything watching?): nothing in APlanChangeSettlesItsMoneyTest or PlanChangeEndpointTest asserts that the resize matches the invoice that was paid, that a downgrade credit is capped at money actually collected, or that the plan move and the invoice are one transaction. All four probes passed silently. Those two tests only guard the no-invoice regression; I did not repeat the re-auditor's mutation.

The tree ends clean: git status --untracked-files=all is empty, HEAD is 462382f5659e163886dc76a764a6cd131e017f3d, the probe file was new and has been removed, and nothing was committed.


#### Skeptic on F-05: confirmed — PARTIALLY_REPRODUCES

I tried to refute the PARTIALLY_REPRODUCES verdict and could not. My probe was stronger than the re-auditor's: it used only customer HTTP routes and did not fake InvoicePaid. It also did not use the RA probe's settle path.

Tree: 462382f. The cheap check passed. Env: DB lynomia_test_sk051, REDIS_DB=33.

Probe: temporary file apps/control-plane/tests/Feature/Orders/SkProbeF05Test.php. It was a new file, since deleted, and git status is empty at the end.
1. PlaceOrder.
2. Top up the wallet by 9000 (Topup).
3. Queue::fake(). This stands in for the async 'payments' worker. phpunit.xml forces QUEUE_CONNECTION=sync.
4. POST /api/v1/invoices/{id}/wallet-credit with an Idempotency-Key header.
5. POST /api/v1/orders/{id}/cancel.
6. Take the queued CallQueuedListener for FulfilOrderOnSettlement and call its handle() with the queued event.

Output, verbatim:
BEFORE wallet=9000 invoice=open order=pending_payment
PAY HTTP 200 {... "status":"paid" ...}
QUEUED FulfilOrderOnSettlement=1
AFTER PAY invoice=paid order=pending_payment wallet=0
CANCEL HTTP 200 {... "status":"cancelled" ...}
AFTER CANCEL invoice=paid order=cancelled
FULFIL THREW IllegalStateTransitionException: Order cannot transition from "cancelled" to "paid".
END invoice=paid paid=9000 order=cancelled services=0 wallet=0

Mechanism, from reading the code:
- CancelOrder::execute and isCancellable check only order.paid_at and order.status. They never check the invoice.
- order.paid_at is written only in TransitionOrder (->Paid). That transition runs inside the queued FulfilOrderOnSettlement listener: queue 'payments', tries=5, backoff [5,15,60,300].
- withdrawTheInvoice skips any invoice that is not collectible. A Paid invoice is therefore left alone and the cancel commits.
- FulfilOrderOnSettlement has no failed() method and no Cancelled branch. It returns early only for Refunded and Terminated. Every retry throws the same exception, so the job ends in failed_jobs.
- Nothing refunds the money or credits it back to the wallet.

Which clause reproduces:
- The terminal state reproduces exactly as the audit words it: "captures money, provisions nothing, refunds nothing, and lands FulfilOrderOnSettlement in failed_jobs while the [payment] answers 200". Both the pay request and the cancel request return 200.
- A customer can trigger it alone. Wallet payment settles instantly, and they cancel before the payments worker runs.

Which clause does not reproduce:
- "A cancelled order's invoice stays collectible; paying it..." no longer holds. After cancel, the only invoice CancelOrder leaves collectible-looking is one that is already Paid. Any collectible invoice is voided in the cancel transaction.
- A capture that lands after the void goes to the wallet through CompensateUncollectableCapture. The ACancelledOrderCollectsNoMoneyTest cases A and C cover this.
- So the order of events differs from the audit's: payment, then cancel, not cancel, then payment. The root and the loss are the same: cancellation is not reconciled against the invoice's money state, and a cancelled order holds captured money.
- This is the audit's defect reached by a second path, not a lesser or out-of-scope issue. It is not a probe artefact: Queue::fake only reproduces the production async queue config.

Oracle gap confirmed: ACancelledOrderCollectsNoMoneyTest::an_order_that_has_been_paid_cannot_be_cancelled settles under sync, so paid_at is stamped inline. It asserts the sync behaviour and cannot observe the settle-to-fulfil window. Nothing guards that half.


#### Skeptic on F-07: confirmed — PARTIALLY_REPRODUCES

I tried to refute the claim and could not. The PARTIALLY_REPRODUCES verdict stands, but it rests on the audit's headline clause, not on the concrete example the audit gives.

**The audit's text for F-07 (line 209):** "Money moves before feasibility. A hosting plan with no package is orderable, payable and renews forever; `placement_blocked_reason` is written in one place and read nowhere, and there is no admin services index." The duplicates section records "money-before-feasibility (2 → F-07)", so the headline names the class, and two finders were merged into it.

**Clauses that do not reproduce (I agree with the re-auditor):**
- **Hosting plan with no package.** It is refused with `checkout.not_deliverable`, and a payment start is refused too. Covered by `MoneyDoesNotMoveForSomethingUndeliverableTest`.
- **`placement_blocked_reason` read nowhere.** It is now read by the `RenewSubscription` skip, by the admin index `GET /admin/services`, and is stripped from `ServiceResource`. Covered by `ABlockedServiceIsSeenAndBilledToNobodyTest`.
- **No admin services index.** It now exists.

A skeptic can say these three are the whole concrete specification.

**The clause that still reproduces: "Money moves before feasibility … renews forever".** It holds for other facts this platform's own rows already know. My probe was `apps/control-plane/tests/Feature/Provisioning/SkProbeF07Test.php` (temporary, now deleted), run at 462382f on DB `lynomia_test_sk072`, Redis 34, result `{"tests":3,"passed":3}`. Output, verbatim:

1. **Shared-hosting plan with one package and zero hosting nodes:**
   ```
   NODES=0
   HOSTING CHECKOUT ACCEPTED order=pending_payment invoices=1
   SERVICE status=failed res={... no placement_blocked_reason ...}
   JOB {"type":null,"status":"needs_review","last_error":"No hosting node can take an account on package hosting-hscjh4."}
   ```
   The error text says the platform knows from its own rows, after the money has moved, that it cannot place the account.
2. **VPS plan whose `placement_constraints` name an offline cluster (with an active template) and an inactive IP pool:**
   ```
   VPS CHECKOUT ACCEPTED order=pending_payment invoices=1
   ```
3. **A subscription whose only service is FAILED and was never activated:**
   ```
   FAILED RENEW considered=1 renewed=1 skipped=0 invoices=1
   ```

**Mechanism, from the code in `src/Modules/Provisioning/Application/Services/LocalPlacementFeasibility.php` and `src/Modules/Subscriptions/Application/Actions/RenewSubscription.php`:**
- `LocalPlacementFeasibility::hosting()` checks only the package, never a node.
- `soleTarget()` returns a declared `cluster_id` or `ip_pool_id` as given, without checking that it exists or is active.
- `RenewSubscription::undeliveredService()` skips only a service that is PENDING and carries a blocked reason. A FAILED service that was never delivered renews.

**Why I could not refute it:** the zero-node case is the audit's exact shape. It is a local-row fact, knowable before checkout, and the order is still accepted, invoiced, built into a failed service, and renewed. It is not a probe artefact.

**Mitigating points, each weaker than a refutation:**
- The FAILED-renewal behaviour is a documented, deliberate policy. The `undeliveredService` docblock says "inventing a renewal policy for it here would be inventing product".
- The failed build surfaces as a `needs_review` job, so an operator can see it. That is unlike the original PENDING row, which nobody read.
- A declared offline cluster is operator misconfiguration.
- I did not drive the VPS case past checkout; I only observed that checkout accepted it.

So the reproducing part is a narrower instance of the same class, not the named package example.

**Oracles (question 4):** the no-package half is held by `MoneyDoesNotMoveForSomethingUndeliverableTest` and `ABlockedServiceIsSeenAndBilledToNobodyTest`. No oracle covers a missing hosting node, an inactive or nonexistent declared cluster or pool, or renewal of a FAILED, never-delivered service. The `LocalPlacementFeasibility` docblock also claims more than the code checks.

**Tree:** the probe file was the only write. It was removed with `rm`, and `git status --porcelain` came back empty at HEAD 462382f5659e163886dc76a764a6cd131e017f3d. The worktree is clean.


## Band B

## Band B: F-02, F-03, F-16, F-17, F-19, F-22, F-31

Tree: 462382f5659e163886dc76a764a6cd131e017f3d, detached. The cheap check passed: `tests/Support/RedisIndexForThisRun.php` and `database/migrations/2026_04_15_000004_record_the_domain_a_hosting_line_was_bought_for.php` are both present. DB lynomia_test_rb, Redis 6380/4.

Two findings in this band still reproduce in part: F-02 (VPS and Dedicated cannot be built on an estate an operator creates) and F-16 (a miscapitalised `--env` still disarms every production guard). The other five do not reproduce. F-19's closure has one state, provisioning_failed, that no oracle protects.

### F-02 — PARTIALLY REPRODUCES

**Closed half.** Region, ComputeCluster, Network, IpPool, Subnet, HostingNode, DedicatedServer stock and BmcEndpoint all have operator routes under `api/admin/infrastructure/*`. The three `findOrFail` chains (datacenter needing a region, rack needing a datacenter, template needing a cluster) now resolve. AnOperatorCanBuildTheEstateFromNothingTest and AFreshDeploymentBecomesConfigurableTest are green.

**Reproducing half.** The finding's headline, "the platform cannot be brought into a sellable state by an operator", still holds for VPS and Dedicated.

I ran a probe, since deleted. It started from an empty database with the production seeder only, ran `operator:bootstrap`, and then created the region, datacenter, pool, subnet 203.0.113.0/24, dedicated server, BMC and a dedicated product and plan, all over HTTP. It then ran `ProvisionDedicatedHandler` on the plan's resources:

```
PROBE os_install_profiles rows: 0
PROBE result: [false,"ipam.pool_exhausted","IP pool \"p4\" has 0 allocatable address(es) left; 1 were requested."]
PROBE server status after run 1: provisioning
-- after seeding the subnet as only the simulation loader can, and resetting the chassis --
PROBE threw2: ErrorException: Undefined array key "os_install_profile_id"
```

Two things block it:
- **No address rows.** `SeedSubnetAddresses` is the only thing in `src/` that inserts into `ip_addresses`. Its only caller is `LoadReferenceTopologyForSimulation`, which refuses to run in production. `RegisterSubnet` writes the subnet and no addresses. The tree admits this itself in the docblock of `MappingChain.php`, term (e).
- **No OS install profiles.** `OsInstallProfile` is written only by factories: there is no route, command or seeder for it. `ProvisionDedicatedHandler` still does `findOrFail` on it, which is F-02's own "parent nothing can create" shape.

`CreateVpsHandler` (line 501) reserves from the same allocator, so every VPS build on an operator-built estate is refused the same way.

**Q4 (oracle).** The named rows are guarded by the two HTTP-only tests above. The residue has no oracle. AFreshDeploymentBecomesConfigurableTest stops at `LocalPlacementFeasibility`, which counts no address rows and checks no Dedicated plan. It was green in the same run where the probe was refused `ipam.pool_exhausted`.

### F-03 — DOES NOT REPRODUCE

- **Operator creation:** `php artisan operator:bootstrap` creates the first super-admin and then refuses. In the probe, that operator created a second super-admin over HTTP (201).
- **`role.manage`:** it now gates 7 routes.
- **Deployment:** it needs a planner who is not the approver. `infrastructure-admin` holds both `infrastructure.manage` and `deployment.run`, so one super-admin can approve what an infrastructure admin plans. Two Super Admins are no longer required.

Two sub-claims are still literally true, and I am not rounding them up:
- `super-admin` holds zero permission rows. This is by design through `Gate::before`, and the bootstrap now assigns it.
- 11 permissions are held by no seeded non-super role. Measured: customer.create, customer.impersonate, deployment.approve, readiness.declare, safety.allow_reimage, audit.view, security.view, settings.manage, integration.manage, feature_flag.manage, role.manage. They are now grantable through `PUT roles/{role}/permissions`, which is what the audit's defect turned on.

**Q4 (oracle).**
- **Guarded:** AFreshDeploymentCanEstablishItsFirstOperatorTest, AFreshDeploymentBecomesConfigurableTest, and `the_permissions_no_role_holds_are_grantable_rather_than_dead`, which works out the orphan permissions from the enums itself.
- **Not guarded:** nothing walks "one operator plans, the only super-admin approves". `the_first_operator_can_create_the_second_without_a_second_super_admin` creates a NOC operator and stops. That sub-claim is held only by the current role table and `ApproveDeploymentPlan`.

### F-16 — PARTIALLY REPRODUCES

**Closed half: `APP_ENV`.** `config/app.php:47` trims and lower-cases the value. A real boot with `APP_ENV=Production`, `PRODUCTION` or `' production'` throws "Refusing to run in production with fake providers configured for: payment, compute, dedicated, hosting, dns, backup".

**Reproducing half: `--env`.** The guards still compare `$app['env']` exactly, and Laravel lets the console `--env` flag override the configured value:

```
$ APP_ENV=production php artisan tinker --env=Production --execute='var_dump(app()->isProduction(), config("app.env"), app()->environment());'
bool(false)
string(10) "production"
string(10) "Production"
$ APP_ENV=production php artisan env --env=Production
 INFO The application environment is [Production].
```

No guard refused. Lower-case `--env=production` is refused. So a miscapitalised environment still disarms every guard at once, through the flag rather than the variable. It needs someone to type the flag on a production host; no shipped script does.

**Q4 (oracle).** AMiscapitalisedEnvironmentCannotDisarmTheProductionGuardsTest is a proxy oracle. Reverting `config/app.php:47` turns 7 of 27 red, so it guards that one edit. But it evaluates the config expression alone, never asks `app()->environment()` or any guard, and so cannot see `--env`. Its docblock's claim that the guards compare `config('app.env')` verbatim is false, as the output above shows. The ledger already calls this a proxy oracle; the `--env` reproduction is new.

### F-17 — DOES NOT REPRODUCE

The invitation limiter is now keyed on the acting account (`invite:account:<id>`), resolved after the account on both the invite and resend routes. Resending, and re-inviting the same address, have a 10-minute cooldown.

HTTP probe results:

| Attempt | Responses | Mails queued |
|---|---|---|
| 40 invites, junk and valid headers alternating | 201×24, 409×6, 429×10 | 24 |
| next 40, valid header only | 429×40 | 0 |
| resend the same invitation ×10 | 409 `invitation_sent_too_recently` ×10 | 1 |
| revoke and re-invite one address ×5 | 409 ×5 | 0 |

Junk headers share the account's 30-per-hour bucket instead of buying new ones.

**Q4 (oracle).** Restoring the raw-header key turns 5 of 22 red in TheInvitationLimiterCannotBeRotatedByAHeaderTest and OneAddressWaitsOutTheInvitationCooldownTest.

**Residue (not a reproduction).** The budget is per account, so total mail scales with how many accounts one person can register. That is bounded by the registration limiter (5 per 10 minutes per IP), not by any per-person limit.

### F-19 — DOES NOT REPRODUCE

All 13 `OrderStatus` cases now have a production writer:

| Writer | States |
|---|---|
| PlaceOrder | Draft, PendingPayment |
| RecordFailedPaymentOnTheOrder | PaymentFailed |
| FulfilOrderOnSettlement | Paid |
| CancelOrder | Cancelled |
| RecordRefundOnTheOrder | Refunded |
| KeepTheOrderInStepWithItsServices, via the synchronous listener MoveTheOrderWithWhatItBought | QueuedForProvisioning, Provisioning, Active, Suspended, ManualReview, ProvisioningFailed, Terminated |

`completed_at` is stamped when an order goes Active. The stock and coupon holds are released on Cancelled and Terminated, and on Refunded once no live service remains.

**Q4 (oracle).**
- **Guarded:** disabling the listener turns 6 of 47 red; removing the `completed_at` stamp turns 2 of 151 red.
- **Not guarded: `provisioning_failed`.** I changed both of its writers to produce ManualReview, so nothing in production could write the state. Everything stayed green: 345/345 across Orders, Unit/Orders, Termination, Provisioning, the hosting-ending test and the F-23 producer gate. The gate counts a read-only priority list at `KeepTheOrderInStepWithItsServices.php:147` as a producer, and went red only when that list was edited too. No test asserts an order reaching `provisioning_failed`; one test sets it directly in a fixture.

**Side note.** A full refund while an order is queued, provisioning or suspended is not a legal transition. It is logged and not recorded on the order.

### F-22 — DOES NOT REPRODUCE

Critical drift is no longer invisible on every channel: the metric leg now pages. Leg by leg:

| Leg | State now |
|---|---|
| Metric unalerted | Fixed. `ResourceDriftOpen` fires on `lynomia_resource_drift_open{severity="critical"} > 0` for 15m. The gauge counts open and acknowledged drift and is exported at zero. Critical alerts route to pagerduty-critical. |
| Log path mismatch | Fixed. Laravel writes `storage/logs/lynomia.json` (default `LOG_STACK=structured`), and Alloy's `__path__` is `/opt/lynomia/current/storage/logs/lynomia.json`. |
| Loki ruler with no rules | Fixed by removal. The validator now refuses a ruler wired to Alertmanager with no rule files mounted. |
| Listener only writes `Log::error` | Still true. |

On the last leg: the log line reaches no operator in any deployment this repo builds, because nothing installs Alloy on a control-plane host. This is declared (`lynomia_log_shipper_deployed: false`) and pinned by TheLogShipperDeclarationIsTrueTest.

`validate-monitoring.py` exits 0; its self-test passes 83/83.

**Q4 (oracle).** Three mutations, each caught:
- Paging on `lynomia_open_drift_total` instead: validator exits 1 with 3 FAIL lines.
- Re-adding a ruler with no rules mounted: validator exits 1.
- Moving the log channel's path: `CriticalDriftReachesAnOperatorTest::alloy_tails_...` goes red.

### F-31 — DOES NOT REPRODUCE

`bootstrap/app.php` now replaces the framework's TrustProxies with the platform's own. It reads `security.trusted_proxies` on each request and refuses any entry that trusts every caller.

Real-boot probe, with the balancer named only in `.env` (`TRUSTED_PROXIES=10.0.0.5`), a request from 10.0.0.5 carrying `X-Forwarded-For: 203.0.113.9`:

| Code | `ip()` |
|---|---|
| Current | 203.0.113.9 (the customer) |
| Pre-fix `env('TRUSTED_PROXIES')` line restored | 10.0.0.5 (the balancer, which is the defect) |

**Q4 (oracle).** With the pre-fix line restored, 12 of 39 go red across five tests. That includes a login answering 429 instead of 422, which is the shared bucket itself.

### Unnumbered observations (authorization and bootstrap, found in this band)

1. **Demoting a super-admin.** A non-super-admin holding `role.manage` can remove Super Admin from any super-admin except the last. `ChangeOperatorRoles` checks only the roles being granted, not the ones removed. Probe: 200. OperatorAndRoleManagementTest asserts this as a "positive control".
2. **Emptying roles.** A holder of `role.manage` can empty any non-super role, including permissions they do not hold. `SetRolePermissions` checks only additions, then replaces the whole set. Probe: C, a support operator, emptied `infrastructure-admin`: 200, remaining [].
3. **Refused invite leaves a trace.** `InviteOperator` commits the user row and an `OperatorInvited` audit entry before the role grant. When the grant is refused (422 `rbac.role_not_yours_to_grant`), both remain: users +1, audit +1.
4. **Bootstrap can take over a customer login.** `operator:bootstrap` uses `firstOrNew` by email. It would promote an existing customer login with that address to super-admin and reset its password. From reading the code; no probe was run.
5. **Stranded chassis.** When a Dedicated build fails IP reservation, the chassis is left in `provisioning`. The probe's next build was refused `dedicated.no_matching_hardware`.

### Could not establish

- COULD NOT ESTABLISH whether shared hosting can be built on an operator-created node. Local feasibility passes; I did not run the build handler against one.
- COULD NOT ESTABLISH whether the real order-driven Dedicated path releases a chassis stranded by an IP-capacity failure. The probe ran the handler with no `order_id`.
- COULD NOT ESTABLISH whether code in the grafted base commit 31a1501 was written in round two or earlier; the repository is shallow.
- COULD NOT ESTABLISH end-to-end page delivery for ResourceDriftOpen. promtool and amtool are not installed; the routing evidence is the validator's model of Alertmanager.
- PHPStan: not run.

### Green proof

- **Backend:** 1085/1085 across the band's paths. JSON summary has no `skipped` key; junit has 145 testsuites, all `skipped="0"`; ARTISAN-TEST EXIT=0.
- **Infrastructure:** `validate-monitoring.py` exits 0; `test_validate_monitoring.py` passes 83/83.
- **Cleanup:** every mutated file was restored, with sha256 matching the original (bootstrap/app.php, config/app.php, config/logging.php, RateLimitServiceProvider.php, MoveTheOrderWithWhatItBought.php, TransitionOrder.php, KeepTheOrderInStepWithItsServices.php, platform.yml, loki-config.yml, .env). The probe directory was deleted. `git status` is clean at 462382f.


#### Skeptic on F-02: confirmed — PARTIALLY_REPRODUCES

I tried to refute the claim and could not. The reproducing half is real, and it is not an artefact of the probe. I ran my own probe rather than the re-auditor's, on tree 462382f in worktree /home/user/cloud/.claude/worktrees/wf_98b6ec76-8e4-6, with DB lynomia_test_sk020 and Redis db 34. It was tests/Feature/ProbeSk020/AddressProbeTest, now deleted. It seeds RolePermissionSeeder only and makes a SuperAdmin via User::factory. Everything else is built over HTTP: POST regions, POST datacenters, POST ip-pools (public, v4), and POST ip-pools/{pool}/subnets with 203.0.113.0/24, gateway .1. It then calls IpAllocator::reserve($pool, ulid, null, 1) directly. There is no customer, no plan and no handler, so the payload is not involved. Verbatim output: `PROBE ip_addresses rows: 0` / `PROBE os_install_profiles rows: 0` / `PROBE reserve threw: Lynomia\Modules\Ipam\Domain\Exceptions\IpPoolExhaustedException: IP pool "p4" has 0 allocatable address(es) left; 1 were requested.` The run itself passed: {tests:1, passed:1}. Static checks back this up. The only insert into ip_addresses in src/ is src/Modules/Ipam/Application/Actions/SeedSubnetAddresses.php. Its only caller in src/ is src/Modules/Infrastructure/Application/Reference/LoadReferenceTopologyForSimulation.php:97. RegisterSubnet writes no address rows, which the MappingChain.php docblock, term (e) at around lines 290-297, itself admits. No route in routes/ creates addresses; the only address POSTs are adopt/release on an existing {address}. `artisan list` has no command that seeds addresses. OsInstallProfile is referenced outside src/ only in database/factories (OsInstallProfileFactory, PxeBootAuthorisationFactory). No migration inserts into it, no seeder or route writes it, and ReinstallServerRequest only validates `exists`. Yet ProvisionDedicatedHandler.php:179 does `OsInstallProfile::query()->findOrFail($payload['os_install_profile_id'])`.

Which clause reproduces and which does not. The audit's F-02 text (line 199) has three clauses. Clause 1, 'no production write path for Region, ComputeCluster, Network, IpPool, Subnet, HostingNode, DedicatedServer stock or BmcEndpoint', does NOT reproduce: my probe made Region, Datacenter, IpPool and Subnet over HTTP with 201s. Clause 2, 'three of the five writers findOrFail a parent nothing can create', does NOT reproduce for the writers the audit meant: the datacenter writer resolved its region in my probe. The OsInstallProfile findOrFail has the same shape, but it sits in a provisioning handler, not in one of the audit's five inventory writers. It therefore supports clause 3 rather than reviving clause 2 literally. Clause 3, 'the platform cannot be brought into a sellable state by an operator', DOES reproduce. A Subnet registered through the production writer is an empty address space the allocator refuses. For VPS and Dedicated that means no build can obtain an address on an operator-built estate: CreateVpsHandler and ProvisionDedicatedHandler both call IpAllocator::reserve. So this is the audit's own defect, 'the operator onboarding chain is dead', one link further down (Subnet to address rows). It is not a lesser or out-of-scope issue. The Subnet writer the audit asked for exists but yields nothing allocatable.

Question 4: nothing would notice this. AnOperatorCanBuildTheEstateFromNothingTest asserts only that the Subnet row exists. MappingChain's preflight explicitly does not check term (e). So the closed half is held by an oracle, and the reproducing half by none.

Worktree: the probe directory was removed, `git status --short` is empty, and HEAD is at 462382f5659e163886dc76a764a6cd131e017f3d. No tracked file was modified, so no sha256 restore was needed.


#### Skeptic on F-16: confirmed — PARTIALLY_REPRODUCES

I tried to refute this and could not. The re-auditor's PARTIALLY_REPRODUCES verdict stands.

Tree: 462382f5659e163886dc76a764a6cd131e017f3d, detached. The cheap check passed: tests/Support/RedisIndexForThisRun.php and migrations/2026_04_15_000004_* both exist. Environment: DB_DATABASE=lynomia_test_sk161, REDIS_PORT=6380, REDIS_DB=35.

**What the audit says (final-independent-multi-agent-audit-round-1.md:218, 59-61, 301-303).** F-16 has two clauses:
- (a) `APP_ENV=Production` (capital P) defeats the fake-provider boot guard, the reference-topology refusal and the readiness gate at the same time.
- (b) The cause: "Every production guard is an exact string comparison." The summary puts the defect class as "a single miscapitalised environment ... disarms every production guard at once."

**Closed half: the environment-variable channel.** config/app.php:47 is now `strtolower(trim((string) env('APP_ENV','production'))) ?: 'production'`. `APP_ENV=Production php artisan env` is refused by the fake-provider guard.

**Reproducing half: the console `--env` channel.** I ran each of these myself:
- `APP_ENV=production php artisan env --env=Production` printed `INFO The application environment is [Production].` with no refusal.
- `APP_ENV=production php artisan env --env=production` printed `Refusing to run in production with fake providers configured for: payment, compute, dedicated, hosting, dns, backup...`
- `APP_ENV=production php artisan tinker --env=Production --execute='var_dump(app()->isProduction(), config("app.env"), app()->environment(), getenv("APP_ENV"));'` printed `bool(false) string(10) "production" string(10) "Production" string(10) "production"`.

The mechanism is in Laravel's `EnvironmentDetector::detectConsoleEnvironment` (vendor .../Foundation/EnvironmentDetector.php:43-68). It sets `$app['env']` from `--env` and bypasses the normalised config value. Every guard reads `$app['env']` through `isProduction()` or `environment('production')` (grep, 44 sites), and none of them normalise. That covers all three surfaces the audit names:
- the fake-provider guards: ProviderRegistryServiceProvider.php:39, plus the Payments, Compute, Dedicated, SharedHosting, Registrar, Dns and Backups fakes
- the reference-topology refusal: LoadReferenceTopologyForSimulation.php:105
- the readiness gate: ProductSellability.php:145 and PreparedProductRehearsal.php:47

**Why this is the audit's defect, not a probe artefact or a lesser issue.** Clause (b) is literally still true: the comparisons are unchanged, and only one of the two inputs Laravel honours was normalised. The effect is the audit's effect: one miscapitalised environment value disarms all the guards together. The control is decisive: lower-case `--env=production` is refused, so the bypass depends only on capitalisation, not on `--env` choosing a different environment.

**Where the re-auditor's framing needs narrowing.** None of these change the verdict:
1. The trigger is now an artisan command-line flag, not an environment variable, so the literal headline trigger in clause (a) (`APP_ENV=Production`) is closed.
2. The blast radius is limited to that artisan process. That includes any long-lived worker started with the flag, such as `queue:work` or `horizon --env=Production`. HTTP/FPM requests do not parse console arguments and stay guarded.
3. No shipped script passes `--env=` except CI's `--env=testing`, which I did not re-check.

**Oracle (question 4).** The re-auditor's reading is correct: the only guard is a proxy that tests config/app.php's expression and never calls `app()->environment()` or any guard. It cannot see the `--env` channel.

**False docblock.** config/app.php:33-34 says `isProduction()` and `environment('production')` "compare this value verbatim". That is false: they compare `$app['env']`, which the tinker probe shows diverging from `config('app.env')`.

**Tree state.** I wrote no files to the tree. `git status --short` is empty and HEAD is still 462382f. The tree is clean.


## Band C

## Band C: provider contracts and the per-product paths (re-audit at `462382f`)

Tree: worktree detached at `462382f5659e163886dc76a764a6cd131e017f3d`. Cheap check passed: `tests/Support/RedisIndexForThisRun.php` and `database/migrations/2026_04_15_000004_record_the_domain_a_hosting_line_was_bought_for.php` are both present. Database `lynomia_test_rc`, Redis port 6380, index 6.

**Green baseline.** 14 band paths gave `{"tool":"phpunit","result":"passed","tests":1640,"passed":1640,"assertions":7330}` with no `skipped` key. The junit report has 158 testsuites, all `skipped="0"`. `ARTISAN-TEST EXIT=0`. Web `src/features/backups` passed 13/13.

**Mutations.** Every mutation was a single exact replacement. Each file's original bytes were restored and its sha256 compared before and after. Two probes were written as temporary test files and deleted afterwards. The worktree ends clean (`git status --porcelain` empty).

### F-04 — DOES NOT REPRODUCE
**How it is prevented now.** `CreateHostingAccountHandler` no longer fills in defaults:
- **Domain:** read through `ProvisioningJob::hostingDomain()`. A missing domain is refused Permanent with `hosting.domain_missing`, and an unusable one with `hosting.domain_unusable`. Both refusals happen before anything is placed or reserved.
- **Contact address:** taken down the chain job, then `billing_email`, then the owner's email. If every link is blank the job is refused with `hosting.contact_email_missing`.
- **Password:** minted per attempt with `Str::password(32, symbols:false)`.
- **Adapters:** both real adapters send all three values.
- **`changePassword` caller:** `ResetHostingAccountPassword` calls it, behind `POST admin hosting-accounts/{id}/password-reset`. That route needs `HostingAccountResetPassword`, which `Role.php:81` grants.

**Measurement.** Mutation m1 made the handler hand over `<username>.hosting.invalid`, password `x` and an empty contact address. Result: 15 of 241 red across `AHostingOrderReachesThePanelWithWhatItNeedsTest`, `NamingTheDomainAHostingBuildWillServeTest` and `TheHostingGoldenPathTest`. Example: `-'golden-hosting-1.example.test' +'lyn337cedp9.hosting.invalid'`.

**Question 4.** An oracle holds this. `RecordingHostingProvider` records what the panel was *handed*, driven through `POST /api/v1/orders`. The simulator on its own refuses only an empty or `[redacted]` password. It still accepts a `.invalid` domain and an empty contact address, so those two halves rest on the recording decorator and the handler.

### F-09 — PARTIALLY REPRODUCES
**The half that does not reproduce.**
- The restore is now polled on `restore_task_id`, and `restored_at` is written only when a restore task succeeds.
- Mutation m2 (poll the backup's own task) turned 7 red, including `a_restore_still_running_is_not_reported_as_completed`.
- Mutation m3 (never write `restored_at`) turned 3 red.

**The half that reproduces.** The consequence comes back through a different mechanism. `ReconcileBackup::giveUpIfOverdue` measures `max_poll_hours` (default 12) from `started_at ?? created_at`, which is when the *archive* was taken, not `restore_started_at`.

The probe used a backup taken 3 days ago, started a restore now, left the restore task running, and ran one reconcile:
```
PROBE-RESTORE state=needs_review restorable=false reason=the provider task was still unfinished after 12 hours; the platform has stopped tracking it RestoreNeedsReview=1 restore_started_at=2026-09-26T12:59:48+00:00
```
It then asked for a restore of a second completed backup of the same machine:
```
PROBE-SECOND first_row=needs_review second_restore_http=202 second_row=restoring
```

**What this means.**
- A restore that is still running is taken off the poller on its first sweep, and the customer is told it could not be confirmed, with a false reason.
- A second restore is accepted over disks the first may still be writing. `assertRestorable` only looks for rows in `restoring`.
- The original good archive can never be restored again, because `NeedsReview` has no outgoing transitions.
- This applies to any restore of a backup older than 12 hours, which in practice means nearly all of them.

**Question 4.** The wrong-task-id half is held by an oracle: `TheBackupRestoreTruthBoundaryTest` with its two-task datastore double. The reproducing half has none. Every restoring or verifying fixture uses an archive taken minutes before the operation (`BackupFactory::succeeded()` sets `started_at = now()-20min`), so the suite cannot see the clock.

### F-10 — DOES NOT REPRODUCE
**How it is prevented now.**
- `Backup::isRestorable()` returns false when `verified === false`.
- The server refuses the restore in `RestoreServiceBackup::assertRestorable`.
- The portal shows three separate states: Verified, Unreadable (in the danger colour) and Not tested. Restore is disabled with a title explaining why.

**Measurement.**

| Mutation | What it broke | Result |
|---|---|---|
| m4 | `isRestorable` ignores `verified` | `a_corrupt_archive_reads_differently_from_an_unchecked_one` red |
| m5 | server refusal removed | `the_server_refuses_to_restore_a_confirmed_corrupt_archive` red |
| w1 | portal shows `false` as "Not tested" | vitest EXIT 1, "says an unreadable backup is unreadable, and will not offer the restore" red |
| w2 | Restore never disabled | 2 red |

**Residual, deliberate.** A false verdict adopted by the inventory sweep leaves the row in `succeeded`, so the state badge still reads success. The difference shows in the Verified column and the disabled button.

**Question 4.** Oracles on both sides: `TheBackupRestoreTruthBoundaryTest` and `what-a-backup-row-says.test.tsx`. The web test supplies `is_restorable` from its own fixture, so the link between API and portal is held on the API side only.

### F-11 — DOES NOT REPRODUCE
**How it is prevented now.** The adapter finds a record with `DnsRecordIdentity`: first by the identifier the zone still knows, otherwise by the record's value, and never by `(type, name)` alone. `records()` reads every page, 100 records at a time.

**Measurement.**
- Mutation m6 made identity collapse to the first record at the same `(type, name)`. It turned 19 red, in *both* `CloudflareAdapterKeepsTheRecordContractTest` and `TheDnsSimulatorKeepsTheRecordContractTest`: round robin, backup MX, and a delete sparing its sibling.
- Mutation m7 made `records()` read only the first page. It turned 2 red, including the 250-record listing.
- Two local rows sharing one provider id is refused by the table and by `ClaimProviderRecord` (`OneRecordAtTheProviderIsOneRowHereTest`).

**Question 4.** An oracle holds this. `DnsProviderContractTestCase` runs the same suite against the Cloudflare adapter (over `CloudflareZoneSimulator`) and against the fake, and the simulator no longer shares the adapter's fault. Its limit: the simulator is this programme's own model of Cloudflare, not Cloudflare.

### F-12 — DOES NOT REPRODUCE
**How it is prevented now.** `DecommissionDedicatedServer` is the only end-of-service path for a dedicated server. Inside its transaction it calls `holdAssignmentsOf`, which:
- stamps every live assignment `released_at`;
- quarantines the address with its clock held;
- moves the PTR to Removing.

`SetReverseDns` refuses an assignment that is no longer live, both before and under the lock. Returning the chassis to stock, or retiring it, starts the quarantine clock.

**Measurement.** Mutation m8 removed the hold call. It turned 6 red, including `the_departing_customer_loses_control_of_the_ptr`.

**Question 4.** An oracle holds this: `DecommissioningGivesTheAddressBackTest`, plus the held-quarantine clock and report tests.

### F-13 — DOES NOT REPRODUCE (in-tree)
**How it is prevented now.** The Proxmox privilege map now covers `reinstall`, `templates` and `inventory_sync`. An end-to-end test drives the real tester, fed the Ansible provisioning role, to ReadyForProduction for VPS's Compute requirement.

**Measurement.** Mutation m9 dropped `templates` from the map. It turned 3 red, including `every_compute_capability_vps_requires_is_settled_by_the_privilege_map`.

**Question 4.** An oracle holds this. `ARealProxmoxClusterCanCarryVpsTest` derives the required set from `ProductRequirements` and checks it against the map, and it parses the Ansible role file. Whether the map matches real Proxmox privilege checks is under *could not establish*.

### F-14 — DOES NOT REPRODUCE
**How it is prevented now.**
- A read with no `error` field must carry at least one of the fields that command returns, or it is refused.
- `licenceStatus()` returns valid only when every status word is one it reads as serving and every expiry parses and lies in the future. Anything else is recorded as `unconfirmed` and not valid.

**Measurement.**
- Mutation m10 hardcoded `valid:true` for the licence. It turned 77 red, including `AnUnreadableDirectAdminNodeIsNotSoldTest`.
- Mutation m11 removed the read check. It turned exactly 1 red: `a_read_whose_body_names_nothing_the_command_returns_is_refused`.

**Question 4.** An oracle holds this, end to end: the node is recorded unlicensed and is not scheduled. The read-validation half rests on a single unit test.

### F-18 — DOES NOT REPRODUCE
**How it is prevented now.**
- A null `suspended_at` is no longer read as an elapsed window.
- The terminate action refuses any account that is not Suspended.
- The controller asks for `service.terminate` for anything other than an unforced Suspended or already-Terminated account.
- `EndOfService` asks for both permissions for hosting, forced or not.

**Measurement.**
- m12 (controller gate loosened): 7 red.
- m13 (action's status guard removed): 5 red.
- m14 (null `suspended_at` read as elapsed again): 1 red, `a_suspension_with_no_date_is_not_an_elapsed_window`.

**Question 4.** An oracle holds this. `TerminatingAHostingAccountTest` and `EndingAHostingAccountThroughEitherDoorTest` pin the controller gate, the action guard and the retention check separately, so each layer goes red on its own.

### F-26 — PARTIALLY REPRODUCES
**The half that does not reproduce: children.** A reserved name now covers itself, its parents and everything beneath it. Mutation m15 dropped the children direction and turned 5 red.

**The half that reproduces: the empty default.** `config/dns.php` still defaults `DNS_RESERVED_ZONES` to `''`, and `.env.example` ships `DNS_RESERVED_ZONES=`. Deriving names from `APP_URL` and `FRONTEND_URL` narrows the consequence but does not remove it. The probe used the shipped empty list, with `APP_URL=https://api.lynomia.example` and `FRONTEND_URL=https://app.lynomia.example`:
```
PROBE-F26 config_default=[] lynomia.example=refused api.lynomia.example=refused x.app.lynomia.example=refused www.lynomia.example=CLAIMABLE mail.lynomia.example=CLAIMABLE preflight="pass"
```
On the shipped localhost nothing is reserved, and preflight returns a warning, not a fail.

**Question 4.** The children half is held by an oracle: `TheReservedZonesTest` and `ClaimingAZoneTest`. The empty-default half is watched only by `ReservedZonesCheck`. That check passes when only the exact platform hosts are reserved, and its text says "everything beneath it" without ever asking whether the registrable domain is covered.

### What round two introduced or left in this band
- **`ReservedZonesCheck` passes when siblings are claimable.** The check is new in round two. It reports PASS with the text "Each covers itself, every parent of it and everything beneath it" while `www.` and `mail.` under the platform's registrable domain can be claimed. The gate checks a narrower thing than its wording promises.
- **The F-09 fix made a wrong clock reachable.** Polling the real restore task means a running restore now reaches `giveUpIfOverdue`, which measures from the archive's `started_at`. The result is the partial reproduction under F-09. The Backups code predates the rebuild, but it is round-two-accepted code. The test fixtures all use an archive taken minutes before the restore, so no test can go red.
- **F-13 widened VPS's requirement.** VPS now also needs `inventory_sync`. This is consistent and pinned, and it is one more privilege that real Proxmox has to confirm.
- **F-14's read check is thinly pinned.** One mutation turns exactly one test red.

### Unnumbered observations
- **The verification sweep can strand a good archive.** `backups:verify` moves an archive older than 12 hours to Verifying without resetting `started_at`. The first poll while verification is still running quarantines it:
  ```
  PROBE-VERIFY state=needs_review restorable=false reason=the provider task was still unfinished after 12 hours; the platform has stopped tracking it BackupNeedsReview=0
  ```
  A readable archive becomes permanently non-restorable, and the customer is not told.
- **A false reason string and a clock nothing reads.** The reason "still unfinished after %d hours" is false for any operation started less than `max_poll_hours` ago. The `RestoreServiceBackup` comment about not "restarting the clock" refers to `restore_started_at`, which nothing reads.
- **A bare `error=0` skips the read check (by reading, not probed).** In `DirectAdminHostingProvider::parse`, a read carrying `error=0` bypasses the field check. `error=0` alone for `CMD_API_SHOW_USERS` would give an empty account list, and `ReconcileHostingNodes` would then record a false Critical "missing at provider" drift for every live account on the node.

### Could not establish
- **F-13:** real Proxmox privilege semantics, including the privileges the tester's own docblock says it does not ask for (`VM.PowerMgmt` on create with `start=1`, `VM.Config.CDROM`, `SDN.Use`).
- **F-11:** whether `CloudflareZoneSimulator` matches Cloudflare on `name=` matching, the `per_page` ceiling, `PUT` semantics and `total_pages`.
- **F-14:** DirectAdmin's real `CMD_API_LICENSE` field names and state words.
- **F-04:** how the real panels (WHM, DirectAdmin) behave on the values now sent.
- **Not run:** PHPStan (not installed). Web `tsc` and `eslint` were not run; only the backups vitest was.


#### Skeptic on F-09: confirmed — PARTIALLY_REPRODUCES

I could not refute it. The band C verdict of PARTIALLY_REPRODUCES stands, and my reproduction includes a control that rules out the probe as the cause.

Tree: 462382f5659e163886dc76a764a6cd131e017f3d, detached. The brief's quick check passed: RedisIndexForThisRun.php and 2026_04_15_000004_* are both present. Environment set up with wt-setup.sh, using database lynomia_test_sk090 and Redis db 36.

The audit's text for F-09 (line 211) says: "A restore is reconciled against the backup's finished task id. restore_task_id is written and never read; restored_at never written. A running or failed restore is reported to the customer as complete — and a second restore can then start over the same disks."

**What the code does now**

- `src/Modules/Backups/Application/Actions/ReconcileBackup.php:202-216`: `taskFor()` returns `restore_task_id` for a row in Restoring.
- Line 257 of the same file: `restored_at` is written only on the success transition out of Restoring.
- So the "wrong task id" and "restored_at never written" clauses, and "reported as complete", do not reproduce. I did not repeat the re-auditor's m2/m3 mutations. The reproduction below confirms the same thing directly: with the archive 20 minutes old, the row stays in restoring and `restored_at` stays NULL.
- `ReconcileBackup.php:408-421`, `giveUpIfOverdue`:

```php
$startedAt = $backup->started_at ?? $backup->created_at;
if ($startedAt->addHours($limit)->isFuture()) return $backup;
```

  `started_at` is when the archive was taken. It is not `restore_started_at`, which `RestoreServiceBackup` line 118 does write.
- `RestoreServiceBackup::assertRestorable` (lines 253-259) only counts rows in state `restoring` as a restore in flight.
- `BackupState` line 163: `NeedsReview => []`, so a row there has no way out.

**Reproduction**

I added a temporary test, `tests/Feature/Backups/ProbeSk090Test.php`. It uses the suite's `TwoTaskDatastore`, which throws on a task id it never issued. The test has a data provider with two archive ages. The restore always starts now, the restore task (UPID:r1) is still running, and a second completed backup of the same machine exists. The test calls `ReconcileBackup::execute` once, then POSTs `/api/v1/vps/{vm}/backups/{other}/restore`. Output, verbatim:

```
PROBE age=4320min state=needs_review restorable=false reason=the provider task was still unfinished after 12 hours; the platform has stopped tracking it restored_at=NULL restore_started_at=2026-09-26T13:20:25+00:00 notif_types=["service.restore_needs_review"]
PROBE-SECOND age=4320min first=needs_review http=202 second=restoring body={"data":{...,"state":"restoring",...}}
PROBE age=20min state=restoring restorable=false reason= restored_at=NULL restore_started_at=2026-09-26T13:20:25+00:00 notif_types=[]
PROBE-SECOND age=20min first=restoring http=409 second=succeeded body={"error":{"code":"backup.restore_in_flight",...}}
```

The two runs differ only in the age of the archive. That shows the cause is the clock being read from the wrong column, not a quirk of the double or the fixture. Restoring a backup that is days old is the normal case, so the trigger is realistic.

**Clause by clause**

- **Does not reproduce:** "reported to the customer as complete". The running restore is never marked Restored, `restored_at` stays NULL, and no RestoreCompleted notification fires. The customer is told the restore needs review (`service.restore_needs_review`), not that it finished.
  - I checked that this is not the same clause in another form. The audit's defect is a false "complete". What happens now is a false "unsettled", with a reason that is untrue: "still unfinished after 12 hours", when the restore started seconds earlier.
- **Reproduces:** "and a second restore can then start over the same disks". This happens exactly: the second restore request gets HTTP 202 and the row goes to restoring while the first restore's provider task is still running on the same machine.
  - The route differs from the audit's: the first row goes to NeedsReview instead of Restored.
  - The effect is the same: `assertRestorable` no longer sees a restore in flight, because the row has left `restoring`.
  - An extra effect the audit did not describe: the source archive is left permanently non-restorable (restorable=false, and NeedsReview has no outgoing transitions).

This is a real part of F-09's described harm, not a separate or smaller issue, and it is inside F-09's scope: F-09 is about restore reconciliation and in-flight restores.

**Is it guarded by a test?** The half that is fixed is guarded by `TheBackupRestoreTruthBoundaryTest`. The half that reproduces is only prevented, where it is prevented at all, by the current code. No test covers it: every Restoring fixture in that file uses `BackupFactory::succeeded()`, which gives a recent archive. So the suite cannot tell the difference between `started_at` and `restore_started_at`.

The probe file was deleted afterwards, and `git status --short` in `/home/user/cloud/.claude/worktrees/wf_74b8ba55-7b1-3` is empty. Nothing was committed.


#### Skeptic on F-26: confirmed — PARTIALLY_REPRODUCES

I could not refute it. The PARTIALLY_REPRODUCES verdict stands, but it is narrower than the re-auditor put it, and one sub-clause of the audit no longer holds.

Audit text for F-26: the Medium list (line 232) says the guard "protects a reserved name and its parents but not its children ... and ships with an empty default". The configuration section (line 358) says "`DNS_RESERVED_ZONES` ships empty and is absent from `.env.example`".

Tree: 462382f, detached. The cheap-check files exist. Set up with wt-setup.sh using sk261, port 6380, db 37.

1) Children half: DOES NOT REPRODUCE.
- `src/Modules/Dns/Domain/ValueObjects/ReservedZones.php::protects` returns true when `$held->isWithin($name) || $name->isWithin($held)`. It checks this against the configured entries plus the names derived from the hosts of APP_URL and FRONTEND_URL.
- In my probe, with APP_URL=https://api.lynomia.example, the child x.api.lynomia.example was refused.
- tests/Unit/Dns/TheReservedZonesTest.php passed 42/42 on this tree. I did not repeat the re-auditor's m15 mutation.

2) Empty-default half: REPRODUCES as a literal fact about the code, with a narrower consequence.
- `config/dns.php` still reads `explode(',', (string) env('DNS_RESERVED_ZONES', ''))`.
- `.env.example` line 217 ships `DNS_RESERVED_ZONES=`.
- The audit's sub-clause "absent from .env.example" does NOT reproduce: the variable is now present there, with a comment telling operators to list the registrable domain.

My probe was a temporary Feature test, run once and then deleted. It sets `dns.reserved_zones` to [] and calls ConfiguredReservedZones plus ReservedZonesCheck. Verbatim output:
```
PROBE-F26 default_env=false
config_default=[]
http://localhost:8000 lynomia.example=CLAIMABLE
http://localhost:8000 api.lynomia.example=CLAIMABLE
http://localhost:8000 x.api.lynomia.example=CLAIMABLE
http://localhost:8000 www.lynomia.example=CLAIMABLE
http://localhost:8000 mail.lynomia.example=CLAIMABLE
http://localhost:8000 preflight=warning
https://api.lynomia.example lynomia.example=refused
https://api.lynomia.example api.lynomia.example=refused
https://api.lynomia.example x.api.lynomia.example=refused
https://api.lynomia.example www.lynomia.example=CLAIMABLE
https://api.lynomia.example mail.lynomia.example=CLAIMABLE
https://api.lynomia.example preflight=pass
```

Why this is the audit's defect and not something lesser or out of scope:
- The audit's complaint is that the guard ships reserving nothing. It still does: the configured default is [].
- Deriving names from the URLs only puts back the exact platform hosts and their parents and children.
- Sibling names under the platform's registrable domain (www, mail) can still be claimed by any account.
- The preflight reports `pass` in that state, with the text "Each covers itself, every parent of it and everything beneath it". Nothing checks that the registrable domain is covered.
- On the shipped localhost nothing is reserved at all, and the check only warns; it does not fail.
- This is a real reproduction, not an artefact of the probe. It uses the real config file default and the production service and check classes. The only override is `dns.reserved_zones=[]`, which is exactly what the shipped env produces, as `config_default=[]` shows.

Question 4 (what would notice if it came back):
- The children half is guarded by tests: TheReservedZonesTest, and ClaimingAZoneTest per the re-auditor.
- The empty default is watched only by ReservedZonesCheck. It warns when nothing is reserved, but it would not notice sibling exposure, and it passes when the platform hosts are derived.

Cleanup: the probe file, tests/Feature/ProbeSk261/ProbeF26Test.php, was created and deleted. `git status --short` was empty afterwards, and I made no commits.


## Band D

## Band D: provisioning, simulation and the test estate

Tree: `462382f5659e163886dc76a764a6cd131e017f3d` (detached). Cheap check passed: `tests/Support/RedisIndexForThisRun.php` and `database/migrations/2026_04_15_000004_record_the_domain_a_hosting_line_was_bought_for.php` are both present. Database `lynomia_test_rd`, Redis 6380/8. Before each mutation I took a sha256, and after each one I confirmed the file was restored to it. Two temporary probe tests were removed after use. `git status --short --untracked-files=all` is empty at the end. Nothing was committed.

### F-15 — DOES NOT REPRODUCE
**How it is prevented now.** The hypervisor id is reserved on the job row before any provider call, with `coalesce(reserved_provider_id, ?)` so the first reservation sticks. The id is derived from the idempotency key; there is no `random_int`. The name each create is sent with is recorded before it is sent. Before any attempt places or builds, it looks under the held identity on every node an earlier attempt used.

**Reproduction attempted.** I ran the simulator's own lost-answer marker through the full production path: worker, NeedsReview, operator retry, worker. Result: 1 machine, 1 create, NeedsReview.

**Mutations.**
- **Look-before-build disabled:** 46 of 411 tests went red. Among them, `an_operator_retry_of_a_create_whose_answer_was_lost_builds_no_second_machine` failed with *"the retry sent a second create … actual size 2"*.
- **`random_int` restored and the reservation made to overwrite:** 35 red, including *"Failed asserting that 68929 is identical to 86584"*. The headline double-build test stayed green under this mutation, because the look under the previously held identity still finds the first machine. The two halves of the fix defend independently.

**Q4 — held by an oracle.** `AnIndeterminateCreateIsNotRetriedIntoASecondMachineTest`, `RepointingAReservedIdentityTest`, `TheReviewListShowsTheLastAttemptsFindingTest` and `TheSimulatorsOwnLostAnswerIsNotRetriedIntoASecondMachineTest`.

**Limit.** Every one of those oracles runs against the simulator. Real Proxmox behaviour at the reserved VMID is not established.

### F-20 — PARTIALLY REPRODUCES
**The half that is closed.**
- `VpsDetailPage` now renders `vps.rebuildDataDestroyed`.
- The key exists in both `en.json` and `ar.json`.
- The API publishes the field, and a backend test pins that.
- Mutation: suppressing the sentence when the state is `failed` turns 4 of 20 red in `whether-the-disk-is-already-gone.test.tsx`.

**The half that still reproduces.** A customer whose VPS rebuild erased the disk and was settled `failed` is still told **"The rebuild did not run"**. The new red sentence, *"This rebuild has already erased the disk…"*, now appears beside it, so the card makes two contradictory statements.

The test pins the false label. `it('says the disk is gone, next to the state that says the rebuild did not run')` asserts `getByText(en.vps.reinstallState.failed)`. When I changed the label to a truthful one for the (failed, destroyed) pair, 2 of 20 went red.

The Dedicated page carries the same label. By the audit's own Dedicated benchmark the omission is closed; the sentence the audit quoted is not.

**Q4.** The rendering half is held by an oracle. The false-label half is held in place by that same oracle, which asserts current behaviour and would go red on the correction.

### F-21 — DOES NOT REPRODUCE
**How it is prevented now.**
- Create account is live only on a literal `true` from the server.
- A failed read shows an error with a request id and a retry button (`type="button"`).
- An answer that says neither open nor closed shows an "unconfirmed" error.

**Mutations.**
- Restoring `disabled={registrationClosed}`: 30 red, across `registration-that-could-not-ask.test.tsx` and the structural gate `a-control-waits-for-its-answer.test.ts`.
- Suppressing the failure alert: 5 red.

**Q4 — held by an oracle.** The rendered-page test, plus a compiler-based gate over every portal control. That gate is load-sensitive; see *Introduced*.

### F-24 — DOES NOT REPRODUCE
All three limbs the audit named are now representable:
- **Compute:** the built-unanswered marker registers the machine before throwing.
- **DNS:** records are identified by id, then by full value, not by (type, name).
- **Hosting:** the simulator refuses a blank password or the redactor's placeholder.

**Mutations.** I reverted all three limbs at once: 24 red of 1,009.
- Compute: 5, including `AnUnansweredComputeCallMayHaveLandedTest`.
- DNS: 9, including `TheDnsSimulatorKeepsTheRecordContractTest` x8.
- Hosting: 10, including `TheHostingSimulatorRefusesACredentialNobodyCanHaveMeantTest` x9.

**Q4 — held by an oracle for each limb.** Only DNS has a contract suite run against both the simulator and the real adapter (`DnsProviderContractTestCase`). The compute and hosting oracles test the simulator alone.

Residues of the same class, which are not the three named limbs, are under *Unnumbered observations*: the compute simulator overwrites a machine at an occupied VMID, and the hosting simulator never judges the domain or contact address.

### F-25 — DOES NOT REPRODUCE
**Reproduction attempted.** I added `ProductKind::WordPress`.
- `maySell(WordPress)` in production: **false**. `sellableCatalogueKinds()` returns `[]`, with no `ValueError`.
- In testing it is true, but only through the configured rehearsal, which is outside production by design.
- 2 red, both in the Feature test `ConfiguringACatalogueDoesNotMakeAnythingSellableTest`. The admin catalogue API accepted the new kind (201 instead of 422), as the enum's docblock says it would.
- The architecture test stayed green, as its own docblock says it would.

Removing the software-state branch in `maySell` turns 3 red in `PreparedProductsCannotBeSoldOnHopeTest`.

**Q4 — held by an oracle.** Two Feature tests. The architecture test pins only the kind-to-product join.

**Dependency.** Containment uses an exact `environment('production')` match, so it depends on F-16 (band B).

### F-28 — DOES NOT REPRODUCE
**How it is prevented now.** The console endpoint takes the connection's own `verifyTls`. Both factories pass the cluster row's value, and the column is `NOT NULL DEFAULT true` (measured). The global config key is read only as a fallback.

**Mutation.** Making the endpoint read the global key again: 4 red, including 2 end-to-end tests over a real TLS socket that ask whether an impostor received the token.

**Q4 — held by an oracle.** `TheConsoleSocketIsVerifiedOnItsClustersTermsTest`.

### F-29 — DOES NOT REPRODUCE
**Probe.** 51 spellings on all three roads, with production=true.
- Refused: every `inet_aton` form, the `::/8`, NAT64, 6to4 and `2001::/23` forms, zone ids, a trailing dot, U+3002 as a separator, the special-purpose blocks, and metadata and localhost names.
- `localtest.me` is refused by resolution.
- Mutation: disabling the numeric-label refusal turns 28 red.

**Q4 — held by an oracle.** `EverySpellingOfARefusedAddressIsStillThatAddressTest` and `EveryRoadToADialledAddressAsksThePolicyTest`.

The dial-without-re-asking roads are disclosed and not guarded. `5f00::/16` is accepted; see observations.

### F-30 — DOES NOT REPRODUCE
**How it is prevented now.** `Horizon::auth` requires verified email plus a capability: `provisioning.view` to read, `provisioning.retry` to write. No environment name is consulted.

**Mutation.** Not registering the callback, which falls back to the package default: 10 of 16 red.

**Q4 — held by an oracle.** The test reads the 22 routes from the live router and asserts the gate on each. It authenticates with `actingAs` only.

### F-35 — DOES NOT REPRODUCE
**How it is prevented now.** The address check runs whatever the template check found. With no pool, it fails.

**Mutation.** Restoring the early return after a passing template check: 4 red in `APreflightNeverDropsACheckSilentlyTest`.

**Q4 — held by an oracle.** A general rule that a band which does not block must hold every check its chain can emit.

**But see the observation below.** On an estate built through the operator's own routes, `mapping.network` passes while no address can be allocated.

### F-42 — REPRODUCES
**Configuration.** `vitest.config.ts` is unchanged since Phase 1c: no `testTimeout` and no `retry`. Round two added only a warm-up in `test-setup.ts`, whose own docblock says it does not make the suite immune to load.

**Reproduction.** Four parallel `npx vitest run` on 4 cores, load average 2.21 at the start:

| Run | Tests | Exit |
|---|---|---|
| 1 | 3 failed, 624 passed (627) | 1 |
| 2 | 2 failed, 625 passed | 1 |
| 3 | 1 failed, 626 passed | 1 |
| 4 | 3 failed, 624 passed | 1 |

Every failure is a timeout, never an assertion:
- `Test timed out in 5000ms`, from `a-control-waits-for-its-answer` in all four runs and from `registration-that-could-not-ask` in runs 1 and 4.
- `Unable to find role="link" and name /confirm your email address/i`, in runs 1, 2 and 4.

A single run straight afterwards was 627/627. The audit measured 3 of 4 runs red; this is 4 of 4.

**Q4 — no oracle.** Nothing holds the timeout budget.

### F-43 — DOES NOT REPRODUCE
**How it is prevented now.** `consume()` reads the stored deadline strictly and refuses at or after it, before the token check and before the atomic consume.

**Reproduction, the audit's shape under Redis.** Temporary probe: issue a permit, `travel(61s)`, consume.
- Candidate tree: `RedisStore … REFUSED`.
- With the deadline comparison disabled: `REDEEMED`, plus 6 failures and 4 errors in `tests/Feature/Console`.

**Q4 — held by an oracle.** `ConsolePermitDeadlineTest`, which uses hour-long TTLs so the driver cannot be what refuses, and `ConsolePermitConcurrencyTest`'s Redis case.

### F-44 — DOES NOT REPRODUCE
**How it is prevented now.** `freezeTime()` in `setUp`, before the fixtures are built.

**The shape is still in the three methods.** I replaced the freeze with a clock stepped across midnight at the quarantine write, and exactly the three audited methods went red:
- `-2026-10-09 +2026-10-08`
- `-2026-10-30 +2026-10-29`
- `-2026-10-03 +2026-10-02`

**Q4 — closed by an edit only.** No gate detects this shape, and round two re-introduced it in another file (below).

### What round two introduced in this band
1. **F-44's clock shape, again.** `DecommissioningGivesTheAddressBackTest`, added with F-12, has it at lines 211 and 244, with no freeze anywhere. With the midnight step at the `quarantined_until` write, both go red: `-2026-10-09 +2026-10-08`.
2. **F-21's compiler-based gate is a new load-sensitive test in the required vitest gate.** "finds the gates to check" takes 1.2 to 1.8 s alone and timed out in 4 of 4 parallel runs. It interacts directly with F-42.
3. **F-20's oracle asserts current behaviour.** It requires the false "The rebuild did not run" label to be shown.
4. **A green line that does not mean what its name says (F-35 × F-02).** `mapping.network` passes on an estate that cannot allocate an address, and `an_operator_creates_a_customer_allocatable_pool_and_its_subnet` never allocates one.

### Unnumbered observations
- **No production writer of allocatable addresses.** Registering a /24 through the admin API leaves 0 `ip_addresses` rows. `IpAllocator::reserve` then throws `IpPoolExhaustedException` ('0 allocatable address(es) left'). The handler classifies that as Capacity, so every VPS build on an operator-built estate retries forever while preflight passes. This bears on F-02 (band B) and on the VPS column of the matrix.
- **The compute simulator silently replaces a machine at an occupied VMID.** Probe: 'strangers-box' at 12345 was replaced by 'our-box'.
- **The hosting simulator judges no domain.** `DnsName::problemWith` accepts `*.invalid`, `localhost`, `.test` and `.example`.
- **`EndpointPolicy` accepts `5f00::/16`.**
- **The ledger contradicts the progress record on F-15.** Ledger row 1391 still reads F-15 `OPEN`; `round-2-rebuild-progress.md` says upheld at round 6.
- **Band-D input to the five-product matrix, not a matrix.** For VPS: F-20 is partial, and the zero-address estate blocks any real build. For Dedicated: F-44's shape is in its test file. No band-D finding blocks Shared Hosting, DNS or Backups.

### Could not establish
- Real Proxmox semantics at a reserved or occupied VMID, and whether `isMissingResource` can misread another 5xx as "nothing there".
- Whether F-42 fires on the actual CI runner.
- Whether an operator's real session, rather than `actingAs`, reaches Horizon through its `web` middleware group.
- Whether the Arabic F-20 sentence is correct.
- The audited `d89e227` tree, which is not in this repository, so F-25 could not be compared against the audited code.
- PHPStan: not run.

### Proof of green
**Backend.** 14 band paths in one invocation: `{"tool":"phpunit","result":"passed","tests":2223,"passed":2223,"assertions":38761}`. No `skipped` key. The junit log has 299 testsuites, all `skipped="0"`. `ARTISAN-TEST EXIT=0`, taken with `${PIPESTATUS[0]}`.

**Frontend.** `tsc` exit 0; `eslint --max-warnings=0` exit 0. A single `vitest run` was 627/627, exit 0, but vitest is **not** claimed green in the brief's sense because of F-42.

#### Skeptic on F-20: confirmed — PARTIALLY_REPRODUCES

I tried to refute this and could not. Tree 462382f (detached; the brief's cheap check passes: RedisIndexForThisRun.php and 2026_04_15_000004_* both exist). wt-setup ran with sk200 and Redis 6380/38.

What the audit says (line 222, and the "Customer portal findings" at l.383-386): (a) data_destroyed "is rendered on the Dedicated screen and on no VPS screen; the strings do not exist in either locale", and (b) "A customer whose VPS rebuild erased the disk is told 'The rebuild did not run'" / "after an operator verdict tells them the rebuild did not run". The benchmark is the Dedicated twin, which "renders the warning correctly".

The state is reachable on the backend. ReinstallStateMachine allows Reinstalling->Failed and NeedsReview->Failed (the operator verdict). VmReinstall::advanceTo stamps destroyed_at when it enters a destructive state, and destroyedData() is `destroyed_at !== null`. VirtualMachineResource:147 publishes 'data_destroyed' => destroyedData(). So (state=failed, data_destroyed=true) is a state that real customers can reach. It is not something only the fixtures produce.

Clause (a) is closed on the detail screen only. VpsDetailPage.tsx:204-207 renders t('vps.rebuildDataDestroyed') when data_destroyed is true. The key exists in en.json:761 and ar.json:765, and the Arabic is real Arabic. The Dedicated page (DedicatedDetailPage.tsx:147-149) has the same shape.

Clause (b) reproduces, and on two screens. I added two console.log lines to whether-the-disk-is-already-gone.test.tsx and ran it with vitest (20/20 passed):
- Detail page, card text for rebuild('failed', true): "Rebuild…State The rebuild did not run Requested Mar 01, 2026 This rebuild has already erased the disk. Whatever was on it is gone…". The false state label is still shown next to the corrective sentence. The test pins it at l.301-308 with `it('says the disk is gone, next to the state that says the rebuild did not run')`, which asserts getByText(en.vps.reinstallState.failed). The Arabic row does the same at l.322.
- The VPS list (/vps, VpsPage.tsx:118), for the same machine: the row text is "web-kw-01 … Running The rebuild did not run Open Reboot" and BODY-HAS-SENTENCE=false. On the list, a customer whose disk was erased is told only "The rebuild did not run", which is exactly what the audit quotes. The re-auditor did not raise this, and it strengthens the verdict. The test file's own comment (l.459-472) admits it ("That is F-20's own sentence still reproducible on a VPS screen … recorded here rather than fixed"), and the test at l.481-497 asserts that behaviour: the row shows reinstallState.failed and a link to the page. It does not assert that the sentence is absent.

Mitigation I weighed, which could argue the detail-page half is closed: by the audit's own benchmark, the Dedicated page shows the identical label ('failed': 'The rebuild did not run', en.json:794) next to its warning, and the audit calls that "correct". So on the detail page alone, the VPS screen matches what the audit accepted. That does not rescue the finding, for two reasons. The literal sentence "A customer whose VPS rebuild erased the disk is told 'The rebuild did not run'" is still true on both VPS screens. And on the list it is told with no correction at all. That is not an artefact of the probe: VpsPage renders only safeLabel('vps.reinstallState', state) and never reads data_destroyed. So the result is PARTIALLY REPRODUCES:
- Closed: the omission of the data_destroyed rendering and of the locale strings on the detail page.
- Reproduces: the false 'did not run' label for (failed, destroyed). On the detail page it contradicts the red sentence beside it; on the /vps list it stands alone.
- Question 4: the list and label behaviour are held in place by assertions of current behaviour (whether-the-disk-is-already-gone.test.tsx l.301-308, 316-322, 481-497), which would go red if it were corrected.

Restore: I copied the file to scratchpad/sk200-orig.tsx before editing and copied it back afterwards. The sha256 e22f45a6f9cc981462675537388b5b675ff6eece42695e220baed083307a20c5 matches the value taken before the edit. `git status --short` is empty. No backend tests were run; they were not needed, since the backend chain was read from source, including the pin in ShowVirtualMachineEndpointTest, which I did not execute.


#### Skeptic on F-42: confirmed — REPRODUCES

I tried to refute this and could not. I worked in worktree /home/user/cloud/.claude/worktrees/wf_74b8ba55-7b1-6, detached at 462382f5659e163886dc76a764a6cd131e017f3d. Both cheap-check files are present (tests/Support/RedisIndexForThisRun.php and migrations/2026_04_15_000004_record_the_domain_a_hosting_line_was_bought_for.php). wt-setup.sh ran with sk421 and REDIS_DB 39.

Specification: audit lines 466-475 and 254-256 describe a required CI gate with retries: 0 and no testTimeout or retry in vitest.config.ts. That gate was red on 3 of 4 parallel runs of one commit, with every failure a timeout and never an assertion mismatch, while each failing file passes in isolation.

Configuration: apps/web/vitest.config.ts at the candidate sets only environment=jsdom, globals, setupFiles and css:false. There is no testTimeout, no retry, and no testTimeout or retry in package.json either. CI line 296 still runs `npm run test --workspace=apps/web -- --run` on defaults. The only F-42 commit touching these files is cd99002, which warms the accessible-name query in test-setup.ts. It gives no timeout budget and nothing that would notice load.

My measurements:
- One run at load average 0.26: Test Files 90 passed, Tests 627 passed (627), exit 0, 66.49s.
- Four parallel `npx vitest run` on the 4-core box, load average 3.63 at start and 17.83 at end: run 1 was 'Tests 1 failed | 626 passed (627)'; runs 2, 3 and 4 were each 'Tests 2 failed | 625 passed (627)'. All four exited 1, so 4 of 4 were red.
- In all four runs, src/lib/__tests__/a-control-waits-for-its-answer.test.ts > 'finds the gates to check' failed with 'Error: Test timed out in 5000ms.'
- In runs 2, 3 and 4, src/app/__tests__/an-unverified-customer.test.tsx > 'is told what is missing and where to fix it' failed with 'Unable to find role="link" and name `/confirm your email address/i`'.

Refutation attempts:
(a) Is the 'Unable to find' failure an assertion mismatch? No. It is a findByRole (waitFor) timeout at line 80 of that test. Run in isolation, the same test passes in 526ms, so it is a timeout, which is the audit's class of failure.
(b) Is the suite simply slow? Both failing files pass in isolation even at load average 13.88: 118/118 passed, and 'finds the gates to check' took 1495ms against a 5000ms budget. The failures therefore depend on load, exactly as the audit says.
(c) Are these different tests from the audit's? Partly. 'finds the gates to check' was added in round two (0a424a2, the F-21 compiler-parsing oracle) and fails reliably under parallel load. That makes the failing set less random than the audit's 'differs every run'. The defect the audit describes is still there, though: a required gate that turns red under load because it runs on framework timeout defaults nobody chose. Round two has added a new CPU-heavy test near that budget, so under the same conditions the gate is red more often (4 of 4, against the audit's 3 of 4).

Clause reproduced: 'load-triggered flaky required gate; no testTimeout/retry; timeouts only; files pass in isolation'. Not reproduced: 'failing set differs every run'. One test now fails every time; the second test varies.

Question 4: nothing prevents the defect. No configured timeout budget exists and nothing detects load sensitivity.

No tree writes were made. `git status --short` is empty, HEAD is 462382f, and the worktree is clean. Logs are sk421-single.log, sk421-par1..4.log and sk421-exits.log in /tmp/claude-0/-home-user-cloud/5bfa5480-56d9-555d-92de-88823bf57839/scratchpad/.


## Band E

## Band E — independent re-audit at 462382f

Tree: detached at `462382f5659e163886dc76a764a6cd131e017f3d`. Cheap check passed: `tests/Support/RedisIndexForThisRun.php` and `database/migrations/2026_04_15_000004_record_the_domain_a_hosting_line_was_bought_for.php` are present. Database `lynomia_test_re`, Redis 6380/10.

Every mutation was applied by anchor string. I recorded sha256 before and after, confirmed a non-empty `git diff --numstat` at each run, and restored each file against the bytes taken before; every restore printed MATCH. The worktree ends clean (`git status` empty).

**One thing is left outside the worktree: the PostgreSQL database `lynomia_latest`, which my F-41 probe created. See Observations. My attempt to drop it was refused by the permission system, so the coordinator must drop it.**

### F-23 — PARTIALLY REPRODUCES
- **The half that no longer reproduces: machine states.** `EveryStateAMachineCanEnterHasAProducerTest` checks the transition targets of all 8 state machines it discovers. I added `DedicatedServerStatus::Scrapped` as a target from `available`, and the test went red: "Scrapped — a legal target from {available}".
- **The half that still reproduces: enum cases, exactly as the audit states it.**
  - I added the same case to the enum and to no transition, with en/ar strings. `tests/Architecture` then passed 215/215 (the 214 real tests plus my probe).
  - Before the strings existed, the only red was the translation gate asking for strings for a state that cannot occur.
  - The translation gate still demands copy for `InvoiceStatus::Uncollectible` and `ProvisioningJobStatus::Cancelled`, which the sibling gate documents as unreachable.
  - Enum cases outside machines are asserted nowhere except `NotificationType`. `PaymentMethodKind::BankTransfer` and `::Wallet` have zero producers, and nothing notices.
  - `RENDERED` is still a hand-written list.
- **Q4:** an oracle for machine transition targets. No oracle for enum cases.

### F-32 — DOES NOT REPRODUCE
- `HostingPackageForPlan` is now the single resolver. It filters on `is_active`, and refuses when no package, only withdrawn packages, or several packages match. A migration indexes `plan_id`.
- Removing the `is_active` filter turned `TheHostingPackageBehindAPlanIsChosenNotStumbledOnTest` red (3 failed).
- **Q4:** an oracle. That behavioural test, plus `OnlyOneResolverChoosesAHostingPackageForAPlanTest`, whose docblock states its blind spots.

### F-33 — DOES NOT REPRODUCE
- `RegisterSubnet` takes an advisory lock and refuses overlaps within a realm.
- Neutering the `overlaps()` check turned 18 of 24 tests red across `OverlappingBlocksAreRefusedTest`, `OneRealAddressReachesOneCustomerTest` and `RegisteringOverlappingBlocksIsSerialisedTest`.
- **Q4:** an oracle.

### F-34 — DOES NOT REPRODUCE
- New operator routes list, adopt and release timeout quarantines. `OperatorAction` is recorded through `ReleaseReason::CLEARED_BY_HAND` in the audit context.
- Neutering the release turned `AnOperatorCanClearATimeoutQuarantineTest` red.
- **Q4:** an oracle. `CustomerRequest`, `Migration` and `Abuse` still have zero writers, and no oracle watches them (F-23's open half).

### F-36 — DOES NOT REPRODUCE
- The command now walks `availableDrivers()`, filtered by the guard's own predicate. The schedule has an `onFailure` hook.
- Putting the fake back in the production list turned 5 of 9 tests in `ReconcilingDomainsInProductionTest` red: "domains:reconcile threw in production after settling".
- **Q4:** an oracle.

### F-37 — DOES NOT REPRODUCE
- **Stale claim.** A 15-minute lease settles a stale claim as `Indeterminate` and never releases it, so one intent still reaches the chassis once. Making `hasLapsed` return false turned `AnAbandonedPowerClaimIsAnsweredTest` red.
- **Monitoring.** `DedicatedCollector` exports the power series. Unregistering it turned `TheApplicationActuallyRegistersItsCollectorsTest` red. `DedicatedPowerIsObservableTest` stayed green, because it builds the collector directly.
- **Q4:** an oracle for both limbs.

### F-38 — PARTIALLY REPRODUCES
- **Still reproduces.** The step "OpenTofu is formatted and valid" runs `tofu validate` only in `infrastructure/tofu/environments/*/`.
  - Those directories hold only `.example` files, and every `.tf` file lives elsewhere, so `validate` parses nothing.
  - `ci.yml`'s own comment admits it: "Known, and deliberately not changed by F-38".
  - **Q4:** no oracle; a comment.
- **Fixed, each with a self-test oracle:**
  - **Inventory validator.** A synthetic host under `all.hosts` with no `safety_class` and an `ansible_password` gives EXIT=1 with both FAILs. An empty document and an empty tree also give EXIT=1.
  - **No-apply gate.** Renamed to what it reads. Literal spellings go red: `sh -c`, an env prefix, `time`, a backslash-split verb, and `ansible-playbook … && echo --check`. Variable indirection (`$T apply`) passes, and the gate's docstring discloses that.
  - **Credential and fake-provider scans.** Both now assert that their subject is non-empty.
- **Validators:** all green — inventory 76/76, runner trust 20/20, monitoring 83/83, runbooks 12/12, runbook-alerts 62/62, no-apply 61/61. ansible-lint in the CI shape: 0 failures on 126 files.

### F-39 — DOES NOT REPRODUCE
- Recount: 68 alerts defined; 51 CamelCase names cited in runbooks. The only undefined one is `MetricsQueryBudgetTest`, a test class.
- Planting `` `ResourceDriftStuck` `` in a runbook made `validate-runbook-alerts.py` exit 1. An unbackticked mention passes; the gate's docstring says only code spans count.
- **Q4:** an oracle, the gate plus its self-test.

### F-40 — DOES NOT REPRODUCE
- `AGENTS.md` and `CLAUDE.md` now state what is true.
- Planting a docblock reference to another module's `Http` turned `LayeringTest` red.
- Putting the audit's false sentence back into both files turned 3 `LayeringTest` methods red.
- **Q4:** an oracle; `LayeringTest` checks the document itself.

### F-41 — DOES NOT REPRODUCE
- **Pins.** 26 pins are forced and mirrored into `<server>`; 6 location pins yield.
- **Hostile exports.** With `CACHE_STORE`, `QUEUE_CONNECTION`, `BCRYPT_ROUNDS`, four `*_PROVIDER`, `MAIL_MAILER` and `SESSION_DRIVER` exported, `tests/Feature/Security` passed 417/417. With `DB_CONNECTION=sqlite` and `APP_ENV=production` exported, the pins and production-guard tests passed 25/25 under `vendor/bin/phpunit`.
- **Guard.** A non-test database name is refused before `migrate:fresh` runs.
- **Q4:** an oracle — `ThePhpunitPinsHoldAgainstAnExportedVariableTest` and `TheTestSuiteRefusesToDropAnythingButATestDatabaseTest`. Both were read, not mutated: the permission system refused those mutations.
- **Weakness:** the name rule is a substring match. See Observations.

### F-45 — DOES NOT REPRODUCE
- I restored the original defect in both halves: the listener puts the password in the payload, and the handler reads it back with the key refusal disabled. 12 of 23 WordPress tests went red, including value-level assertions.
- WordPress stays `Prepared`.
- **Q4:** an oracle.

### F-46 — DOES NOT REPRODUCE
- Of the audit's 16 types, 8 are now wired (4 account-security, 4 backup/restore) and 8 are deleted. `InvoiceIssued` is wired.
- A new unproduced type, and removing the `PasswordChanged` producer, each turned the gate red. The second also turned 4 behavioural tests red.
- **Q4:** an oracle.

### F-47 — DOES NOT REPRODUCE
- `RetireDedicatedServer` behind `POST admin/dedicated/{server}/retire` writes `Retired`.
- **Q4:** the oracle is **behavioural only**. Removing the write while keeping `assertCanTransition(…, Retired)` left the architecture gate green 3/3. `DecommissioningGivesTheAddressBackTest` and `DedicatedInventorySweepTest` went red.
- `retired_at` still has no production writer. This is documented and pinned.

### Introduced by round two (in band)
1. **A state gate its own worked example can escape.** The state-producer gate counts a guard call naming a state as a writer. That hides the removal of F-47's writer, which is the gate's own worked example. Latent today.
2. **A stale docblock measurement.** `check-ci-cannot-apply.py`'s docstring says 44 run steps and nine Python validators. The truth is 46 and 11, after F-39 added two steps. Nothing pins the numbers.
3. **Two gates that disagree.** The translation gate and the state gate disagree about the two excused states: one demands copy for them, the other documents them as unreachable.

### Observations (unnumbered)
- **The test-database guard's name rule is a substring match.** `TestDatabaseGuard` checks `str_contains(name, 'test')`, the same rule as `WorkerHarness`.
  - With `DB_DATABASE=lynomia_latest`, the guard let the run through. Laravel's migrate then **created** that PostgreSQL database and migrated it, and the test passed 4/4.
  - **That database still exists** (owner `lynomia`, empty schema, 71 migrations). My drop was refused by the permission system. The coordinator must drop it.
- **Two CI steps treat a grep error as a pass.** The placeholder scan and the fake-provider scan read grep's exit 2 as "no match", and `$templates` is word-split unquoted. Minor.
- **The power-claim lease can overwrite a slow call.** It settles a still-running BMC call as `Indeterminate`, and the in-flight settle then overwrites it. The action's docblock documents this choice.
- **Two payment method kinds are still dead.** `PaymentMethodKind::BankTransfer` and `::Wallet` have no producers.

### Could not establish
- `tofu validate`'s exit status on an empty directory: no tofu binary is installed.
- Mutations of `phpunit.xml` and of the guard: refused by the permission system.
- Dropping `lynomia_latest`: refused by the permission system.
- PHPStan: not run.

### Proofs
- **Band run:** 1,209/1,209 passed, no `skipped` key in the JSON, 169 testsuites all at `skipped="0"`, ARTISAN-TEST EXIT=0.
- **Architecture baseline:** 214/214.
- **Hostile-export security run:** 417/417, EXIT=0.
- **Pint:** `pint --test` passed.

The worktree is clean at the end.

#### Skeptic on F-23: confirmed — PARTIALLY_REPRODUCES

I could not refute it. F-23 partially reproduces at 462382f. I checked the tree first: RedisIndexForThisRun.php and migration 2026_04_15_000004_* are both present. Setup used slug sk230 and REDIS_DB 40.

The audit's own text for F-23 (line 225, and the line 420 paragraph) has two clauses:
(a) "The architecture suite enforces translations for states that cannot occur."
(b) The suite "asserts reachability for methods, events, capabilities, metrics and translations — and for no enum case or state-machine state."

CLOSED HALF: machine transition targets. EveryStateAMachineCanEnterHasAProducerTest discovers the machines and tokenises src/app to find what writes each transition target. Its UNPRODUCED list excuses exactly two states: InvoiceStatus::Uncollectible and ProvisioningJobStatus::Cancelled. no_excuse_outlives_the_state_it_excuses makes each excuse fail once it is no longer needed. I read the code and did not repeat the re-auditor's red mutation. So for the part of clause (b) about states a machine declares it can enter, there is now a real oracle.

OPEN HALF, clause (a), reproduced directly:
- Probe: I deleted `"uncollectible": "Written off"` from apps/web/src/i18n/locales/en.json and ran tests/Architecture/EveryStateAScreenShowsIsTranslatedTest.php. It failed with EXIT=1: "The en catalogue has no string for these states ... status.uncollectible (Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus)".
- Why that state cannot occur: the tree's own producer gate says so in UNPRODUCED ("No write-off exists: nothing moves an open invoice to uncollectible"). A grep of apps/control-plane outside tests/ finds InvoiceStatus::Uncollectible only in the enum, the machine's table and SettleInvoice::PAYABLE, which reads it. Nothing writes it.
- So the translation gate still requires en/ar copy for a state the tree itself certifies unreachable. This is the audit's clause (a) exactly, not a lesser issue.
- The gate's NOT_SHOWN list does not exempt it. EveryStateAScreenShowsIsTranslatedTest::RENDERED is still a hand-written list. Round two only requires it to include the enums a machine governs (every_enum_a_state_machine_governs_is_named_by_the_translation_gate).

OPEN HALF, clause (b) for enum cases that are not transition targets, reproduced with a sharper probe than the re-auditor's:
- A caveat on the re-auditor's probe: adding DedicatedServerStatus::Scrapped does leave tests/Architecture green, 214/214 (EXIT=0, 51 testsuites at skipped="0"). But the same probe goes RED in tests/Unit/Dedicated/DedicatedServerStateMachineTest::anything_that_is_not_retired_can_fail_and_can_be_retired ("Hardware can fail while \"scrapped\""). So "nothing notices" overstates it for that enum.
- That red comes from a domain rule about outgoing edges (every non-retired state can fail), specific to one enum. It is outside the architecture suite and is not a reachability oracle, so it does not refute clause (b).
- To remove the confound, I added `case Scrapped = 'scrapped'` to ProvisioningJobStatus, a machine-governed enum with no cases() loop in any test. It is not a transition target, and I added en/ar status.scrapped strings. `php artisan test tests/Architecture tests/Unit` then passed 1132/1132 with 20081 assertions. The JSON summary had no skipped key, all 137 testsuites were at skipped="0", and ARTISAN-TEST EXIT=0.
- An unreachable case added to a state-machine enum is therefore noticed by nothing in the architecture or unit suites. The only architecture-level reachability check for enum cases in general remains EveryNotificationTypeHasAProducerTest, which covers F-46's NotificationType only.
- A note on the re-auditor's PaymentMethodKind example: the only 'wallet' strings in production are provider and route names. A static absence overstates deadness (as the audit's own Corrections section says), so it is illustrative, not proof. Clause (b) stands on the probe above without it.

Question 4: the only oracle is EveryStateAMachineCanEnterHasAProducerTest, and it covers transition targets only. There is none for enum cases in general, none for machine-enum cases that are not transition targets, and none that stops the translation gate demanding strings for states excused as unproduced.

Restoration: I took sha256 of DedicatedServerStatus.php, ProvisioningJobStatus.php, en.json and ar.json before editing. `sha256sum -c` shows all four OK after restoring, and `git status --short` is empty. Nothing was committed. PHPStan: not run.


#### Skeptic on F-38: confirmed — PARTIALLY_REPRODUCES

I could not refute it. PARTIALLY_REPRODUCES stands, and the part that reproduces is the defect the audit itself describes.

Spec checked: the audit's F-38 text (line 250) says "Five CI/IaC gates do not test what their names claim". Its infrastructure section (lines 339-346) adds: "The OpenTofu step has never parsed a .tf file with content ... the tofu apply path and inventory-committed credentials have no sound gate at all". The audit narrows this at lines 600-602: "'The tofu step validates nothing' — rejected as stated. `tofu fmt -check -recursive` ... does walk all twelve .tf files and catches syntax; semantics are ungated." So the tofu limb, as the audit states it, is that no semantic validation of the configuration happens.

What I ran on detached 462382f5659e163886dc76a764a6cd131e017f3d (cheap check passed: RedisIndexForThisRun.php and migration 2026_04_15_000004_* both exist):
- `.github/workflows/ci.yml` lines 591-600: the step 'OpenTofu is formatted and valid' runs `tofu fmt -check -recursive infrastructure/tofu`. It then loops over `infrastructure/tofu/environments/*/`, running `tofu init -backend=false && tofu validate` in each.
- `git ls-files infrastructure/tofu/environments` lists only 4 files: backend.hcl.example and terraform.tfvars.example for production and for staging. No .tf file is tracked there, and the directories contain no module block pointing at `../..`.
- All 12 tracked .tf files are in infrastructure/tofu/ itself or under modules/dns-records and modules/proxmox-vm. No other workflow or script runs `tofu validate` on them. The only other hits are strings inside the fixtures in test_check_ci_cannot_apply.py.
- So `tofu validate` never loads a line of the configuration, and semantic errors (an undeclared variable reference, a bad module input) are ungated. This is exactly the audit's "semantics are ungated" clause, not a lesser or different issue. It is not an artefact of a probe either: it follows from the tracked-file layout and needs no binary.
- ci.yml lines 580-590 admit this in a comment: "Known, and deliberately not changed by F-38: the `validate` half of this step's name is not met."

Caveat: tofu and terraform are not installed here, so I could not measure whether validate exits 0 or non-zero in a directory with no configuration. That does not change the verdict. If it exits 0, the step passes vacuously. If it exits non-zero, the step would be red on every run (a different symptom, and the coordinator reports the suite green). Either way no semantic validation happens.

The half that does not reproduce: I re-ran the self-tests. test_validate_inventory.py printed 76/76 passed and test_check_ci_cannot_apply.py printed 61/61 passed, which supports the re-auditor's claim that the inventory (all.hosts) and no-apply limbs are closed. The re-auditor's disclosed residual on the no-apply gate, variable indirection (`T=tofu; $T apply`), is documented in the renamed step's name and comment. It stays within the partial verdict.

Question 4: the fixed limbs are held by self-test oracles that CI runs. The tofu-validate limb has no oracle, only a comment admitting the gap.

I wrote nothing: no files, no mutations. `git status --short` shows 0 lines, so the worktree is clean. Files: /home/user/cloud/.claude/worktrees/wf_e99789eb-ce1-4/.github/workflows/ci.yml, /home/user/cloud/.claude/worktrees/wf_e99789eb-ce1-4/infrastructure/tofu/.


## Band X

## Cross-cutting assignment: what round two introduced

Tree: 462382f5659e163886dc76a764a6cd131e017f3d (cheap check passed: `tests/Support/RedisIndexForThisRun.php` and `database/migrations/2026_04_15_000004_record_the_domain_a_hosting_line_was_bought_for.php` are present). Change set: `git diff c36f188b..462382f`, 480 files. Database `lynomia_test_rx`, Redis port 6380, index 12 (exported, except where a probe says it deliberately left one out). All probes were written under `apps/control-plane/tests/Feature/ProbeRx/`, which has been deleted. Every file mutated was restored and its sha256 checked against the value taken before (`loki-config.yml` 6e8d725e…, `docker-compose.monitoring.yml` 9f74865c…). `git status` is empty at the end. Nothing was committed.

No F-number is assigned below. Each item names the findings whose repairs produce it.

### I-1 — A Shared Hosting service that has ended keeps renewing (F-18 × F-19, touching F-07)

**Reproduction.** I copied `EndingAHostingAccountThroughEitherDoorTest`'s fixture: a live account bought through checkout, and an operator holding both `service.terminate` and `hosting_account.manage`. Output, verbatim:

```
PROBE before: account=active service=active order=active sub=active
PROBE hosting door: http=200 destroyed=yes
PROBE after: account=terminated service=active order=active sub=active
PROBE service door afterwards: http=202 code= service=terminated order=terminated sub=active
PROBE renewal after both doors: next_invoice_at=2026-10-26 12:54:41 considered=1 renewed=1 skipped=0 failed=0 invoices=["01m3ewgeash75zddsm2m4jm0r5"]
```

**What it shows.**
- F-18's door (`DELETE /api/admin/hosting-accounts/{id}`, forced) deletes the site at the panel and leaves the service `active`.
- F-19 derives the order's status from its services (`KeepTheOrderInStepWithItsServices`), so the order also reads `active` for a site that no longer exists.
- F-19 now sends hosting through the service door as well. Forced, that door ends an **active** hosting service: the service and the order go to `terminated`, but the subscription stays `active`.
- `RenewDueSubscriptions` then issues a renewal invoice. `RenewSubscription::undeliveredService()` skips only F-07's case (Pending plus `placement_blocked_reason`). Its docblock says "Every other state is left alone, because each has a reason to keep billing". It names Provisioning, Active, Suspended and Failed, and never Terminated.

**Question 4.** No oracle.
- `EndingAHostingAccountThroughEitherDoorTest` asserts the status code, the error code and whether the account was destroyed. It says nothing about the service, the order or the subscription.
- `ABlockedServiceIsSeenAndBilledToNobodyTest` covers only the blocked-Pending case.

Before round two the service door refused a hosting service by accident, so this is newly reachable for Shared Hosting. The VPS equivalent (a forced end of an active VPS) has the same code shape at c36f188. That half is an unnumbered observation, inferred from the code and not measured.

### I-2 — F-41 moved every run that isolated itself through `.env.testing` onto Redis index 0 (F-41 × every suite that flushes Redis)

**Reproduction.** At 462382f, with `.env.testing` holding `REDIS_PORT=6380` and `REDIS_DB=12` and neither variable exported:

```
PROBE resolve(15)=0 config.redis.default.database=0 port=6380 env(REDIS_DB)='0'
```

The same probe under the pre-round-two phpunit.xml (`git show c36f188b:…/phpunit.xml`, run with `vendor/bin/phpunit -c`):

```
PROBE resolve(15)=12 config.redis.default.database=12 env(REDIS_DB)='12'
```

**Why.** phpunit.xml now gives `REDIS_DB` a default of 0, and applies it before dotenv, so the value in `.env.testing` is ignored. `REDIS_PORT` is not in the phpunit block, so it still comes from `.env.testing`. The index and the port of one connection now come from two different sources.

**Effect.** Every checkout that relied on `.env.testing` for isolation now shares index 0 of its port with every other such checkout. The worktree setup script used in this programme writes `REDIS_DB` to `.env.testing`. `WorkerHarness` and `ConsolePermitConcurrencyTest` both `flushdb` that index before every test. Index 0 is also the application's own default (`config/database.php`), which the phpunit.xml comment admits.

**Question 4.** The oracle cannot go red for this. `ASuiteThatFlushesRedisKnowsWhichIndexItOwnsTest::both_suites_fall_back_to_their_own_index_when_nothing_names_one` asserts 15 by unsetting the variable at runtime, which a `php artisan test` run never does. In a real run, "nothing names one" means 0.

### I-3 — A misconfigured list of reserved zones is reported to customers as their own invalid input (F-26 × F-27)

**Reproduction.** With `dns.reserved_zones = ['lynomia.test', 'internal_panel.corp-secret.example']`, a customer claims the valid name `unrelated.test`:

```
PROBE status=422 body={"error":{"code":"dns.invalid_name","message":"That is not a valid DNS name.","request_id":"01M3EWDAJBSRPP9ZWRD6X53XXB"}}
```

**What it shows.**
- F-27 holds: `details` is absent, and no configuration value reaches the body.
- The platform's own misconfiguration answers as a 4xx validation error, with the same code and sentence as a genuinely malformed name. Neither the portal nor the customer can tell the two apart.

**Question 4.** No oracle. `ClaimingAZoneTest::one_entry_in_the_reserved_list_that_is_not_a_name_refuses_every_claim` asserts only `assertNotSame(201)`. Its comment calls the answer "a validation error about a name the customer did not type" and deliberately does not pin it, so the known-wrong answer stays in place.

### I-4 — Two new checks in validate-monitoring.py pass when their subject is empty (F-22, against F-38's rule)

The F-38 header in `.github/workflows/ci.yml` lists `validate-monitoring.py` among the validators "each refusing its own empty subject". Two checks F-22 added do not.

**(a) `loki_ruler_problems`.** It reads the fixed path `loki/loki-config.yml`, and `_yaml_mapping` returns `{}` for a missing file.
- Mutation: rename the file to `loki/loki.yml`, repoint the two compose lines, and put back the pre-F-22 wired-but-empty ruler (`alertmanager_url`, `/loki/rules`, no rule files).
- Result: `8 rule file(s), 77 rule(s), 62 exported by the control plane, 1 declared by a collector contract`, exit 0.
- Control: the same ruler appended at the original path gives `FAIL loki/loki-config.yml wires the ruler to http://alertmanager:9093 and mounts no rule files at /loki/rules/<tenant>/…`.

**(b) `alertmanager_model_problems`.** It returns `[]` when there is no compose service named `alertmanager`.
- Mutation step 1: set the image to `prom/alertmanager:v0.31.0`, a version the check does not model. It fires (1 problem).
- Step 2: also rename the service key to `alertmanager-primary`. It exits 0 with the unmodelled Alertmanager.

**Question 4.** Nothing notices either case; `test_validate_monitoring.py` (83/83) does not pin empty-subject behaviour for these two functions. Whether a deployment could actually run with the renamed service (Prometheus resolving `alertmanager:9093`) was not established.

### I-5 — The F-38 CI header counts one site too few (F-38 × F-39)

The header says "By sites there are twelve" and names four validators that each refuse an empty subject. `validate-runbook-alerts.py`, added by F-39 and run in the same Infrastructure job, also refuses an empty subject. Probe against an empty rules directory: `no rule files under …/rules`, exit=1. F-38 merged (94fc6c2) before F-39 (e7813f5), and the count was never updated. This is a false docblock with no oracle.

### I-6 — A measurement in the runbook-alert gate's docstring is stale (F-39 × F-22/F-37)

`validate-runbook-alerts.py` says the 59 citations it read on 2026-09-26 were exactly the code spans that league/commonmark 2.10.0 found.
- At 462382f the gate reads 61: `32 file(s), 61 citation(s) of 51 defined alert(s), 68 alert(s) in 8 rule file(s)`.
- At 853892f, where the sentence was written, it read 59: `31 file(s), 59 citation(s) of 49 defined alert(s), 66 alert(s) in 7 rule file(s)`.

The F-22/F-37 integration (07e730e) added a rule file, an alert page and two citations. It regenerated the README counts block but left the cross-check sentence as it was, so nothing shows the check was re-run for the two new citations. No oracle.

### Unnumbered observations

- **VPS termination and renewal (pre-existing).** A forced VPS termination skips both the retention window and the "still in service" check (`TerminateVpsService::execute`, `if (! $force)`), and nothing on that path touches the subscription. The c36f188 code has the same structure. Inferred from the code, not measured.
- **Gap in the F-41 guard.** `TestDatabaseGuard::DESTROYING_TRAITS` does not count `tests/Support/LeavesNothingCommitted`, which truncates every table in tearDown. Probe: `destroys(class_uses_recursive(new class { use LeavesNothingCommitted; }))` returns `false`. All three classes that use it today also use RefreshDatabase, so the guard fires for them. That protection comes only from how those classes are written; no oracle covers it.
- **Checked and holding:**
  - F-27's details engine: final and private declaration, and names in `NEVER_PUBLISHED` dropped at read time.
  - F-17's cooldown is per account, so `retry_at` says nothing about another account.
  - The payload-written-once census states its own gaps in detail.
  - `CriticalDriftReachesAnOperatorTest` works out both the Alloy path and the Laravel path from their sources.
  - The Makefile sets `SHELL := /bin/bash`, so its `set -euo pipefail` is safe.
  - The Proxmox `inventory_sync` requirement for VPS is intended and documented.
  - F-43's deadline test does not depend on the cache driver.

### Could not establish

- **COULD NOT ESTABLISH** that the diff was covered completely. It is 480 files. I examined the named interactions and sampled the new gates, CI steps, validators and modified tests. Most frontend changes and most provider-adapter changes were not examined for interactions.
- **COULD NOT ESTABLISH** the VPS half of I-1 at runtime. It is read from the code only.
- **COULD NOT ESTABLISH** whether the renamed Alertmanager service in I-4(b) would still be reachable by Prometheus. No Docker was run.
- PHPStan: not run (not installed). The full backend suite was not run, per the brief.

### Green proofs

None claimed. The artisan test runs were diagnostic probes, now deleted. The validator runs were baselines taken before mutation.

### Tree

Clean at the end: `git status --short` is empty, and HEAD is 462382f5659e163886dc76a764a6cd131e017f3d, detached.
