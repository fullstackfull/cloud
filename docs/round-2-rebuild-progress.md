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
| F-04 | Critical | rebuilt — 84 new tests (82 red→green), 1,792 related tests green; 3 migrations | `9ff1e82` |
| F-13 | Critical | rebuilt — 47 new tests red→green, Providers+Architecture green | `79b6b13` |
| F-14 | Critical | rebuilt — 91 new tests (71 red→green), SharedHosting+Architecture green | `b2f33d1` |
| F-08 | High | rebuilt — 12 of 47 new tests red→green; Queue+Billing+Architecture green | `f24548a` |
| F-11 | High | rebuilt — new contract tests red→green; Dns+Simulation+Security+Architecture green; closes F-24 DNS limb | `d69856a` |
| F-12 | High | rebuilt — 9+14 new tests red→green; Dedicated+Ipam+Api+Security+Architecture green | `e0aa0ab` |
| F-15 | High | in progress (round six + round-seven fixes) | |
| F-17 | High | queued (PARTIAL: control half) | |
| F-18 | High | rebuilt — 14 new tests red→green; Admin+Termination+SharedHosting+Security+Architecture green | `a327f44` |
| F-19 | High | in progress | |
| F-20 | High | in progress (rebuild → independent verification) | |
| F-21 | High | in progress (rebuild → independent verification) | |
| F-22 | High | rebuilt — drift alert + route pinned by validator (39/39), log path fixed; Architecture+Monitoring green | `bb1c77e` |
| F-23 | High | rebuilt — reachability gate red→green (11 unwritten states allow-listed to owners), WordPress state strings added; Architecture 135/135, vitest/tsc/eslint clean | `5ae2e58` |
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
