# Independent re-audit after round five

**Candidate:** `00a6e68` on `claude/session-014hzcec3ombgztszvbjlwcv-vbzsx9`. Rounds
three, four and five are merged, and every round-five band was independently
verified. **Brief:** `docs/round-2-briefs/final-re-audit.md`, with the earlier
overrides and `docs/independent-re-audit-after-round-4.md` as the specification of
what was still wrong.

**Suite at the candidate** (measured by the cross-cutting agent):
- backend: 5,661 / 5,661, JSON `tests == passed` with no `skipped` key, junit 701
  testsuites all `skipped="0"`, exit 0;
- vitest: 648 / 648, three runs in parallel under load;
- `make infra-validate`: 0.

PHPStan could not be run: its dependencies download from GitHub, which this
environment's network policy refuses.

## How it was run

Five band re-auditors (A–E) and one for *"what round five introduced"*. Each
worked in its own worktree, database and Redis index, and measured before reading
the progress record. Items reported as reproducing went to a separate skeptic,
except for band C's low residues and band D's measured capacity items, which went
straight to a fixer under the same verifier rule. Every probe and mutation was
restored against the bytes taken before.

## SOFTWARE_CODE_COMPLETE — adjudication at `00a6e68`

**`SOFTWARE_CODE_COMPLETE = NO`.**

- **46 of 47 findings do not reproduce.** Each has the mechanism that prevents it
  and an oracle that went red under mutation, except F-44, which is held by an
  edit with no gate for its shape. F-09 and F-23 are closed.
- **F-07 partially reproduces** (skeptic confirmed). A hosting plan with no
  package, reachable when an operator builds a plan before mapping its package or
  withdraws a mapped package, is sold through a *plan change*: the proration
  invoice is paid, the subscription renews at the new price, and nothing is
  delivered.
- **Confirmed unnumbered defects:**
  - **Money:**
    - A customer's reused Idempotency-Key suppresses a later downgrade's resize,
      for VPS and hosting alike.
    - A paid, undelivered upgrade is kept at the end when a later *unpaid* change
      exists, inside the queue-lag window.
    - A VPS resize never moves its node's capacity, so a grown machine oversells
      its node.
    - A retry cannot be placed on the node its own reservation fills, so the paid
      order waits in review.
    - An adopted late create leaves capacity on the wrong node.
  - **Authorization:** an address registered by someone else before an operator
    invite is promoted with the registrant's password. Email verification is the
    only barrier, and no test holds it on `/api/admin`.
  - **Availability:** the cluster inventory sync deadlocks with a capacity
    reservation (measured both ways).
  - **Oracles:**
    - The throttle oracle is blind to guard lists other than `sanctum`.
    - The enum gate's spelled check was satisfied by a comment; fixed at
      integration, `037156e`.
    - `Role`'s by-value site was a read; fixed at integration, `b350894`.
  - **Wording and low residues:** listed in the digests.

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

