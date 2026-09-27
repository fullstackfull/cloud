# Round two — rebuild progress

Round two's remediation code lived on `remediation/f*` branches in a cloud
session that was never pushed; that container is gone and the code with it.
`docs/round-2-remediation-ledger.md` and `docs/round-2-briefs/` survived and
describe each finding's final, accepted fix.

This rebuild reimplements those final fixes directly on this branch, one finding
at a time, each with a regression test shown red on the unfixed tree and green
after. It does not repeat the review rounds; one independent re-audit
(`docs/round-2-briefs/final-re-audit.md`) runs at the end.

Every rebuilt finding also gets an **independent verifier** in its own
worktree and database, which reproduces the defect on the base tree, probes the
fix, reverts each load-bearing hunk to prove a test goes red, and checks every
changed sentence. A rejection goes back for repair and re-verification (at most
three rounds). No finding is marked verified on its own rebuilder's word.

The table is updated as each finding lands, and each landing is pushed
immediately, so a stoppage loses at most the finding in flight.

| Finding | Sev | Status | Commit |
|---|---|---|---|
| F-04 | Critical | **UPHELD WITH RESERVATIONS** (round 2; round 1 rejected a contact-address fallback that skipped a blank billing address — fixed); merged with F-04 × F-15 | `50edbe1` |
| F-13 | Critical | **UPHELD WITH RESERVATIONS** (round 4, under the ledger's occupancy precedent; rounds 1–3 rejected only its source-reading gates' completeness claims — cheap escapes fixed, the rest narrowed with measured occupancy); merged | `776c5d8` |
| F-14 | Critical | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking, 10 reservations); merged | `b2f33d1` |
| F-08 | High | **UPHELD WITH RESERVATIONS** (round 2; round 1 rejected one blocking item — fixed); merged | `6f605da` |
| F-11 | High | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking, 6 reservations); merged; closes F-24 DNS limb | `d69856a` |
| F-12 | High | **UPHELD WITH RESERVATIONS** (round 2; round 1 rejected two docblock-held ordering keys — now held); merged | `db4bcc6` |
| F-15 | High | **UPHELD WITH RESERVATIONS** (round 6; rounds 1–5 each rejected one or two items — a stale finding on the operator screen, census blind spots, a rewritten test that lost its capacity oracle, a first-attempt stranger claimed as this build's own (coordinator ruling), a pinned-id carve-out on a first attempt — all fixed); merged with F-04 × F-15 | `e0dbb9d` |
| F-17 | High | **UPHELD WITH RESERVATIONS** (round 7, under the occupancy precedent; rounds 1–6 rejected sentence reach and then the attachment scan's spellings; the per-address cooldown the audit clause asks for was built in round 2; all three audit clauses measured closed); merged, with the F-17 × F-27 `retry_at` declaration | `df9ca26` |
| F-18 | High | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking, 7 reservations); merged | `a327f44` |
| F-19 | High | **UPHELD WITH RESERVATIONS** by independent verification (round 1, 0 blocking, 7 reservations); merged | `45d53af` |
| F-20 | High | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking); merged | `05cafe8` |
| F-21 | High | **UPHELD WITH RESERVATIONS** (round 4, under the occupancy precedent; rounds 1–3 rejected its frontend gate's parsing and query-state model); merged | `64581fe` |
| F-22 | High | **UPHELD WITH RESERVATIONS** (round 4, under the occupancy precedent; rounds 1–3 rejected only the route-walk gate's model of Alertmanager/Prometheus/Loki — empty-label, Loki-flag and duplicate-key gaps fixed, sentences narrowed); merged | `42b1d71` |
| F-23 | High | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking, 7 reservations); merged; nine F-19 excuses retired at F-19's merge | `5ae2e58` |
| F-24 | High | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking; built on F-04, F-11, F-15 and F-45 — no longer red by design); the verifier was cut short before its green runs, which the coordinator ran after merging: 1,165/1,165 across Compute, SharedHosting, Simulation, Vps, Dns and Architecture | `0586031` |
| F-41 | High | **UPHELD WITH RESERVATIONS** (round 2; round 1 rejected two missing oracles — guard-before-trait ordering, and a trait list read from the constant under test — both fixed); a deliberately exported DB_DATABASE / REDIS_DB still selects the run's database and index, so the rebuild's isolation is unchanged; merged | `e65d8e5` |
| F-46 | High | **UPHELD WITH RESERVATIONS** (round 2; round 1 rejected one blocking item — fixed); census rebuilt first; merged | `3fa4d48` |
| F-25 | Medium | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking); merged | `272d8a3` |
| F-26 | Medium | **UPHELD WITH RESERVATIONS** (round 2; round 1 rejected an IDN platform host left unreserved — now folded to its A-label); merged | `6b32e9b` |
| F-27 | Medium | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking); merged | `7c75b98` |
| F-28 | Medium | **UPHELD** outright (round 1, no reservations); merged | `47df427` |
| F-29 | Medium | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking); merged | `62aac2d` |
| F-30 | Medium | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking); merged | `d9dbf7c` |
| F-31 | Medium | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking); merged | `07c8ed3` |
| F-32 | Medium | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking); merged | `58dfc01` |
| F-33 | Medium | **UPHELD WITH RESERVATIONS** (round 2; round 1 rejected one blocking item — fixed); merged | `b864728` |
| F-34 | Medium | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking); merged | `f30f479` |
| F-35 | Medium | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking); merged | `68c33c0` |
| F-36 | Medium | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking); merged | `eab4d6b` |
| F-37 | Medium | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking); merged | `a922b43` |
| F-38 | Medium | **UPHELD WITH RESERVATIONS** (round 5, under the occupancy precedent; round 4 rejected an empty-YAML inventory the INI plugin reads — fixed); merged, its self-test arity guard carried onto F-22's grown table (77 cases) | `f31f42d` |
| F-39 | Medium | **UPHELD WITH RESERVATIONS** (round 4, under the occupancy precedent; rounds 1–3 rejected the runbook gate's Markdown reading); merged; counts block regenerated for F-22/F-37's alerts | `853892f` |
| F-40 | Medium | **UPHELD WITH RESERVATIONS** (round 4, under the occupancy precedent; rounds 1–3 rejected LayeringTest's reading of PHP names); merged | `831096a` |
| F-42 | Medium | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking); merged — status stays PARTIAL as the ledger recorded: mechanism established, budget question unanswerable at obtainable sample sizes | `cd99002` |
| F-43 | Medium | **UPHELD WITH RESERVATIONS** (round 2; round 1 rejected one blocking item — fixed); merged | `8799204` |
| F-44 | Medium | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking); merged | `a0462ca` |
| F-45 | Medium | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking); merged | `76c75d5` |
| F-47 | Medium | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking); census rewritten for F-12's production writer, not patched; merged | `93327c3` |

