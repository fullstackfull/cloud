# Independent re-audit after round six

**Candidate:** `a66ac17` on `claude/session-014hzcec3ombgztszvbjlwcv-vbzsx9`. Rounds
three to six are merged, and every round-six band was independently verified.
**Brief:** `docs/round-2-briefs/final-re-audit.md`, with the earlier overrides and
`docs/independent-re-audit-after-round-5.md` as the specification of what was
still wrong.

**Suite at the candidate** (the cross-cutting agent and band E each ran it independently):
- backend: 5,781 / 5,781. The JSON summary has `tests == passed` and no `skipped` key.
  The junit has 724 testsuites, all `skipped="0"`. Exit status 0.
- vitest: 651 / 651, over three runs in parallel under load.
- `make infra-validate`: 0.

PHPStan could not be run. Its dependencies download from GitHub, which this
environment's network policy refuses.

## SOFTWARE_CODE_COMPLETE — adjudication at `a66ac17`

**`SOFTWARE_CODE_COMPLETE = NO`.**

- **46 of 47 findings do not reproduce.** Each has the mechanism that prevents it and
  an oracle that went red under mutation. F-44 is now held by a gate
  (`NoAssertionComparesAClockReadOnAnUnpinnedClockTest`); stepping-clock sweeps
  across the suites found no instance of its shape.
- **F-07 partially reproduces, in its VPS half.** `services.resources` is written once
  at purchase, and no resize updates it. The plan-change quote and the delivery check
  read it, so an upgrade back to the shape originally bought skips the capacity
  question. The customer is charged and the resize can never happen.
- **Confirmed unnumbered defects:**
  - **Money:**
    - The same stale shape lets a downgrade after an upgrade queue no resize, so the
      platform credits the customer while the machine stays large.
    - A paid change whose settlement is heard after the renewal can be recorded as
      delivered without being built.
  - **Lifecycle:**
    - A plan change that becomes undeliverable after acceptance leaves the customer
      unable to pay or change plan until renewal.
    - An adopted VPS has no machine row, so the platform cannot manage it.
    - An operator retry of a resize that landed grows the disk twice.
    - A destroy that overlaps a resize leaves capacity committed for a machine that
      no longer exists.
  - **Authorization:** a delegate's invitation of a registered customer's address is
    refused with 422 while a new address gets 201, so the response reveals whether a
    login exists.
  - **Reads:** the cPanel account listing reads unreadable bodies as an empty node,
    which raises false Critical drift.
  - **Availability:** a non-panel failure while reconciling one hosting node stops
    the whole sweep and starves the other nodes.
  - **Oracles:** several are owed; they are listed in the digests.

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

| Finding | Verdict at `a66ac17` |
|---|---|
| F-01, F-05, F-06, F-08, F-27 | Do not reproduce (band A) |
| **F-07** | **Partially reproduces**: VPS half, the quote reads a shape no resize updates |
| F-02, F-03, F-16, F-17, F-19, F-22, F-31 | Do not reproduce (band B) |
| F-04, F-09, F-10, F-11, F-12, F-13, F-14, F-18, F-26 | Do not reproduce (band C) |
| F-15, F-20, F-21, F-24, F-25, F-28, F-29, F-30, F-35, F-42, F-43, F-44 | Do not reproduce (band D; F-44 now held by a gate) |
| F-23, F-32, F-33, F-34, F-36, F-37, F-38, F-39, F-40, F-41, F-45, F-46, F-47 | Do not reproduce (band E) |

## What happens next

Round seven fixes every confirmed item on `round7/<band>` branches. Each fix is
independently verified before merge. A fresh re-audit follows. The appendix holds
the band and skeptic digests verbatim.

## Appendix — band and skeptic digests

