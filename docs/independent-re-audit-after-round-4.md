# Independent re-audit after round four

**Candidate:** `a6b583b` on `claude/session-014hzcec3ombgztszvbjlwcv-vbzsx9` — the
five round-four bands merged, each independently verified. **Brief:**
`docs/round-2-briefs/final-re-audit.md`, with the round-three overrides and
`docs/independent-re-audit-after-round-3.md` as the specification of what was
still wrong.

**Suite at the candidate:** backend 5,601 / 5,601 (JSON `tests == passed`, no
`skipped` key; junit 690 testsuites all `skipped="0"`; exit 0); vitest 648 / 648,
`tsc` and `eslint --max-warnings=0` clean; every infrastructure validator and
self-test green; `make infra-validate` 0. PHPStan could not be run: its
dependencies download from GitHub, which this environment's network policy refuses.

## How it was run

Five band re-auditors (A–E) and one for *"what round four introduced"*. Each
worked in its own worktree, database and Redis index, and measured before
reading the ledger or the progress record. Every finding or observation reported
as reproducing went to a separate skeptic told to refute it. Every probe and
mutation was restored against the bytes taken before, and every worktree ended
clean.

## SOFTWARE_CODE_COMPLETE — adjudication at `a6b583b`

**`SOFTWARE_CODE_COMPLETE = NO`.**

- **44 of 47 findings do not reproduce.** Each has the mechanism that prevents
  it and an oracle that went red under mutation. The exception is F-44, which is
  held by an edit; its shape was found again in a second file (below).
- **3 findings partially reproduce**, each confirmed by a skeptic:
  - **F-07**: a VPS plan is sold onto a pool whose only customer subnet sits on
    a network with no bridge, or on one whose bridge is later cleared. Placement
    feasibility never asks whether the address can be attached; the build fails
    `ipam.pool_exhausted` on every attempt, and the money is held in
    `needs_review`.
  - **F-09**: with overlapping reconcile sweeps, a sweep holding an earlier
    in-memory copy of a restoring backup settles the customer's *next* restore
    attempt. The compare-and-set compares the state only, not the attempt.
  - **F-23**: the whole-enum "by value" excuses for `NodeStatus` and
    `ServerState` hid four cases nothing writes.