F-01, F-02, F-03, F-05, F-06, F-07, F-09, F-10 and F-16 were closed before
round two and are already in the tree.

## Full-suite checkpoints

| When | Findings merged | Backend suite | Proofs |
|---|---|---|---|
| after F-04 (`2b40b61`) | F-04, F-08, F-11, F-12, F-13, F-14, F-18, F-22, F-23 | 4,279 / 4,279 passed, 154,383 assertions | JSON `tests == passed`; junit 545 testsuites, all `skipped="0"`; exit 0 |
| after F-15 + F-04 × F-15 (`9065968`) | + F-17, F-15 | 4,326 / 4,327 — one timing-dependent failure in the new F-04 × F-15 test (`updated_at` to the second), fixed next commit, 5/5 green after | exit 1 on that run |
| after F-19 (`b14fee5`) | + F-19 (12 findings) | 4,357 / 4,357 passed, 155,166 assertions | JSON `tests == passed`; junit 555 testsuites, all `skipped="0"`; exit 0 |
| after upheld repairs F-04/F-08/F-12 (`501cab7`) | 12 findings + 3 repair rounds | 4,381 / 4,381 passed, 155,284 assertions | JSON `tests == passed`; junit 557 testsuites, all `skipped="0"`; exit 0 |
| after workflow A (12 more findings) | 24 findings | 4,836 / 4,837 — the one failure was the ledger-predicted F-26 × F-27 row, fixed next commit as the ledger ruled (804/804 in Dns, Api, Security, Architecture after) | exit 1 on that run; frontend vitest 473/473, tsc + eslint clean |
| after F-13, F-15, F-22 final repairs | 25 findings | 5,020 / 5,020 passed, 166,820 assertions | JSON `tests == passed`; junit 600 testsuites, all `skipped="0"`; exit 0 |
| after workflow B's eight upheld findings | 34 findings | 5,094 / 5,094 passed, 167,461 assertions | JSON `tests == passed`; junit 609 testsuites, all `skipped="0"`; exit 0; frontend vitest 495/495, tsc + eslint clean |
| after F-21, F-24, F-38, F-39, F-40 | 35 findings | 5,117 / 5,117 passed, 167,555 assertions | JSON `tests == passed`; junit 615 testsuites, all `skipped="0"`; exit 0; frontend vitest 627/627, tsc + eslint clean; every validator and self-test green (safety-gate 10/10 with ansible-core on PATH — it is 5/10 without, identically on `main`) |
| after F-41 — **the re-audit candidate**, all 36 findings | 36 findings | 5,170 / 5,170 passed, 167,817 assertions | JSON `tests == passed`; junit 624 testsuites, all `skipped="0"`; exit 0 |

