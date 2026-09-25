# The final independent re-audit — master brief

This is the brief for the re-audit that follows round two. It is written now
and dispatched later, because **it cannot run until integration is done.**

## Read this first, because it is the trap

Every remediation branch is **unmerged**. `claude/relaxed-turing-nh8ybf` — the
branch the repository checks out — contains none of round two's work. An agent
pointed at it would audit the *unremediated* tree, reproduce every finding
perfectly, and report that round two achieved nothing.

**So the re-audit runs against the integration tree and nothing else**, at a
sha named in the dispatch. If the sha you are given does not contain the work,
say so in those words and stop. Check it cheaply: the tree must carry
`apps/control-plane/tests/Support/RedisIndexForThisRun.php` (F-41) and
`apps/control-plane/database/migrations/2026_04_15_000004_*` (the renumbering).
If either is missing you have the wrong tree.

## What the re-audit is for, and what it is not

It answers three questions, in this order, and the third depends on the first
two:

1. **Does each of F-01 … F-47 still reproduce?** Not *was it addressed* —
   whether the defect the audit described can still be produced.
2. **What did round two introduce or leave?** Regressions, new defects in
   remediation code, and the standing residues round two disclosed.
3. **Can `SOFTWARE_CODE_COMPLETE` truthfully become `YES`?**

It is **not** a review of the remediation's craft, not a re-run of the
verifications, and not an opportunity to renumber or rename anything.

## The prohibition that outranks every finding

**F-48 does not exist and must not be invented.** The authorised namespace is
**F-01 through F-47 and nothing else.** A defect you find that is not one of
the 47 is reported as *an unnumbered observation*, described in full, with its
evidence — and it stays unnumbered. Do not create new phases, new roadmaps or
new numbering schemes. This is not a formality: a finding number in this
programme is a commitment to a remediation round, and inventing one commits
work nobody authorised.

## Do not trust the ledger

`docs/round-2-remediation-ledger.md` is the coordinator's record. It is
available to you and you may read it **after** you have measured, to compare —
never before, and never as a substitute. Its rows are what this re-audit is
checking, so reading them first is reading the answer.

The same applies to every docblock in the tree that asserts a property. This
programme has found, in four consecutive verifications, at least one sentence
per round that was reported as corrected and was not. **A claim in a comment is
a hypothesis.**

## Method, per finding

For each finding in your band:

1. Read the audit's own description of it in
   `/home/user/cloud/docs/final-independent-multi-agent-audit-round-1.md` —
   that text is the specification, not the ledger's summary of it.
2. **Try to reproduce the defect.** A test, a probe, a query, a mutation —
   whatever the defect's shape needs. Say what you ran.
3. Report one of exactly these, and nothing vaguer:
   - **DOES NOT REPRODUCE** — with the mechanism that now prevents it, and the
     measurement that shows it.
   - **REPRODUCES** — with the reproduction, verbatim.
   - **PARTIALLY REPRODUCES** — with precisely which half, and which half not.
   - **COULD NOT ESTABLISH** — in those words, with what you tried and what
     stopped you.
4. **And then ask the second question, which is the one that matters more:**
   is the defect prevented by something that would *notice* if it came back, or
   only by the current state of the code? A defect closed by an edit is closed
   until the next edit. A defect closed by an oracle stays closed. Say which,
   for every finding, and name the oracle.

Question 4 is where this re-audit earns its keep. Round two's own standing
complaint about itself is that a list is not a rule, and a green suite is not
evidence that anything was asked.

## Bands

Dispatched as separate agents, each in its own worktree, database and Redis
index. **No two of you share anything.**

| band | findings | centre of gravity |
|---|---|---|
| A | F-01, F-05, F-06, F-07, F-08, F-27 | money and the order/payment seam |
| B | F-02, F-03, F-16, F-17, F-19, F-22, F-31 | inventory write paths, operator bootstrap, authorization |
| C | F-04, F-09, F-10, F-11, F-12, F-13, F-14, F-18, F-26 | provider contracts and the per-product paths |
| D | F-15, F-20, F-21, F-24, F-25, F-28, F-29, F-30, F-35, F-42, F-43, F-44 | provisioning, simulation and the test estate |
| E | F-23, F-32, F-33, F-34, F-36, F-37, F-38, F-39, F-40, F-41, F-45, F-46, F-47 | architecture tests, CI/IaC gates, configuration, containment |

