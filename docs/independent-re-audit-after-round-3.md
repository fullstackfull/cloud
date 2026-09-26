# Independent re-audit after round three

**Candidate:** `88c4dd8` on `claude/session-014hzcec3ombgztszvbjlwcv-vbzsx9` — round
three's ten groups merged, each independently verified. **Brief:**
`docs/round-2-briefs/final-re-audit.md` with round-three overrides (candidate,
environment, the previous re-audit as the specification of what was still wrong).

**Suite at the candidate:** backend 5,507 / 5,507 (JSON `tests == passed`, no
`skipped` key; junit 674 testsuites all `skipped="0"`; exit 0); vitest 642 / 642,
`tsc` and `eslint --max-warnings=0` clean; every infrastructure validator and
self-test green; `make infra-validate` 0. PHPStan could not be run: its
dependencies download from GitHub, which this environment's network policy refuses.

## How it was run

Five band re-auditors (A–E) and one for *"what round three introduced"*, each in
its own worktree, database and Redis index, measuring before reading the ledger or
the progress record. Every finding or observation reported as reproducing went to
a separate skeptic told to refute it. Every probe and mutation was restored against
the bytes taken before; every worktree ended clean.

## SOFTWARE_CODE_COMPLETE — adjudication at `88c4dd8`

**`SOFTWARE_CODE_COMPLETE = NO`.**

- **42 of 47 findings do not reproduce**, each with the mechanism that prevents it
  and, for all but F-44 (closed by an edit, no gate for its shape), an oracle that
  went red under mutation.
- **5 findings partially reproduce**, each confirmed by a skeptic:
  **F-07** (checkout accepts a VPS plan whose cluster has no node in service or
  whose pool has no subnet), **F-09** (a second restore of the same archive polls
  the previous restore's task and releases the machine while the new restore
  writes), **F-23** (two states nothing writes pass the enum gate through
  self-references; a by-value excuse hides `CustomerStatus::Closed`), **F-26** (the
  empty reserved-zone default still accepts claims in production; the preflight
  watches, nothing guards), **F-38** (Ansible's `*_pass` credential variables pass
  the inventory validator whose first sentence claims to stop committed
  credentials).
- **Confirmed unnumbered defects**, several introduced by round three where two
  repairs meet — money: a cancelled or ended subscription's open invoices stay
  payable (the F-05 class on the subscription seam); a downgrade credit is invisible
  to the card-refund guard (117.000 returned of 90.000 paid, operator-reached); a
  renewal lapse returns money a refund in flight also returns; a pending card refund
  never resolves; a discounted period is credited at list price. Authorization: a
  delegate holding `role.manage` can give the customer role operator permissions,
  after which every customer login reads the operator and customer lists; the first
  operator bootstrap races into two super-admins. Availability: a refund and a
  settlement of the same capture deadlock. Provisioning: an operator retry of a VPS
  create after its automatic retries crashes on a unique key; a stranger at the
  derived VMID on another node is a dead end.

The frozen statuses do not move and nothing here moves them:

```
30B.0-E                 = NOT READY
REAL_INFRA_VERIFIED     = NONE
REAL_PAYMENT_VERIFIED   = NONE
REAL_REGISTRAR_VERIFIED = NONE
REAL_HOSTING_VERIFIED   = NONE
READY_TO_SELL           = NONE
```

WordPress remains `Prepared`.

## Verdict per finding

| Finding | Verdict at `88c4dd8` |
|---|---|
| F-01, F-05, F-06, F-08, F-27 | Do not reproduce (band A; oracles named in the band report) |
| **F-07** | **Partially reproduces** — (c) confirmed; (e) refuted as F-07 by the skeptic |
| F-02, F-03, F-16, F-17, F-19, F-22, F-31 | Do not reproduce (band B; F-02 and F-03 spot-checked by the skeptic) |
| F-04, F-10, F-11, F-12, F-13, F-14, F-18 | Do not reproduce (band C) |
| **F-09** | **Partially reproduces** — clock half closed; stale restore handle reproduces across processes |
| **F-26** | **Partially reproduces** — children half closed; empty default accepts claims in production |
| F-15, F-20, F-21, F-24, F-25, F-28, F-29, F-30, F-35, F-42, F-43, F-44 | Do not reproduce (band D; F-44 held by an edit only) |
| **F-23** | **Partially reproduces** — confirmed; the `Frozen` probe refuted as a disclaimed reservation |
| **F-38** | **Partially reproduces** — tofu, scan and empty-subject limbs closed; the credential limb reproduces |
| F-32, F-33, F-34, F-36, F-37, F-39, F-40, F-41, F-45, F-46, F-47 | Do not reproduce (band E) |

