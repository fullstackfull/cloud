# Round two — rebuild progress

Round two's remediation code lived on `remediation/f*` branches in a cloud
session that was never pushed; that container is gone and the code with it.
`docs/round-2-remediation-ledger.md` and `docs/round-2-briefs/` survived and
describe each finding's final, accepted fix.

This rebuild reimplements those final fixes directly on this branch, one finding
at a time, each with a regression test shown red on the unfixed tree and green
after. It does not repeat the review rounds; one independent re-audit
(`docs/round-2-briefs/final-re-audit.md`) runs at the end.

The table is updated as each finding lands, and each landing is pushed
immediately, so a stoppage loses at most the finding in flight.

| Finding | Sev | Status | Commit |
|---|---|---|---|
| F-04 | Critical | in progress | |
| F-13 | Critical | rebuilt — 47 new tests red→green, Providers+Architecture green | `79b6b13` |
| F-14 | Critical | rebuilt — 91 new tests (71 red→green), SharedHosting+Architecture green | `b2f33d1` |
| F-08 | High | rebuilt — 12 of 47 new tests red→green; Queue+Billing+Architecture green | `f24548a` |
| F-11 | High | rebuilt — new contract tests red→green; Dns+Simulation+Security+Architecture green; closes F-24 DNS limb | `d69856a` |
| F-12 | High | rebuilt — 9+14 new tests red→green; Dedicated+Ipam+Api+Security+Architecture green | `e0aa0ab` |
| F-15 | High | in progress (round six + round-seven fixes) | |
| F-17 | High | queued (PARTIAL: control half) | |
| F-18 | High | in progress | |
| F-19 | High | queued | |
| F-20 | High | queued | |
| F-21 | High | queued | |
| F-22 | High | in progress | |
| F-23 | High | queued | |
| F-24 | High | queued | |
| F-41 | High | queued (last: changes the test harness) | |
| F-46 | High | queued (PARTIAL) | |
| F-25 | Medium | queued | |
| F-26 | Medium | queued | |
| F-27 | Medium | queued | |
| F-28 | Medium | queued | |
| F-29 | Medium | queued | |
| F-30 | Medium | queued | |
| F-31 | Medium | queued | |
| F-32 | Medium | queued | |
| F-33 | Medium | queued | |
| F-34 | Medium | queued | |
| F-35 | Medium | queued | |
| F-36 | Medium | queued | |
| F-37 | Medium | queued | |
| F-38 | Medium | queued | |
| F-39 | Medium | queued | |
| F-40 | Medium | queued | |
| F-42 | Medium | queued | |
| F-43 | Medium | queued | |
| F-44 | Medium | queued | |
| F-45 | Medium | queued | |
| F-47 | Medium | queued | |

F-01, F-02, F-03, F-05, F-06, F-07, F-09, F-10 and F-16 were closed before
round two and are already in the tree.
