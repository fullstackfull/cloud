# F-15 round seven — two reds, both in test files, neither prose

Round six was **REJECTED** by its independent verification (`v15k`, at
`0f7151c`). Most of the round is upheld, and upheld at a stronger standard than
the round itself used — the corpus was rebuilt twice by two independent
enumerations and came back at the same 1,356 files with the same four matches
and zero false positives; both of round five's blocking defects reproduce
hostname-preserving with real executing writers and the census is red against
each for the right reason; the M7 argument for declining the handed-down
regex is correct; the band recomposition is right to the test name.

**Two things block, and neither is a sentence.** Both are reproducible from
green, both are fixable inside test files, and neither needs a line of `src/`.

## Red 1 — the raw-SQL window is seven times too small, and nothing pins it

The shipped shape is

```
/\bset\b[^;]{0,200}?["'`]?\bpayload\b["'`]?\s*=(?![=>])/is
```

`ProvisioningJob::reserveProviderIdentity()` builds one raw `update … set …`
by concatenating string literals, and, comment-stripped, **there are 1,467
characters between that `set` and the point where a new column would be
appended.** So the detector cannot see into the very statement the
`WRITE_SHAPES` docblock at `:154-157` cites as its own reason for existing.

**Your starting red, which the verification already built and measured.**
Append to that existing UPDATE's SET clause:

```sql
payload = payload || jsonb_build_object(?::text, ?::text)
```

with the two bound arguments — *"stamp when we reserved into the payload so
the screen can show it"*, which is round six's own predicted future edit, made
inside a statement that was already there. It was proved to execute,
hostname-preserving, in a rolled-back transaction:
`{"hostname": "web-01"}` → `{"hostname": "web-01", "reserved_at": "…"}`.

With that writer live, the measured result is

```
537 tests, 537 passed, 2,770 assertions, 66 testsuites skipped="0", EXIT=0
```

byte-for-byte the clean figures. The census is silent, the never-grow list is
silent, the behavioural pin is silent. **Reproduce that yourself before you fix
anything** — if it does not reproduce, say so in those words and stop.

It matches none of the three exemptions the round's own *WHAT THIS SCAN STILL
DOES NOT SEE* section names: the column is spelled at the write site, it is not
in a variable, it is not assembled across statements, it is not mass
assignment.

**The fix and its cost are already measured, which is why this is cheap.** The
verification swept the clean 1,356-file corpus at window widths of 200, 400,
800, 1,500, 3,000 and 6,000 and found **zero matching files at every width** —
the raw-SQL shape fires on nothing in clean `src/` today, since all four census
entries are `array key`. So widening costs nothing. Re-measure it rather than
take it, then pick a width and **say why that width and not another**; a number
with no criterion is the defect this programme keeps finding.

**And the window is unpinned in the other direction too, which is the half you
must not skip.** Mutation `{0,200}` → `{0,30}` leaves the census file **9/9
green**. Every committed `raw SQL` fixture has a gap of ≤27 characters, so the
gate that exists to catch a loosened detector does not constrain the window at
all. Add a fixture whose gap is wide enough that narrowing the window fails,
and prove it by making the narrowing red.

## Red 2 — the seam: the gate tests the helper, the production path is `census()`

`censusOf()` was split out of `census()` during round six's own drafting,
because a mutation to a line-by-line loop had survived. Its docblock says the
split *"is not tidiness"*. The verification measured what that split actually
pins, and the answer is: the helper, not the path.

- Mutating **`censusOf()`** to a per-line loop is **killed** — one genuine
  assertion failure in `the_census_reads_whole_files_and_not_lines`. Round
  six's M9 is upheld.
- Mutating **`census()`** to scan per line itself, leaving `censusOf()`
  untouched, leaves the census file **9/9 green**. And with a heredoc raw-SQL
  plant in `RetryProvisioningJob` whose `set` and column sit on different
  lines, it is still **9/9 green, EXIT=0** — round five's exact hole fully
  reopened, with `the_census_reads_whole_files_and_not_lines` passing.
- Control: that same plant at the shipped tree **is red**. So the plant is a
  genuine second writer and the difference is the `census()` mutation alone.

The motive is not hypothetical. `census()` today slurps all 1,356 files into
one array before scanning, and a memory-motivated rewrite to stream
file-by-file is the ordinary shape of that edit — and round six's own first
draft failed in exactly this place.

**The fix**: drive that gate through `census()`'s own entry point rather than
through the helper — an injectable source reader, or an assertion on the
delegation. A handful of lines in a test file. **Then re-run both mutations**
and show the `census()` one dying.

## Ride-along corrections, all cheap, none blocking

Take these in the same commit; they are the kind that get lost otherwise.

1. **A sentence recorded as corrected was not.**
   `AProvisioningJobsPayloadIsWrittenOnceAndNeverAgainTest.php:140` still reads
   *"This one catches any second writer at all."* Round six's commit message
   and the ledger both say it was replaced; the diff shows the paragraph was
   **rewrapped and the sentence kept**, with the new *does-not-see* section
   added twenty lines above it, so the docblock now contradicts itself. Red 1
   falsifies the sentence again. Its twin is at
   `AnIndeterminateCreate…Test.php:1420` — the census *"fails on a second one,
   whatever shape it takes"*. Both fail question 2 in the unqualified form, so
   both are sentences to narrow, not code to change. **Narrow them, and do not
   report them as corrected without a grep proving it.**
2. **A new false count, introduced by the commit that corrected a stale one.**
   `normalise()`'s docblock says *"this very file's docblock spells three of
   the five shapes out."* There are **six** shapes, and of them exactly **one**
   (`raw SQL`) appears in the file's comment text. Both numerals are wrong.
   While you are there: comment stripping is currently a **no-op over `src/`**
   — zero of 1,356 files have a comment matching any shape — so its stated
   rationale is defensive rather than operative, and the docblock should say
   which it is.
3. **The route count is five, not four.** Measured with
   `php artisan route:list --json` and at `routes/api_admin.php:107-145`: two
   GETs and three POSTs, not one GET and three POSTs. *"No PATCH and no PUT"*
   is true. The extra route is a read, so the bound itself is unharmed — fix
   the numeral, keep the argument.
4. **The `routes/` bound should be asserted, and the precedent is in your own
   band.** `tests/Feature/Admin/AdminSurfaceTest.php:70` already iterates
   `Route::getRoutes()` filtered on the `api/admin` prefix. Roughly eight lines
   asserting that the `api/admin/provisioning*` route set and its verbs are
   exactly those five would close what the docblock honestly admits is
   asserted nowhere. **Do this**; it is the only one of the four that adds an
   oracle rather than fixing a word.
5. Optional, and say so if you skip it: a `;` inside a string literal in a SET
   clause defeats the raw-SQL detector (`set note = 'a; b', payload = ?`). The
   *does-not-see* section's stated reason is statement boundaries, which this
   is not.

## What is explicitly NOT in scope

- **Do not add `payload` to `$guarded`.** The verification tested round six's
  reasoning rather than agreeing with it and came down on round six's side:
  there is no `->update(…validated())`, no `->fill(`, no
  `ProvisioningJob::create(` and no `$job->update(` anywhere in `src/`, `app/`
  or `routes/`, and `ProvisioningController` calls `validated()` zero times.
  The shape has no call site at all, so forbidding it would be a production
  behaviour change made so a test could pass. **Round six was right to decline
  it and so are you.**
- **Do not adopt the handed-down raw-SQL regex.** M7 stands: it cannot see
  `set attempts = ?, payload = ?`.
- Nothing in `src/`. `git diff a23e099 0f7151c -- apps/control-plane/src` is
  zero lines and round seven should keep it that way.

## Mutation discipline, four obligations

1. Prove the edit landed (sha256 before and after, whole-file `diff -u0`).
2. Prove it landed **where you meant** — anchor by string, never by line.
3. Prove it is behaviour-preserving except for the property under test.
4. **Restore against the value taken before, not against HEAD**, never
   `git checkout --`.

And **a harness that refuses to mutate must also refuse to run** — self-test it
with a non-existent anchor first.

## Proof standard

Three proofs, all three required:

- a JSON summary with **no `skipped` key** and `tests == passed`;
- `--log-junit`, every `testsuite` at `skipped="0"`;
- `ARTISAN-TEST EXIT=0`, taken with `${PIPESTATUS[0]}`.

The three bands, whose clean figures are known: the F-15 band (three files)
**64/64, 566 assertions, 4 suites**; the broad band
(`tests/Feature/{Provisioning,Vps,Admin,Compute}` + `tests/Unit/Compute`)
**537/537, 2,770 assertions, 66 suites**; `tests/Architecture` **124/124,
8,535 assertions, 34 suites**. Report your after-figures against those, and
attribute every delta to a named test.

`./vendor/bin/pint --test` at tree scope from `apps/control-plane`.

Test counts add across paths. **Assertion totals do not.**

## Standing constraints

- **No real infrastructure.** No providers, credentials, payments, registrars,
  no apply, no deploy, no Docker. Simulators only.
- Worktree `/home/user/worktrees/f15m`, branch `remediation/f15m`, cut at
  `0f7151c`. Database `lynomia_test_f15m`, Redis index **2**.
- Never `git stash`; never `composer install`/`dump-autoload`; never edit
  `vendor/` in place (use `scratchpad/unshare-vendor-package.sh f15m <ns>/<pkg>`);
  never `pkill` anything you did not start.
- **Never run a bare `php artisan migrate:fresh`** — `.env` carries
  `APP_ENV=local` and `DB_DATABASE=lynomia`, so from `apps/control-plane` it
  lands on the application database. It has happened here.
- One `php artisan test` at a time against your database.
- Known flake: `TheBridgeRefusesToRunWhereItMustNotTest`, about one full run in
  2,300.

**Absolute paths** (a bare relative path from your worktree resolves to
`apps/control-plane/scratchpad/`):

- `/home/user/cloud/docs/round-2-remediation-ledger.md` — read F-15's row; do
  not edit it, your worktree's copy is frozen.
- `/home/user/cloud/docs/round-2-briefs/adjudication-rule.md` — the two
  questions; read before judging any sentence.

You do **not** have authority to declare F-15 closed.

## What I want back

Both reds reproduced from green, in the verification's own figures, before any
fix. Then the two repairs with the mutations that now kill them. Then the four
ride-alongs, each with the grep or the measurement that proves it landed — the
first one is on this list precisely because it was reported as done and was
not. Then the three bands, Pint, and, in those words, anything you **could not
establish**.
