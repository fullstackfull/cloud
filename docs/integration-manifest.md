# The integration manifest, computed rather than remembered

**Taken at `b9f72fb` on `claude/relaxed-turing-nh8ybf`. Recompute before you
act on it** — the script that produced it is in this document, and a manifest
that is not re-derived at integration time is a list of shas that were true
once.

This exists because a worktree slug does not name a finding's work, and the
ledger's status cells have been caught naming a branch whose tip carries none
of the finding's commits. Reading a row and merging the branch its slug
suggests would merge the wrong thing and look like success. So the manifest is
derived from the commit graph, not from prose.

## The rule that produces it

> **A finding's integration tip is the descendant-most commit reached by an
> `f`-branch for that finding. Verification branches are never merged.**

Both halves were tested rather than assumed, and the second half needed
checking, because six verification branches carry commits their finding's
`f`-tip does not reach. See "What is on the verification branches" below.

Derivation, which is the authority for the table:

```python
# for each finding: the f-branches with commits since their merge-base with
# the integration branch, then the one commit among them that has every other
# as an ancestor. If that commit is not unique, the finding has genuinely
# forked and needs a human.
```

Run over all `remediation/f*` branches this yields **exactly one tip for every
finding that has work** — no forks. The two findings with an empty branch,
F-17 and F-46, have rounds in flight and no commits yet.

## The manifest

| finding | branch | tip | commits |
|---|---|---|---|
| F-04 | `remediation/f04m` | `4e3a324` | 26 |
| F-08 | `remediation/f08g` | `4b90fa6` | 30 |
| F-11 | `remediation/f11f` | `0adebcd` | 25 |
| F-12 | `remediation/f12` | `61409f1` | 12 |
| F-13 | `remediation/f13` | `224a5b8` | 21 |
| F-14 | `remediation/f14t` | `0a94264` | 33 |
| F-15 | `remediation/f15j` | `0f7151c` | 35 |
| F-17 | *(no work yet — round in flight)* | — | — |
| F-18 | `remediation/f18` | `eae4ff1` | 14 |
| F-19 | `remediation/f19` | `4bcefa3` | 8 |
| F-20 | `remediation/f20` | `0d2e178` | 5 |
| F-21 | `remediation/f21` | `1e1ecc7` | 5 |
| F-22 | `remediation/f22e` | `44d0f6f` | 5 |
| F-23 | `remediation/f23c` | `ddd2c54` | 3 |
| F-24 | `remediation/f24a` | `dba5965` | 1 |
| F-25 | `remediation/f25b` | `e7c09ac` | 2 |
| F-26 | `remediation/f26` | `63f254f` | 7 |
| F-27 | `remediation/f27,remediation/f27d` | `a7a017a` | 11 |
| F-28 | `remediation/f28` | `ad46b35` | 4 |
| F-29 | `remediation/f29g` | `e55b11c` | 22 |
| F-30 | `remediation/f30b` | `75ea887` | 3 |
| F-31 | `remediation/f31` | `85c2ddb` | 17 |
| F-32 | `remediation/f32` | `79b1c36` | 6 |
| F-33 | `remediation/f33e` | `768442f` | 12 |
| F-34 | `remediation/f34b` | `33331fa` | 2 |
| F-35 | `remediation/f35` | `aec350f` | 5 |
| F-36 | `remediation/f36a` | `6c4f255` | 2 |
| F-37 | `remediation/f37b` | `adc754e` | 3 |
| F-38 | `remediation/f38g` | `d3d4fc9` | 4 |
| F-39 | `remediation/f39a` | `64feace` | 1 |
| F-40 | `remediation/f40a` | `8f900db` | 1 |
| F-41 | `remediation/f41g` | `de9da2b` | 4 |
| F-42 | `remediation/f42b` | `76ae3a1` | 3 |
| F-43 | `remediation/f43a` | `6b9ee69` | 1 |
| F-44 | `remediation/f44a` | `62d2059` | 1 |
| F-45 | `remediation/f45a` | `6f03478` | 1 |
| F-46 | *(no work yet — round in flight)* | — | — |
| F-47 | `remediation/f47g` | `dfadcd1` | 6 |
Findings not in the table have nothing to integrate: **F-01, F-02, F-03, F-05,
F-06, F-07, F-09, F-10 and F-16** closed before the branch-per-finding regime
and are already on the integration branch. That claim is enforced rather than
asserted — `validate-ledger-rows.py` accepts the phrase *"already on the
integration branch"* only from a row that holds no `remediation/f<NN>*` branch.

## What is on the verification branches, and why none of it is merged

Six verification branches carry a commit their finding's `f`-tip does not
reach. They fall into two groups and neither is a reason to merge a `v`-branch.

**Four are verification evidence, and it is documentation.** `v14n`, `v34b`,
`v38b` and `v40b` each carry one commit of mutation logs, band outputs, probe
scripts and an evidence write-up — 28, 11, 1 and 6 files respectively, all
under `docs/verification/`, `docs/round-2-verifications/` or (in `v34b`'s case,
inconsistently) a top-level `verification/`. No source, no test, no
configuration.