The previous re-audit's 28 round-two items: all gone except the shapes noted in
the band reports (the Dedicated "capacity wait" sentence; `mapping.network` passing
through the network term; F-13's deliberate `inventory_sync` requirement; the F-37
lease overwrite, deliberate and documented; the F-17 residue).

## What happens next

Round four fixes every confirmed item above, on `round4/<band>` branches, each
independently verified before merge; then a fresh re-audit. The appendix holds the
band and skeptic digests verbatim.

## Appendix — band and skeptic digests

### Band A @88c4dd8 (F-01, F-05, F-06, F-07, F-08, F-27)
Green: 888/888 (111 suites) + 689/689 (106 suites), skipped=0, exit 0.
- F-01 DOES NOT REPRODUCE. Oracles: invoice_outstanding (2 red), ceilCredit (11), resize-from-paid (1), outer tx (5). Multi-process pay-vs-renewal and pay-vs-void races consistent.
- F-05 DOES NOT REPRODUCE (orders). Multi-process wallet pay vs cancel, 8 rounds; oracle hasTakenMoney (2 red). NOT covering subscription seam (O-1).
- F-06 DOES NOT REPRODUCE. Multi-process last-unit checkout vs plan change 10 rounds; oracle PlanCapacity::lock (5 red).
- F-07 PARTIALLY REPRODUCES: (c) VPS on active cluster with ZERO compute nodes + pool with zero addresses → checkout accepted, paid, service failed/needs_review, order manual_review; (e) Dedicated plan whose only machine is in maintenance → same. No oracle. Dedicated docblock "a capacity wait is the right outcome" inaccurate (3 attempts → needs_review). Gone: zero-node hosting, offline cluster/inactive pool VPS, FAILED renewal.
- F-08 DOES NOT REPRODUCE. Oracles EveryQueueOutlivesItsLongestJobTest etc.
- F-27 DOES NOT REPRODUCE. Oracles publishedContext (10 red), ACustomerRouteNeverSpeaksTheEngineersSentenceTest.
Round-two [A] items: all gone except feasibility docblock (Dedicated sentence). Observations: F-08 guard inert under APP_ENV=testing — still present; throttle names gone; units gone.
Unnumbered:
- O-1 cancelled subscription's open invoices (upgrade proration, renewal) stay payable: CancelSubscription voids nothing; PayInvoiceFromWallet/StartInvoicePayment don't check subscription; paid → resize queued on cancelled sub (P4, P5, P11). F-05 class on subscription seam.
- O-2 downgrade credit (Adjustment, no invoice_id) not seen by WhatAnInvoiceStillHolds → card refund of 90000 accepted after 27000 credit: 117000 returned of 90000 paid (operator-reached).
- O-3 credit at list price: discounted period over-credited (30000 vs ~15000 value); upgrade from period that collected 0 credited full remainder; refunded upgrade counted as paid → credit after full refund. Bounded per period.
- O-4 RestorePlanOnVoidedUpgrade docblock false: ended-subscription void restores plan and audits plan_changed.
Could not establish: resize on suspended machine of cancelled sub; real queue latency; real Proxmox zero-node cluster; exhaustive search of Adjustment credits; PHPStan.