### Band A @a66ac17 (re-audit after round six)
Green 1365/1365 (191 suites) skipped=0 exit 0; 20/20 mutations red.
- F-01, F-05, F-06, F-08, F-27 DO NOT REPRODUCE (oracled). F-06 residue by reading: ReturnAPlanChangeNoLongerDeliverable puts the subscription back on its old plan even if that plan's last unit was sold in the window (can exceed stock_limit).
- F-07 PARTIALLY REPRODUCES — VPS half: services.resources is written once (ProvisionOrderedService, EnforceComputeSuspension) and never by a resize; QuotePlanChange::currentResources and PlanChangeDelivery::runningBefore read it. Buy large → downgrade small (resize runs) → node fills → upgrade back to large: quote refusals=[], changes_infrastructure=false, "current" 8/16384/160 while machine is 2/4096; 200, 27.000 captured, delivered_at set, recurring 90.000, machine stays small, resize refused for capacity → review with money held. Falsifies PlanChangeDelivery "the quote and the resize ask one question". No oracle. Probe r7a/ZzR7aProbeTest.php::r7a_upgrade_back_to_the_bought_shape_skips_the_capacity_question.
- U-1, X1, R4, returned_at exclusions, ReturnAPlanChangeNoLongerDeliverable: hold (oracled). Lock order: no cycle by reading.
Unnumbered:
 N1 MONEY (platform loses, customer-reachable; same cause): small→large delivered (machine 8/16384/160, recorded 2/4096/40) → large→small 200, quote changes_infrastructure=false, no resize queued, wallet +27000, billed 9.000, machine stays large. large→mid bypasses WouldShrinkDisk; resize then fails "A resize may not reduce a disk" but subscription stays mid with 20.000 credited, machine large; customer told "left as it was. Nothing has been charged" — false.
 N2 LOCKOUT (round six; = X7-2): package withdrawn / node filled after acceptance → card and wallet 409 invoice.plan_change_not_deliverable; invoice open → every change refused invoice_outstanding; no customer void route; until renewal or operator void. GET invoice says is_payable:true.
 N3 LOCKOUT edge: a machine with no live reservation on a full node — a pure shrink is refused not_deliverable.
 N4 WORDING: plan_change_returned "stays on its current plan" false when plan not put back; failed_after_payment / needs_review say payment "held" while renewal bills the new plan; PlanChangeDelivery says a hosting service with no account is not asked — the code asks.
Could not establish: a change paid near period end whose settlement is heard after renewal (aPaidChangeAwaitsDelivery looks only at the current period).

### Band B @a66ac17 (re-audit after round six)
Green 1442/1442 (209 suites) skipped=0 exit 0.
- F-02, F-03, F-16, F-17, F-19, F-22, F-31 DO NOT REPRODUCE (oracled). B1 squatter GONE (full HTTP replay, R1–R7/R11 red). Reset verifies: holds. PUT roles non-operator: holds (residue: super admin PUT roles:[] on a customer strips `customer`, low). verified oracle, throttle oracle, PAT refusal: hold. OB5-4 harmless, unpinned.
Unnumbered:
 B7-1 (= X7-1, independently confirmed): delegate invite of a /register login → 422 rbac.role_not_yours_to_remove vs 201 for a new address — existence oracle; delegates can never promote a registered customer. Also for role-less logins the 201's `id` is a ULID carrying the login's creation time (400 days vs 0). The oracle test uses a role-less factory user, strips id, and back-dates created_at while the ULID is minted now — cannot go red for the real case.
 B7-2 (low): inviting the address of a soft-deleted login → 500 (lookup without withTrashed, then users_email_unique) for super admin and delegate alike; another existence oracle for a delegate.