| Finding | Verdict at `00a6e68` |
|---|---|
| F-01, F-05, F-06, F-08, F-27 | Do not reproduce (band A) |
| **F-07** | **Partially reproduces**: a no-package hosting plan sold through a plan change (skeptic confirmed; reachable by operator routes) |
| F-02, F-03, F-16, F-17, F-19, F-22, F-31 | Do not reproduce (band B) |
| F-04, F-09, F-10, F-11, F-12, F-13, F-14, F-18, F-26 | Do not reproduce (band C; F-09's three-process races refused) |
| F-15, F-20, F-21, F-24, F-25, F-28, F-29, F-30, F-35, F-42, F-43, F-44 | Do not reproduce (band D; F-44 held by an edit) |
| F-23, F-32, F-33, F-34, F-36, F-37, F-38, F-39, F-40, F-41, F-45, F-46, F-47 | Do not reproduce (band E) |

## What happens next

Round six fixes every confirmed item above on `round6/<band>` branches, and each
fix is independently verified before it merges. A fresh re-audit follows. The
appendix holds the band and skeptic digests verbatim.

## Appendix — band and skeptic digests

### Band A @00a6e68 (re-audit after round five)
Green 776/776 (92 suites) skipped=0 exit 0. Probes/mutations in scratchpad/r6a/.
- F-01, F-05, F-06, F-08, F-27 DO NOT REPRODUCE (M13, M14, M15, M17, M16 red).
- F-07 PARTIALLY REPRODUCES: round-four bridgeless case gone (M12 red). Reproduces the audit's "hosting plan with no package is payable and renews forever" via plan change: QuotePlanChange has no deliverability refusal for the target plan. Probe ZzR6aNoPackageUpgradeProbeTest over HTTP: starter→agency (active, public, no package): 200, invoice paid 7500, sub on agency, recurring 9000, package_jobs=0, delivered_at set, no warning; wind-up keeps money. Two packages on sale: same + warning; TheHostingPackageBehindAPlanIsChosenNotStumbledOnTest::an_upgrade_onto_a_plan_with_two_packages_on_sale_leaves_the_quota_and_says_so asserts the defect; two other tests pin the silence. QueuePlanChangeAtProvider docblock discloses "a decision about the plan-change path F-32 did not take". Fix: QuotePlanChange refuses when resolveForSale/HostingPackageForPlan refuses the target plan.
- OX-1, OA-1, OA-3, OA-4, OA-2/wording GONE with oracles (M1..M11 red).
Residues:
 R1 (latent, real Stripe only): refund on undelivered upgrade reversed AFTER wind-up → invoice Paid, 10.000 held for an ended service (probe ZzR6aReversalProbeTest: wallet 17000 holds 10000); pending refund failing after wind-up same shape (returnWhatAVoidInvoiceHolds covers Void only).
 R2 policy: "delivered" = settlement heard while live, not resize ran; resize failing because service destroyed still keeps upgrade; no-package change "delivered" with nothing queued.
 R3 wording: WindUpAnEndedSubscription "an upgrade whose resize was queued before the end was delivered" vs actual rule.
 R4: EndTheSubscriptionWithItsService catches the rethrown deadlock and logs → "fails loudly and is retried or refused" false for that caller; subscription stays active, invoices payable (only on deadlock; no cycle found).
 R5 (by reading, real providers): IssueRefund saves the provider's synchronous result through an unlocked stale model; a webhook between call and save can be overwritten (failed booked as succeeded).
 R6 wording: routes/v1/billing.php "The one POST here is POST {invoice}/wallet-credit" — file has three POSTs.
Unnumbered:
 U-1 (money, customer-reachable, pre-round-5): QueuePlanChangeAtProvider::keyFor = plan-change:<sub>:<plan>:<raw customer key>; CreateProvisioningJob insert-or-ignore → reused key on a later downgrade returns old completed job, no resize; machine stays 8 vCPU billed as pa, credit posted. Also key invoice:<upgrade id>. Hosting package path same scheme. wasDelivered LIKE fallback satisfiable. Probe ZzR6aKeyReuseProbeTest. Fix: scope key per PlanChange id like VpsIdempotencyKey.

### Band B @00a6e68 (re-audit after round five)
Green 2029/2029 (286 suites) skipped=0 exit 0.
- F-02, F-03, F-16, F-17, F-19, F-22, F-31 DO NOT REPRODUCE, oracled. OB5-1, OB5-2, OX-2, OB-1, F-03 walk GONE. OB5-4 present, harmless, unpinned.
Unnumbered:
 B1 AUTHZ: an address registered by someone else before an operator invite is promoted with the registrant's password. HTTP: attacker registers new-operator@lynomia.test (202) → super-admin POST /api/admin/operators infra-admin (201, has_signed_in:false, no warning the account exists as a customer) → row keeps attacker's password, unverified, customer membership → attacker logs in 200; /api/admin 403 only by `verified` → mailbox owner clicks verification link → attacker's session /api/admin/customers 200, regions 200. Falsifies InviteOperator docblock "there is never a moment where a credential somebody else picked opens an operator account". Probe r6b/ZzR6bAuthzProbeTest.php::pre_registered_address_is_promoted_with_the_squatters_password.
 B2 ORACLE: nothing holds that /api/admin routes run `verified` (dropping it from the support admin group: 1072 tests green; EmailVerificationEnforcementTest uses a synthetic route).
 Note: F-22 validator alone passes a changed Alloy path (only the PHP test catches it).

### Band C @00a6e68 (re-audit after round five)
Green 1569/1569 (157 suites) skipped=0 exit 0; web backups 15/15.
- F-04, F-09, F-10, F-11, F-12, F-13, F-14, F-18, F-26: DO NOT REPRODUCE, oracled. F-09 three-process races (drive.php, drive2.php in r6c/) refused on TIP.
- Owned: F-09 stale reader GONE; DirectAdmin scalar list GONE; archive-lock oracle committed and red without lock.
Residues (low, unnumbered; not blocking):
 C1 DirectAdmin listAccounts: list[][]=bob and list[0][name]=bob read as no accounts (non-string elements filtered) → false missing drift; list[]=bob,alice / bob%0Aalice read as one account with comma/newline in name. F-14 class.
 C2 indeterminate-poll recordPoll call site: 0 red of 172 when reverted (owed test).
 C3 operator verdicts not bound to the review seen (hours window).
 C4 RestoreServiceBackup writes restore handle with plain save (~238); VerifyStoredArchives plain save of verification_requested_at/attempt count after lock ended (CAS still refuses).
 C5 F-10: browse/download/fetch of verified=false archive have no own test (assertOpenable removal: 2 red only via file-restore/describe).
 C6 no committed multi-process oracle for the overlapping-sweep race (in-process hydration oracle exists).

### Band D @00a6e68 (re-audit after round five)
Green 3093/3093 (404 suites) skipped=0 exit 0; vitest 3 parallel 648/648.
- F-15, F-20, F-21, F-24, F-25, F-28, F-29, F-30, F-35, F-42, F-43 DO NOT REPRODUCE (oracled); F-44 DNR held by edit (no gate for shape).
- OD5-1, OD5-2, OD5-3, OD-1, OD-2 GONE. F-07×gateway merge consistent. Hosting simulator TLDs still accepted (.local, .internal, .home.arpa, .onion, .alt) — simulator oracle gap.
Unnumbered (measured):
 D1 SyncClusterInventory deadlocks with ReserveNodeCapacity (node A, shared pool, node B UPDATE order vs reserve node B → pool): 40P01 3/3 each way (r6d/cap_racer.php, race_sync.sh). Reserve loses an attempt (provisioning.unclassified transient); sync pass fails, last_sync_error not written. CapacityPathsTakeTheirLocksInOneOrderTest sees only FOR UPDATE selects, never runs the sync. Availability.
 D2 VPS resize never moves capacity (ResizeVpsHandler updates only virtual_machines): 2/4096/40 → 16/65536/400, node still 2/4096/40, pool 40; second VM 80000 MiB placed → 145536 MiB on a 131072 node. Destroy gives back original figures. Handler docblock mentions "capacity accounting that has drifted". Customer-reachable via plan upgrade. Money/capacity.
 D3 retry cannot be placed on the node its own reservation fills: one node, 60000 MiB machine, transient refusal → attempts 2/3 "No active node can host", needs_review, order paid. Money held.
 D4 (by reading) ReserveNodeCapacity chooses move lock set from unlocked $existing; locked re-read in lockForAMove discarded.
 D5 (from X skeptic) late adoption: machine on pve-01 (charged 0), reservation on pve-02 (charged); AdoptOrphanResource never touches capacity. Reachable with AnswerLosingComputeProvider via operator retry + adopt.

### Band E @00a6e68 (re-audit after round five)
Green 1705/1705 (250 suites) skipped=0 exit 0; infra-validate 0.
- F-23, F-32, F-33, F-34, F-36, F-37, F-38, F-39, F-40, F-41, F-45, F-46, F-47: DO NOT REPRODUCE, each oracled by mutation.
- F-23: all 28 remaining by-value enums have a producer for every case (r6e/byvalue.txt). Per-case entries true.
Reservations (not blocking): restoring round four's whole-enum excuse AND deleting the per-case lines passes (list contents, not an oracle); pairing check has no dedicated test (M6); spelled check is a str_contains (comments/reads satisfy — M7, M8); const-list writers unseen.
Unnumbered (F-23 class, wording): Role by-value site InviteOperator.php:121 is a read (Role::tryFrom in alreadyAnOperator) — Q1 fails for the entry, Q2 passes.
COORDINATOR FIX (this commit): Role per-case spelled (staff at ChangeOperatorRolesRequest 'Role::cases()', Customer at RegisterCustomer); Registrant spelling '[DomainContactRole::Registrant->value =>'. Mutations red. Needs verifier.

### What round five introduced @00a6e68 (a6b583b..00a6e68)
Full suite 5661/5661, 701 suites skipped=0, exit 0. Every round-five repair's oracle red under mutation (table in r6x/mut-*.summary).
Unnumbered:
 X1 MONEY, blocking by the auditor: ReturnAnUpgradeTheEndPrevented::wasSuperseded() treats ANY later PlanChange as superseding, never checks it was settled (unlike ResizeOnPlanChangeSettlement::aLaterChangeHasBeenSettled). Repro r6x/ZzR6xSupersededProbeTest.php: upgrade1 paid (InvoicePaid faked, delivered_at null) → upgrade2 unpaid → cancel immediately → settlement heard: upgrade1 27000 held, wallet 0, second invoice void. Docblock "an upgrade a later change superseded was settled by that change (its credit is drawn on this invoice)" false.
 X2 ORACLE: EveryAuthenticatedRouteIsThrottledTest::every_authenticated_api_route_carries_a_throttle now counts only exact Authenticate:sanctum; a route with auth:sanctum,web and no throttle passes (a6b583b version fails). OB5-2 repair regression.
 X3 enum gate spelled check satisfied by a comment (// NodeStatus::Draining ... keeps gate green after removing from SETTABLE).
Could not establish: capacity move + late identity adoption (machine on A, capacity on B); SyncClusterInventory vs move deadlock; production queue lag frequency.

#### Skeptic (r6sx) verdicts
- X1 CONFIRMED + NARROWED (money, blocking-grade): reproduced over customer routes with real InvoicePaid, listener held by Queue::fake (queue lag) — second change 200, cancel 200, listener runs → 27000 held, wallet 0. Control without second change returns 27000. Window = settlement commit → payments-queue listener (lag, or listener exhausting 5 tries: unbounded). QuotePlanChange refuses only InvoiceOutstanding/ServiceBusy, not "last change undelivered". → given to round6/A.
- X2 CONFIRMED weaker oracle, NARROWED no current exposure: 293/300 api routes exact auth:sanctum, other 7 unauthenticated and throttled. Regression only for auth:sanctum,<more>. Oracle/wording.
- LATE ADOPTION (reachable with in-tree AnswerLosingComputeProvider): attempt1 create lost on pve-01 → needs_review; pve-01 maintenance; operator retry → reservation moved to pve-02; attempt1's create lands on pve-01; attempt2 refused (VMID taken) → found own build → operator adopt → machine on pve-01 (charged 0), reservation on pve-02 (charged 1/4096). AdoptOrphanResource never touches capacity. Mirror of OD5-3. Data (capacity/oversell).
