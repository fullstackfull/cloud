# Independent re-audit after round seven

**Candidate:** `c4209fc` on `claude/session-014hzcec3ombgztszvbjlwcv-vbzsx9`. Rounds
three to seven are merged, and every round-seven band was independently verified.
**Brief:** `docs/round-2-briefs/final-re-audit.md`, with the earlier overrides and
`docs/independent-re-audit-after-round-6.md` as the specification of what was
still wrong.

**Suite at the candidate** (the cross-cutting agent ran it alone):
- backend: 5,942 / 5,942. The JSON summary has `tests == passed` and no `skipped` key.
  The junit has 751 testsuites, all `skipped="0"`. Exit status 0.
- vitest: 653 / 653, over three runs in parallel under load.
- `make infra-validate`: 0. `openapi:generate --check`: up to date, 302 operations.

PHPStan could not be run. Its dependencies download from GitHub, which this
environment's network policy refuses.

## SOFTWARE_CODE_COMPLETE — adjudication at `c4209fc`

**`SOFTWARE_CODE_COMPLETE = NO`.**

- **All 47 findings do not reproduce.** Each has the mechanism that prevents it and
  an oracle that went red under mutation. The round-six F-07 VPS half is gone as
  specified, and every round-six unnumbered item is gone. F-44 is held by the gate,
  and two whole-suite stepping-clock sweeps found no flaky assertion.
- **Confirmed unnumbered defects, blocking under the adjudication rule** (each is a
  claim the code fails and could satisfy):
  - **Money:**
    - After a resize whose read-back fails (`vps.resize_unverified`), the machine
      row keeps the old shape. A downgrade is then credited, 27.000 in the probe,
      with no resize, and the machine stays large.
  - **Capacity:**
    - A resize that Proxmox answers with a running task is read back at once.
      The job succeeds with the old disk recorded, and the node ends up
      under-committed. The fake provider applies the change at once while
      reporting it as running.
    - With the hypervisor reporting no disk figure, a retried resize can grow the
      disk twice.
  - **Monitoring:** `RecordScheduledRun` counts every failed scheduled run twice, so
    `ScheduledCommandFailing` fires after two runs rather than three, and its text
    doubles the count. Two bands found this independently.
  - **Authorization:**
    - Two invitations of one address that interleave can re-role an existing
      operator and revoke their credentials.
    - `PUT /api/admin/operators/{id}/roles` with `roles: []` on a customer login
      answers 200 and describes the customer.
  - **Sentences:**
    - The enum gate says a spelled excuse "names the write". For `NodeStatus::Draining`
      the excuse is only the bare case name, so the case's one producer can be
      deleted and every test stays green.
    - `docs/api.md` states the OpenAPI and pagination facts wrongly.
    - `docs/openapi.yaml` describes `/api/v1/activity` with the wrong pagination.
    - One sentence of the clock gate claims more than the scanner does.
- **Lower-severity observations and owed oracles** are listed in the digests.

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

| Finding | Verdict at `c4209fc` |
|---|---|
| F-01, F-05, F-06, F-07, F-08, F-27 | Do not reproduce (band A) |
| F-02, F-03, F-16, F-17, F-19, F-22, F-31 | Do not reproduce (band B) |
| F-04, F-09, F-10, F-11, F-12, F-13, F-14, F-18, F-26 | Do not reproduce (band C) |
| F-15, F-20, F-21, F-24, F-25, F-28, F-29, F-30, F-35, F-42, F-43, F-44 | Do not reproduce (band D) |
| F-23, F-32, F-33, F-34, F-36, F-37, F-38, F-39, F-40, F-41, F-45, F-46, F-47 | Do not reproduce (band E) |

## What happens next

Round eight fixes every confirmed item on `round8/<group>` branches. Each fix is
independently verified before merge, and a fresh re-audit follows. The appendix
holds the band digests verbatim.

## Appendix — band digests

### Band A @c4209fc (re-audit after round seven)
Green 1453/1453 (11 dirs) skipped=0 exit 0; 34/35 mutations red.
- F-01, F-05, F-06, F-07 (as round six specified), F-08, F-27 DO NOT REPRODUCE (oracled). U-1, X1, R4, returned_at, restore on return, look-back, N2, N3 (readable), N4 hold/gone. Lock order: no cycle.
- Owed: MN14 withdraw route permission billing.pay→billing.view leaves Billing green (gate works by probe; uncommitted oracle).
Unnumbered:
 A8-1 MONEY BLOCKING (F-07 class, new path): after vps.resize_unverified (read-back fails), ResizeVpsHandler restates the commitment but never saves the machine row; job Failed Permanent; Failed job does not make the service busy. Probe r8a_an_unverified_upgrade_then_a_downgrade: row=[2,4096,40] hypervisor=[8,16384,160]; downgrade options current={2,4096,40}, refusals=[], changes_infrastructure=false, due_now=-27.000; DOWN 200, wallet 0→27000, recurring 9000, machine stays 8/16384/160. Falsifies PlanChangeDelivery::whatTheServiceRuns() "what the service runs now, the shape the hypervisor confirmed". Q2: quote holds the hypervisor reading; measure from it or refuse while the last resize is unverified. Also failed_after_payment text "still running as it was" false when the machine was resized. No oracle. Probe r8a/ZzR8aProbeTest.php.
 A8-2 F-06 residue low: per_customer_limit exceeded silently on a return (claimed_by_customer=2 limit 1, plan_stock_exceeded_by=0, no record). No sentence claims otherwise. Probe r8a/ZzR8aPerCustomerProbeTest.php.
 A8-3 low availability: plan-options and invoice GETs call getVm per request; disclosed.
