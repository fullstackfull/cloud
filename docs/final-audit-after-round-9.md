# Final independent audit after round nine — adjudication

**Candidate audited:** `d75281b` on `claude/session-014hzcec3ombgztszvbjlwcv-vbzsx9`
(rounds three to nine merged). **Closed at:** `2d89da4`, which adds the one blocking
fix the audit required (`round10/M`, merged at `f8906c1`) and a stale measurement in
the runbook gate corrected at integration.

**Rule applied:** `docs/round-2-briefs/closure-threshold.md`, decided by the owner after
the re-audit after round eight: an item blocks only when it touches **money,
authorization, data loss or lockout**; every other item is a documented reservation.
Q1 and Q2 of `adjudication-rule.md` were asked of every item.

## Suite at `f8906c1` / `2d89da4`

- backend: **6,220 / 6,220**; JSON summary `tests == passed`, no `skipped` key; junit
  801 testsuites, all `skipped="0"`; exit status 0 (at `f8906c1`; `2d89da4` changes only
  a comment in `infrastructure/scripts/validate-runbook-alerts.py`).
- `apps/web`: vitest 655 / 655; `npm run typecheck` 0; `eslint src --max-warnings=0` 0.
- `pint --test` 0; `openapi:generate --check` up to date (304 operations);
  `npm run openapi:lint` valid (6 warnings, unchanged); `make infra-validate` 0 (at `2d89da4`).
- PHPStan could not be run: its dependencies download from GitHub, which the
  environment's network policy refuses.

## SOFTWARE_CODE_COMPLETE — adjudication

**`SOFTWARE_CODE_COMPLETE = YES`**, under the owner's closure threshold.

- **All 47 findings (F-01 … F-47) do not reproduce.** Six independent auditors each
  re-measured their band at `d75281b`, and every finding has the mechanism that
  prevents it and a committed oracle that went red when the defect was put back.
- **One blocking item was found, in money, and is fixed.** A paid plan change whose
  resize never completed was kept when its service ended, though the customer texts
  promise it is returned. The fix (`round10/M`) was rejected twice by its independent
  verifier on further money paths — a hand refund on a live service leaving the
  upgraded plan billed at renewal and a downgrade over-crediting; an undelivered later
  change keeping an earlier one; a renewal already issued at the new price; an
  over-credit after a middle plan — each repaired, and then upheld: every probed money
  outcome is exact, returned once, in every ordering. A new operator route,
  `POST /api/admin/provisioning/jobs/{job}/return-payment`, returns a held paid change
  and takes it out of play; the raw refund route refuses an undelivered paid change.
- **Nothing else in the four classes was found** by any band.

This verdict is about the code. It says nothing about real providers, real payments,
real registrars or real hosting, none of which has been exercised:

```
30B.0-E                 = NOT READY
REAL_INFRA_VERIFIED     = NONE
REAL_PAYMENT_VERIFIED   = NONE
REAL_REGISTRAR_VERIFIED = NONE
REAL_HOSTING_VERIFIED   = NONE
READY_TO_SELL           = NONE
```

## Documented reservations (none in a blocking class)

**Money-adjacent, disclosed:**
- A retry that gives up on a resize task still running can grow a disk twice
  (measured 40→280 GiB against 160); capacity is recorded truthfully afterwards and no
  charge is wrong; the handler and the runbook say so.