#### Skeptic (r4sa) verdicts
- F-07 PARTIALLY REPRODUCES narrowed to (c): VPS on active cluster whose nodes are all non-serving (0 nodes, or only maintenance — SyncClusterInventory creates nodes in Maintenance until a person activates) and/or pool with 0 subnets → accepted, paid, needs_review, money held. Configuration fact from operator rows (analogue of hosting nodes refused at sale). LocalPlacementFeasibility::compute() reads neither compute_nodes nor subnets. MoneyDoesNotMove...::a_vps_plan_whose_placement_resolves_is_accepted uses zero-node cluster/zero-subnet pool as positive control → asserts defect. (e) Dedicated machine in maintenance REFUTED as F-07 (capacity side, documented); wording residual "a capacity wait is the right outcome" overstated (3 attempts then needs_review).
- O-1 CONFIRMED (F-05 class, subscription seam, money): immediate cancel leaves upgrade/renewal invoices open and payable by wallet and card; resize queued on cancelled sub's suspended service.
- O-2 CONFIRMED (money, operator): downgrade credit Adjustment without invoice_id; WhatAnInvoiceStillHolds reads 90000; IssueRefund 90000 accepted → 117000 of 90000. Docblock "by any route" fails Q1; link exists via MoneyCollectedForThePeriod.
- O-3 NARROWED: (a) discounted period over-credited at list price (27000 vs 15000 unused paid value) + false rationale in ChangeSubscriptionPlan::prorationLine → discount the credit. (b)(c) within documented "money, not time" design.
- O-4 CONFIRMED wording/audit only.

### Band B @88c4dd8 (F-02, F-03, F-16, F-17, F-19, F-22, F-31)
Green: 1846/1846 (229 suites) skipped=0 exit 0.
All seven DO NOT REPRODUCE, each with a mutation-proven oracle:
- F-02: own HTTP-only probe from empty DB: VPS built; Dedicated built; pool empty → refused ipam.pool_exhausted and chassis released; subnet cap /15 refused, /16 ok, held /14 blocks inner /24; route permissions correct. Oracles: subnet expansion off → 7 red; chassis release off → 3 red.
- F-03: bootstrap + one super-admin approves infra-admin's plan works. 11 permissions held by no seeded non-super role (grantable). Sub-claim "one super-admin approves" held only by role table (no test walks it).
- F-16: real boot with APP_ENV=production refuses every --env variant; oracle AnEnvFlagCannotDisarm... (12/22 red when hook removed).
- F-17: oracle (4/20 red). Residue: invitation budget per account, unchanged.
- F-19: producer gate now red for ProvisioningFailed (1/385; no behavioural test drives an order into provisioning_failed); refund recorded (oracle).
- F-22, F-31: oracles.
Round-two [B] items: all gone. F-17 residue still present.
Unnumbered:
- OB-1 AUTHORIZATION: customer role editable via PUT /api/admin/roles/customer/permissions. A delegate (support + role.manage + catalog.view) set customer role to its own permissions: 200; a plain customer login then GET /api/admin/operators 200, GET /api/admin/customers 200. Super-admin also unrefused. ChangeOperatorRolesRequest refuses to hand out `customer` for this reason; permission path does it. Needs a delegate (none seeded).
- OB-2 bootstrap race: operator:bootstrap counts super-admins outside lock/transaction; two concurrent runs → 2 super-admins in 4/6 rounds; contradicts "single use, by state" docblock. Needs host console.
- OB-3 (not new) ProviderRegistryServiceProvider::productionIsAnUnconfiguredDefault ignores cached config: config:cache with no .env/APP_ENV caches env=production + fake providers; at runtime boot does not refuse; mitigated: FakePaymentProvider still throws FakeProviderInProductionException.
- Noted: paid Dedicated order when the only chassis is already sold → manual_review (documented policy; overlaps band A F-07(e)). Node status change takes no row lock; audit `from` read outside tx.
Could not establish: real adapters with operator-entered credentials; ResourceDriftOpen end-to-end paging; RegisterSubnet/invite concurrency under contention; PHPStan.