- **Confirmed unnumbered defects:**
  - **Money:**
    - A customer's `Idempotency-Key` can take a system ledger key, so their
      renewals are never billed.
    - An upgrade paid for but never delivered is kept when the subscription
      winds up.
    - A card refund reported failed after it succeeded is dropped (latent;
      real Stripe only).
    - A VPS is built from a customer block with no gateway, and preflight
      passes it.
    - Capacity stays on the node a retry moved away from, so the other node
      is oversold.
  - **Authorization:**
    - A customer-surface API token works on `/api/admin` with operator
      authority.
    - Two oracles cannot go red for what they guard: the staff-gate test is
      blind to `withoutMiddleware`, and the domain-queue permission test uses
      a login the staff gate refuses first.
  - **Availability:** renewal-lapse and plan-change deadlock on plans and
      the wallet.
  - **Reads:** a scalar DirectAdmin `list` is read as a node with no accounts.
  - **Tests:** a quarantine test goes red at UTC midnight (F-44's shape).
  - **Wording:** several sentences, named in the digests.

The frozen statuses do not move and nothing here moves them:

```
30B.0-E                 = NOT READY
REAL_INFRA_VERIFIED     = NONE
REAL_PAYMENT_VERIFIED   = NONE
REAL_REGISTRAR_VERIFIED = NONE
REAL_HOSTING_VERIFIED   = NONE
READY_TO_SELL           = NONE
```

## Verdict per finding

| Finding | Verdict at `a6b583b` |
|---|---|
| F-01, F-05, F-06, F-08, F-27 | Do not reproduce (band A) |
| **F-07** | **Partially reproduces**: bridgeless or cleared-bridge customer network sold onto (bands A, B and X; skeptic confirmed) |
| F-02, F-03, F-16, F-17, F-19, F-22, F-31 | Do not reproduce (band B) |
| F-04, F-10, F-11, F-12, F-13, F-14, F-18, F-26 | Do not reproduce (band C) |
| **F-09** | **Partially reproduces**: stale in-memory reader across overlapping sweeps (skeptic confirmed with three processes) |
| F-15, F-20, F-21, F-24, F-25, F-28, F-29, F-30, F-35, F-42, F-43, F-44 | Do not reproduce (band D; F-44 held by an edit, its shape found in a second file) |
| **F-23** | **Partially reproduces**: by-value excuses hid unwritten `NodeStatus` and `ServerState` cases |
| F-32, F-33, F-34, F-36, F-37, F-38, F-39, F-40, F-41, F-45, F-46, F-47 | Do not reproduce (band E) |

Round three's unnumbered items and round four's own items are gone, each with an
oracle, except the residues named in the digests.

## What happens next

Round five fixes every confirmed item on `round5/<band>` branches, and each is
independently verified before it merges. A fresh re-audit follows. The appendix
holds the band and skeptic digests verbatim.

## Appendix — band and skeptic digests

### Band A @a6b583b (F-01, F-05, F-06, F-07, F-08, F-27)
Green: 761/761 (93 suites) skipped=0 exit 0.
- F-01, F-05, F-06, F-08, F-27 DO NOT REPRODUCE (oracles M2, M9, M1, M10, M11, M14, M13).
- F-07 PARTIALLY REPRODUCES: pool whose only subnet sits on a customer network with no bridge (RegisterSubnet docblock: bridge not required at registration) → checkout accepted; customerAttachableCount=0; build IpPoolExhausted (attachableOnly) → needs_review money held. Feasibility docblock "because IpAllocator reserves only from those rows" false for VPS. PlaceableEstate / inService() fixtures with network_id null assert the defect. (Same as band X F-07.)
- Round-3 items N-1, N-2, N-3, O-1, O-2, O-3, O-4, pending refund: GONE (oracled; O-1 backstop hasEnded thinly guarded — M5 only 1 red). Put-off bounded (one sweep delay).
- Minor docblock: routes/v1/billing.php says "Nothing writes to an invoice. No POST…" but defines POST {invoice}/wallet-credit.
Unnumbered:
- OA-1 deadlock (availability, money-safe): renewal lapse of sub A (part-paid upgrade): X_A → A → wallet → VoidInvoice → RestorePlanOnVoidedUpgrade → PlanCapacity::lock(small); vs same customer downgrading sibling B onto small: B → orders → invoices → plans(small) → wallet → 40P01 4/4 on "plans". Lock order text omits plans and wallet relative order; MoneyPathsTakeTheirLocksInOneOrderTest pins neither.
- OA-2 latent: lockTheInvoicesItDrawsOn locks every non-open invoice (Void/Refunded too) not only paid; Uncollectible-void cycle latent.
- OA-3 money kept for nothing (policy-level): wallet pay of an open upgrade wins the race vs immediate cancel (2/4) or operator end (3/4) → upgrade paid 27000, resize suppressed, nothing returned. Rationale "what they bought was the period it ran for" doesn't fit a suppressed upgrade.
- OA-4 refund reported failed after succeeded ignored (provider behaviour unconfirmed).
- OA-5 dashboard refunds 503 (documented).

#### Skeptic (r5sa) verdicts
- OA-1 CONFIRMED (availability, money-safe): 40P01 4/4 via real 2 processes; renewal lapse locks X→A→wallet→(void→restore)→plans(small); ApplyPlanChange locks B→orders→invoices→plans(small)→wallet. Lock-order text in WhatAnInvoiceStillHolds omits plans; MoneyPathsTakeTheirLocksInOneOrderTest pins neither plans nor wallets.
- OA-3 NARROWED to documented policy ("kept, and an operator's to refund") — only a log line surfaces it; WindUpAnEndedSubscription "Paid invoices ... what they bought was the period ... the subscription ran for" false for an upgrade never delivered. COORDINATOR RULING: an upgrade paid for but never delivered (resize suppressed because the subscription ended) is returned to the wallet on wind-up — money for nothing is not kept.
- OA-4 CONFIRMED (money, latent — real Stripe only): Stripe adapter maps charge.refund.updated with status failed on an already-succeeded refund → SettleRefundFromProvider drops it silently; platform books refunded while funds return to merchant. Also refund.failed unmapped (Unknown).
- OA-2 REFUTED as defect (Uncollectible unreachable); wording: lockTheInvoicesItDrawsOn "paid invoices" imprecise.

### Band B @a6b583b (F-02, F-03, F-16, F-17, F-19, F-22, F-31; OB-1..3)
Green 1196/1196 (159 suites) skipped=0 exit 0.
- All F-02/03/16/17/19/22/31 DO NOT REPRODUCE (oracled). OB-1/2/3 GONE (oracled).
- F-03 note: "one super-admin is enough" chain has no walking test (APlanIsApproved... uses two super-admins).
Unnumbered:
- OB5-1 AUTHZ: customer-surface API token (IssueApiToken, abilities *, bound to customer) works on /api/admin with operator authority; nothing on /api/admin checks token customer_id/abilities; promotion does not revoke. Measured: infra-admin owner minted via POST /api/v1/me/api-tokens → 200 on /api/admin/customers; customer token 403 → after POST /api/admin/operators promotion same token 200.
- OB5-2 oracle blind spot: every_admin_route_carries_the_staff_gate_after_authentication reads gatherMiddleware(); ->withoutMiddleware('staff') passes. Mutation on audit.index: all green, customer with audit.view got 200.
- OB5-3 = F-07 A×D (bridgeless network; RegisterSubnet docblock says vps.network_not_attachable but now ipam.pool_exhausted).
- OB5-4 minor: customer on /api/admin 403 x120 then 429 (throttle before staff?). Unestablished.
Probes: scratchpad/ZzR5b*.php, r5b_*.sh.

### Band C @a6b583b (F-04, F-09..F-14, F-18, F-26)
Green: 1629/1629 (164 suites) skipped=0 exit 0; web backups 15/15.
- F-09 PARTIALLY REPRODUCES (stale in-memory reader): ReconcileRunningBackups loads up to 200 rows then polls one by one; Backup::compareAndSet compares only `state`, so a row that went Restoring→Restored→Restoring meanwhile passes. 3 real processes: sweep A loaded row on T1; sweep B settled it restored; restore C started T2; A then writes Restored with task T2 never polled; machine released; third restore accepted; RestoreCompleted ×2. If T1 failed → writes Succeeded + RestoreFailed. Precondition: overlapping sweeps (withoutOverlapping(10) on 5-min cadence; 200 rows × up to 60s; manual run). Docblocks false: RestoreServiceBackup "no reader ever sees restoring beside a previous attempt's handle"; ReconcileBackup::execute "another worker already settled ... not written over". Fix: CAS on the attempt too (restore_started_at/restore_task_id).
- F-26 DOES NOT REPRODUCE in production (oracled). Reservation: exactly-listed hosts → warning, siblings claimable (disclosed).
- F-10 DOES NOT REPRODUCE incl. file surfaces; gap: browse/download/fetch no own test.
- F-04, F-11, F-12, F-13, F-14, F-18 DO NOT REPRODUCE (oracles).
Round-3 C items: deletion under running file restore GONE (m4, m5), residue: RestoreBackupFiles archive-row lockForUpdate removal 0 red (edit-held); file restore unreadable GONE; DirectAdmin active-no-expiry valid (by design); DirectAdmin `list=` etc. read as 0 accounts → false missing drift for every live account (round-two residue, still present).
Could not establish: real providers; sweep overlap frequency; concurrent deletion race with lock removed; real production boot; PHPStan; web tsc/eslint.

#### Skeptic (r5sc) verdicts
- F-09 CONFIRMED, PARTIALLY REPRODUCES (overlapping sweeps): 3-process race; stale sweep A polls T1 from in-memory copy; Backup::compareAndSet compares only state → settles T2 attempt as restored/failed, RestoreCompleted keyed to T2, third restore accepted, T2 orphaned. Sentences falsified: RestoreServiceBackup "no reader ever sees restoring beside a previous attempt's handle"; ReconcileBackup::execute "not written over"; restoreAttributesFor; NoOneMovesABackupOnAStaleReadTest docblock. Q2 yes: CAS must compare the attempt (restore_task_id / restore_started_at). Reachable: withoutOverlapping(10) expires with ~10 60s timeouts; manual backups:reconcile not Isolatable. Racer script scratchpad/r5sc/racer.php.
- DirectAdmin list: empty forms (list=, list[]=, list) = empty node, not a defect. SURVIVES: scalar list=bob / list=alice,bob read as 0 accounts (should be refused). Harm: false critical drift + alert only. F-14 class, unnumbered, wording/operability.
- RestoreBackupFiles archive-row lock: NARROWED — ordering "deletion first" distinguishes lock (without lock: delete_requested AND file restore running; DeleteBackupAtProvider backstop holds). Oracle owed (uncommitted), not blocking; severity data low.

### Band D @a6b583b (F-15, F-20, F-21, F-24, F-25, F-28..F-30, F-35, F-42..F-44)
Green: 1815/1815 (250 suites) + 1225/1225 (150 suites) skipped=0 exit 0; vitest 2 parallel 648/648 each (load 2.15→11.71).
All twelve DO NOT REPRODUCE (oracles; F-44 edit-only, see OD5-2). OD-1, OD-2 (for rowed nodes), OD-3 GONE (oracled). Hosting simulator still accepts .local/.internal/.home.arpa/.onion/.alt (oracle gap).
Unnumbered:
- OD5-1 (F-35/F-02 class, lifecycle): customer block registered with no gateway (RegisterSubnetRequest 'gateway' nullable; round-4 rule checks only network) → 201, mapping.network pass, VPS built with ip=203.0.113.1/29,gw= (empty gateway) and .1 handed out as a host; ReinstallVpsHandler also sends gateway. Real Proxmox behaviour on gw= not established.
- OD5-2 (F-44 shape): tests/Feature/Ipam/HeldQuarantineClockTest.php the_clock_starts_for_the_holder_named_and_nobody_else (~l100) and an_abuse_hold_gets_the_abuse_window_when_its_clock_starts (~l250) unfrozen; midnight-step probe turns both red. No gate for the shape.
- OD5-3 (capacity drift, pre-existing Phase 3, on round-4 path): transient refusal not compensated → reservation stays live on node A; next attempt placed on node B; ReserveNodeCapacity returns the live row on A, handler ignores it and builds on B → B uncharged (can be oversold), A over-committed until destroy; no reconciler. No oracle. Probe at scratchpad/r5d/ProbeR5dCapacityTest.php.
Could not establish: real Proxmox occupied VMID/getVm; gw= empty; F-42 on CI/>2 runs; adopted machine's reservation node; PHPStan.

#### Skeptic (r5sd) verdicts
- OD5-1 CONFIRMED narrowed: gateway-less customer subnet is legitimate (dedicated uses profile default); .1 as host is fine. Survives: VPS path (CreateVpsHandler:617, ReinstallVpsHandler:290-295) sends gw= empty; preflight says mapping.network pass; no subnet update route. F-35 class (preflight green for estate that cannot deliver), money. Fix: refuse at checkout/feasibility/preflight for VPS when no gateway (dedicated-like), or omit gw.
- OD5-2 CONFIRMED: HeldQuarantineClockTest ~l100/~l250 compare now()->addDays at assert time with value written earlier; red across midnight (STEP/RUN probes). F-44 class, flaky gate. Fix: freeze time.
- OD5-3 CONFIRMED + oversell: transient refusal on pve-01 keeps reservation; retry re-placed on pve-02, ReserveNodeCapacity returns pve-01 row, handler ignores it (CreateVpsHandler:477) builds on pve-02 → counters wrong, node B oversold (8192 on 6000). Release by key goes to pve-01. scheduleRetry sentence "addresses and capacity this attempt reserved are what the next attempt will use" false. Money/data. Unnumbered (sits on F-15 retry path). Fix: build on the reserved node, or release+re-reserve when placement moves.

### Band E @a6b583b
- F-32, F-33, F-34, F-36, F-37, F-39, F-40, F-41, F-45, F-46, F-47 DO NOT REPRODUCE; each oracle measured red under its mutation (band E runs f32 … f47 all `failed` under mutation, band run green).
- F-38 closed (DOES NOT REPRODUCE).
- F-23 PARTIAL: whole-enum "by value" excuses for NodeStatus and ServerState hid unwritten cases (NodeStatus Offline; ServerState Discovered/Connected/Retired).
- COORDINATOR FIX 6568d78: whole-enum excuses removed; per-case entries (Active/Draining spelled at ChangeComputeNodeStatus; Offline, Discovered, Connected, Retired unwritten). Mutation: deleting each of 5 entries → gate red; restored by sha256. tests/Architecture 273/273. Needs independent verifier.

### What round four introduced @a6b583b (88c4dd8..a6b583b)
Full suite: 5601/5601, 690 suites skipped=0, exit 0.
- F-07 PARTIALLY REPRODUCES (A×D): LocalPlacementFeasibility::holdsAHostAddress() accepts any active IPv4 subnet with a non-unavailable address, never asks the network; CreateVpsHandler reserves attachableOnly → pool whose only subnet has no network / bridgeless / management network is sold and paid, build fails ipam.pool_exhausted every attempt → needs_review, money held. No oracle; positive controls (Subnet::factory network_id null) in MoneyDoesNotMove...::a_vps_plan_whose_placement_resolves_is_accepted/inService(), tests/Support/PlaceableEstate.php, OrderToProvisionedServiceTest::setUp assert the defect. Fix: feasibility uses the same attachable rule (customerAttachableCount counting reserved/assigned).
- OX-1 (money, customer-reachable): PayInvoiceFromWallet passes the customer's Idempotency-Key raw into the wallet ledger key space; ReturnWhatAnInvoiceStillHolds uses predictable keys invoice:<id>:<purpose>:<amount> (lapsed-upgrade, subscription-ended, withdrawn-refund-failed); customer pays 1.000 with Idempotency-Key invoice:<upgradeId>:lapsed-upgrade:1000 → every renewal throws IdempotencyKeyConflictException → never renewed/billed, service runs. Predates round 4 for lapsed-upgrade (by reading); extended to two more paths. Fix: namespace customer keys or reserve system prefix.
- OX-2 (test shape, B×everything): TheDomainQueuesAnOperatorWorksTest::a_person_without_the_permission_cannot_read_either_queue uses a no-role login → refused by staff gate before permission → domains.index/operations permission removed or weakened (ServiceViewAny→CatalogView) stays green. Fix: StaffHoldingExactly there.
- Minor: lockTheInvoicesItDrawsOn docblock "only a paid-for one" locks every non-Open (safety relies on Draft never committed, Uncollectible no writer); toTheWallet() returns heldMinor even on ledger replay; wind-up unlocked read can miss an upgrade invoice committed before the subscription lock (backstop: hasEnded refusal); dashboard refunds 503 ~3 days (disclosed).
Swept & holding: savepoint technique (oracled), put-off (oracled), lock order by reading (no cycle), money arithmetic by hand, staff gate + Horizon + customer role + bootstrap, capacity index/release.
Could not establish: real Stripe/Proxmox; key collision at 88c4dd8 measured; F-07 end-to-end in one run; frontend/Ansible; DNS/backups probes; PHPStan.

#### Skeptic (r5sx) verdicts
- F-07 CONFIRMED PARTIALLY REPRODUCES (money): through routes — network with no bridge, or PUT bridge=null after subnet exists → paid, service failed, needs_review, money held. Management network/pool refuted. Readiness gate not a backstop.
- OX-1 CONFIRMED over HTTP (money, blocking-grade): colliding key → renewals never issued, service active free indefinitely; key fully predictable; per-wallet (no cross-customer). Same key-space pattern: subscription-ended, withdrawn-refund-failed, cancelled-order (self-harm).
- OX-2 CONFIRMED NARROWED (test gap on authorization control): weakening domains routes' permission caught by nothing.