## Two cross-cutting assignments, which are not bands

**The five-product matrix.** The audit published one, and every cell said
**No**. Re-derive it — VPS, Dedicated, Shared Hosting, DNS, Backups — and for
each product name the findings that still block it. **Do not copy the audit's
blocking lists**; derive them from your bands' results. A product moves to
*Yes* only if every finding the audit named for it does not reproduce **and**
nothing new blocks it. Say for each product which it is and why.

**What round two introduced.** Round two added roughly 450 files' worth of
change across 37 findings. Look for: a test that asserts current behaviour
rather than intended behaviour; an oracle that cannot be made red by the
failure its name promises; a gate whose subject can be empty; a docblock
claiming a property the code does not have; and any place where two findings'
repairs interact badly. This programme has recorded all five of these shapes
happening to itself, so they are not hypothetical.

## The statuses, which do not move

These are **frozen** and no result of yours changes them:

```
30B.0-E              = NOT READY
REAL_INFRA_VERIFIED  = NONE
REAL_PAYMENT_VERIFIED= NONE
REAL_REGISTRAR_VERIFIED = NONE
REAL_HOSTING_VERIFIED= NONE
READY_TO_SELL        = NONE
```

**No local test, simulator, inventory row, UI page or fake provider may change
any of them.** If your reading of the tree suggests otherwise, your reading is
wrong and the interesting thing is *why the tree misled you* — report that.

`SOFTWARE_CODE_COMPLETE` is the coordinator's adjudication, not yours. What is
yours is the evidence for it: state plainly, with your measurements, whether
the code gaps in your band are closed, and do not round up.

And the standing rule on WordPress, which is F-45's: **never promote WordPress
merely to eliminate a finding.** `Product::softwareState()` and
`ProductSellability` do not move. If a change would make any surface read as
more sellable than it was, that is the wrong change.

## Standing constraints

- **No real infrastructure.** No providers, credentials, payments, registrars,
  no apply, no deploy, no Docker. Simulators only. This is a re-audit of files
  and behaviour, not of a deployment.
- Never `git stash` — the stash stack is shared across worktrees.
- Never `composer install` or `composer dump-autoload`.
- Never edit `vendor/` in place; use `scratchpad/unshare-vendor-package.sh`.
- Never `pkill` anything you did not start.
- **Never run a bare `php artisan migrate:fresh`.** `.env` carries
  `APP_ENV=local` and `DB_DATABASE=lynomia`, so from `apps/control-plane` it
  lands on the **application** database. It has happened in this programme.
  Export `DB_DATABASE` explicitly and check
  `config('database.connections.pgsql.database')` before you run it.
- One `php artisan test` at a time against your database.
- Known flake: `TheBridgeRefusesToRunWhereItMustNotTest`, about one full run in
  2,300.
- You may write to the tree **only** in your own worktree, only for probes and
  mutations, and everything you write is restored against the bytes you took
  before — never `git checkout --`. Your worktree ends clean and you say so.

## Proof standard

Anything you call green carries all three:

- a JSON summary with **no `skipped` key** and `tests == passed`;
- `--log-junit`, every `testsuite` at `skipped="0"`;
- `ARTISAN-TEST EXIT=0`, taken with `${PIPESTATUS[0]}`.

Test counts add across paths. **Assertion totals do not.**

## What to hand back

A section per finding in your band, each with its verdict from the four above,
the reproduction or the mechanism, and the answer to question 4 naming the
oracle. Then your cross-cutting assignment if you have one. Then, in those
words, everything you **could not establish**.

Do not summarise your band as a score. *"Eleven of twelve do not reproduce"* is
a number; which one does, and what it costs, is the report.
