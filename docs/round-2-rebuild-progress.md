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
| F-13 | Critical | rebuilt + merged; rounds 1–3 REJECTED only on completeness claims of its source-reading gates (a new escape each round, none present in the tree); final round under the ledger's occupancy precedent running | `79b6b13` |
| F-14 | Critical | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking, 10 reservations); merged | `b2f33d1` |
| F-08 | High | **UPHELD WITH RESERVATIONS** (round 2; round 1 rejected one blocking item — fixed); merged | `6f605da` |
| F-11 | High | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking, 6 reservations); merged; closes F-24 DNS limb | `d69856a` |
| F-12 | High | **UPHELD WITH RESERVATIONS** (round 2; round 1 rejected two docblock-held ordering keys — now held); merged | `db4bcc6` |
| F-15 | High | rebuilt + merged; verification rounds 1–3 REJECTED (2, 1, 1 blocking — stale finding on the operator screen, census blind spots, a rewritten test that lost its oracle), each repaired; round 4 (repair + re-verify) running, incl. a coordinator ruling that a first-attempt stranger must not be claimed | `8e15efa` |
| F-17 | High | rebuilt + merged; verification rounds 1–3 REJECTED on sentence-vs-test reach; repairs built the per-address invitation cooldown the audit clause asks for; round 4 running | `171142b` |
| F-18 | High | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking, 7 reservations); merged | `a327f44` |
| F-19 | High | **UPHELD WITH RESERVATIONS** by independent verification (round 1, 0 blocking, 7 reservations); merged | `45d53af` |
| F-20 | High | in progress (rebuild → independent verification) | |
| F-21 | High | in progress (rebuild → independent verification) | |
| F-22 | High | rebuilt + merged; rounds 1–3 REJECTED only on the route-walk gate's model of Alertmanager/Prometheus/Loki (a new divergence each round, none present in the tree); final round under the occupancy precedent running | `bb1c77e` |
| F-23 | High | **UPHELD WITH RESERVATIONS** (round 1, 0 blocking, 7 reservations); merged; nine F-19 excuses retired at F-19's merge | `5ae2e58` |
| F-24 | High | queued | |
| F-41 | High | queued (last: changes the test harness) | |
| F-46 | High | in progress (rebuild → independent verification) | |
| F-25 | Medium | in progress (rebuild → independent verification) | |
| F-26 | Medium | in progress (rebuild → independent verification) | |
| F-27 | Medium | in progress (rebuild → independent verification) | |
| F-28 | Medium | in progress (rebuild → independent verification) | |
| F-29 | Medium | in progress (rebuild → independent verification) | |
| F-30 | Medium | in progress (rebuild → independent verification) | |
| F-31 | Medium | in progress (rebuild → independent verification) | |
| F-32 | Medium | in progress (rebuild → independent verification) | |
| F-33 | Medium | in progress (rebuild → independent verification) | |
| F-34 | Medium | in progress (rebuild → independent verification) | |
| F-35 | Medium | in progress (rebuild → independent verification) | |
| F-36 | Medium | in progress (rebuild → independent verification) | |
| F-37 | Medium | in progress (rebuild → independent verification) | |
| F-38 | Medium | in progress (rebuild → independent verification) | |
| F-39 | Medium | in progress (rebuild → independent verification) | |
| F-40 | Medium | in progress (rebuild → independent verification) | |
| F-42 | Medium | in progress (rebuild → independent verification) | |
| F-43 | Medium | in progress (rebuild → independent verification) | |
| F-44 | Medium | in progress (rebuild → independent verification) | |
| F-45 | Medium | in progress (rebuild → independent verification) | |
| F-47 | Medium | in progress (rebuild → independent verification) | |

F-01, F-02, F-03, F-05, F-06, F-07, F-09, F-10 and F-16 were closed before
round two and are already in the tree.

## Full-suite checkpoints

| When | Findings merged | Backend suite | Proofs |
|---|---|---|---|
| after F-04 (`2b40b61`) | F-04, F-08, F-11, F-12, F-13, F-14, F-18, F-22, F-23 | 4,279 / 4,279 passed, 154,383 assertions | JSON `tests == passed`; junit 545 testsuites, all `skipped="0"`; exit 0 |
| after F-15 + F-04 × F-15 (`9065968`) | + F-17, F-15 | 4,326 / 4,327 — one timing-dependent failure in the new F-04 × F-15 test (`updated_at` to the second), fixed next commit, 5/5 green after | exit 1 on that run |
| after F-19 (`b14fee5`) | + F-19 (12 findings) | 4,357 / 4,357 passed, 155,166 assertions | JSON `tests == passed`; junit 555 testsuites, all `skipped="0"`; exit 0 |
| after upheld repairs F-04/F-08/F-12 (`501cab7`) | 12 findings + 3 repair rounds | 4,381 / 4,381 passed, 155,284 assertions | JSON `tests == passed`; junit 557 testsuites, all `skipped="0"`; exit 0 |

Baseline before any rebuild: 3,905 / 3,905. Frontend baseline: vitest 471 / 471.
