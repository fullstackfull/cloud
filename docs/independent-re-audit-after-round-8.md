# Independent re-audit after round eight

**Candidate:** `6c234dc` on `claude/session-014hzcec3ombgztszvbjlwcv-vbzsx9`. Rounds
three to eight are merged, and every round-eight group was independently verified.
**Brief:** `docs/round-2-briefs/final-re-audit.md`, with the earlier overrides and
`docs/independent-re-audit-after-round-7.md` as the specification of what was
still wrong.

**Suite at the candidate:** the coordinator's full run at `34b4727` (whose difference
from `6c234dc` is the progress document) was 6,080 / 6,080, 777 suites `skipped="0"`,
exit 0. The cross-cutting agent's own full run could not complete: the machine's disk
filled (ENOSPC) during the re-audit and Postgres went into crash recovery. Space was
freed, every band discarded and re-ran what the outage touched, and the per-band
greens below are post-recovery. Band D ran stepping-clock sweeps over the whole suite.
vitest 653 / 653; `make infra-validate`: 0.

PHPStan could not be run (its dependencies download from GitHub, which the network
policy refuses).

## SOFTWARE_CODE_COMPLETE — adjudication at `6c234dc`

**`SOFTWARE_CODE_COMPLETE = NO`.**

- **All 47 findings do not reproduce**, each with its mechanism and an oracle that went
  red under mutation. Every round-seven unnumbered item is gone or disclosed.