- A returned held change is returned to the wallet only, never to a card (the
  platform's stated policy; an owner decision).
- A proration invoice from before plan changes were recorded can still be refunded by
  hand (legacy data only).
- A reissued renewal follows the subscription's current coupon; a coupon can be
  changed only outside the application.
- When the end of a service returns money for a change an operator already took out
  of play, the customer gets no second notice (the first one promised it).

**Lifecycle and operations:** the close route does not take a build, a destroy or a
WordPress job on an ended service (its review alert stays until a person acts); an
operator retry of an old pending hosting build is reported as critical while it runs;
one stranger on two nodes is one drift row.

**Identity:** doubled separators, U+FE52 and U+2024 keep their own undeliverable login;
verification links sent before the address rewrite need a resend; addresses the
invitations now refuse (dotless, quoted, IP literal, local part over 64) can no longer
be invited, though their own logins still sign in.

**API and documentation:** an activity cursor with an out-of-range timezone offset
answers 500 without disclosing anything; the conformance oracle walks GETs with two
queries only; several operations describe a 409 their response map does not list.

**Owed oracles:** the node row lock in the hosting sweep; the row check's place after
the busy question; several round-ten guards (ended subscription, a renewal moved
meanwhile, coupon terms); `MoneyPathsTakeTheirLocksInOneOrderTest` does not yet include
the return-payment path.

**A proposal for the owner:** `composer.json` does not declare `ext-intl`, which the
login-address spelling depends on. It is provisioned by the Ansible role and in CI, and
a test fails without it; declaring it is a dependency change and is proposed, not made.

## Appendix — band digests of the final audit

### Final audit, band A @d75281b
Green 1684 (12 dirs) skipped=0 exit 0; 7/9 mutations red.
- F-01, F-05, F-06, F-07, F-08, F-27 DO NOT REPRODUCE (oracled). A9-1, A9-2, X9-1 GONE (oracled).
BLOCKING: none.
RESERVATIONS:
 R1 a retry that gives up on a task still running grows the disk twice (row 40, hypervisor 280, committed 160; job stays in review; disclosed in handler and runbook; later downgrades refused would_shrink_disk until support acts). No wrong charge or destroyed data.
 R2 the close route does not cover destroy_vps, WordPress kinds, create_hosting_account, provision_dedicated on an ended service (review-queue alert never clears; no customer or money locked). destroy_vps exclusion unpinned (M4). Documented.
 R3 carried: a downgrade credit stays if its resize later fails permanently; a paid upgrade counts delivered once settled even if its resize later ends in review/closed; a plan restore can exceed per-customer limit.
 minor: M6 (no machine treated as row unchanged) equivalent.
Could not establish: real Proxmox; timing window frequency; PHPStan; whole suite.

### Final audit, band B @d75281b
Green 1645 (8 dirs) skipped=0 exit 0; 58/60 mutations red (2 equivalent).
- F-02, F-03, F-16, F-17, F-19, F-22, F-31 DO NOT REPRODUCE (oracled). B9-1, B9-2, X9-2 GONE (oracled). No existence oracle (forgot/register/login byte-identical); no lockout (logins stored under older looser rules still sign in and get reset mail).
BLOCKING: none.
RESERVATIONS: doubled separators, U+FE52, U+2024 keep their own undeliverable login (not the real mailbox; UTS #46 refuses; Mime encodes ops@); verification links before the rewrite need a resend; logins with dotless/quoted/IP-literal/65+ local addresses can no longer be promoted or team-invited (own account untouched); k02 (max:254 redundant with strict) and n21 (InviteOperator's own normalise redundant) equivalent; normalise not idempotent for "。"+U+FE0F (intake refuses that stored form); ext-intl undeclared; staff-facing engineer messages on the operator API (by design).

### Final audit, band C @d75281b
Green 2189 (15 dirs) skipped=0 exit 0; infra-validate 0; 61/63 mutants red.
- F-04, F-09, F-10, F-11, F-12, F-13, F-14, F-18, F-26 DO NOT REPRODUCE (oracled). C9-1..C9-5 and the two-node deadlock GONE (C9-5 wording has no oracle). Last round's "MR green alone" was a test-path artifact (red on Feature/Backups).
BLOCKING: none (round nine's C changes write only drift rows and node stamps; nothing destroyed, orphaned or locked).
RESERVATIONS: the node row lock has no oracle (L2 green; sorted drift order prevents the deadlock by itself; the docblock's "TwoOverlappingHostingSweepsInTwoProcessesTest holds it" no longer describes what it catches); a pending row with null updated_at treated as young (nil reach); an operator retry of an old pending row reported critical while building (false alarm); stranger on two nodes merges into one drift row; node deleted mid-sweep ends that sweep; X8-2, X8-3 carried.

### Final audit, band D @d75281b
Green 4035 (17 paths) skipped=0 exit 0; web vitest 183/183 over its oracles, npm run typecheck 0; 56 mutations restored identical; stepping-clock sweep over round nine's 19 files at three settings: no flaky assertion.
- F-15, F-20, F-21, F-24, F-25, F-28, F-29, F-30, F-35, F-42, F-43, F-44 DO NOT REPRODUCE (oracled). Round-eight owed oracles (adoption guard placement, failed-task log) closed.
BLOCKING: none.
RESERVATIONS: double disk growth on a give-up while the task still runs (disclosed; capacity recorded truthfully afterwards; customer holds 120 GiB more than the plan sells); the close test derives its refused-kinds dataset from CLOSABLE_KINDS, so adding CreateVps to it stays green (owed oracle, orphan class; code correct today); F-25 count-pin-only oracle; close on a suspended service unpinned.
Could not establish: real Proxmox; PHPStan; whole-suite clock sweep this round; F-42 under parallel load; "stale row, no reading, no job" enumeration.

### Final audit, band E @d75281b
Green 3588 (16 paths) skipped=0 exit 0; web vitest 655/655, npm run typecheck (verified it checks), eslint; openapi check 303 and lint (6 warnings); infra-validate 0.
- F-23, F-32..F-34, F-36..F-41, F-45..F-47 DO NOT REPRODUCE (oracled); F-38 tofu step not re-run (nothing it checks changed since c4209fc).
- E9-2, E9-3 GONE (oracled). Close route publication, permission and guards oracled.
BLOCKING: none.
RESERVATIONS:
 E9-1 residue: a cursor with an out-of-range timezone offset (+16:00..+99:00) answers 500 (PostgreSQL 22009); falsifies the published cursor sentence; body discloses nothing; no oracle.
 conformance oracle walks GETs with two queries only (?page=0 → unpublished 422 on 16 paged operations, pre-existing); push-impact success/409 unexercised; seed-null fields checked only as null.
 close route and siblings publish no 409 in their responses map though their descriptions say 409.
 carried: E8-2 held test unpinned by #[Test]; x5 placeholder shapes; F-38 indirections.

### Final audit, band X (what round nine introduced) @d75281b
Full suite 6172/6172, 795 suites skipped=0, exit 0 (14G free before run); openapi check 303; web typecheck 0.
- X9-1, X9-2 DO NOT REPRODUCE (oracled). Interactions (close × scheduler/reaper; re-read loop × payment/settlement; error renderer × web; migrations × data; generator × web): no defect.
BLOCKING: none found.
RESERVATIONS: stale-sweep worker could overwrite a later close (pre-existing shape, no money/data); A→B→A row sequence within one reading window (reasoned, effectively unreachable); migration collision stop leaves a root-label login unreachable until a person decides (disclosed, no loss); verification links need resend; A9-2 double disk growth; U+FE52/doubled separators separate undeliverable login.
OBSERVATION (pre-existing, for the coordinator): a paid upgrade counts delivered once settled (delivered_at) whether or not its resize succeeded; a resize stuck in review followed by the subscription ending keeps the proration; closing the job does not change this.

