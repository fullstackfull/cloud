# F-15 round six — independent verification (`v15k`)

You are the **independent verifier** for F-15's round six. You did not write
it and you do not defend it. Your worktree is `/home/user/worktrees/v15k`,
already cut at **`0f7151c1ce50e21575e6d43428f1b8c85ea4daeb`** on branch
`remediation/v15k`. Database `lynomia_test_v15k`, Redis index **2**, scratch
under `/tmp/claude-0/agent-v15k`.

**You do not have authority to declare F-15 closed.** You report; the
coordinator decides.

## Read this first

`/home/user/cloud/docs/round-2-briefs/adjudication-rule.md` — the two
questions. It replaces an earlier, defective "is the property closed" form
that is still quoted in older parts of the ledger. Apply the two questions to
every reservation you raise, and say which question each one fails.

Read F-15's row in `/home/user/cloud/docs/round-2-remediation-ledger.md` —
**the main checkout's copy, not your worktree's, which is frozen** (a defect of
mine, recorded there). Do not edit the ledger; hand back instead.

## What round six claims

One commit, three files, **nothing under `src/` changed**. Its claims, in the
order I would attack them:

1. **Both of round five's blocking defects reproduce**, hostname-preserving,
   with real executing writers — and the corrected census goes **red against
   each plant for the right reason**: B1 as a new file under `raw SQL`, B2 as
   an occurrence count of 2.
2. The fix is a **comment-stripped whole-file scan** with occurrence counts,
   the constant split into a one-entry never-grow list
   (`PROVISIONING_JOBS_PAYLOAD_WRITERS`), six shapes.
3. **Zero false positives over all 1,356 clean `src/` files**, measured with
   the shipped constants.
4. It **declined to adopt the raw-SQL regex it was shown**, because that regex
   cannot see `set attempts = ?, payload = ?`; mutation **M7** is offered as
   the proof.
5. **Ten mutations, ten killed** — and two caught holes in its own first
   draft, including **M9, which originally survived**: reverting to a
   line-by-line census was round five's exact hole, and its gate did not see it
   until `censusOf()` was split out.
6. Bands at the tip: **64 / 537 / 124**, the 537 being +4 for exactly the four
   new gate-on-the-gate tests, with 4, 66 and 34 suites all `skipped="0"`.
7. It **re-derived the band composition** and says the naive reading is wrong:
   the F-15 band is three files at 64, and the census file is in the broad
   band rather than in it.

## What to attack hardest, and why

**(a) The zero-false-positive figure over 1,356 files.** That is the claim a
census like this always gets wrong, and it is the one whose instrument is
easiest to mis-build. Rebuild the corpus yourself — do not reuse the round's
script — and say how many files you measured and how you enumerated them. If
you get a different denominator, that is a result.

**(b) M9, the mutation that survived its own first draft.** A mutation that
survives and is then killed by a refactor is the most interesting event in the
round, because the refactor is now load-bearing and nothing may be testing
that. Re-run M9 against the shipped tree, and then ask the question the round
did not: **is `censusOf()` being split out itself pinned by anything?** If
inlining it back silently restores the hole, the gate depends on a shape no
test holds.

**(c) The live, uncovered hole the round named itself.**
`$job->update($request->validated())` with `$guarded = ['id']` reaches the
column by mass assignment and is statically undecidable. The round says what
bounds it is the admin surface — *"four routes, one GET and three POSTs, no
PATCH and no PUT — which is a fact about `routes/`, not `src/`, and is not
asserted anywhere."* **Verify the route count yourself** and say whether it is
four. Then adjudicate: the round declined to add `payload` to `$guarded`
because that is a production behaviour change made so a test can pass. I think
that reasoning is right and I want it tested, not agreed with. If you think an
assertion over `routes/` is available and cheap, say so and say where it would
live.

**(d) The three corrected sentences and the one stale count.** Check each
against the code at `0f7151c`, not against the round's description of it. This
programme's last four verifications each found at least one sentence that was
corrected into a new false one.

**(e) The band recomposition.** 64 / 537 / 124 with +4 attributable to exactly
four named tests is checkable to the test name. Check it that way.

## Proof standard

Three proofs, all three required, for anything you claim is green:

- a JSON summary with **no `skipped` key** and `tests == passed`;
- `--log-junit`, with **every** `testsuite` element at `skipped="0"`;
- `ARTISAN-TEST EXIT=0` — and take the exit code with `${PIPESTATUS[0]}`, not
  `$?` after a pipe.

Run `./vendor/bin/pint --test` **at tree scope from `apps/control-plane`**,
which is CI's `working-directory`. It is a first-class gate: a shipped docblock
has turned CI's lint step red in this programme while a full suite and a
token-level proof both passed over it.

Test counts add across paths. **Assertion totals do not** — do not report an
assertion delta as a finding.

## Mutation discipline, four obligations

1. Prove the edit landed (sha256 before and after, plus a whole-file
   `diff -u0` showing exactly the lines you meant).
2. Prove it landed **where you meant** — anchor by string, never by line
   number.
3. Prove it is behaviour-preserving except for the property under test.
4. **Restore against the value taken before, not against HEAD**, and never
   with `git checkout --`.

And: **a harness that refuses to mutate must also refuse to run.** Self-test
that, with a non-existent anchor, before you trust a single verdict from it.

## Standing constraints

- **No real infrastructure.** No providers, credentials, payments, registrars,
  no apply, no deploy, no Docker. Work against the simulators.
- Never `git stash` — the stash stack is shared across worktrees.
- Never `composer install` or `composer dump-autoload`.
- Never edit anything under `vendor/` in place; use
  `scratchpad/unshare-vendor-package.sh <slug> <ns>/<pkg>`.
- Never `pkill` or `killall` anything you did not start.
- **Never run a bare `php artisan migrate:fresh`.** `.env` carries
  `APP_ENV=local` and `DB_DATABASE=lynomia`, so from `apps/control-plane` it
  lands on the **application** database. An agent in this programme has already
  done it.
- One `php artisan test` at a time against your database.
- A known flake: `TheBridgeRefusesToRunWhereItMustNotTest` fails about one full
  run in 2,300 for a reason that is not yours.

## What I want back

A verdict — UPHOLD, UPHOLD WITH RESERVATIONS, or REJECT — with every
reservation marked blocking or not, and the two questions applied to each.

Then answer the closure question directly, in these words or against them:
**six rounds in, is anything left that a seventh round could close?** If yes,
name the red it starts from. If the only content of a seventh round would be
prose edits, say so — that is an argument for closing, not for another round.

Say **"I could not establish"** in those words wherever that is the honest
answer. A measurement you could not take is a result; a measurement you assumed
is not.