- **Confirmed unnumbered defects, blocking under the adjudication rule:**
  - **Money:** a machine reading taken before the plan-change locks can be stale; a
    resize that finishes in between makes a later downgrade a credit with no resize
    (introduced by round eight).
  - **Lifecycle:** a resize whose recorded task can never be asked about, and a
    non-build job in review on an ended service, have no exit from review (the second
    introduced by round eight's adoption guard).
  - **Identity:** an address ending in an ideographic full stop is stored with a
    trailing dot beside the real login; an address that grows when normalised
    overflows its column and answers 500 with SQL in the message.
  - **Monitoring:** an overlap skip of a sub-minute entry is counted as a failure; the
    hosting sweep compares a stale capacity count; a pending account the panel does
    not list is a critical drift; two timing sentences and one alert description.
  - **API:** an unreadable activity cursor answers 500; plan-options money fields are
    published non-nullable; four older schema types disagree with the responses.
- Lower observations and owed oracles are in the digests.

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

| Finding | Verdict at `6c234dc` |
|---|---|
| F-01, F-05, F-06, F-07, F-08, F-27 | Do not reproduce (band A) |
| F-02, F-03, F-16, F-17, F-19, F-22, F-31 | Do not reproduce (band B) |
| F-04, F-09, F-10, F-11, F-12, F-13, F-14, F-18, F-26 | Do not reproduce (band C) |
| F-15, F-20, F-21, F-24, F-25, F-28, F-29, F-30, F-35, F-42, F-43, F-44 | Do not reproduce (band D) |
| F-23, F-32, F-33, F-34, F-36, F-37, F-38, F-39, F-40, F-41, F-45, F-46, F-47 | Do not reproduce (band E) |

## What happens next

Round nine fixes every confirmed item on `round9/<group>` branches, each independently
verified before merge; a fresh re-audit follows. The appendix holds the band digests.

## Appendix — band digests

### Band A @6c234dc (re-audit after round eight)
Green 1644 (12 dirs) skipped=0 exit 0; 47/48 mutations red (post-recovery).
- F-01, F-05, F-06, F-07 (numbered), F-08, F-27 DO NOT REPRODUCE (oracled). A8-1, A8-2, MN14 GONE; A8-3 still present, disclosed.
Unnumbered:
 A9-1 MONEY BLOCKING (U9-1, new in round eight): ApplyPlanChange reads the machine before its locks; whatTheServiceRuns() takes vCPU/memory from that reading. An upgrade's resize finishing between the reading and the subscription lock passes the busy check; the downgrade finds no change of shape, queues no resize: DOWN 200, resize null, wallet 0→27000, recurring 9000, machine stays 8/16384/160. At af2bba2 a shrink resize is queued. Falsifies "A quote with a reading measures from the machine in any case, so even a row left behind is not a credit…". Q2: under the lock take max(reading,row) per dimension, or queue a resize when either differs. Probe r9a/probes r9a_reading_before_the_lock_goes_stale.
 A9-2 LOCKOUT BLOCKING (U9-2): a resize whose recorded task can never be asked about (getTask always throws): retries needs_review again (compute.provider_request_failed), adopt 409, quote service_busy for ever; paid money held. Falsifies "an operator's retry looks at the machine first". Q2: look at the machine when the task ask fails permanently, or an operator verdict. Probe r9a_task_that_cannot_be_asked_about.
 minor: adoption guard pre-provider placement unpinned; failed-task log line unpinned. Existing: a downgrade credit posted at change time stays if its resize later fails permanently.
Could not establish: real Proxmox pending vCPU/mem in status/current; permanent getTask failure; U9-1 window length.

### Band B @6c234dc (re-audit after round eight)
Green 1609 (8 dirs, 244 suites) skipped=0 exit 0 (post-recovery re-runs); 48/48 mutations red (serial re-run).
- F-02, F-03, F-16, F-17, F-19, F-22, F-31 DO NOT REPRODUCE (oracled). B8-1, B8-2, B8-3 GONE (oracled). Every production path that stores/compares a login address goes through the normaliser; operator-invite answers identical for existing/new.
Unnumbered:
 B9-1 BLOCKING by letter, low: normalise turns a final U+3002/U+FF0E/U+FF61 in the domain into a trailing ASCII '.', so ops@EXAMPLE.com。 (accepted raw by email:rfc,strict at operator and team invite) is stored as ops@example.com. — a second login beside ops@example.com (201, promoted:false, [noc]); login/forgot 422; Symfony Mime refuses the address so the mail never sends. Team invite likewise. Falsifies InviteOperator "a login under the address means under any spelling" and User::email() "one login per mailbox". Q2: drop the trailing empty label, or refuse at intake. Probes r9b/ZzR9bProbeTest.php, ZzR9bTeamProbeTest.php.
 B9-2 low: 000120 does not rewrite password_reset_tokens.email; an in-flight reset token fails after migration (fresh forgot works); verification links (sha1 of email) likewise. No sentence claims otherwise.
Reservations: ext-intl undeclared — not blocking, owed (polyfill stores x@STRAẞE.de as x@strasse.de, a different domain; declaring ext-intl would make platform_check refuse boot — coordinator proposal). "Quoted local part holding @ has no oracle" — now false (n23 red at OneMailbox…:247). Operator list shows a promoted login's id/name/age — by design, not blocking.
Could not establish: timing oracle; PHPStan; normalised-length overflow (see X9-2, found by band X via 130×U+0130).

### Band C @6c234dc (re-audit after round eight)
Green 2219/2219 (15 dirs) skipped=0 exit 0 (clean re-run after the disk incident); vitest 16/16; infra-validate 0; 42/43 mutations red (MR overlap-cas green alone, red with Unit).
- F-04, F-09, F-10, F-11, F-12, F-13, F-14, F-18, F-26 DO NOT REPRODUCE (oracled).
- X8-1/C8-1 GONE (real schedule:run of hosting:reconcile 1,2,3 then reset; every event shape once; real background runs). C8-2, C8-3 (one node), C8-4 GONE. X8-2, X8-3 still present, no sentence claims otherwise.
Unnumbered:
 C9-1 BLOCKING by letter, low reach: RecordScheduledRun::failed() counts an overlap skip of a sub-minute repeating entry (stale exitCode from the first repetition → ScheduledTaskFailed): 1 run + 10 skips → consecutive_failures 11. Falsifies "Skips are deliberately not recorded at all". Q2: same skippedBecauseOverlapping guard in failed(). Probe r9c/ZzR9cRepeatProbeTest.php.
 C9-2 BLOCKING by letter, low: ReconcileHostingNodes discards the FOR UPDATE row and checkCapacity compares the stale $node->account_count read before the listing's HTTP call → false spec_mismatch drift when ReserveHostingNodeCapacity commits during listAccounts. Falsifies checkCapacity docblock. Q2: use the locked row. Probe r9c/ZzR9cStaleNodeProbeTest.php.
 C9-3 low (pre-existing): a pending account the panel does not list is recorded as critical missing_at_provider (existsAtPanel treats pending as present; the suspension check's reasoning not applied). Test covers only a pending account the panel lists.
 C9-4 wording: runbook "no sooner than eight hours and five minutes after the first failure" / rule comment "two intervals after the first" measure from failure record (end of run); should be from the first failed run's start.
 C9-5 wording (round seven): alert description "the work it does has not been done since its last success" false for reconcile commands that reconcile the rest.
 Reasoned only: two sweeps on different nodes of one panel listing the same two names in opposite orders could still deadlock (drift identity has no node).

### Band D @6c234dc (re-audit after round eight)
Green 3991 (17 paths) skipped=0 exit 0 (post-recovery); mutations of every finding red; stepping-clock sweeps: round eight's 30 files 313/313 at two settings; whole suite 6080 with only the 9 known clock_timestamp() method artifacts.
- F-15, F-20, F-21, F-24, F-25, F-28, F-29, F-30, F-35, F-42, F-43, F-44 DO NOT REPRODUCE (oracled). D8-1..D8-4 GONE. FakeComputeProvider::resizeVm honest (f1..f5 red).
Owed oracles (not blocking): adoption guard's "refused before the provider is asked" — moving the guard after the provider call leaves Provisioning and Vps green; failed-task log line unpinned. F-25: adding a Prepared ProductKind is caught only by a Catalog count pin.
Reservations: assertViewHas key position over-reports (false red only). "Stale row, no reading, no job" not independently enumerated.
Could not establish: real Proxmox UPID/hotplug; PHPStan; native time() straddles; second whole-suite sweep setting; full web vitest.

### Band E @6c234dc (re-audit after round eight)
Green 3334 (15 paths) skipped=0 exit 0; web vitest 653/653, tsc, eslint; openapi check and lint; infra-validate 0 (29/29).
- F-23, F-32..F-34, F-36..F-41, F-45..F-47 DO NOT REPRODUCE (oracled). F-38 re-measured parts hold; tofu step not re-run (binary deleted in disk clean-up; nothing tofu/CI changed since c4209fc).
- E8-1, E8-3, E8-4, E8-5 GONE. E8-2 still present, scoped (held provider yields; not falsified).
- Round eight's isOnlyACaseName matches its sentence; ActivityPage button oracled; page-meta gate oracled.
Unnumbered:
 E9-1 BLOCKING by letter (U-1): GET /api/v1/activity with a cursor decoding to invalid UTF-8 (base64url of "2026-01-01T00:00:00+00:00|\xff\xfe") → 500 server.error "Malformed UTF-8". Falsifies the round-eight published cursor parameter ("One the server cannot read is answered with the newest page rather than refused") and CustomerActivity::page() docblock. Lesser: NUL in id and year-3170843 timestamp → 200 empty page rather than newest. Probe r9e/ZzR9eResponsesProbeTest.php::a_cursor_the_server_cannot_read.
 E9-2 BLOCKING by letter (U-2): plan-options items publish credit, charge, amount_due_now as non-nullable Money; code sends null for a refused plan (operation description and schemas.php comment say null). Re-published in round eight.
 E9-3 (U-3, older, same published surface): Subscription.service_is_running published string|null, sent boolean; AdminDedicatedServer.rack_unit string|null vs integer; AdminHostingNode.load_average string|null vs float; /api/admin/readiness/products dependencies object vs [] when empty. Undetected because TheClientAndTheDescriptionAgreeTest compares names only.
 minor: console session id uppercase vs lowercase Ulid pattern; inbox default 20 vs published perPage default 25; /vps/{vm}/backups refuses per_page=0 where others clamp; ActivityPage test serve() meta typed unknown; openapi-lint CI comment says one warning (6).

### What round eight introduced @6c234dc (af2bba2..6c234dc)
Full suite: NOT established — disk full (ENOSPC; Postgres entered recovery). Space freed by the coordinator afterwards; rerun owed.
Unnumbered:
 X9-1 LIFECYCLE (new in round eight): a non-build job (resize, change_hosting_package, stop) in needs_review on an ended (Terminated) service: retry 409 provisioning.retry_after_the_service_ended, adopt 409 provisioning.adoption_not_a_build → stays in review for ever; ProvisioningJobsAwaitingReview alert fires permanently; no product action clears it. At af2bba2 adopt 200 → succeeded. Needs a supported close/cancel for such a job, or retry settling it by reading.
 X9-2 (pre-existing): an address that grows when normalised (130×U+0130 + @example.com = 142 chars, passes max:254; normalised 272) → 500 server.error SQLSTATE 22001 varchar(255), response carries SQL text. Same at af2bba2. Team invitations and registration have the same shape (not probed).
 C repair holds (SchedulerLivenessTest red under double count). E gate reservations as disclosed. Docs on scheduler/shared-hosting true.