### Band C @a66ac17 (re-audit after round six)
Green 1994/1994 (211 suites) skipped=0 exit 0; web 16/16.
- F-04, F-09, F-10, F-11, F-12, F-13, F-14 (DirectAdmin), F-18, F-26 DO NOT REPRODUCE (oracled; F-09 three-process races refused).
- C1–C5 GONE; C6 (no committed multi-process oracle for overlapping sweeps) present, low. Duplicate schema gone.
Unnumbered:
 U-1 (F-14 class, false Critical alarm): CpanelHostingProvider::listAccounts uses `?? []` and silently drops rows not arrays / without `user` → {"metadata":{"result":1}} (no data), data:{foo:bar}, acct:[{"name":..}], acct:["liveone"], acct:{"user":..} each → 1 Critical missing_at_provider, reconcile_error null; acct:[{"user":"liveone,livetwo"}] → missing + stranger. Probe r7c/ZzR7cCpanelListProbeTest.php. docs/shared-hosting.md:116 claims such a node "records no drift".
 U-2 (availability, low): ReconcileHostingNodes catches only HostingProviderException; any other exception from compare/RecordDrift/checkCapacity aborts execute() before the node is stamped → stays first forever. Probe r7c/ZzR7cSweepStarvationProbeTest.php: a 300-char token (accepted by design) overflows resource_drifts.provider_reference varchar(255) → QueryException 22001 every run; node-fine never compared. Contradicts "a refusal is not silent … moves the node behind the others".
 U-3 (runbook wording): docs/runbooks/backup-failure.md step 1 "its task id" — a restore in review without a handle shows none; a late handle is only in a log line.

### Band D @a66ac17 (re-audit after round six)
Green 3605/3605 (444 suites) skipped=0 exit 0; vitest 3 parallel 651/651.
- F-15, F-20, F-21, F-24, F-25, F-28, F-29, F-30, F-35, F-42, F-43 DO NOT REPRODUCE (oracled). F-44 DOES NOT REPRODUCE, held by the gate; stepping-clock sweeps found no instance. Gate limits (no in-tree instance): variable read after code ran (disclosed); hand comparison !== then fail(); assertThat(..., identicalTo(now())); now() passed to a non-assert helper; any Carbon::setTestNow(<closure>) in a shared setUp counts as a pin and blinds the gate.
- D1–D5 GONE (sync race re-run 21x, no 40P01; ledger = reservations = hypervisor after every op).
Unnumbered (measured):
 D7-1 CAPACITY LEAK (round six): destroy overlapping a resize leaves a live commitment for a gone machine — MachineCommitment::keyFor falls back to machine:<id>, RestateNodeCommitment writes a new row when none live; destroy inside the resize after its machine-row write → node holds 1/4/8192/80 under machine:<id> with 0 machines; nothing releases it. Engine doesn't serialise jobs per service. No oracle. Probe r7d/.
 D7-2 DOUBLE DISK GROWTH (pre-existing, F-15 class): indeterminate resize 40→400 that landed → NeedsReview → operator retry 200 → hypervisor disk 760 (handler computes growth from the recorded row, never reads the machine). Customer 760 GiB, pays for 400. Falsifies ProvisioningController::retry docblock "refuses every case where running the job again would cause a second event".
 D7-3 ADOPTED VPS HAS NO virtual_machines ROW (pre-existing; F-15 recovery path): lost-answer create → adopt 200 → job succeeded, service active, 0 machine rows; TerminateVpsService throws ABuildMayExistException "adopt what exists"; adopted machine cannot be resized/destroyed/terminated/seen by customer; round six commits capacity no path can release.
 Also: resize whose read-back fails (vps.resize_unverified, permanent) leaves commitment at the reserved ceiling (over-commit).
 + X7-3 (from X, narrowed low): adoption calls hypervisor getVm per node inside its DB transaction holding the job lock.