#### Skeptic (r4sb) verdicts
- F-02, F-03 DO NOT REPRODUCE (refutation failed; AnOperatorCanBuildTheEstateFromNothingTest oracle).
- OB-1 CONFIRMED, F-03 class authorization, HIGH: delegate (support+role.manage+catalog.view — a supported configuration per SetRolePermissions docblock) PUT /api/admin/roles/customer/permissions → 200; brand-new customer login GET /api/admin/operators 200, /api/admin/customers 200. No staff gate on /api/admin (route docblock says per-route permissions are the only separation). SetRolePermissions refuses only super-admin; RoleController advertises customer permissions_are_editable=true. Fix: protect the baseline customer role (and/or require operator-ness on /api/admin).
- OB-2 CONFIRMED, locking/authorization, MODERATE: BootstrapFirstOperator count at line ~85 outside tx, no lock, no uniqueness → 2 super-admins; docblock "Single use, by state" false. Fix: advisory lock / serializable.
- OB-3 CONFIRMED, F-16 class, LOW: ProviderRegistryServiceProvider::productionIsAnUnconfiguredDefault ignores configurationIsCached() (Settle's nothingWasConfigured checks it) → session-enumerable and driver-exists checks stand down; fakes still throw at construction. Fix: align predicate.

### Band C @88c4dd8 (F-04, F-09..F-14, F-18, F-26)
Green: 1848/1848 (194 suites) skipped=0 exit 0; web backups vitest 13/13.
- F-09 PARTIALLY REPRODUCES (new mechanism): RestoreServiceBackup moves row to Restoring without clearing restore_task_id and writes the new id only after startRestore returns → on a second restore of the same archive ReconcileBackup::taskFor() polls the PREVIOUS finished task → row Restored, machine released while new restore writes; cross-process reproduced (backups:reconcile in provider-call window); crash between transition and handle write → deterministic; notification key from stale task id dedups the new message; second restore over same disks accepted (PROBE-THIRD 202). Clock half, NeedsReview hold, CAS, per-machine lock all oracled (real-process race 12/12). Stale-handle half no oracle.
- F-10 DOES NOT REPRODUCE on audited surface; sibling: file-level restore of verified=false archive accepted (202); FileLevelSupport checks only state; portal Files button enabled.
- F-04, F-11, F-12, F-13, F-14, F-18 DO NOT REPRODUCE (oracles).
- F-26 PARTIALLY REPRODUCES: empty DNS_RESERVED_ZONES default still ships; ClaimZone never consults preflight → production estate accepts www./mail. claims; preflight(prod)=blocked is a watcher not a guard. Children half oracled.
[C] items: ReservedZonesCheck pass gone; F-13 inventory_sync still present (deliberate); F-14 one-test gone; F-09 clock gone; verify sweep strand gone; false reason gone; DirectAdmin bare error=0 gone (residue: `list=` accepted as empty list); I-3 gone.
Unnumbered: (1) ReconcileBackup/RestoreServiceBackup docblocks false for second restore; (2) deleting the source of a running file restore accepted (RequestBackupDeletion, retention don't look at BackupFileRestore); (3) file restore of verified=false; (4) DirectAdmin licence active without expiry reads valid (by design, unverified).
Could not establish: real Proxmox/Cloudflare/DirectAdmin semantics; real startRestore duration; PHPStan; web tsc/eslint.

### Band D @88c4dd8 (F-15, F-20, F-21, F-24, F-25, F-28..F-30, F-35, F-42..F-44)
Green: 2426/2426 (332 suites) + 501/501 (69 suites), skipped=0, exit 0; vitest 642/642 6/6 runs, tsc, eslint.
All twelve DO NOT REPRODUCE, each with an oracle (F-44 held by edit only — no gate for the shape). F-42 measured under 2 parallel vitest at loads up to 14.5: 642/642; counterfactual old 5s budgets fail.
[D] items: 20 gone (edit only); 21 gone; 22 gone; 23 instance gone, shape remains: subnet route accepts network_id null → mapping.network passes "5 address(es) a customer machine can be given" while the VPS build fails Permanent (subnet names no customer-attachable network with a bridge); docblock's "what a pass does not say" omits this.
Last-round observations: all gone; hosting simulator still accepts .local/.internal/.home.arpa/.onion/.alt (oracle gap, not live).
Unnumbered:
- OD-1 VPS create cannot recover once automatic retries on a transient failure run out: ReserveNodeCapacity looks up existing reservation whereNull('released_at'), but node_capacity_reservations.reservation_key is unique across released rows; NeedsReview releases the reservation; operator retry (POST /api/admin/provisioning/jobs/{id}/retry → 200) → SQLSTATE 23505 unique violation → needs_review again. Old code, on F-15's operator-retry path; no test.
- OD-2 stranger at our derived VMID on another node is a dead end (F-15 × R3-10): whatIsAlreadyThere looks only at nodes this job was placed on; simulator (like Proxmox) refuses an occupied id cluster-wide → 3 transient failures → needs_review; repoint 409 provisioning.repoint_no_identity_finding; retry hits OD-1. Handler docblock promises "a stranger at the id is a finding an operator can act on rather than a dead end".
- OD-3 = item 23 shape (network term).
Could not establish: real Proxmox at occupied VMID across nodes; F-42 at 4 parallel runs/CI; Horizon real cookie; PHPStan.

### Band E @88c4dd8 (F-23, F-32..F-34, F-36..F-41, F-45..F-47)
Green: 2510/2510 (330 suites) skipped=0 exit 0; every validator & self-test green; CI tofu step as written passes.
- F-23 PARTIALLY REPRODUCES: (1) DomainState::TransferredAway has no production writer but gate counts self-references in DomainState::canBecome() (enum-held transition table, lines 237/252/253) as producers; FactSource::Declared same via mayReplace() match arrays; removing self-refs turns gate red. Translation gate still demands status.transferred_away (clause (a) verbatim). (2) "by value" excuse covers whole enum: CustomerStatus excused at CustomerController (rule in:active,suspended); CustomerStatus::Closed has no producer; added CustomerStatus::Frozen with strings → 1212/1212 green. Q4: oracle EveryEnumCaseHasAProducerTest can't go red for self-table cases or by-value hidden cases; RENDERED hand-written list.
- F-32, F-33, F-34, F-36, F-37, F-39, F-40, F-41, F-45, F-46, F-47 DO NOT REPRODUCE, each with a mutation-proven oracle.
- F-38 PARTIALLY REPRODUCES: tofu limb closed (step red on 11 plant shapes; outside infrastructure/tofu and dot-dirs green — zero occupancy, reservation); scan steps and empty subjects closed; no-apply gate green on eval "tofu ap""ply" (disclosed, reservation). REPRODUCES: validate-inventory.py SECRET_HINTS covers password/passwd not `pass`; ansible_ssh_pass / ansible_become_pass pass (probe: 12 hosts ok exit 0). Validator's first sentence claims to stop committed credentials → by precedent not a reservation. No oracle for aliases.
Round-two [E] items: all gone (same shape survives for TransferredAway). Observations: substring guard gone; lynomia_latest gone; grep exit 2 gone; F-37 lease overwrite still present, deliberate+documented; PaymentMethodKind BankTransfer/Wallet still dead (named unwritten).
I-2, I-4, I-5, I-6 gone.
Reservations: customer-route code gate doesn't read ternary (DomainRegistrarException) / match (PreflightRefusalReason) — all codes catalogued; emptying sweep doesn't read Model::query()->delete()/->truncate() — all current uses under RefreshDatabase.
Could not establish: other by-value excuses (12/30 checked); other classifier escapes; uncatalogued codes via unread spellings; PHPStan.

#### Skeptic (r4se) verdicts
- F-23 CONFIRMED PARTIALLY REPRODUCES. (1) TransferredAway (DomainState::canBecome enum-held table) and FactSource::Declared (match-condition arrays lines 36/38 — contradicts classifier's own rule that match-arm conditions are reads) are occupied and claimed ("Every case of every enum ... is one some production code produces — or it is named below"); TransferredAway promised by OpenAPI schemas.php:200, statusVocabulary.ts:139, en.json:243 → audit clause (a) verbatim. Fix: descend match-condition arrays; treat enum-held canBecome tables as reads (or name these as unwritten). (2) CustomerStatus::Frozen REFUTED (reservation, disclaimed); CustomerStatus::Closed CONFIRMED (occupied, excuse site validates in:active,suspended; missing from unenterable(); frontend useAuth.ts promises 'closed'; masked translation via TicketStatus::Closed). Fix: CASES entry Closed => unwritten (Identity). Severity TEST_GAP.
- F-38 CONFIRMED PARTIALLY REPRODUCES: ansible_ssh_pass, ansible_become_pass, ansible_sudo_pass pass validate-inventory.py (SECRET_HINTS lacks `pass`); unoccupied but claimed by docstring lines 4–7; closed enumerable set, Q2 passes → fix code. Severity: latent credential exposure.

### What round three introduced @88c4dd8 (cb427ec..88c4dd8: 230 files, +21,661/-1,804)
Full suite: 5507/5507, 674 suites skipped=0, exit 0.
- N-1 (F-01 × F-05 repair) REPRODUCES: RenewSubscription::lapse() computes held = amount_paid − amount_refunded − topups, not WhatAnInvoiceStillHolds (which counts refund rows still holding funds). amount_refunded is written by queued RecordRefundAgainstTheInvoice; a pending Stripe refund writes nothing. Probe: 5000 paid by card, IssueRefund 5000 with queued listeners faked, renewal → wallet 5000 + card 5000 = 10000 returned of 5000; later RecordInvoiceRefund → InvoiceRefundExceedsPaymentException into failed_jobs. Probe 2 (pending refund row): same. Docblocks WhatAnInvoiceStillHolds ("the three actions ... cannot between them return the same money twice") and lapse() ("Only what the invoice still holds") false. No oracle.
- N-2 (F-08 class) REPRODUCES: IssueRefund::reserve locks invoice→capture (round 3); SettleInvoice locks capture→invoice; RecordPaymentCapture attaches invoice_id before settlement → real 2-process deadlock 40P01 (refund went through at provider, recording failed). IssueRefund docblock "cannot deadlock" wrong for SettleInvoice. Test a_refund_locks_the_invoice_before_the_capture pins the deadlocking order. No money lost measured; availability/failed jobs.
- N-3 (F-05 shape, reintroduced by I-1 repair) REPRODUCES: EndTheSubscriptionWithItsService leaves a partly paid invoice open; StartInvoicePayment/PayInvoiceFromWallet check only isCollectible; revive listener only logs. Probe: ended sub, invoice open paid=500 → pay rest → invoice paid 9000, sub cancelled, service terminated, nothing delivered/returned. Test a_partly_paid_invoice_is_left_open... pins it.
[X] items: I-1..I-6 all GONE with mutation-proven oracles; VPS forced end measured; LeavesNothingCommitted in guard.
Swept & holding: new admin routes permissions; backups CAS; plan capacity join; renewal tx; lock order among renewal/void/restore/plan change/wallet (by reading); enum gate vs new cases (UnitCountUnknown, Superseded).
Could not establish: whole diff (frontend, Ansible/Tofu, DirectAdmin/simulators, Backups reconcile not examined in depth); sibling order-pool arithmetic (read only); other multi-process deadlocks; PHPStan.

#### Skeptic (r4sx) verdicts
- N-1 CONFIRMED narrowed (money): production route = wallet part-payment of open upgrade + IssueRefund of wallet charge + lapse inside booking window → 10000 back for 5000; wallet refund credit is kind Refund (not Topup) so lapse misses it. Card variant rests on fixture (StartInvoicePayment charges full amountDue). Pending Stripe refund never resolves: IngestWebhookEvent maps RefundSucceeded to null → pending is permanent (additional defect).
- N-2 CONFIRMED deadlock (availability only): 2-process 40P01 every run; no money lost; "refund went through, recording failed" refuted as sync-queue artefact; SettleInvoice docblock states payment-row-first convention which IssueRefund breaks.
- N-3 CONFIRMED (money, small): hosting renewal 1500, wallet 500, operator force-delete → customer pays remaining 1000 for terminated service; class docblock "an open renewal cannot be paid for something that no longer exists" false.