**This is the one thing in the integration that is genuinely lost by the
rule**, and it is lost deliberately. The evidence is the audit trail of four
verifications, and it is reachable at those branch tips for as long as the
branches exist. Merging it would put four differently-shaped evidence
directories into the tree at three different paths, none of which the
repository has a convention for. **If it is to be kept it should be kept as one
directory with one shape, cherry-picked deliberately at integration, not
inherited by accident from whichever branch happened to carry it.** That is a
decision, and it is recorded here as one rather than taken silently.

**Two are the same commit, three times.** `v12c`, `v12g` and `v29e` each carry
a commit titled *"Let a checkout own the Redis database its workers use"* —
three distinct shas (`cc7f7ba`, `5961359`, `a7e0c60`) with byte-identical
stats: three files, 45 insertions, 6 deletions. It is one infrastructure change
cherry-picked separately onto three verification branches, and it **arrives at
integration anyway**, through the `f`-tips of F-04, F-13, F-14, F-15, F-29 and
F-31, all of which reach it. Nothing is lost.

It is worth one more sentence, because it explains something this programme
spent a round on. That commit touches `WorkerHarness.php` and
`ConsolePermitConcurrencyTest.php` in the same diff, and it is where the
`is_numeric($configured) ? (int) $configured : self::REDIS_DATABASE` idiom was
written — **twice, in one commit**. F-41's repair had to find both copies, and
the reason there were exactly two is visible here: one author, one sitting, two
files.

## The migration collisions: seven files, three groups

Recomputed over the manifest above, so this is the collision set the
integration will actually hit and not an older one:

| timestamp | finding | file |
|---|---|---|
| `2026_04_15_000000` | F-04 | `record_the_domain_a_hosting_line_was_bought_for.php` |
| | F-08 | `record_which_payment_failure_the_dunning_counter_counted.php` |
| | F-15 | `reserve_a_provider_identity_before_the_call.php` |
| `2026_04_16_000000` | F-11 | `record_which_call_left_a_dns_record_unanswered.php` |
| | F-15 | `record_which_cluster_an_identity_was_reserved_against.php` |
| `2026_04_17_000000` | F-11 | `one_live_row_per_provider_record.php` |
| | F-15 | `record_every_name_a_reserved_identity_was_called_with.php` |

Ten migrations are added by the manifest in total; seven of them collide.

**The scheme is F-32's, because F-32 already did this and its answer is
better than inventing a second one.** `remediation/f32` carries
`2026_04_15_000032_index_hosting_packages_by_plan.php`: the last six digits of
the timestamp carry the finding number. Applied to the seven:

```
2026_04_15_000004_record_the_domain_a_hosting_line_was_bought_for.php
2026_04_15_000008_record_which_payment_failure_the_dunning_counter_counted.php
2026_04_15_000015_reserve_a_provider_identity_before_the_call.php
2026_04_16_000011_record_which_call_left_a_dns_record_unanswered.php
2026_04_16_000015_record_which_cluster_an_identity_was_reserved_against.php
2026_04_17_000011_one_live_row_per_provider_record.php
2026_04_17_000015_record_every_name_a_reserved_identity_was_called_with.php
```

It is worth saying why a scheme is needed at all rather than just picking
distinct numbers. Laravel orders migrations by filename, so two files with the
same timestamp run in an order decided by the filesystem — which is stable
enough to hide the problem locally and is not a guarantee. A scheme that
derives the suffix from the finding number gives the same answer on every
machine, is checkable by eye against the ledger, and makes the next collision
visible as a repeated number rather than as a repeated zero.

**Ordering is preserved and was checked, not assumed.** F-15's three run on
three consecutive days, so the day field keeps them in sequence whatever the
suffix; F-11's two likewise. Within `2026_04_15` the three colliding
migrations touch three unrelated tables — hosting lines, dunning counters and
provider identities — so no ordering between them is load-bearing.

**Nothing outside `database/migrations/` names any of the seven filenames**,
checked by grep across `*.php`, `*.md` and `*.yml`, with one exception: this
repository's own `docs/round-2-remediation-ledger.md` quotes four of them.
Those quotations need updating in the same commit as the rename, or the ledger
will name files that no longer exist — which is the citation-rot defect F-14
spent five corrections on, and there is no excuse for walking into it
knowingly.

## Still owed before integration

- **PHPStan has never run in this programme.** `tools/phpstan/vendor` is
  present and empty — 82 directories, zero files, no autoloader, no binary —
  and populating it needs `composer install`, which is forbidden here. The
  check is `test -x apps/control-plane/tools/phpstan/vendor/bin/phpstan`, which
  is false. So CI's `static-analysis` job has never been exercised against any
  of this work.
- **F-24 is red by design and mergeable only with F-04 and F-45 both present.**
  Measured at five points, not inferred: F-04 alone clears none of F-24's eight
  deliberate reds, F-45 alone clears six, both together clear all eight.
- **The contended files.** 31 of 378 touched files are touched by more than one
  finding. That set is computed in the ledger and should be recomputed against
  this manifest before the merge order is fixed.