Baseline before any rebuild: 3,905 / 3,905. Frontend baseline: vitest 471 / 471.

## Final re-audit and adjudication

`docs/final-independent-re-audit-after-round-2.md`, at the candidate `462382f`:
**36 of 47 findings do not reproduce; eleven do** — F-42 reproduces, and F-01,
F-02, F-05, F-07, F-09, F-16, F-20, F-23, F-26 and F-38 partially reproduce,
each confirmed by an independent skeptic. Six of the eleven (F-01, F-02, F-05,
F-07, F-09, F-16) were closed before round two and had not been measured again
until now. Round two itself introduced 28 recorded defects. All five products
remain `No`.

**`SOFTWARE_CODE_COMPLETE = NO`.** `30B.0-E = NOT READY`; every `REAL_*` and
`READY_TO_SELL` = `NONE`.

## Round three — fixing what the re-audit found

Ten groups, each fixed on `round3/NN` with a failing test first, then an
independent verifier (repair and re-verify until upheld), then merged; a fresh
independent re-audit follows. The specification is
`docs/final-independent-re-audit-after-round-2.md`.

| Group | Scope | Status | Commit |
|---|---|---|---|
| 01 | F-01 plan changes: credit minting, resize to what was paid, atomicity; F-01 × F-06 capacity; billing route docblock, units, throttle names | upheld with reservations after four repairs (trial merge onto 4d900d9 clean and green); merged `88c4dd8`; full suite 5,507/5,507, 674 suites `skipped="0"`, exit 0; vitest 642/642, tsc, eslint | `727a403` |
| 02 | F-05 settle-to-fulfil cancel window; F-07 feasibility and renewal of undelivered services; I-1 ended services keep renewing; refunds in-flight | upheld with reservations; follow-up `f10e588` checked; merged `2325458` | `f10e588` |
| 03 | F-02 production writer of allocatable addresses and OS profiles; stranded chassis; estate tests that stop short | upheld after four repairs (last delta checked); merged `2325458` | `dc0d06c` |
| 04 | F-09 restore/verify clock measured from the archive; second restore over the same disks | upheld with reservations; merged `2325458` | `a087707` |
| 05 | F-16 `--env` disarms production guards; operator RBAC (role removal, permission emptying, invite atomicity, bootstrap promotion); F-03 test; EndpointPolicy 5f00::/16 | upheld with reservations; merged `a607ea2`, full suite 5,199/5,199, 628 suites `skipped="0"`, exit 0 | `31c8357` |
| 06 | F-26 empty reserved-zone default and preflight wording; I-3 customer blamed for operator misconfiguration | upheld with reservations; sentences narrowed in `862f416`; merged `306aa57`, full suite 5,233/5,233, 631 suites `skipped="0"`, exit 0 | `862f416` |
| 07 | F-20 false rebuild label; F-42 vitest under load; F-21 gate budget | upheld with reservations; follow-up `46e5a17`; merged `0a4ffda`: vitest 642/642, tsc, eslint, Architecture 219/219 | `46e5a17` |
| 08 | F-23 enum reachability and translation-gate reconciliation; producer-gate blind spots; F-44 clock; F-41 guard gaps; I-2; F-14 oracles | upheld with reservations (follow-up verified); merged `65965e7`, full suite 5,296/5,296, 638 suites `skipped="0"`, exit 0 | `584ce03` |
| 09 | F-38 `tofu validate` on no configuration; validator empty subjects and stale docstrings; CI grep exit codes | upheld after four repairs (last delta checked by the coordinator); merged `e423c17`: every self-test, safety gate 10/10, make infra-validate 0 | `4d23a55` |
| 10 | Simulator convenient cases (occupied VMID, `.invalid` domains); DirectAdmin `error=0`; uncatalogued error codes on customer routes | upheld with reservations; follow-up `b1147a9` checked; merged `5cbd39d`: Architecture+Api+Simulation+Compute 633/633, pint, openapi | `b1147a9` |