Reservations of round seven (look-back, invoice-list reads, N3 unreadable): not blocking.

### Band B @c4209fc (re-audit after round seven)
Green 1482/1482 (219 suites) skipped=0 exit 0; 32/32 mutations red.
- F-02, F-03, F-16, F-17, F-19, F-22, F-31 DO NOT REPRODUCE (oracled).
- B7-1 GONE (six variants of a /register login vs new address: 201, byte-identical body, headers except the delegate's own x-ratelimit-remaining, mail identical). B7-2 GONE. roles:[] strips customer: GONE.
- User permission override holds on every path (can/hasPermissionTo/hasAnyPermission/checkPermissionTo; /api/admin/*; horizon; /me; PAT). permission() scope counts customer role — disclosed, unused.
- Recorded reservation (operator list shows promoted login's id/name/age): NOT blocking (sentence scoped to response; design note owed).
Unnumbered:
 B8-1 AUTHZ low (blocking by letter): alreadyAnOperator runs in the form request, outside the lock. Interleaved second invite: super admin B commits infra-admin between A's validation and A's FOR UPDATE → A 201 promoted_existing_account:true, final roles [noc], credentials revoked on an operator, B's reset token deleted. NOC delegate after B gave noc: 201 on an existing operator. Falsifies InviteOperator docblock "Reached only for a login that holds no staff role" and OpenAPI "An address that already holds a staff role is refused rather than silently re-roled". Q2: re-check holdsAStaffRole under the lock. Probe r8b/ZzR8bRaceProbeTest.php.
 B8-2 AUTHZ/PII low (blocking by letter; introduced by round seven): PUT /api/admin/operators/{id}/roles roles:[] on a customer login → 200 with the customer's name, email, created_at, and a no-op OperatorRolesChanged audit entry (at 6c91e09: 422). Falsifies OperatorController "A customer login … appears nowhere on this surface". Q2: refuse roles:[] for a login with no staff role (and no 200 describing a non-operator).
 B8-3 wording minor: promoted customer gets generic reset mail "If you did not request a password reset, no further action is required" though credentials are already revoked.
Could not establish: strtolower vs Str::lower for non-ASCII addresses; network timing; PHPStan.

### Band C @c4209fc (re-audit after round seven)
Green 2232/2232 (15 dirs) skipped=0 exit 0; web backups 15/15; infra-validate 0.
- F-04, F-09, F-10, F-11, F-12, F-13, F-14, F-18, F-26 DO NOT REPRODUCE (oracled).
- U-1, U-2, U-3, C6 GONE. Listing-step failure reservation resolved (pinned).
- ListedAccountName holds for both adapters; per-node txn mutations red except 25P02 rethrow removal (claim still true; uncommitted oracle).
Unnumbered:
 C8-1 = X8-1 BLOCKING (independently confirmed): RecordScheduledRun counts finished(exit!=0)+failed() → 2 per failed run; alert fires after 2 runs (~4h05m for hosting:reconcile, comment says 8h); summary "{{ $value }} runs in a row" doubles; metric help "how many times in a row" false (pre-round-seven). SchedulerLivenessTest never dispatches the pair Laravel sends. Probe r8c/ZzR8cSchedulerProbeTest.php.
 C8-2 runbook minor gap: backups:reconcile also exits non-zero on a file restore that fails to reconcile (log "A file restore could not be reconciled with its provider." with file_restore_id); scheduler-stale.md does not mention it.
 C8-3 low, not blocking: two overlapping hosting sweeps on one node can deadlock (RecordDrift advisory lock now held to node commit; no ordering); B stamps reconcile_error DeadlockException after A compared. Drift rows correct. Probe r8c/overlap.sh, sweep_proc.php. No committed multi-process oracle.
 C8-4 wording: backup-failure.md says quarantined_from; API field is interrupted_operation.
Could not establish: panel listing order stability; AlertOnCriticalDrift throwing after commit; PHPStan.

### Band D @c4209fc (re-audit after round seven)
Green 3955 (17 paths) skipped=0 exit 0; vitest 3x653 under load; multi-process capacity tests 10x no 40P01; 31/31 mutations red.
- F-15, F-20, F-21, F-24 (named defects), F-25, F-28, F-29, F-30, F-35, F-42, F-43 DO NOT REPRODUCE (oracled). F-44 DOES NOT REPRODUCE (scanner 4693 tests 0 findings; two stepping-clock sweeps: only 9 method artifacts on DB clock_timestamp()).
- D7-1, D7-2 (audited case), D7-3, read-back ceiling, X7-3 GONE. Lock order single; $alreadyRuns oracled; no hypervisor call in a transaction on money paths.
- Reservations: invoice list reads — not blocking; N3 unreadable — not blocking (customer text says "not deliverable" for "cannot tell right now").
Unnumbered:
 D8-1 (U-A) BLOCKING by letter: Proxmox adapter reports a resize answered with a UPID as RemoteTaskStatus::Running; ResizeVpsHandler ignores the status and reads back at once → job succeeded, row/commitment at old disk 40 while machine reaches 400 (360 GiB under-committed; paid upgrade row wrong). Falsifies "the row moves after the provider does" / "Read back rather than assumed… the machine is that size". Q2: wait for the task like other task paths. Also pending memory/CPU without hotplug (reading). FakeComputeProvider::resizeVm applies at once while returning Running (F-24-shaped simulator gap). Probe r8d/ZzR8dProbeTest.php.
 D8-2 (U-B) BLOCKING by letter, low reach: hypervisor reports no disk figure (maxdisk missing) → resize falls back to the row → retry after a landed indeterminate growth grows again (fleet disk 760 with row 400). Falsifies ProvisioningController::retry / RetryProvisioningJob "a growth that already landed is not applied twice". Q2: refuse to grow when the disk figure is absent.
 D8-3 gate sentence: "any other callable replaces a pin rather than being one" false for a callable held in a variable (scanner docblock and clean_a_test_now_variable_pins say it pins); narrow the sentence.
 D8-4 low: EQUALITY list lacks assertHeader, assertStringContainsStringIgnoringCase, assertRedirect, assertCookie, assertViewHas; no in-tree instance; scanner defines equality by its list.
Could not establish: real Proxmox resize async/UPID and pending memory; PHPStan; native time() straddles dynamically.

### Band E @c4209fc (re-audit after round seven)
Green 3231 tests (14 dirs), skipped=0, exit 0; infra-validate 0; openapi:generate --check 0.
- F-23 DOES NOT REPRODUCE (oracled; G1–G7, M6, M7, M7b, N4, N5 red; M3-full every staff role red on both routes). F-32..F-34, F-36..F-41, F-45..F-47 DO NOT REPRODUCE (oracled). E1–E6 gone; round-seven E reservations all fixed.
Unnumbered:
 E8-1 (sentence false; Q2 passes): NodeStatus::Draining producer (ChangeComputeNodeStatus::SETTABLE) removable with gate + Infrastructure/Compute/Vps/Admin green if any bare `NodeStatus::Draining` read stays in the file. Enum gate docblock "the spelling is chosen to name the write" false for the two NodeStatus spelled entries; no test drives an operator draining a node.
 E8-2 held entries check provider yields case, not that a test consumes it (claim scoped; not falsified).
 E8-3 docs/api.md "## OpenAPI" says no generated specification yet — contradicts header and docs/openapi.yaml (302 ops).
 E8-4 docs/api.md "## Pagination" says all lists cursor-paginated meta{next_cursor,per_page}; spec and code use PaginationMeta (page,per_page,total,last_page,max_per_page) on every list but /activity.
 E8-5 docs/openapi.yaml /api/v1/activity publishes ActivityItemPage (PaginationMeta) but ActivityController returns meta{next_cursor,per_page}; no gate compares.
 minor: E5 unclaimed literal shapes (false, ' ', empty per-locale array, 0/true) unasserted; planName() '' fallback reachability unknown.

### What round seven introduced @c4209fc (6c91e09..c4209fc)
Full suite 5942/5942, 751 suites skipped=0, exit 0.
Unnumbered:
 X8-1 MONITORING (falsifies C2 rule comment, runbook, alert text): RecordScheduledRun counts a failed foreground scheduled run twice (ScheduledTaskFinished exit!=0 → finished() +1; ScheduleRunCommand then throws → ScheduledTaskFailed → failed() +1). One failing schedule:run → consecutive_failures=2; two → 4. ScheduledCommandFailing (>=3) fires after two runs (~4h05m for hosting:reconcile, comment says 8h); "{{ $value }} runs in a row" doubles. SchedulerLivenessTest dispatches only ScheduledTaskFinished by hand — a model of Laravel Laravel does not follow. Probe r8x/ZzR8xScheduleProbeTest.php.
 X8-2 (low, reading): a node whose listing is permanently refused by ListedAccountName is never compared; nothing reads reconcile_error / reconciled_at staleness; no sentence claims an alert.
 X8-3 (low, reading): GET /api/v1/invoices and /invoices/{id} call getVm per open plan-change invoice, uncached, no own throttle; 30s timeout stalls.
Interactions: withdraw×pre-lock reading OK; User override×Horizon OK; per-node txn OK; enum gate nothing to miss; no provider read under a lock found.
X7-1, X7-2, X7-3 GONE. planName() '' fallback still present (reachability unknown).
Could not establish: card capture on withdrawn invoice path by reproduction; D7-2 with async disk grow.