### Band E @a66ac17 (re-audit after round six)
Green 1721/1721 (255 suites) skipped=0 exit 0; infra-validate 0; whole suite 5781/5781 (under M3-full).
- F-23, F-32, F-33, F-34, F-36, F-37, F-38, F-39, F-40, F-41, F-45, F-46, F-47 DO NOT REPRODUCE (oracled). 27 by-value enums re-derived: every case accepted at its site.
Owed / reservations (not blocking):
 E1 M3-full: billing-admin and network-engineer made unassignable (invite + role change) → enum gate AND whole suite green; noc/support/finance/infra-admin removal → Rbac red. Six staff Role entries share spelling 'Role::cases()'.
 E2 M6: Role back to whole-enum by-value at InviteOperator (only a Role::tryFrom in a predicate — a read) + per-case lines deleted → green; the docblock's "a from() in a list filter or over a stored column is a read, and does not qualify" is not enforced. M7 same for NodeStatus.
 E3 M8: the pairing check removed → tests/Architecture green (no test of its own).
 E4 M10: Rule::in([...]) in place of literal in: → green (disclosed).
 E5 notification gate: N5 literal 'plan' => null green (renderer drops null → ":plan" shown); a literal array not an {en,ar} map counts as supplied but renderer drops it. Docblock disclaims only run-time nulls.
 E6 EverySchemaIsDefinedOnceTest: no check that a schemas.php key doesn't collide with a components.php schema or a generated …Page/…Response envelope (generator lets schemas.php override silently); behavioural half covers hosting-node list only, not IP-pool list.
 Unchanged disclosed: tofu dot-directory, eval/variable indirection in the no-apply gate; string/heredoc/attribute satisfy fileSays.

### What round six introduced @a66ac17 (00a6e68..a66ac17)
Full suite 5781/5781, 724 suites skipped=0, exit 0.
Unnumbered:
 X7-1 AUTHZ (blocking by auditor): a delegate (role.manage, not super-admin) inviting the address of a login made through /register (which holds role `customer`) is refused 422 rbac.role_not_yours_to_remove (syncRoles removes `customer`; assertEveryRemovedRoleIsTheActorsToTake), while a never-registered address → 201 → status code is a login-existence oracle; falsifies OperatorController docblock + OpenAPI promoted_existing_account "does not distinguish". AnInvitationTakesTheAccount…::only_a_super_admin_is_told… uses User::factory() (no customer role) so cannot go red. Ledger reservation "role.manage inviting a customer resets credentials" false for real customers. Probe r7x/ZzR7xDelegateProbeTest.php.
 X7-2 LIFECYCLE: plan change accepted, then undeliverable before payment → payment 409 invoice.plan_change_not_deliverable; subscription stays moved (recurring 1500→9000); every plan option refused invoice_outstanding; no void route until renewal lapses or operator voids. Customer error text doesn't explain. Also VPS node filling. Probe r7x/ZzR7xReturnedThenEndedProbeTest.php.
 X7-3 (mechanism): AdoptOrphanResource calls NodeCapacityFollowsAnAdoption::whereItIs() (hypervisor getVm per node) inside its DB::transaction holding the job-row lock — breaks the codebase rule "the transaction must not span the provider call".
 Minor: Role::cases() spelled for six cases (ledger reservation); NotifyOnProvisioningOutcome::planName() falls back to '' → "on the  plan".

#### Skeptic (r7sx) verdicts
- X7-1 CONFIRMED (authorization, low–medium, blocking: falsified promise + vacuous oracle). Noc/NetworkEngineer delegates lack customer.view_any, so the 422 discloses something they can't otherwise learn. Super admin's promotion replaces `customer` role (customer memberships stay). → round7/B.
- X7-2 NARROWED (lifecycle + wording, no money): stuck up to one billing period (renewal lapses it) unless an operator voids or re-maps; no sweep for overdue proration invoices; GET /api/v1/invoices/{id} says is_payable:true while payment is refused; error text doesn't say it lapses at renewal. VPS node-fills variant reaches it with no operator action.
- X7-3 NARROWED to design note (low): getVm per node inside the adoption transaction (advisory + job lock); worst case one 30s timeout or php-fpm 60s kill → clean rollback; no shipped sentence claims otherwise (the rule is scoped to RunProvisioningJob). ProvisioningController::adopt "asserting something the platform could not check for itself" slightly stale.
- Minors: Role::cases() spelling also substring-matches CustomerRole::cases(); planName '' reachability unestablished.