Candidate `4d900d9` (05–10 plus 04, 02, 03 and four integration fixes), merged
as `2325458`: backend 5,454 / 5,454, 669 testsuites `skipped="0"`, exit 0;
`make infra-validate` 0; tsc and eslint clean.

PHPStan could not be run in this environment: its dependencies download from
`api.github.com` and `codeload.github.com`, which the environment's network policy
refuses (measured 2026-09-26). It runs in CI (`ci.yml`, "Static analysis").

### Independent re-audit after round three

Dispatched against `88c4dd8` (all ten groups merged; the three proofs above):
five band re-auditors (A–E, the bands of `docs/round-2-briefs/final-re-audit.md`)
and one for "what round three introduced", each in its own worktree, database
and Redis index. Brief: the round-two master brief plus round-three overrides.
Skeptics and the five-product matrix follow.

### Round four — fixing what the re-audit after round three found

| Band | Scope | Status | Commit |
|---|---|---|---|
| A | F-07 (c); cancelled/ended subscription invoices; downgrade credit vs card refund; lapse vs refund in flight; refund/settle deadlock; discounted credit; pending refunds never settle | upheld with reservations after two repairs; merged | `b70eb6f` |
| B | customer role editable into operator permissions; bootstrap race; cached configuration | upheld with reservations; merged; Horizon staff gate added `841f05b` | `12b1a03` |
| C | F-09 stale restore handle; file restore of unreadable archives and deletion under it; F-26 production guard | upheld with reservations; merged `8afa8c6`; runbook `6837954` | `ad9af6a` |
| D | operator retry after released capacity; stranger elsewhere in the cluster; network-less customer subnets | upheld with reservations after one repair; merged | `fb1518c` |
| E | F-23 enum gate self-references and by-value excuses; F-38 Ansible credential variables | upheld with reservations; merged `427c9be`; reservations `03e3919` | `06ff527` |

All five round-four bands merged at `a6b583b`: backend 5,601 / 5,601, 690
testsuites `skipped="0"`, exit 0; vitest 648 / 648, tsc and eslint clean;
`make infra-validate` 0 and every infrastructure self-test green. An
independent re-audit after round four is dispatched against `a6b583b`.

### Round five — fixing what the re-audit after round four found

The re-audit against `a6b583b` (five bands plus "what round four introduced",
with skeptics) is in `scratchpad` digests until its document is written. Its
numbered verdicts: F-07 partially reproduces (a customer-facing network with no
bridge is sold onto); F-09 partially reproduces (overlapping reconcile sweeps
settle a later restore attempt); F-23 partially reproduces (by-value excuses hid
unwritten server and node states). Every other finding re-audited does not
reproduce. The unnumbered items it found stay unnumbered.

| Band | Scope | Status | Commit |
|---|---|---|---|
| A | F-07 network attachability; system ledger keys a customer's Idempotency-Key can take; renewal/plan-change deadlock; undelivered paid upgrade on wind-up; refund failed after success; wording | fixing | — |
| B | a customer-surface API token accepted on `/api/admin`; staff-gate oracle blind to `withoutMiddleware`; one-super-admin chain walked | fixing | — |
| C | F-09 compare-and-set on the attempt; a scalar DirectAdmin `list` read as no accounts; file-restore lock oracle | fixing | — |
| D | a VPS built with no gateway; a quarantine test red at midnight; capacity left on the node a retry moved away from | fixing | — |
| E | F-23 per-case entries replace the by-value excuses (coordinator; each entry mutation-pinned) | awaiting verifier | `6568d78` |
