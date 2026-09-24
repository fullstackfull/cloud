# Round 2 — Remediation Ledger

The execution record for the findings in
[`final-independent-multi-agent-audit-round-1.md`](final-independent-multi-agent-audit-round-1.md).

This is **not** a roadmap and it introduces no work of its own. It tracks
F-01 … F-47 and nothing else: the authorised finding namespace ends at F-47,
and a defect discovered while repairing one of them is recorded under
*Discovered / out of scope* rather than given a number.

A finding is CLOSED here only when an independent reviewer — never the agent
that repaired it — has reproduced the original defect, read the diff, tried to
invalidate the test oracle, and agreed; and only when the integration CI run
named in its row is green on the exact merged SHA.

## Status vocabulary

| Status | Meaning |
|---|---|
| `CLOSED` | Original defect not reproducible; guarded by a live oracle; verified independently; integration CI green on the named SHA. |
| `OPEN` | Not started, or started and not yet integrated. |
| `PARTIAL` | Some of the finding's subject is closed and some is not, with the remainder named exactly. |
| `BLOCKED_BY_EXISTING_FINDING` | Cannot be closed until another F-ID is, with that F-ID named. |
| `NOT_APPLICABLE_WITH_PROOF` | Obsoleted by a proven architecture change, or outside approved scope, with concrete code evidence and an independent reviewer. |

`mostly done`, `probably fixed` and `looks good` are not statuses.

## External truth — immutable for the duration of this program

    SOFTWARE_CODE_COMPLETE = NO
    30B.0-E                = NOT READY
    REAL_INFRA_VERIFIED    = NONE
    REAL_PAYMENT_VERIFIED  = NONE
    REAL_REGISTRAR_VERIFIED= NONE
    REAL_HOSTING_VERIFIED  = NONE
    READY_TO_SELL          = NONE

No local test, simulator, inventory row, admin screen or controlled provider
may move any of these. Software completeness is reconsidered in exactly one
place — the final independent re-audit — and never after an individual wave.

## Entry state

| | |
|---|---|
| Repository | `fullstackfull/cloud` |
| Branch | `claude/relaxed-turing-nh8ybf` |
| Entry HEAD | `6a7583523833ecdaea84136fdb0eb1c52ff4f121` |
| Findings in scope | F-01 … F-47 (47 total) |
| Accepted closed at entry | F-01, F-02, F-03, F-05, F-06, F-07, F-09, F-10, F-16, F-17 (10) |
| Remaining | 36 open, plus F-45 to adjudicate |

## Agent isolation

The Round-1 audit demonstrated that shared test state manufactures failures
that are about the harness rather than the code — `WalletRaceTest` failed 18 of
20 runs under cross-agent load and passed 20 of 20 quiet. So no two mutating
agents share anything:

`scratchpad/mkworktree.sh <slug>` gives each agent its own git worktree under
`/home/user/worktrees/<slug>`, its own branch `remediation/<slug>`, its own
PostgreSQL database `lynomia_test_<slug>`, its own Redis index and its own
scratch directory. `node_modules` is symlinked; `vendor/` is *assembled* —
packages symlinked one by one, but `vendor/composer`, `vendor/bin`,
`vendor/autoload.php` and PHPUnit's launcher copied. `lynomia_test` and
`lynomia_e2e` are reserved for the coordinator's integration runs.

The database name must contain `test`: `WorkerHarness` refuses to empty a
database that is not named as one, and `tests/Feature/Queue` and
`tests/Feature/Simulation` then error and leave rows behind that cascade into
dozens of false failures in whatever runs next.

The database override works because `phpunit.xml` pins `DB_DATABASE` without
`force="true"`, so an ambient variable wins. That is F-41, and this program
depends on the defect it is going to repair; when F-41 closes, this script must
change with it.

### Six isolation defects this program had to find the hard way

1. **The shared autoloader.** `vendor/` was first symlinked whole. Composer's
   autoloader hard-codes an absolute `$baseDir` computed from `__DIR__`, and
   PHP resolves `__DIR__` through symlinks — so every worktree loaded the
   canonical map. One `composer dump-autoload` then wrote *through* the
   symlink and repointed `$baseDir` at that agent's worktree, after which
   every other worktree, and the canonical repository, was executing that
   agent's uncommitted source while reporting its own suite green. Caught
   only because a deliberate breakage failed to break anything. The canonical
   `autoload_*.php` are now mode 444 so such a run fails loudly.
2. **`git stash` is not worktree-local.** The stack lives in the common
   `.git`; two agents had their stashes cross, one popping the other's work
   into its tree. Prohibited — `git diff > parked.patch` and
   `git checkout HEAD -- <path>` are the substitutes.
3. **`REDIS_DB` was a placebo for the two suites that need it.** The script
   hands out an index, but `WorkerHarness::REDIS_DATABASE` was a hard-coded
   `15` and `setUp()` overrode the ambient variable with it; each worker
   subprocess was handed `REDIS_DB => 15` explicitly too. Every agent's real
   worker traffic therefore shared one index and deleted each other's queued
   messages. All seven golden-path `Simulation` files descend from
   `GoldenPathHarness extends WorkerHarness`, so the golden paths were
   affected as well. Fixed on the trunk at `0a3bc09`.

   **This fix landed after seven remediation branches had been cut, so those
   branches did not have it and their Queue and Simulation numbers were
   load-dependent.** Each affected agent has been told to `git cherry-pick
   0a3bc09` before measuring those two suites and to re-run or re-label any
   number taken before it. At integration, no Queue or Simulation figure from
   a branch that lacks `0a3bc09` may be treated as evidence.

4. **A missing Redis, and what it actually looks like.**
   `WorkerHarness::setUp()` calls `markTestSkipped()` on every real-worker
   test when Redis is unreachable. This entry has been rewritten twice,
   because the first two versions were written from an agent's impression
   rather than from a measurement, and each overstated the danger in a
   different way. What follows was measured three ways.

   The reporter emits `"skipped":n` when anything skipped and omits the key
   when nothing did, but `result` reads `"passed"` either way:

       with a skip  {"result":"passed","tests":2,"passed":1,"skipped":1}
       with none    {"result":"passed","tests":2,"passed":2}

   And a genuinely dead Redis does not produce a clean skip at all. Pointed
   at a dead port, one real-worker file gave:

       CI unset  tests=10 passed=0 skipped=5 errors=5 risky=5
       CI=1      tests=5  passed=0 failed=5
                 "CI must run the queue proofs, and Redis is not reachable."

   The skips arrive with errors on the sibling tests (`Database connection
   [queue_test] not configured`), so the run does not read as green. The
   agent that raised this had in fact seen `tests 195, passed 107, errors
   44` and described it from memory as a plausible green when it was
   nothing of the kind.

   **The conclusion is narrower than the alarm.** No figure in this
   programme was a silent lie; `tests == passed` with no `skipped` key is
   sound proof of `skipped=0`, and every figure reported in that form
   stands. What `CI=1` buys is turning an ambiguous signal into an
   unambiguous one — worth having, now the default, and a smaller claim
   than the one made twice above it. What remains genuinely unsafe is
   quoting a run as a bare "green", or as `result: passed`, without its
   counts: that proves nothing, and it is the habit this entry exists to
   kill.

   The harness is not wrong — it already calls `$this->fail()` instead of
   skipping when `getenv('CI')` is set, which is the right behaviour for a
   build and the wrong one only for a developer who has not started Redis.
   The defect was this programme's: agents were not running as a build.
   `mkworktree.sh` now hands out `CI=1` as a fourth mandatory variable, and
   every agent has been told to confirm `pg_isready` and `redis-cli ping`
   before measuring and to report `skipped=0` beside any figure from those
   two suites. A count that cannot be shown to have had `skipped=0` is not
   evidence.

5. **Two test runs against one database, which `CLAUDE.md` already forbade.**
   F-29's implementer backgrounded one band and ran another in the foreground
   against the same `lynomia_test_f29`, and got five errors — four
   `relation "permissions" does not exist` and one migration deadlock — in a
   band that had nothing to do with its change. It caught this itself, re-ran
   strictly serially, and **reported the bad run alongside the good one**
   rather than only the figures that flattered it. That disclosure is the
   reason this entry can be written accurately, and it is the standard.

   This one is not a hole in the isolation scheme: `CLAUDE.md` says never to
   run two `php artisan test` invocations at once against the same database,
   and the scheme gives every agent a database precisely so that *different*
   agents cannot collide. The failure was an agent colliding with itself.
   Every brief now says it in the agent's own instructions, because a
   same-database collision is indistinguishable, from the inside, from a real
   failure in the change under test.

   A related mechanical change: `mkworktree.sh` now **re-attaches to an
   existing `remediation/<slug>` branch** instead of refusing. Redis indexes
   are the scarce resource — fourteen for the whole programme — so a
   worktree whose implementer has finished is retired to free its index while
   its independent verification runs, and comes back at its own head if the
   verification finds something. The script ignores the base argument in that
   case and says so, so a branch can never be silently moved.

6. **`vendor/` is shared, so an in-place edit to it is machine-wide.**
   `mkworktree.sh` symlinks `vendor/` packages one by one at the canonical
   checkout's — deliberately, because they are 4.2G and read-only to every
   agent. That holds until an agent needs to *mutate* a package to reproduce a
   runtime condition. F-31's implementer did exactly that: to reproduce a PHP
   built without IPv6 it backed up `vendor/symfony/http-foundation/IpUtils.php`,
   flipped the one condition such a build changes, measured, and restored it
   with a hash check both ways. Careful work, fully disclosed — **and
   machine-wide for its duration**, because `vendor/symfony` in every worktree
   is a symlink to the canonical tree. Every other agent running tests in that
   window had a PHP that appeared built without IPv6.

   Nothing was corrupted, because the restore was verified. It was found by
   F-31's next verifier, which needed the same reproduction, **declined to
   repeat the method**, worked out why it was unsafe, and built the safe one
   instead. I confirmed the symlink myself across three worktrees and confirmed
   the canonical file is byte-correct now.

   **This is the provisioning script's defect, not the agent's** — the script
   said nothing about it, and the instruction to back up and restore in place
   was the obvious reading. Repaired two ways: `mkworktree.sh` now warns in the
   text every agent is handed, and `scratchpad/unshare-vendor-package.sh <slug>
   <namespace>/<package>` replaces one worktree's namespace symlink with a real
   directory — siblings still symlinked, the named package genuinely copied — so
   a mutation cannot leave that checkout. Cost is one `cp -a` of one package.

Only the coordinator writes to `claude/relaxed-turing-nh8ybf`.

### The eighth and ninth: both mine, and the second destroyed a live measurement

**Eighth — a slug's remembered Redis index reissued while another worktree held it.** `f29` and `v35c` both ran on index 4. My allocator looked up a returning slug's previous index and handed it back **without checking whether it had since been reassigned** — the one door that looks like a cache hit rather than an allocation. Caught by a routine reconciliation of slots against live worktrees, not by anything going wrong, which is luck. Fixed: a remembered index is reused only if no live worktree holds it, and the slot file no longer accumulates two lines for one slug. The fix proved itself minutes later by refusing `f19`'s stale index 7 and allocating a fresh one. The affected agent was told which bands to discard; none of its figures touched Redis.

**Ninth — I removed a worktree while its agent was still working.** I reclaimed `f19` after its hand-back, having checked for live `php artisan test` processes and found none. But a hand-back is not the end of an agent: I had *prodded that agent for its report*, it delivered, and it then kept working — applying a comment-only docblock tweak it had not yet committed. I deleted the directory underneath it.

Three consequences, in ascending order of how much they should worry the next coordinator:

* The uncommitted tweak was lost. It turned out to be two lines of prose that no claim depended on, and the implementer had the text, so nothing was actually destroyed. **That is luck, not process** — a forced removal discards whatever it finds, and I did not capture the diff first.
* **A live run was executing in that directory and produced 45 errors** — every one `Failed opening required '.../bootstrap/app.php'`. A run with no application under it. Its log and JUnit are void, and they look exactly like a catastrophic regression to anyone who opens them without provenance.
* I then briefed the verifier that the lost diff was unrecoverable and that `EvidenceOfABuild.php` should be treated as suspect. **That was wrong and I had to retract it mid-verification.** An error of mine had become an instruction to doubt an implementer's work.

The rule, now mine to follow rather than an agent's:

> **A hand-back is not a lock release.** Before reclaiming a worktree, check that no agent is live in it — not merely that no test is running. An agent that has reported may still be committing, tidying, or answering a prod.

And the smaller one that would have contained all of it: **capture the diff before any forced removal.** `git diff > <scratch>/reclaimed.patch` costs nothing and would have turned this entry into a footnote.

### The seventh isolation defect, and the first one fixed with a guard rather than a sentence

Three separate agents have now corrupted their own measurements the same way:
two `php artisan test` invocations against **one** database at the same time.
`RefreshDatabase` runs `migrate:fresh` at the start of a run, so the second
drops the schema underneath the first.

What makes it expensive is that the damage does not look like what it is. It
surfaces as `relation "wallets" does not exist`, `relation "permissions" does
not exist`, or `null is identical to an object of class …` — in files that have
nothing to do with the change under test, in a **different set on each run**.
All three agents first suspected their own work. One killed both runs, deleted
the entire window of results and re-ran its sweep serially, which is the correct
response and cost it hours. The third reported a full suite as 3952/3943 with 9
errors and only afterwards established the 9 were its own.

Every brief has carried "never run two at once" in prose since the first
occurrence. It happened twice more. **A rule that has been stated three times
and broken three times is not a rule, it is a wish**, so it is now a guard:
`scratchpad/artisan-test.sh` takes a lock keyed on `DB_DATABASE` and refuses,
naming the holder's pid, uptime and worktree.

Two design choices worth recording, because both were wrong in my first cut:

* **It scans `/proc` as well as taking the lock.** A lock only binds runs that
  both go through the wrapper, and while this programme is mid-flight most runs
  were started directly. Scanning for another `artisan test` whose
  `DB_DATABASE` matches makes the guard useful from the moment it is dropped in
  rather than only once every agent has adopted it. I found this out by testing
  the refusal path against a genuinely busy database and watching it sail
  through.
* **It fails fast rather than queueing.** An agent whose run is silently waiting
  behind another believes its suite is merely slow, and a forty-minute band that
  has not started yet is worse than a refusal it can act on.

Verified by deliberate collision: a second invocation against a database with a
live run exits 3 and prints the holder. It is wired into `mkworktree.sh`, so
every worktree provisioned from now on is told to prefer it.

## A bookkeeping rule I had to be shown: two kinds of total are not comparable

F-18's cleanup reported its full suite as **3,931 tests / 144,377 assertions**
and then, rather than banking a green figure, said plainly that the assertion
total did not reconcile with the **137,561** its verification had recorded — a
gap of about 6,800 that its own change could not account for. It ruled out its
test file (+51, measured at both ends), the source change (zero executable
lines), and any hidden failure or skip, guessed that the cause was measurement
scope, labelled the guess as a guess, and offered to settle it with a
seventeen-minute A/B rather than assert it.

The guess is right, and the programme already held the evidence. Unpathed
full-suite runs reported by five different agents on five different trees:

| branch | tests | assertions |
|---|---|---|
| F-18 | 3,931 | 144,377 |
| F-29 | 4,029 | 144,517 |
| F-08 verification (`v08d`) | 3,961 | 144,411 |
| F-29 rework (`4cd7627`), two runs | 4,032 | 144,660 then 144,657 |
| F-14 round eleven (`0e5afb3`) | 4,152 | 144,967 |
| integration tip, my own run | 4,150 | 144,930 |

**Three rows of this table were destroyed by a mis-targeted edit of mine** — a
script that appended finding narratives matched on a category keyword and wrote
four full ledger rows into this three-column table, overwriting the figures that
stood here. The two originals that survived are the first two rows; the rest are
later unpathed runs measured since, each attributed to the tree it was taken on.
I have not reconstructed the three lost rows, because I would be reconstructing
them from memory of a table I can no longer read.

Every unpathed run in this programme lands between 144,377 and 144,967
assertions, across trees that differ from one another by more than two hundred
tests. Band sums land near 138,000. That ~6,800 gap is the structural difference
between the two kinds of total, and it does not close as the tree grows: the
spread *within* the unpathed family is about 600, an order of magnitude smaller
than the gap to a band sum. **This is why a band sum may never be quoted against
an unpathed figure, and why the arithmetic coincidence of one summing to the
other is not evidence of anything** — F-13's verifier declined exactly that
temptation when its two bands summed to precisely its unpathed 3,968.

**RETRACTED, one commit after I wrote it.** I recorded here that the ±3
assertion delta was "a first-run effect, and deterministic", generalising from
three independent observations that all showed **+3 on the first run against a
fresh database**: 144,653→144,650, 144,291→144,288, 144,517→144,514. It was a
clean pattern and I stated it as a rule.

F-26's verifier then ran the full suite twice on one clean tree and got
**144,106 then 144,113** — the *second* run seven assertions **higher**. That
breaks the rule in both direction and magnitude, and it was measured on exactly
the question my rule claimed to answer.

So the honest statement is the conservative one its verifier reached
independently, and it is narrower than what I wrote:

> **The assertion total is not reproducible. The test count is.** Two assertion
> figures that differ are not evidence of anything until something else says
> they are, and an assertion total should not be carried into this ledger as an
> exact figure or used as a fingerprint for a tree.

**A briefing hazard this exposed, which is mine.** F-29's second rework was
dispatched with the *retracted* version of this rule — I told it "your database
is not fresh, so expect 144,650" in a brief written before F-26's verifier
broke the first-run theory, and the retraction landed while that agent was
already working. It then measured **144,660** and, rather than quietly
reconciling to the number it had been handed, flagged it and decomposed it: its
new test contributes exactly 7 assertions, measured directly and
database-independent (pure `preg_match` / `ctype_digit` / value-object
assertions), leaving a residual of 3 that is not its own.

**Then it did the thing that actually settles it** — it launched a second
full-suite run on the same tree and named, in advance, what each outcome would
mean: 144,657 would mean the fresh-database effect is real and the rule holds;
144,660 again would mean the 3-assertion delta is not a first-run effect and the
rule needs restating. A pre-registered decisive experiment, on a question I had
by then taken two positions on.

Two things to keep. **A brief is a snapshot, and mine go stale while agents
work** — this programme's rules have been revised nine times in a day, and an
agent acting faithfully on a rule I have since retracted will produce a
"contradiction" that is my bookkeeping rather than its measurement. Where a
brief states a rule, it should say what would falsify it, which is what let this
agent handle the discrepancy correctly without being told.

And **the retraction was right to be conservative.** Had I left the
deterministic +3 standing, this agent's 144,660 would have read as a defect in
its own commit rather than as a datum about the harness.


What I got wrong is worth more than the number. Three consistent observations of
a small effect, all in the same direction, is exactly the amount of evidence
that feels like a law and is not one — and I had been telling every agent in
this programme to report contradictions rather than reconcile them, while
generalising from a sample of three myself. The band-sum rule above still
stands, because that gap is ~6,800 and structural; this one was noise wearing a
pattern.

**Third confirmation, on exactly matching test counts.** F-14's twelve-band sum
was 4,150 tests / **138,168** assertions; the unpathed run at the same commit is
4,150 tests / **144,930**. Identical test count, ~6,760 more assertions. That
removes the last way to read the gap as coverage: the two figures cover the same
tests and disagree only on how many assertions get counted. It also widens the
unpathed band a little — 144,930 sits above the ~144,300 cluster — so the rule
is *compare like with like*, not *expect a fixed number*. F-31's verifier
established the fine structure independently by running the full suite twice on
one tree and reading 144,291 then 144,288: `tests`, `passed`, `failed` and
`errors` are exact; the assertion total is not.
 Every **sum of path-scoped bands** lands around 137,500–138,100. A gap
that appears identically on five branches cannot have been introduced by six
docblock-prose commits on one of them, so it is a measurement-mode difference
and not a regression. The decisive detail is the cleanup's own reconciliation of
the *test* count — 3,930 settled + 1 new = 3,931, predicted and measured
agreeing exactly. **Same tests, more assertions**, which rules out a coverage
gap in either direction.

**The defect this exposed is mine.** I have been recording unpathed totals and
band-sum totals side by side in these tables as though they were the same kind
of figure. That is precisely how a future reader — or the final re-audit —
would "discover" a 6,816-assertion regression in a commit that changed no
executable line, and spend a day on it. The rule, now standing:

> **An assertion total is comparable only to another taken the same way. A sum
> of path-scoped bands is not a full-suite figure and must never be subtracted
> from one.** Every figure recorded here carries the command that produced it,
> and band sums are labelled as band sums.

Band splitting is not going away — it exists because a single unpathed run has
twice hit an agent's tool timeout under load from sibling agents, and because
twelve bands localise a failure that one number hides. So the two kinds of
total will keep appearing side by side, and the labelling is the whole of the
defence.

One smaller practice from the same message, worth copying: it grepped its output
for `skipped`, found **one** occurrence, checked what it was, and reported that
it was its own `echo` line rather than phpunit's — because a bare "1 occurrence"
would have read as a skip. Most agents would not have looked twice at a number
that agreed with the conclusion they wanted.

### Two measurement facts every remaining round should know

**Assertion counts vary by about three between runs of identical code.** Two
verifiers have now hit it independently and both stopped to check: F-29's saw
144,517 against 144,514 on the same tree, and F-08's saw 144,411 against a
reported 144,408. Tests match exactly in both cases; only assertions move. It is
data-dependent counting in some row, not a behaviour change, and nobody should
spend another hour on a delta of three. **A difference of three is noise. A
difference of thousands is a band sum being compared with a full-suite figure**
(see the rule above). Anything in between is worth investigating.

**Every mutation matrix in this programme is report-only.** F-08's verifier went
looking for the implementer's "39 mutations, 38 caught" and found no breakage
matrix anywhere in the tree — `git diff --name-only` over the whole branch
touches nothing outside `apps/` and `scripts/`. So that claim, and every claim
like it in every hand-back, is **unverifiable from the repository**. It ran 19
mutations of its own instead, which is the right response.

**A second constraint on the final re-audit, from F-19's verifier, and it is
the sharper of the two.** Its closing line:

> *"A green 3967-test suite is not evidence against either block. No test
> anywhere sends a force-less termination of a live hosting service through the
> service route, and no test asks `RetryProvisioningJob` about a timed-out
> create with a null `remote_job_id`. Both were found by measuring outside the
> suite's reach."*

Every finding in this programme reports a green suite, and it means less than it
reads. Both of F-19's blocks — one of them a live authorization gap on a
customer-destroying route — sit in code the suite never exercises, so the suite
was green before them, during them and after them. The same is true of F-13's
cloud-init survivor (Pint-clean, band at baseline) and F-35's fifth survivor
(both bands at exactly baseline).

So: **a green suite is evidence that nothing pinned has moved, and evidence of
nothing else.** The re-audit must go looking outside it — at routes no test
drives, at permission pairs no fixture constructs, at inputs no provider double
produces — because that is where every serious defect in this round has been
found. Three of the four closures were decided by measurements taken outside
the test suite entirely.

I am deliberately **not** requiring matrices to be committed. A matrix in a
document is a claim like any other, and a reader who trusts it is doing exactly
what this programme exists to stop. The real check is an independent sweep by
someone who did not write the code. What follows from this is a constraint on
the **final re-audit**: it must re-derive rather than read. No closure may rest
on a mutation table that exists only in a hand-back report, including the tables
quoted in this ledger.

### The test wrapper's own defect, found by an agent using it

F-14's verifier reported that `scratchpad/artisan-test.sh`'s stdout is **not
cleanly parseable**: the application's test bootstrap writes a bare
`METRICS SCRAPE: 60 metrics, 742 series, 45 queries` line ahead of the JSON
result object. It cost that agent a **false completion signal** — an
`until [ -s file ]` wait fired on the stray line rather than on the run
finishing, reporting a still-running suite as done.

That is a defect in my tool, found by someone using it, and it is the second
time this programme has produced one: the wrapper's first cut failed to refuse
against a genuinely busy database because a lock only binds runs that take it.

It is now documented in the script's header with the two consequences and the
correct patterns — extract the object with `grep -o '{"tool".*}' | tail -1`, and
wait on `until grep -q '{"tool"' <file>`, never on file non-emptiness. **I
deliberately did not filter the stray line out.** It comes from the application
rather than the wrapper, and swallowing stdout would hide genuine diagnostics on
a failing run, which is worse than a parse step. The edit was made by atomic
replace rather than in place, because a dozen agents were executing the script
at the time and bash reads a script lazily.

## Dependency graph and wave order

The Round-1 report's recommended sequence ran F-17+F-16 → F-01 → F-09+F-10 →
F-05+F-06+F-07 → F-02+F-03 → F-04+F-14+F-13 → mechanism fixes. The first five
groups are complete, so execution resumes at the sixth. Waves after that are
ordered by dependency and by file-conflict safety, not numerically.

```
done ──► F-16 F-17 ─► F-01 ─► F-09 F-10 ─► F-05 F-06 F-07 ─► F-02 F-03
                                                                │
Wave 1  real operator day one ─────────────────────────────────►│ F-04  F-13  F-14
Wave 2  concurrency / lifecycle / auth ────────────────────────►│ F-08  F-12  F-15  F-18  F-19
Wave 3  DNS / network / platform security ─────────────────────►│ F-11  F-26  F-28  F-29  F-31  F-33  F-35
Wave 4  customer-facing correctness ───────────────────────────►│ F-20  F-21  F-27  F-32  F-34  F-43  F-44
Wave 5  operability / observability / scheduled work ──────────►│ F-22  F-30  F-36  F-37  F-39
Wave 6  test and architecture mechanism ───────────────────────►│ F-23  F-24  F-25  F-38  F-40  F-41  F-42
Wave 7  declaration and notification truth ────────────────────►│ F-46  F-47
adjudication at final re-audit ────────────────────────────────►│ F-45
```

Known cross-wave dependencies:

- **F-33** (overlapping subnets accepted) was latent behind F-02, which is now
  closed — an operator can create subnets today, so F-33 is live rather than
  theoretical.
- **F-23** (architecture must test state/enum write reachability) is the
  mechanism that F-19, F-34, F-46 and F-47 are each an instance of. It is
  scheduled in Wave 6 rather than first because the instances tell it what
  shape to be; the reachability judgements made in Waves 2, 4 and 7 feed it.
- **F-24** (simulators cannot represent real failure modes) gates the honesty
  of any adapter proof, so Waves 1 and 3 record whether the simulator could
  represent the failure they repair, and F-24 collects those answers.
- **F-41** (phpunit env pinning, `migrate:fresh` safety) changes how every
  other agent's isolation works and is therefore late, not early.
- **F-45** may not be closed by promotion: if any change makes WordPress
  production-complete or sellable, F-45 becomes blocking.

## Ledger

Columns: original severity and class are the report's own. `Verifier` names the
independent reviewer's verdict, which is never the implementer's.

### Closed before this program

| ID | Sev | Class | Status | Notes |
|---|---|---|---|---|
| F-01 | Critical | MONEY_INTEGRITY | `CLOSED` | Proration discarded in both directions. Closed in an earlier round; guard re-verified at entry to this program. |
| F-02 | Critical | CODE_GAP | `CLOSED` | No production write path for the inventory chain. Closed at `6a75835`; CI run 218. |
| F-03 | Critical | AUTHORIZATION | `CLOSED` | No operator account could be created. Closed at `6a75835`; CI run 218. |
| F-05 | High | MONEY_INTEGRITY | `CLOSED` | Cancelled order's invoice stayed collectible. |
| F-06 | High | CONCURRENCY | `CLOSED` | Stock and per-customer limits read unlocked. |
| F-07 | High | MONEY_INTEGRITY + OPERABILITY | `CLOSED` | Money moved before feasibility. |
| F-09 | High | DATA_INTEGRITY | `CLOSED` | Restore reconciled against the backup's task id. |
| F-10 | High | DATA_INTEGRITY | `CLOSED` | Corrupt archive rendered as unchecked, Restore enabled. |
| F-16 | High | SECURITY | `CLOSED` | `APP_ENV=Production` defeated every production guard. Guard is a proxy oracle: it evaluates `config/app.php`'s normalisation in isolation and exercises none of the 38 guards that read it. Sound only while no guard reads `APP_ENV` raw for its value — re-verified at entry that none does. |
| F-17 | High | SECURITY | `PARTIAL` | Invitation throttle keyed on an unvalidated header. The **key** is soundly pinned and the defect does not reproduce. The **control is not**: removing `throttle:team-invitations` from both routes leaves the suite green, so the finding's outcome is reachable again by a one-line edit. Reopened to `PARTIAL` on that evidence; closes when the oracle covers attachment. |

### Wave 1 — real operator day one

| ID | Sev | Class | Status | Notes |
|---|---|---|---|---|
| F-04 | Critical | CODE_GAP | `OPEN` — rework delivered at `3ed4846`, **in verification** | **Both blockers closed by code, not by rewording**, which was my ruling on the second. **(1)** The index is now a guarantee about a *name*: one fold in the naming authority, **composed** from the existing `canonical()` so the platform keeps one lower-casing rule, with byte-equivalence to the old expression **pinned across 22 inputs rather than argued** — because a fold that moved would move `orders.request_fingerprint` and 409 every mid-retry client. Made at the seam where a name enters the index, **and underneath it a CHECK constraint**, on reasoning worth keeping: *"'the action folds it' is a property of the action; what the index needs is a property of the column."* It folds legacy rows where that is a spelling correction and refuses where it would be a decision. The migration's collision scan — which had reported **zero** for `Example.test` beside `example.test` and then built an index that let both stand — now groups on the folded name. **(2)** The remedy is **built end to end**: a new audited operator surface, deliberately separate from retry because *"a retry is not a repair"*, writing the job payload only. And the check-then-act gap underneath it is closed — `reserve()` previously asked whether `right.test` was free, was told yes, and armed a row serving `wrong.test`; both writes are now in one `forceFill` inside the existing try. First-fail for that item was **10 of 10 red**, with the correction silently discarded and the wrong name handed to the panel on a job reporting `successful=true`. **It found a false claim in the round it was reworking**: `77fe24e` said the fold was *"called by all three, so a fourth reader cannot be written without it"* — there **was** a fourth, inline. **28 mutations, all die but one**, the survivor being the clause the previous commit itself admits deletes without failing anything. Three first-pass survivors were **gaps in its own pinning**, closed and re-killed; two more it traced to its own fixtures rather than the code — both its probe keys contained *"pan"*, and `'pan'` is a substring-matched redacted key, so the redactor swallowed them. |
| F-13 | Critical | CODE_GAP | `OPEN` — **upheld with reservations**, two blocking, in round three | **The overturn stands and `M-D` survives independent re-measurement** — the claim I said I would attack first. Deleting `inventory_sync` from all four places leaves `tests/Architecture` at **124/124/8517, byte-identical to baseline**, while the F-13 file breaks: the category gate cannot see both halves removed together, so the pin is in the right place. One correction in the safe direction: **six** tests detect the revert, not five, the sixth **erroring** rather than failing. VPS-alone is right in **both** directions — GpuCompute is `Prepared` with no handler *and* inherits VPS's readiness through `dependsOn()`, so it is not under-strict either. **Both blocks are claims the round made about itself.** The cloud-init pin does not detect what its commit headline says: `reachedConditionally()` consults only the brace stack and **never reads `$pending` at the needle**, so a sibling conditional create with the cloud-init site at plain depth passes the pin **and is Pint-clean** — precisely the regression it exists to catch. And *"the only writer of the `ComputeStorage` rows"*, stated unambiguously in **three** places, is false: `ReserveNodeCapacity`, `ReleaseNodeCapacity` and `LoadReferenceTopologyForSimulation` all write them, the last of which **creates** rows carrying exactly the fields `NodeScheduler` reads. **The conclusion survives both**, which is why neither is a rejection: the two capacity writers reach their row by id and cannot bring one into existence, and the simulation loader refuses production and stamps its rows `development` — so a production cluster short of `Datastore.Audit` still places nothing, and the decision would have been identical had the truth been known. The verifier also **built the control the round had not** for the resize backstop, establishing that the passing test is correct behaviour rather than a hole. **Supplement:** the whole suite landed at **3968/3968, 0 failed, `skipped=0` proved by grep**. It also **declined a tempting arithmetic** — reported WIDE 1576 + complement 2392 sums to exactly 3968, and it refused to treat that as verification of either band, because a band sum is not comparable to an unpathed figure. Both bands stay unverified, their path sets being recorded nowhere. |
| F-14 | Critical | DATA_INTEGRITY | `OPEN` — round eleven delivered at `fb49c66`, **awaiting verification** | **The frame change worked and the rejection is not about it.** The outcome rule is not outflanked by any day-moving token: an independent 453,744-row sweep gives **0 accepted leading and 0 trailing** post-fix against 3,454 and 6,312 pre-fix; over-refusal is **exactly the two** rows I named and nothing else; the 17 fleet formats across 29,585 days are **0 refused, 0 misfiled**; `date_parse` emits **exactly three** distinct warnings over 578,511 inputs, confirming the claim at a wider scope than it was made; the limb division is real; and the unusual `errors=1` first-fail reproduces. **The rejection is that the 9999 ceiling is in the wrong place, on a premise that is false.** It is applied only inside the numeric branch, justified by a docblock saying it is *"the ceiling this method already has everywhere except its numeric branch"*. **I verified the counterexample myself**: `UTC+22099-01-01` gives `err=0 warn=0`, gate year **22099**, and `CarbonImmutable::parse` **agrees exactly** — so limb one sees nothing and limb two is structurally blind, by construction. `ACDT+29999-01-01` → 29999; `UTC+2100000-01-01` → year 2,100,000. A sweep finds **4,028 of 8,076 accepted probes with a year past 9999**: a family, not a freak. End-to-end it licenses a node, and the `+` has to arrive as `%2B` because `parse_str` turns a bare `+` into a space — *which is why a naive probe looks safe*. Pre-existing rather than introduced, but it meets both rejection lines: the code is wrong, and the decision to guard one branch would plainly have been different had the truth been known. The fix is bounded — apply the ceiling on the single return path instead of inside the numeric `if` — and then re-measure over-refusal, because the ceiling will then meet textual values for the first time. **Round eleven moved the ceiling onto the single return path**, which was the bounded fix the tenth rejection named, and then did the work the move created: because the ceiling meets textual values for the first time, over-refusal had to be re-measured rather than assumed. It is **463,108 rows** for the ceiling's own cost — 643 accepted-by-round-ten values with a year past 9999, **all 643 refused now, 0 refused at or below the ceiling** — and the frame change's older over-refusal is still **exactly two rows** (`jan 2099-01-01`, `january 2099-01-01`), neither of them from the ceiling. Two new tests fail against round ten, one on the node's own row, and both send `%2B` because `parse_str` turns a bare `+` into a space. Three previously uncounted refusal classes now carry stated grids (seconds-60 4,317 rows; doubled-timezone 764; ISO week date 1,090) — and the round **measured the classes rather than ranking them from memory**, finding seconds-60 nearly six times larger than the doubled-timezone class it had first written down as the largest, then corrected its own source sentence in `fb49c66` and reported the correction unprompted. **It also corrected the false sentence I specified myself** (see below): no digit is dropped — timelib spends the first four digits on a clock `HHMM` and short-year-expands what is left, so `10000-01-01` is `y=2000, H=10` and `99999-01-01` is `y=2009` with no hour; and five-digit years are refused only in the **year-last** forms. Its mutation honesty is the part I would not have caught: its first R4 left a redundant duplicate ceiling in place, **survived correctly as an equivalent mutant**, and it rewrote the mutation to round ten's actual shape rather than report the survival as coverage. One survivor written down rather than fixed: **the ceiling is a maximum only** — `UTC-22099-01-01` stores year −22,099 and never sells only because `isPast()` refuses it. Not verified yet: no phpstan (its vendor is absent and `composer install` is forbidden), and the full unpathed suite ran at `0e5afb3` (4,152 / 144,967), not at the six-word comment change `fb49c66`, where the SharedHosting band is identical at 455 / 1,701. |

### Wave 2 — concurrency, lifecycle, authorization

| ID | Sev | Class | Status | Notes |
|---|---|---|---|---|
| F-08 | High | CONCURRENCY | `OPEN` — round four delivered at `a81f28e`, **in verification** (`v08e`) | **The fourth reading is built, not narrowed**, per my ruling. First-fail measured with real workers on the file's own ratio scale: the control's 2 starts / 0 finished / **0.00s overlap** against the treatment's **3 starts, 3 finished, 10.50s of two live executions** — one dispatch, one added property, both config files untouched. **Two measurement errors in its own first attempt, fixed in code rather than remembered**, and both are the kind that produce a false *safe*: a worker hitting its own `--timeout` dies by `posix_kill(SIGKILL)`, which Symfony raises as `ProcessSignaledException`, so *the control's expected ending arrived as an error*; and a control job killed mid-run is never deleted, so it sits in `:reserved` where `queued()` cannot see it and the **next** measurement's second worker spends its whole window on the previous test's leftover — which is exactly why the treatment first reported *"1 execution, safe"*. Each measurement now drains reservations. Six declaration forms covered, including the queued-listener path pinned **against a real dispatch** rather than asserted from the docblock, and both forms disagreeing. A declared `0` is read as the **worst** case, not the smallest, because `pcntl_alarm(max(0,0))` cancels the alarm. The accept side is pinned as hard as the reject side — the exact map, then `[]` for the set, then `[]` for **each class individually so neither hides behind the other**, then the margins. **It closed one nobody asked for**: the rule took the *first* supervisor watching a queue, so which answer you got depended on declaration order — under-inclusion, the direction the class exists to refuse. It now measures against all of them. **22 mutations, all dead.** And it applied this ledger's own lesson to itself one scale down, renaming a test once it asserted six forms rather than three and adding *"four is what has been found, not a proof there is no fifth"* — while naming the form no source reading can see, a `$timeout` assigned at runtime. **Round three's verification returned UPHELD WITH RESERVATIONS, one blocking.** It confirmed the half that decides deployability and that I said was its first job — that the widened check does **not** refuse Horizon's own spawned workers — three independent ways: all 30 real spawned command strings accepted through the real listener using Symfony's own shell tokenizer with two live controls refused, five as real OS processes, plus 20 further environment and command shapes; it could not break it. R2–R6 and the volunteered fifth instance all confirmed against vendor and all mutation-sensitive, 14 of its own 19 mutations caught; suite 3961/3961 with `skipped=0` proved. **The blocker was a fourth way in that the source denied existed**: `QueueRetryClocks.php:39` asserted *"three ways to reinstate the defect"*, and a job's or queued listener's own `$timeout` beats the worker's `--timeout` at `Worker.php:351` (`$job->timeout() ?? $options->timeout`), so one line on a `payments` listener produced **four completed overlapping executions** with both config files untouched and all 49 rule tests green. Another quantifier sentence, and again the code beneath was right — which is why it was blocking rather than a rejection. Round four's eight commits **build the fourth reading** rather than narrow the sentence, and its tip is named *"four is what has been found, not a proof there is no fifth"* — an honesty the verification now underway is briefed to test by hunting a fifth. |
| F-12 | High | DATA_INTEGRITY | `OPEN` — **upheld with reservations**, two blocking, in round five | Both limbs reproduce against the parent version of the defective file and pass at tip, and there is a **single funnel** — every automatic and operator dedicated termination routes through `DecommissionDedicatedServer`, with no second path to `Terminated`. Round four's **six** pins each die for the right reason at the right line, none tautological, and all **four** of its inert classifications are genuinely inert, each confirmed by deletion. **Its refusal to write the test I asked for is now proved rather than argued** — the verifier removed the `$from === $to` short-circuit and showed that the only way to make a second retirement throw is to make the no-op illegal, at which point a replayed POST to an endpoint with no idempotency key gets a 409 instead of a no-op. That is the test's premise made real, and it vindicates the refusal I had already accepted on argument. **Blocking one: a false sentence in the paragraph round four edited.** *"One retiring while another shelves it both pass in either order … so only that one order is refused"* contradicts itself, and measured, exactly one order **is** refused: `retire` then `shelve` returns **409 state.illegal_transition**. What makes it blocking rather than inherited is that round four edited lines 76-78 of this same paragraph and **left the false clause three lines above it standing**. **Blocking two: the completeness claim is false.** `73ec70c` claims *"a sweep over every guard, condition and filter this branch has added"*; the verifier enumerated ~46 candidates against 33 mutations, probed **three** uncovered lines and **all three survived** — including one whose removal would make `ipam:capacity --pool=X` list held addresses from every pool, and one whose removal would name the **wrong machine** for an address with several historical assignments. Three probes, three survivors. It also named the nine lines it did not reach, so round five does not have to guess. |
| F-15 | High | CODE_GAP | `OPEN` — **rejected**, in rework | **The original finding is closed and well oracled** — round four reproduced the defect on the parent (two machines), got `already_built` at the tip, reproduced the eighth door independently matching the implementer's own measurement, and confirmed **27 of 30 tests die on a revert** with the three survivors being declared controls. It rejected on two proved grounds, and **what fails is the new surface built beside the fix**. First, the branch is **red**: the new repoint route is undocumented, `docs/openapi.yaml` is stale, and two CI-gated `OpenApiSpecificationTest` rows fail — and the implementer's reported *"390/390 with 12,249 assertions"* matches no band the verifier could find (the whole suite is 3942 / 144,378), so whatever it ran excluded `tests/Feature/Api`. Second, **the ninth door: the remediation's own new route builds the second machine, following the remediation's own runbook**. `RepointReservedIdentity`'s docblock claims `vps.create_identity_taken` *established* the machine is not this build's; it establishes only that it does not match what this job would build **now**, and `refusalFor()`'s own docblock enumerates the counterexamples. With **no database edit and no payload edit** — a silent build, an ordinary hypervisor resize, then the repoint the runbook prescribes — `machines=2`, the customer's own first machine orphaned and unbilled while holding a configured address, and the platform's only handle on it thrown away by the repoint. A variant settles the job green on top of it. |
| F-18 | High | AUTHORIZATION | **`CLOSED`** — upheld by independent verification; cleanup complete at `eae4ff1`, seven commits, **zero non-docblock lines in `src/`** across all of them and the query block byte-identical to the verified tip | Six rounds, and **the first finding in this round to close.** Both limbs are shut: `retentionHasElapsed()` no longer reads a missing `suspended_at` as an elapsed window, and the second permission is now demanded for any non-suspended, non-terminated account whether or not `force` was sent. **10 of 10 control mutations caught** — including C7, which restores the original F-18 bug verbatim and dies, and C1, which reverts the controller gate and dies because the action still refuses with **409** while the test insists on **403**: the two layers are independently pinned, and the suite is not satisfied by *"it was refused somehow"*. C6 shows the ordering the docblock calls *"the guard rather than a detail of it"* is pinned as ordering, and C10 shows Pending and Failed are genuinely pinned rather than only Active. **The rejection I made in round five is answered by measurement rather than by argument, and the answer went against me on the point I was least sure of.** I had rejected on the deleted `AND ha.terminated_at IS NULL` clause. The verifier built the only row shape in which that clause discriminates protectively — a re-used/rebuilt account whose username *is* present at the panel, so the docblock's manual step succeeds while `cancel()` is the wrong repair — in two variants identical in every other column. Measured: the query returns the hazard **with the clause restored as well as without it**, because the same shape is reachable with `terminated_at` NULL from a Failed build rather than a Terminated one. The clause covered half of a pre-existing hole by accident, while demonstrably removing a row the sweep fails on every night. The construction that would have upheld my rejection refuted it. It also re-derived the six-predicate analysis by hand and then mechanically and **matched the commit's table exactly**, each of the five discriminating clauses having a fixture excluded by it alone, with the sixth surviving ablation exactly as documented — and corroborated the redundancy independently, since `EndExpiredServices::outOfTime()` carries the same clause beside its own `<=`. **It reproduced the twelve-band partition cell for cell** — all twelve bands, 3,930 tests / 137,561 assertions, every band carrying `tests == passed` and **no `skipped` key**, so `skipped=0` is proven per band rather than inferred from a total — and re-derived the partition's completeness before running it (444 test files by three independent counts; zero outside the bands). **36 mutations of its own enumeration, 25 caught**, 8 of the 11 survivors correct or explicitly declared. **Three residues, none blocking, all in the auxiliary repair-query harness rather than the control**, now in a short bounded cleanup: a fixture that barely differs from the neighbour it is meant to contrast with, letting `AND hn.panel = 'fake'` survive — **which I verified myself** at `TheRetentionSweepTest.php:520-521`, where both nodes are created `HostingPanel::Fake`, so a repair query carrying that narrowing returns **zero rows on every real estate** while the suite stays green; a comment guard enforced line-leading only while its stated purpose covers any `--`; and an oracle pinning a `NOW()` count rather than its position. |
| F-19 | High | CODE_GAP | **`CLOSED`** — **UPHELD** by the fourth independent verification, nothing blocking, two contradictions recorded | **Both blocks were closed by measurement, not by rewording.** Block 1, the claim that the service route is no longer the weaker door, was verified by *driving both routes* through a 16-cell table the verifier wrote from scratch against a real checkout-built live hosting account, fresh fixture per cell: unforced the service route needs `{service.terminate, hosting_account.manage}` against the hosting route's `{hosting_account.manage}` — a **strict** superset, proper because `hosting_account.manage` alone opens the hosting route and is refused by the service route — and forced the two sets are **equal**. The `service.terminate`-only principal gets **403 with `destroyed=no` in both force modes**, which is the deliverable the F-18 integration check depends on, now measured rather than inferred. **`force` adds no permission**: a token-level diff with comments stripped shows the file's only code changes are the removal of the `if ($force)` wrapper, the swap to `EndOfService::authorityOver($found->kind)`, and a now-unused import; `$force` thereafter decides only the window. The deleted check really was tautological — route middleware at `api_admin.php:833` already demands exactly what it re-asked. `authorityOver()` was verified live on all four arms including the throw. Block 2 was verified as **not widened by so much as a token** — three files code-identical to the parent — and the falsification reproduces exactly: a `create_vps` in `needs_review` with `failure_class=timeout` and a NULL `remote_job_id` is **accepted** by the operator's retry while `isAutomaticallyRetryable=false`, because that method's only two production call sites are both inside `RunProvisioningJob`. **7 mutations, 7 died**, with a positive control proving the refusal test is not satisfiable by a route nobody can use, and the C1 strengthening demonstrated the only way it can be — the same mutation **survives** the old `>= 400` oracle and **dies** under the exact-code oracle in the same tree. The before-measurement on pristine parent source reproduced exactly: 7 tests, 4 passed, 2 failed (`Expected 403 but received 202`, both), 1 errored. Band 60/60 twice, `skipped=0` **parsed from JUnit** by both attribute sums and element counts rather than inferred. **Contradiction 1 is the quantifier pattern a seventh time**: *"no caller outside `EndOfService`"* is true in production and false in tests (three call sites), so the sentence needs the scope word and the substance — that the two rows are the whole door set — stands. Contradiction 2 is a test name in my own brief. **The `EvidenceOfABuild:34` elision I predicted is real and I am not holding on it**: the verifier swept all 45 retry-refusal sentences, found exactly one without the qualifier, and judged it scoped by *"the engine"* — which is right, and my own rule says an ambiguous sentence is not a false one. It is carried to integration on the narrower ground the verifier gives: it is the single place in the codebase where the reader must supply *automatically*, while `RunProvisioningJob:63`, `DriftKind:12` and `ProvisioningJobStatus:13` all say it, and it sits in the very paragraph whose contrast produced Block 2. **Not re-run:** the wide 1,621-test regression band, which was my measurement rather than the implementer's, and on which the verdict does not rest — both blocks lived in code no test exercised. |

### Wave 3 — DNS, network, platform security

| ID | Sev | Class | Status | Notes |
|---|---|---|---|---|
| F-11 | High | DATA_INTEGRITY + TEST_GAP | `OPEN` — **rejected**, in rework | Four rounds, each finding a door inside work the previous round declared complete. Round four was briefed to assume a tenth door and **found a tenth and an eleventh**. The tenth is the finding's own subject matter: `CloudflareDnsProvider::payloadFor()` sends `data` and **no `content`** for CAA, `records()` reads `content` as `''`, and every identity comparison in the module compares `content` byte-exactly — so the two sides of the adapter hold two representations of the same record and **no comparison can bridge them**. Measured through the repository's own Cloudflare simulator: a CAA record the platform published correctly is reported as a **Critical `MissingAtProvider` plus an `OrphanAtProvider`, both against the same provider id, on every sweep for ever**, and republishing it creates a duplicate. The repository pins **both** shapes in this same commit and never compares them, and two docblocks the commit added contradict each other on the point — both true, of different sides of the wire, with nothing saying so. The eleventh: `ReconcileZones::settle()` reads `indeterminate_after` in the absence arm and **ignores it eight lines above in the presence arm**, so a delete that timed out and did *not* land marks the record the customer asked to remove as `active`, stamps the provider's id on it, clears the evidence and reports zero drift. Three of four quadrants are covered; the missing fourth is that one. The verifier's own enumeration reached ~32 comparison sites against the inventory's 24, and it **upheld** the deliberate `find()` divergence — including against the identifier-as-fallback variant it judged the likelier edit — plus M13, `IndeterminateAfter::Publish`, the deleted message, the index's case-sensitivity and the pagination fix. It also found 3 of the 6 arms of the new `contentIsCaseInsensitive()` classification unpinned, and that `docs/dns.md`'s "465 tests green" is stale in the tree that ships it. |
| F-26 | Medium | DATA_INTEGRITY | **`CLOSED`** — **UPHELD outright**, the first finding in this round accepted with no reservations at all | Its verifier attached nothing: *"Nothing that reaches a reservation."* **The declined fix is upheld as the right call, and for a reason stronger than the rework's own**: `DomainName::fromString` refuses a single label outright, so `localhost` is unclaimable through the route regardless — making the derivation non-empty for it would have required **relaxing `DomainName`**, i.e. the obvious fix is strictly worse than the one taken. *"The report cannot drift from the guard"* is **not a claim but one line** — `derived()` is `array_filter($this->derivedByVariable())` — and the property that the report never quotes a reserved name or a configuration value is **pinned rather than merely true**, killed by a mutation that appends the configured value to the summary. `Fail` is proved the only blocking state by reading `CheckStatus::blocking()` through to the command's exit code. The estate-only wiring is placed beside the two existing deployment-wide checks and pinned by a mutation that unwires it. All three states were driven end to end on the shipped configuration, including the `[FAIL]` with no value quoted. **15 mutations of its own, 15 killed, 0 survivors** — substituted because the implementer's 17 are recorded in no commit message and so are unverifiable from the repository. It ran an **invented-history sweep over the whole diff** — grepping every added line for temporal claims — and found four hits, all true. Both self-corrections check out, including the arithmetic on *"two days old"*. Two sub-blocking observations recorded and not held against it: one `.env.example` sentence reads as promising *which* names are held where the report gives only a count and the variable names (ambiguous, not false, and a one-word fix); and the Pass summary counts list entries rather than distinct trees, so three redundant members of one tree read as *"3 name(s) reserved"* — true by the documented design, but a larger number than the protection it represents. |
| F-28 | Medium | SECURITY | **`CLOSED`** — **UPHELD**, nothing false found, two observations recorded | Two verifications. The first upheld everything but one docblock and pinned the falsified *"cannot"* from exactly the surface that claimed it could not be. The second **found nothing false at all** and went further than the rework in three ways. It verified *"no executable lines"* **by tokenising rather than reading** — `token_get_all()` over each file at both ends, dropping comments and whitespace, then diffing: `ProxmoxConnection.php` and `config/compute.php` are **token-identical**, so the entire non-test change across three commits is comment text. It checked the control survives **by name in each of the four mutation results**, not by inferring from counts. And it ran a **fifth mutation the rework did not**, adding a realistic `cafile`, which **row 5 kills** — establishing that the kept residual is *stated more pessimistically than it is*: the only surviving `cafile` mutant names a per-run randomly-minted CA at a `tempnam()` path, which no source change can express, so it is an equivalent mutant rather than a gap. **A round under-claiming its own coverage is the direction to err in**, and the measurement is recorded so the next round need not rediscover it. It confirmed the 7-test arithmetic by enumerating method names, measured 182 non-destructively, and was precise about what it could *not* establish: *"I verified 182 is right, not that 180 is wrong about some band I never saw"* — the implementer's report exists in no repository artifact, so striking the figure is correct on the available evidence rather than proved. |
| F-29 | Medium | SECURITY | `OPEN` — rework delivered at `4cd7627`, **in its sixth verification** | Four verification rounds; the fourth is the most thorough this programme has produced, and it closes the security substance. All seven address families are refused on **both** entry points with the right specific reason, against a **2,096-spelling fuzz that accepted none** — every `inet_aton` form of four sensitive addresses in decimal, octal and hex across 4-, 3-, 2- and 1-part, crossed with six decorators, plus 48 IPv6 embeddings, plus 636 more with UTS-46 separators, root labels, zone identifiers, control characters and fullwidth prefixes. It **attempted the construction that would have broken the last-label rule and proved it impossible**: the new rule refuses iff the last label is numeric, the old refused iff *all* labels were, and all-numeric implies last-numeric, so the new rule is a strict superset — confirmed by mutant M13 restoring the old loop and dying. The change traded nothing away. **No false refusals anywhere in the fleet**: every hostname, endpoint and address literal in `database/`, `src/`, `tests/` and `infrastructure/` was swept and only the intended negative fixtures refuse. **40 mutants, 36 killed**, the three survivors being exactly the three anchors the commit declares unpinnable, for exactly the stated reason — and it checked that declaration by turning each of the file's seven `\z` anchors into `$` in turn and counting which lines died: four died, three did not, which is the claim. It verified the BMC information disclosure closed **three deep** without taking the implementer's word, and found **a fourth road the implementer never mentioned** — `ReinstallDedicatedHandler:247` writes the address-bearing message into `dedicated_reinstalls.failure_message` — then established that column is withheld by `ReinstallRequestResource`, `CustomerOperationResource` and `ActivitySources` alike. And it proved **zero real DNS syscalls** through `SystemHostResolver` in the whole suite by putting the throw *at the syscall* rather than at `addressesFor()`, and logging as well as throwing so a swallowed `\Error` could not pass as a clean run: the suite is 4029/4029 and the log file was never created. **Held open on two.** The agreement test's docblock claims it judges *"every shape both of them judge, judged by both"* with *"one deliberate difference"*; it feeds **nine** shapes, and an exhaustive 66,048-label fuzz finds disagreements in two classes (underscore, asserted; **trailing-LF, undeclared**) — **the fourth verification put that count at 376 and it is wrong; the true figure is 111, and the correction is recorded below under my own name because I published the wrong one** with a 117-shape whole-name differential finding **five** divergence classes against the one asserted. The cause is one character: `DomainName::assertLabel` is anchored `$` where `HOST_LABEL` is anchored `\z`. Second, **an undeclared mutation survivor**: `M08` moves the 253-character host boundary by one and survives suite-wide, because the only over-length row in the entire test tree is 257 characters, so any mutant up to `> 256` passes — a fourth unpinned rule in a commit that declares exactly three. Three further false sentences and an F-40-shaped import set ride along non-blocking. **The rework's pre-registered experiment came back.** Rather than restate the 144,650 I had briefed (a figure resting on a rule I had already retracted), it named the two possible answers and launched a second identical run to choose between them: **4032 / 144,657, skipped=0**, three below its own first run on an unchanged tree. Its reading — that this confirms the fresh-database first-run rule — I do not adopt; see the section on what the experiment did and did not settle. The figure to carry under matched conditions is **4032 / 144,657**, +1 test on the 4031 predecessor. Both blocking items are closed and the survivor count is at one, `HOST_CHARACTERS`, with its reason narrowed to the true claim (*pinned by nothing worth writing*, not unreachable by any test) after the agent found that a comparison against the three near-miss host rules would in fact kill the `$` mutant — a quantifier sentence caught prospectively, by its author, before it was written. **The fifth verification confirmed every figure, including the ones I told it to take from nobody** — it transcribed both patterns by hand from source and re-swept, getting 111 / 75 / 36 exactly, and checked that the new test's assertion is tied to the sweep it runs rather than a remembered number. It went **wider than the rework could**, running both declared survivors against the full suite and confirming they survive with *zero* observable difference. It also ran the counterfactual on the 253/254 zone-entry subtlety and got the resolver's error instead of the row's failure, establishing that the reasoning was real and not a rationalisation. **Both new blockers are false sentences in the commit's own anchor accounting — the very thing that round was convened to correct.** The first: the round corrected the count in all four pre-existing places and then **introduced a fifth, stale one in the same commit**, still saying three. The second is the more interesting, because it is an **under**-claim: `NUMERIC_LABEL`'s `\z` is declared unpinnable on the ground that the label-by-label comparison road *"this pattern has no equivalent of"*. It has one — `DomainName::fromString`'s `ctype_digit($last)` — and **I measured it myself** over the identical 66,048-label space: **0 disagreements as shipped, 10 under the `$` mutation**. The road exists and is *cleaner* than the one the round built, because the rules agree perfectly as shipped. The verifier's own framing is the right one: *"the same class of error the rework was written to correct, found with the rework's own new technique."* And the round's own correction forecloses the narrow reading — establishing that *"no test can falsify"* means any test, not only tests through an entry point, is exactly why the count moved from three to two. **Second rework delivered at `4cd7627`, in its sixth verification.** It took the harder of the two options I offered — adding the comparison test and moving the count to **one** rather than striking the false clause — on the ground that striking it *"would have preserved the under-claim rather than removed it, in the one commit whose headline is that the count is corrected when it moves."* It reproduced my measurement independently (0 disagreements shipped, 10 under the mutation) and ran the **whole seven-anchor matrix**: six now fail a named line each, `HOST_CHARACTERS` the sole survivor. It fixed the fifth stale count **by not stating the number a sixth time**. Policy change proven comment-only by zero non-comment diff lines. | |
| F-31 | Medium | SECURITY | **`CLOSED`** — upheld by the sixth independent verification; its two prose reservations closed at `85c2ddb`, **mechanically verified comment-only** and both bands re-run to the exact expected figures | The sixth verification re-derived the runtime-teeth chain independently against a private vendor copy, driving **real requests through `Kernel::handle()`** with the throw site instrumented, and confirmed every sub-claim **down to the named frames**. If anything the rework understated it: the exception is re-raised **nine** times, not twice, as it unwinds each pipeline stage. It counted the seams by reflection and got eight — **confirming the rework and correcting me**, and noting my four also omitted `setTrustedProxyIpAddresses()` itself, the very method the false sentence called closed. **It proved the guard is more robust than the rework claimed for it.** Asked whether the guard is one framework reorder from being decoration, it reversed the `&&` and found the suite still green — because of a **second, independent framework property nobody had named**: `IpUtils::checkIp()` computes its dispatch method but only *calls* it inside `foreach ($ips as $ip)`, so an empty list never enters the loop. Two independent reasons, not one. **It proved `skipped=0` with a positive control** — a throwaway file containing a real `markTestSkipped`, confirming the reporter emits the key and makes `tests > passed`. That is the strongest form of that proof this programme has produced. And it ran the full suite **twice**, reading 144,291 then 144,288, establishing directly that the assertion total is not stable to ±3 while `tests`, `passed`, `failed` and `errors` are exact. Two one-line reservations: an inventory sentence the rework made stale **inside its own band** — the very failure `25ef4c1`'s message names — and a test docblock claiming a mechanism the row does not pin, where the claim is false and the row pins something better. **Closed.** The two reservations were the only thing the sixth verification held it on, and both are now true. The fix is comment-only, which I verified myself rather than accepting: filtering the diff of docblock and blank lines leaves **nothing**, across 3 files and +60/−12. Both bands re-ran to the exact expected numbers — 34/34 with 208 assertions, and 257/257 with 1110 — with `skipped=0` proved three ways. **And the rework found the defect was worse than either of us had it.** I said the anonymous subclass was added *inside the band* that carried the false inventory sentence. It is tighter: `919048f` introduced **the sentence and the subclass in the same commit**, proved three ways. The sentence was false **at the moment it was written**, not falsified later — so the docblock now states the boundary form (*"nothing outside the test suite extends this"*), which the next test subclass cannot falsify, and points at the reflection test that pins the seam's size rather than leaving prose to keep a list. It also corrected a line citation I had relayed from the verification: the empty-array loop is at `IpUtils.php:68-76`, not `69-73` — **I checked, and 68/70/76 is right** — with the note that the wrong pair probably came from a different vendor version, which would matter anywhere else in this programme citing that file by line. |
| F-33 | Medium | DATA_INTEGRITY | `OPEN` — first round delivered at `d4e85ed`, **in verification** (`v33a`) | **The defect is at the definition layer and everything downstream is correct, which is what makes it bite.** `subnets` is unique on `(ip_pool_id, cidr)` and the request rule compared the same string — so `203.0.113.0/24` and `203.0.113.0/25` are two rows. `SeedSubnetAddresses` then writes one row per address per subnet, so the overlap becomes **two rows holding one address string**, and the allocator's concurrency machinery — keyed on `ip_address_id`, with `FOR UPDATE SKIP LOCKED` and two partial unique indexes — is **entirely satisfied while two customers are handed `203.0.113.10`**. **First-fail proof committed red before any fix**, driven through the operator route F-02 made reachable and read back from the **customer API**: *"the same real address reached two customers: 203.0.113.10"*. The guard compares **parsed blocks** under an advisory lock, against a realm it argues for rather than assumes: same datacenter always; **platform-wide when either side is internet-routed**, because a public address is unique in the world; and deliberately **not** private space in another datacenter, since refusing `10.20.30.0/24` in two datacenters would be a false refusal against a normal estate. Inactive subnets are included — *"`is_active=false` stops the allocator reading a subnet, it does not release the addresses already assigned out of it."* It **removed** `Rule::unique('subnets','cidr')` as the second, weaker copy the file's own docblock warns against, wrong three ways at once: too weak (string comparison), too strong (global, refusing legitimate repeated RFC1918 space), and applied to **unnormalised** input so `203.0.113.7/24` passed the rule and hit the table index as a **500**. Restoring it kills two tests, so the removal is load-bearing. **17 mutations, 14 killed, 3 argued equivalent and stated in the code rather than left implied.** Two taught it something and produced tests rather than rationalisations — one exposed that nothing pinned a private pool holding public space the estate already has; the other, read-then-lock, is **indistinguishable through two connections** because there is nowhere to interleave, which it said rather than dropping, asserting the ordering on the statements issued via `DB::listen` instead. The verification is briefed to put its weight on the question this programme has seen fail every previous time it was asserted: **is `RegisterSubnet` the only writer?** A guard on one writer is not a guard, and nothing in the round establishes that seeders, imports, console commands, model mass-assignment or a later **edit** of a subnet's `cidr` pass through it. It is also briefed on the classifier the realm rule rests on (CGNAT, link-local, multicast, documentation space, IPv6 unique-local), on IPv6 and unnormalised input now that the request rule is gone, and on the advisory lock's key and scope — whether two overlapping-but-different blocks can take different keys and both pass. |
| F-35 | Medium | OPERABILITY | `OPEN` — **upheld with reservations**, one blocking, in bounded rework | The verification reproduced F-35's literal sentence on the parent **through the command's own output** — five mapping checks all pass, `mapping.network` absent at any status — and showed it gone at the tip. It verified the rule is live on all six products with a chain by **instrumenting the rule loop** rather than reading the constant, corroborated by the JUnit per-test assertion count, and verified *"the shapes are the whole of the difference"* **three ways**, including restoring the previous revision's test file both with and without the new constant entries. It verified the ProviderChain paragraph line by line and established there is **no seventh exit**, showing `notTestedBelow()`'s own `return []` unreachable. And it sustained the volunteered contrast on **three** independent pairs rather than the two claimed. **The blocker is a fifth survivor in the class the enumeration claimed to cover**: dropping `mapping.none` — the `default` arm serving **6 of the 12 products** — passes both the oracle set and the whole Infrastructure band at exactly baseline, while the id is emitted in reality (`--product=dns` returns a band of exactly that one check). It is neither of the two accounted-for categories. The verifier offers a reconstruction of the reported 22 that fits the numbers exactly and implies `mapping.none` was never enumerated. **The same hole appears as a loose sentence in the source**, claiming a forgotten check would be named by the assertion — false for the six unshaped products, proved by adding a real new check and watching the band stay at baseline. **Rework delivered at `399e691`, in its third verification.** It **found the parent's actual 22-mutation list in agent scratch** — it is nowhere in the repository — and used it to confirm the verifier's inference *and correct its shape*: the 22 touched 12 of the 13 ids, with `mapping.none` never enumerated, so *"over every check id the chain can emit"* was false. Corrected: **24 mutations, 20 dead, 4 survivors** at the tip, against 24/18/6 at the parent. The fix is a ninth estate shape naming `dns`, which **takes no fixture because the arm reads nothing — *"which is exactly why it was easy to leave out"***. **It ran three claims of the *cannot/would* shape rather than recording them**, per the standing rule, and one was a prediction nobody had ever executed: *"a `Product` missing from `MAPPING_CHECKS` would raise an undefined-array-key, not a failure"* — measured, 1 error and 0 failures. A second, *"adding a check without adding it to the constant does not weaken the test"*, was **false as an absolute** and is now qualified where the rule runs. The third sent it to read `IpAllocator::subnetIdsFor()` rather than assert, finding a **fourth** estate where `mapping.network` answers PASS and nothing can be allocated — *"F-35's own defect one level down: counting pool rows instead of active pools was the bug; counting active pools instead of allocatable subnets is what is left."* |

### Wave 4 — customer-facing correctness

| ID | Sev | Class | Status | Notes |
|---|---|---|---|---|
| F-20 | High | CODE_GAP | `OPEN` | `data_destroyed` rendered on Dedicated and on no VPS screen; strings absent from both locales. |
| F-21 | High | CODE_GAP | `OPEN` | Registration dead-ends silently; Create-account stays enabled because `registrationClosed` tests `=== false` on `undefined`. |
| F-27 | Medium | SECURITY | `OPEN` — **started**, first round | `error.details` publishes `$e->context()` verbatim to customers, contradicting `ErrorCatalogue`'s docblock, on two customer routes leaking provider identity and configuration key paths. **Dispatched with corroborating evidence already measured by another finding's verification**: F-26's verifier reached F-27's shape from the side, showing `InvalidDomainNameException::malformed()` puts a **configured** value into `error.details.name` on a 422 to an unrelated customer — and deliberately recorded it in prose rather than a test, on the ground that *"a row asserting it would bless it"*. The brief tells the implementer to verify that rather than inherit it, to prove each of the finding's three clauses separately, and to treat the audit's *"two routes"* as a claim to be counted rather than a fact. |
| F-32 | Medium | — | `OPEN` | Withdrawn hosting package chosen by row order in two modules; no index on `plan_id`. |
| F-34 | Medium | — | `OPEN` | Timeout quarantines can never be cleared; `ReleaseReason::OperatorAction` has no callers. |
| F-43 | Medium | TEST_GAP | `OPEN` | `ConsoleSessionStore::consume()` never compares `expires_at`; its expiry tests prove `ArrayStore`. |
| F-44 | Medium | TEST_GAP | `OPEN` | `QuarantineLifecycleTest` carries the reboot-race clock shape in three methods. |

### Wave 5 — operability, observability, scheduled work

| ID | Sev | Class | Status | Notes |
|---|---|---|---|---|
| F-22 | High | OPERABILITY | `OPEN` | Critical drift invisible on every channel; Loki ruler wired to Alertmanager with zero rule files. |
| F-30 | Medium | SECURITY | `OPEN` | Horizon has no `Horizon::auth()`; 22 routes gated only by `APP_ENV=local`. |
| F-36 | Medium | — | `OPEN` | `domains:reconcile` throws uncaught in production every three hours. |
| F-37 | Medium | — | `OPEN` | Dedicated power operations invisible to monitoring; a stale claim poisons its idempotency key for ever. |
| F-39 | Medium | OPERABILITY | `OPEN` | Fifteen alert names appear in runbooks and in no rule file. |

### Wave 6 — test and architecture mechanism

| ID | Sev | Class | Status | Notes |
|---|---|---|---|---|
| F-23 | High | TEST_GAP | `OPEN` | Architecture suite asserts reachability for methods, events, capabilities, metrics and translations — and for no enum case or state-machine state. |
| F-24 | High | TEST_GAP | `OPEN` | Simulators model the convenient case; three blocking defects unrepresentable in-tree. |
| F-25 | High | ARCHITECTURE_RISK | `OPEN` | `ProductKind` pinned by no architecture test; Prepared containment rests on a `ValueError`. |
| F-38 | Medium | — | `OPEN` | Five CI/IaC gates do not test what their names claim. |
| F-40 | Medium | ARCHITECTURE_RISK | `OPEN` | 542 cross-module `Infrastructure` imports against a `CLAUDE.md` claim of enforcement; only the `Http` half is enforced. Decide which of the rule, the enforcement or the documentation is wrong **before** touching any import. |
| F-41 | High | ARCHITECTURE_RISK | `OPEN` | `phpunit.xml` pins ~30 variables without `force="true"`; `RefreshDatabase` runs `migrate:fresh` with no safe-database guard. |
| F-42 | Medium | TEST_GAP | `OPEN` | `apps/web` vitest suite load-triggered flaky; required CI gate, `retries: 0`, no configured `testTimeout`. |

### Wave 7 — declaration and notification truth

| ID | Sev | Class | Status | Notes |
|---|---|---|---|---|
| F-46 | High | OPERABILITY_GAP (account-security four arguably SECURITY) | `PARTIAL` | Sixteen notification types declared, translated into both locales, produced by nothing. Some backup/restore producers were wired while closing F-09/F-10; the remainder must be recounted against current HEAD before any repair, and nothing already wired may be duplicated. |
| F-47 | Medium | — | `OPEN` | `DedicatedServerStatus::Retired` — three readers, one of them a race argument, against zero production writers; only writer is a test factory. |

### Adjudicated at the final re-audit

| ID | Sev | Class | Status | Notes |
|---|---|---|---|---|
| F-45 | Medium | TEST_GAP | `OPEN` | WordPress admin password redacted before it reaches the installer, which receives the literal `[redacted]`; the guarding test inspects column names, not values. Out of approved launch scope and latent. May not be closed by promoting WordPress. If any change makes WordPress production-complete or sellable, F-45 becomes blocking. |

## Cross-branch hazards: what merges cleanly and still breaks

The migration collisions above are a naming problem. This section is for the
other kind — changes on two branches that touch **disjoint files**, merge with
no conflict, and interact anyway. Git cannot see these, and neither can an agent
inside one worktree.

### RETRACTED: my sign-off on F-19's behaviour change

I signed this off. The verification measured it and I was wrong, so the
retraction goes above the original rather than replacing it.

I accepted the rework's argument that moving hosting terminations onto the
service route *"grants no new power, because `DELETE /api/admin/hosting-accounts/{account}`
has always done exactly this with byte-identical gating."* **That is true of the
window gating and false of the permission gating** — literally the same
`retentionHasElapsed()` call, and an entirely different set of permissions — and
authorization is the dimension F-18 is about. I read "byte-identical gating" and
did not ask *which* gating.

Measured on F-19's own branch, before F-18 merges at all:

| route, live hosting account | permission | `force` | result |
|---|---|---|---|
| `DELETE /api/admin/services/{service}` | **`service.terminate` alone** | no difference | **202, destroyed** |
| `DELETE /api/admin/hosting-accounts/{account}` | `hosting_account.manage`, **plus** `service.terminate` when forced | a different grant | 200 |

The same principal, holding only `service.terminate`, is **refused 403** by the
hosting-account route and **destroys the account with 202** through the service
route. Neither permission implies the other, so this is a second door with a
different key — and under `force` the service route's requirement is a **proper
subset** of the other's, which bites hardest on the suspended-inside-retention
case the window exists to protect.

**Three things I am taking from this, because the error is instructive rather
than just wrong.**

**I approved a security-relevant change on an argument rather than a
measurement.** The claim was specific, plausible, and made by an agent that had
been right about everything else in its report. It was also checkable in about
twenty minutes by hitting two routes with one principal, which is what the
verifier did. My own standing instruction to every agent here is to measure
rather than believe; I did not apply it to a hand-back I found convincing.

**"Byte-identical" is a dimensioned claim and I took it as a scalar.** Two
routes can share a gate exactly and differ completely in what reaches it. The
question that was missing is *identical in which respect* — and that question is
cheap to ask of any claim of sameness.

**The change was invisible.** `grep` over the whole source diff for any record
of it returns **0 hits**, and the branch never tests the newly-permitted case.
A behaviour change that appears in no commit message, no comment and no test is
one that can only be caught by someone re-deriving it from scratch. That is what
happened, and it is luck rather than process.

### F-19 × F-18 — a hardened door, and a new corridor that bypasses it

**F-18 is closed.** It is an AUTHORIZATION finding about a live hosting account
being destroyed on the weaker of two permissions, and its fix has **two
independently pinned layers**: a gate in `Admin/Http/Controllers/HostingController.php`
and a refusal inside `TerminateHostingAccount`.

**F-19 reroutes the service endpoint.** Ending a shared-hosting service by
*service* id used to go through the VPS action and be refused 409
`vps.termination_before_suspension` — VPS rules applied to a panel account by
accident. It now goes through `EndOfService` → `EndHostingService` →
`TerminateHostingAccount`, which is correct and is what removed the duplicated
dispatch table.

The two branches touch **disjoint files** — F-18: `HostingController`,
`TerminateHostingAccount`; F-19: `Admin/Http/Controllers/ServiceController` —
so they will merge with no conflict. **That is precisely the danger.** After the
merge the service route inherits F-18's *action* layer and **not** its
*controller* gate, and F-18's own verification established that the two layers
refuse differently: reverting the controller gate still produced a 409 from the
action where the test demanded a 403. So the layers are not interchangeable, and
a route that reaches only one of them is a different control from the one F-18
closed.

I have **signed off F-19's behaviour change in principle** — preserving an
accidental divergence is worse than removing it, and on F-19's own branch it
grants no power the hosting-account route did not already grant. What is not
yet established is what it grants **after F-18 merges**. F-19's verifier is
required to characterise, on its branch, exactly which permission constants the
new service-route hosting path demands to destroy a live account, at which gate,
and whether `force` changes it — not to judge F-18, but to produce the baseline
that makes this check decidable at integration.

**The general rule this produces**, which applies to every remaining wave: two
branches that change *the same behaviour* through *different files* are the
hazard this programme's parallelism manufactures, and the merge being clean is
evidence of nothing. Before integration, every pair of branches touching one
customer-visible or operator-visible path must be checked against each other,
not merely merged.

## Corrections owed but not yet in the tree

Small, agreed, and lost or deferred for a mechanical reason rather than a
judgement. Each names the exact text so it cannot evaporate.

### F-19 — `EvidenceOfABuild`'s class docblock

Written by the implementer after its wide band came back green, uncommitted when
**I destroyed the worktree** (see the ninth isolation defect). Not re-appliable
by its author — there was no worktree left — and I am not applying it to
`remediation/f19` while `v19e` verifies that exact tip.

Current text, which is the defect:

    *    Reading the absent row as "never built" turns the one failure the engine
    *    refuses to retry — because retrying it builds a second machine — into the
    *    one failure an operator may close with a single click.

*"the engine refuses to retry"* is true **only of the automatic path**, and that
is the identical elision that turned `TerminateVpsService`'s docblock into the
false sentence which became this finding's Block 2. The replacement must insert
**AUTOMATICALLY**, name `FailureClass::isAutomaticallyRetryable()` as the
exclusion, and state that `RetryProvisioningJob` — the operator's button — does
not read the failure class at all. Two lines, no behaviour.

**Adjudicated: apply at integration.** F-19's fourth verification returned
UPHELD with nothing blocking, so there is no next round to carry it, and I am
not editing a verified tip to add prose — the tip I close on must be the tip that
was measured.

Two things changed my reading of *why* it should be applied, and neither is the
reason I first gave. The verifier swept **all 45 retry-refusal sentences** in
`src/` and `tests/` and found **exactly one** without the qualifier: this one. It
then declined to hold on it, on the ground that *"the engine"* does scope the
claim and the engine genuinely does refuse — which is correct, and is my own rule
that an ambiguous sentence is not a false one. So this is **not** a false
sentence and **not** the same defect as Block 2; my earlier entry overstated it,
and the overstatement is the kind this ledger exists to catch.

What survives is narrower and still worth the two lines: it is the single place
in the codebase where the reader must supply *automatically* for themselves,
while `RunProvisioningJob:63`, `DriftKind:12` and `ProvisioningJobStatus:13` all
say the word — and it sits in the very paragraph whose engine-versus-operator
contrast produced Block 2. It is recorded here because a correction that exists
only in a hand-back is a correction that will be lost, which this programme has
now demonstrated twice.

## Migrations awaiting integration review

Two parallel agents may not independently add migrations touching the same table
family without review, and the coordinator orders them before integration.

Three branches each added a migration and each chose the same timestamp,
`2026_04_15_000000`:

| Branch | Migration | Table |
|---|---|---|
| `remediation/f04` | `…_record_the_domain_a_hosting_line_was_bought_for` | `order_items` |
| `remediation/f08` | `…_record_which_payment_failure_the_dunning_counter_counted` | `subscriptions` |
| `remediation/f15` | `…_reserve_a_provider_identity_before_the_call` | `provisioning_jobs` |

**And a second collision, at `2026_04_16_000000`:**

| Branch | Migration | Table |
|---|---|---|
| `remediation/f11` | `…_record_which_call_left_a_dns_record_unanswered` | `dns_records` |
| `remediation/f15` | `…_record_which_cluster_an_identity_was_reserved_against` | `provisioning_jobs` |

This one corrects a claim made in this section and repeated through three
verifications: that later migrations *"deliberately avoided the timestamp —
`remediation/f11` chose `2026_04_16_000000`"*. That was true when it was
written and is no longer, because `remediation/f15`'s second migration landed
on the same date. It was found by F-11's implementer, who scanned **all refs**
rather than its own branch and the base — which is what the earlier enumeration
checked, and is why the earlier enumeration could not have caught it. I
re-verified it here by diffing every `remediation/f*` branch against the trunk:
five added migrations across four branches, in two colliding groups of three
and two, plus `remediation/f04`'s second at `2026_04_24_000000`, which is
genuinely unique.

Implementers have been told **not** to rename any of these themselves, because
a rename on one branch would conflict with the others' history; the coordinator
does it once, at integration.

Nothing is broken by either collision — every one of these five touches an
unrelated table, there is no dependency between them, and Laravel orders by
filename so the sequence is deterministic even when two files claim the same
instant. But a timestamp is a record of when something was written, and
three files claiming the same instant is a record that is false and that makes
the history harder to read than it needs to be. All five are renumbered to distinct
timestamps at integration, preserving the order in which they were verified.
That is a rename; no content changes.

All three are additive and nullable. Each is checked at integration for a clean
`migrate:fresh`, a rollback that removes what it added, and a re-apply.

A fourth is expected: F-04's rework has to decide whether two live hosting
accounts may carry the same primary domain, and a database-level uniqueness
constraint is the only answer that survives a concurrent double-submit. That
agent has been told not to reuse `2026_04_15_000000`.

A fifth has arrived and did not collide: `remediation/f11` adds
`2026_04_16_000000_record_which_call_left_a_dns_record_unanswered`, which
records whether an indeterminate row came from a publish or a delete. The
zero-schema alternative was considered and rejected with a proof: narrowing
the settle rule to `Deleting` rows alone would strand every unanswered
delete for ever, because an unanswered delete moves `Deleting` →
`Indeterminate`. That trap is now pinned by its own breakage case.

`remediation/f15` edited its own migration in place rather than adding a second
one to change a column from a string to an append-only list. That is correct
here and only here: the migration is unmerged and has never run outside a test
database, so a column added and dropped two commits later would be a worse
record than one column that was always a list.

### What the collisions actually cost, measured rather than assumed

Re-surveyed at the current heads of every `remediation/f*` branch. The picture
is unchanged in shape — seven added migrations across four branches, five of
them in the two colliding groups above — and I have now measured the thing
that decides how much work integration is, which nobody had checked:

**The five colliding migrations touch five different tables, and the only
same-table pair is on one branch, in date order.**

| timestamp | branch | table |
|---|---|---|
| `2026_04_15_000000` | `f04` | `order_items` |
| `2026_04_15_000000` | `f08` | `subscriptions` |
| `2026_04_15_000000` | `f15` | `provisioning_jobs` |
| `2026_04_16_000000` | `f11` | `dns_records` |
| `2026_04_16_000000` | `f15` | `provisioning_jobs` |

Every one is a `Schema::table` alter — **none creates a table** — so nothing
depends on another's existence. None declares a foreign key or a cross-table
constraint; `f08`'s carries an explicit comment saying it deliberately does
*not* take one, because the column names a row in another module. No two of
them add a column to the same table. The only repeated table is
`provisioning_jobs`, both on `f15`, dated a day apart, so their order is fixed
by their own filenames and by being on one branch.

**Consequence: renumbering is a mechanical rename with no semantic risk.**
Laravel orders by filename, so with identical prefixes the run order falls out
of the remainder of the name — and since no two of these five share a table,
that order cannot change the resulting schema. This section previously read as
though integration had an ordering problem to solve. It does not. It has a
naming problem, which is worth fixing so the history is legible and so the next
parallel branch does not make it a real collision by landing on the same table.

That distinction is worth keeping because the danger it removes is real in the
general case and would not have been obvious: two same-timestamp migrations on
one table are an ordering hazard that surfaces only on a fresh install, long
after the branches have merged and the order that produced the working database
has been forgotten.

## Discovered / out of scope

Defects found while repairing a finding, recorded rather than numbered. Repaired
immediately only when closing the active finding truthfully requires it, or when
this program introduced them.

| Description | Evidence | Related F-ID | Blocks? |
|---|---|---|---|
| **The IPAM migration's own comment argues for PostgreSQL network types, then declares `varchar`.** At `2026_01_03_000001_create_ipam_tables.php:70-72` the comment states why a varchar is wrong — it accepts `10.0.0.300` and sorts `10.0.0.9` after `10.0.0.10` — immediately above `$table->string('cidr')` and `$table->string('address')`. | **The consequence is live and was observed rather than reasoned**: F-33's first-fail proof handed out `203.0.113.10` before `.2`, because `IpAllocator::lockAvailableAddresses()`'s `ORDER BY address` is lexicographic. | Found by F-33; belongs to no finding | No — every address is still handed out exactly once. But *"first available"* does not mean what the code reads as meaning, and **the comment describes a schema that was never built**. |
| **`redacted_keys` is matched by substring, so it silently destroys operator data.** `config/security.php` lists `'pan'` (card PAN) and `SecretRedactor::isSecretKey()` matches substrings, so **any** key containing "pan" is replaced with `[redacted]`. Demonstrated live: `provisioning_jobs.result.response.panel` is stored as `"[redacted]"` — the field that tells an operator whether an account is on cPanel or DirectAdmin, on the queue screen they open when a customer rings. `'card'` does the same to `discard`, `'auth'` to `author`. | Found by F-04's rework when two of its own mutation probes appeared to survive; it traced the survival to its own key names rather than to the code, which is how the config defect surfaced. | Found under F-04; belongs to no finding | No. **It fails safe for secrets and silently for everything else**, which is the right direction and the wrong mechanism. |
| **`wordpress_sites.domain` has the identical shape to the defect F-04 just fixed**: a unique index on a raw text column, made a rule about *names* only by one fold in `OrderWordPressSite` — one deleted line, as F-04's own mutation M13 showed. | F-04's rework pinned that fold and added the twin assertions, but deliberately did **not** add a CHECK constraint there — different table, outside the finding. | F-04's shape, not F-04 | No — latent rather than live, because that action is currently the only writer. That is a property of today's code, not of the schema. |
| **The operator remedy F-04 built is unreachable after a transient panel refusal.** `CreateHostingAccountHandler` sets `providerReference` on a panel refusal **and** releases the slot — i.e. on the one path where it has just established nothing was created — and `RetryProvisioningJob::whatItBuilt()` then refuses every operator retry for that job for ever. | Disclosed by the implementer against its own work. The remedy does work for the two refusals that advertise it, neither of which sets a provider reference. | F-04 built it; the guard belongs to the indeterminate-create territory | No — the guard is load-bearing there and was correctly left alone. But it is a real narrowing of operator recovery, and it is the second time this week a guard written for indeterminate creates has blocked a legitimate repair. |
| **`ComputeCluster::$attributes` does not default `verify_tls`**, so an unsaved model has it NULL, hits the `??`, and **makes the fleet-wide key live again — the exact shape F-28 exists to prevent.** | Measured with no DB write: `new ComputeCluster()` gives `verify_tls === null` while `status` is correctly defaulted. The model's own docblock two lines above explains this hazard for `status`. No production path creates a cluster in-request (`grep` for `ComputeCluster::create`/`new ComputeCluster` over `src/` and `app/` returns nothing), so nothing is broken today. | F-28 | No. But *"no cluster can arrive with no preference"* is load-bearing on a property of **today's code** rather than of the schema. One line — `'verify_tls' => true` in `$attributes` — makes it a property of the model. Worth a future round. |
| **`docs/production-checklist.md:34` now asks operators for a setting that changes nothing**: *"`PROXMOX_VERIFY_TLS=true`. Disabling certificate verification on the link that creates and destroys customer machines is not an acceptable shortcut."* F-28 established the key is inert for compute and backups. | The config docblock justifies its own placement as *"where an operator reaching for the environment variable will read it"* — but the checklist is the likelier place an operator reaches **from**, and it says the opposite thing. | F-28 | No — the security posture it states is right, and it was outside the sentences under review. |
| **A flaky test with a real cause: the VM factory can collide on a unique index.** `ListVirtualMachinesEndpointTest::the_page_size_is_bounded_however_much_is_asked_for` errored with `SQLSTATE[23505]` on `virtual_machines_cluster_id_provider_id_unique`. The factory generates `'provider_id' => (string) ($vmId ?? random_int(100, 999999))` against a unique index on `(cluster_id, provider_id)` — a birthday collision, and **more likely the more rows a page-size test puts in one cluster**. | Failed 1 of 2 full runs, passed 3/3 in isolation. File last touched at `b56e352`, unrelated to the diff it surfaced under. | Found during F-26's verification; belongs to neither | No — but it is a genuine intermittent failure that will be blamed on whatever change is under test when it fires. A deterministic sequence in the factory fixes it. |
| **`dedicated.bmc.verify_tls` (`REDFISH_VERIFY_TLS`) is defined in `config/dedicated.php:23` and read by nothing.** A documented global TLS switch that silently does nothing: an operator who sets it to work around a self-signed BMC gets no effect and no error. | `grep -rn "dedicated.bmc"` across `src app config tests` returns only `timeout_seconds`, `ipmi_timeout_seconds` and unrelated message codes. | F-28 found it; it is the **mirror image** of F-28 — that finding was a global key reaching a socket it should not, this is a global key reaching nothing at all | No. Redfish correctly sources `bmc_endpoints.verify_tls`, so the per-row discriminator is unharmed and no socket is mis-configured. |
| **`10000-01-01` is read as year 2000.** `date_parse` takes the first four digits of the year field and drops the fifth, so the gate and the reader **agree**, and F-14's outcome rule — which refuses when they disagree — structurally cannot see it. The value is stored as 2000-01-01, `isPast()` fires, the node is refused. | Found by F-14's round ten and ruled **record, not fix**: pre-existing, unchanged by that round's five commits, safe-direction, and reachable only by a five-digit-year ISO string no panel writes. | F-14 | No. It is the **second** case in this method that is safe *by accident rather than by design* — `Ymd` is the first, `20991231` reading as 31 August 1970. Both are now labelled as accidents in the docblock, together, because the class is the warning and one instance does not show it. |
| **On a PHP built without IPv6, a caller already behind a trusted balancer can put an IPv6 address in `X-Forwarded-For` and get a rendered 500** on any route that reads the client address. `Request::normalizeAndFilterClientIps()` runs `IpUtils::checkIp()` over every entry, so the same unevaluable-build throw reaches it by a second door. `/up` is out of band here, and it renders rather than escaping as a fatal. | Measured by F-31's rework: `GET /up 200, POST login 500`. Each candidate repair is worse than the fault — trusting nobody hands a client the shared rate-limit bucket on demand; stripping the entry lets a client choose its own key (measured: `8.8.4.4, 2001:db8::1` keys on `2001:db8::1`, `8.8.4.4` alone keys on `8.8.4.4`); parsing the chain duplicates the framework. | F-31 | No. **Ruled: stays open.** I will not trade a fault that requires an unsupported build for a vulnerability that works on every build. The right instrument is a deployment-time check that the build can evaluate the configured entries, not a per-request repair. |
| **A mistyped trusted-proxy entry is refused silently**, so a deployment with a typo gets balancer-keyed rate limiters and no signal. | The lost attempt's salvaged patch added a deduplicated `Log::warning`; F-31's rework declined to adopt it and said why — per-request flooding, and new scope. | F-31 | No. **Ruled: not F-31's, and a request-path logger is the wrong instrument.** It belongs with the deployment-time check above, where it fires once against configuration rather than once per request. |
| **A Proxmox row whose token the cluster refused is recorded `ReadyForProduction` with a `Valid` credential.** Mutating `ConnectionState::PermissionInsufficient` → `Connected` on `classify()`'s `/nodes` 401/403 branch passes **1568 tests**. Capabilities become `allOf(Unknown)`, which is not empty, so they are recorded; `usable()` becomes true; readiness returns ReadyForProduction. | Pre-existing at `515a425`, outside the F-13 commits and outside the scope the commit swept. No *product* verdict moves, because everything `Unknown` means `supports()` is false. | F-13 found it; predates it | No — but it is a credential the cluster rejected being shown to an operator as valid. |
| **`privilegesIn()` flattens privilege scope across paths**, so a token holding `VM.Allocate` only at `/vms/101` is told `create` is Supported although it cannot allocate a new VMID. The docblock justifies the flattening with a power-management example (*"a token that may power on exactly one machine can power on a machine"*) that does not carry to `VM.Allocate`. | Pre-existing at `515a425`; moot for this repository's own clusters, whose automation grants the ACL at `/`. | F-13 | No. |
| **`TestConnection::recordCapabilities()` uses `updateOrCreate` and never deletes**, so a capability row once written survives a later test that no longer reports it — latent staleness in the table the readiness engine reads. | Only reachable if a category's capability list shrinks. | F-13 | No. |
| **`suspend`/`unsuspend` report Supported on a token that cannot perform them.** The adapter PUTs `onboot` and `lock` — an options write — while the map requires only `VM.PowerMgmt`. | Recorded in the oracle's own comment with its backstop; the backstop holds (both compute products require `create`, which requires `VM.Config.Options`), so no readiness verdict moves. | F-13 | No — but the connection test's answer for those two capabilities is wrong for such a token. |
| **A panel-controlled expiry string throws clean out of `SyncHostingNodeHealth`, and a millisecond timestamp licenses a node until the year 131968.** The numeric branch on the first line of `parseTimestamp()` sits outside the `try`, so `expires=1e15` raises `InvalidFormatException` past a handler that catches only `HostingProviderException`; and `4102358400000` parses to year 131968 rather than being refused. | Found, bisected (99,999,999,999,999 returns / 100,000,000,000,000 throws) and deliberately **not** fixed by F-14's round ten, which referred both to me. **Ruled in**, with a hard constraint: refuse an implausible numeric, never reinterpret it — inventing a millisecond contract this repository does not evidence would silently mis-license real nodes. | F-14 — same method, same harm class | **Yes, for F-14.** Now inside its scope rather than beside it. |
| **`DELETE services/{service}` always forces.** The route is gated by `Permission::ServiceTerminate` at the middleware, and `ServiceController::terminate` then re-checks the *same* permission inside `if ($force)`. The middleware already guarantees it, so the inner check can never fire and the retention window is effectively unenforced on that path. | Not an escalation — the strong permission is required throughout — but the second check is decorative there in a way it is not on the hosting route. | F-18 (same control, different route) | No. Recorded; it weakens no permission boundary. |
| **`TerminateHostingAccount` short-circuits on `=== Terminated` alone**, so a **Failed** build — nothing at the panel, no slot held, nothing to destroy — falls through to the suspension guard and is refused for ever. | The commit records this itself, correctly, and does not repair it; verified unchanged since trunk. | F-18 | No — it fails closed. |
| **A shared-hosting service with no `hosting_accounts` row** makes `EndOfService::endHosting()` throw and the nightly sweep log `failed=1` for ever. The repair query joins `hosting_accounts`, so this shape is **structurally unfindable by the very query written to find stuck services**. | Throw confirmed at `EndOfService.php:75-80`; recorded in the docblock as a separate concern. | F-18 found it; not F-18's | No — but it is a permanent nightly failure that the operator's own diagnostic tool cannot see. |
| **A re-used hosting-account row keeps its old `service_id`.** `ReserveHostingNodeCapacity`'s re-arm branch updates only `status`, leaving `customer_id`, `service_id` and `primary_domain` pointing at the row's previous life. | Harmless today because re-use only ever happens within one job, but it is the mechanism that would make the unreachable re-used-row hazard real if the username derivation ever changed. | F-18 | No — unreachable by any supported flow today. |
| **F-18's escalation needs a custom role, and custom roles are creatable.** In the default seed only `InfrastructureAdmin` holds either of `hosting.account.manage` / `service.terminate`, and it holds both — so no seeded role can perform the escalation. `SetRolePermissions` exists on this branch, so an operator can create a role that can. | Verified against the default seed and the role-editing surface. | F-18 | No — the control is closed either way. Recorded because it is the reason the control is not theoretical. |
| **The boot check reads `config('app.env')`; `HorizonCommand::handle` resolves `--environment ?? config('horizon.env') ?? config('app.env')`.** The three agree in every shipped configuration (`config/horizon.php` declares no `env` key) and CI still fails on a bad staging block because the test reads every environment. They part only in a deployment whose environment blocks differ **and** where the operator passes `horizon --environment=staging` on a production machine. | Round three left the behaviour alone on my instruction and wrote the gap into the docblock rather than describing it as covered. It reports the closure would be about two lines, since that `--environment` is readable off the same bound input the invocation reading already uses. | F-08 | No — disclosed as an uncovered gap, which is the honest form. A later round can take the two lines. |
| **Programme infrastructure, live and undiagnosed elsewhere.** Running the whole `tests/Feature/Queue` directory against a **fresh** database fails on the first run with `relation … does not exist`: `RefreshDatabase` drops and re-migrates on the first run of the process while the harness's second connection (`queue_test`) is reading the same database. Second and subsequent runs are clean. | Reported by F-08's implementer after hitting it. Every verification worktree gets a fresh database, so **every** verifier of a queue-touching finding will meet this, and a first-run failure reads exactly like a regression in the change under test. | Programme infrastructure | No — but it is now stated in every brief that touches `tests/Feature/Queue`, because the misreading it invites is expensive. |
| **`DomainName::fromString` accepts an embedded newline in an interior label.** `assertLabel` is anchored `/…$/`, and PCRE's `$` matches before a final newline, so the label `"ab\n"` passes. I verified it myself on `remediation/f29`: `fromString("ab\n.example.com")` returns a `DomainName` whose `value()` still carries the `\n`, while `fromString("ab.example.com\n")` is accepted with the newline **stripped**. The interior case is the dangerous one — a value object whose whole purpose is to be a trusted name, carrying a control character into whatever consumes it. | Probed directly at `v29f`@`726a7c3`; `DomainName.php:99` anchored `$` against `EndpointPolicy.php:457` anchored `\z`. | F-29 found it; the defect is in the **Dns** module | No — F-29's own `HOST_LABEL` is correctly anchored and refuses the class. Recorded, not fixed: it is outside F-01..F-47. |
| **`HttpSiteProbe` makes real DNS lookups during the test suite.** `HttpSiteProbe::resolvesSomewherePublic()` calls its own `@dns_get_record` at `HttpSiteProbe.php:120`, outside the `HostResolver` seam that F-29 stubbed. `TheSiteProbeCannotBeAimedInwardsTest` makes 8 such calls, two of them on names that reach the resolver (`localhost`, `this-name-does-not-exist.invalid`). | Probe installed at the syscall; F-29's own seam logged **zero** calls across 4029 tests, so the two surfaces are genuinely separate. | F-29 (its `TestCase` sentence claims the opposite of the suite) | No — but it makes a new suite-wide sentence false, which is item 4 of F-29's rework. |
| **A `Shared` class gained four imports of `Dns`, `Dedicated` and `Providers` types used only in docblocks** — in the very file whose docblock gives *"this class is in `Shared`, and is depended on by `Dns` rather than the other way round"* as a reason for not using `DomainName`. The F-40 shape, inside the file arguing against it. | None of the four is referenced outside a docblock. | F-40; introduced by F-29's branch | No — harmless at runtime. Folded into F-29's rework because this programme introduced it. |
| **A second agent ran two of its own `php artisan test` invocations against one database**, and disclosed it. F-29's *verifier* this time, not its implementer — it killed both runs, deleted every result from that window and re-ran the whole sweep serially from scratch rather than keeping the figures. This is the same defect the fourth isolation entry records, arriving by the same door to a different role, which says the brief text alone is not preventing it. | Self-reported in the verification hand-back, unprompted. | Programme infrastructure | No — but the honest response cost a full sweep, and the prevention is still only prose in a brief. |
| **SECURITY, reachable by an ordinary customer in one API request, and outside the authorised finding namespace.** `AssertRecordFitsTheZone::assertAddressIsTheirs()` looks the address up with `IpAddress::query()->where('address', $content)` — a byte-exact string match — and `DnsRecord::of()` normalises the record's **name** (`strtolower(trim(...))`) and leaves its **content** untouched. A spelling that does not match the stored row therefore takes the early return whose comment reads *"Not one of ours. Not our business"*, and the record is accepted. Measured through the public API against a neighbour's platform-allocated IPv6: `2001:db8::1` → **403** (correct), `2001:DB8::1` → **201**, `2001:0db8:0000:0000:0000:0000:0000:0001` → **201**. All three resolve to the neighbour's machine. I re-read both sites myself and confirm the mechanism. The rule's own test file calls it *"the most serious refusal on this surface … where virtual-host hijacking begins, and where a certificate authority is persuaded to issue for a domain the requester does not control"* — and tests it **for IPv4 only**, where the bypass is unavailable. **I cannot number this**: the authorised namespace is F-01…F-47 and inventing F-48 is forbidden. It is recorded here at the top of the table and raised to the user, because it is the most serious thing this programme has found that no finding owns. | F-11 verifier's three API calls; `AssertRecordFitsTheZone::assertAddressIsTheirs()`; `DnsRecord::of()` | none — the rule belongs to IPAM authorisation, not to F-11 | **Yes, for the final adjudication.** It is not F-11's to fix and no finding covers it |
| **Programme infrastructure, now repaired.** The first `mkworktree.sh` symlinked the whole `vendor/`. Composer's autoloader hard-codes an absolute `$baseDir` and PHP resolves `__DIR__` through symlinks, so every worktree loaded the canonical PSR-4 map; then one agent's `composer dump-autoload` wrote through the symlink and repointed `$baseDir` at that agent's worktree. For roughly twenty minutes every other worktree — and the canonical checkout — executed one agent's uncommitted source while reporting its own suites green. Caught by a reviewer when a deliberate breakage failed to fail. Every affected agent re-measured; no conclusion changed. | `vendor/composer/autoload_psr4.php:6` (repaired), `scratchpad/mkworktree.sh` | F-41 (same family: the suite's outcome depending on ambient state rather than on anything Git has) | No, once repaired |
| **Programme infrastructure, live.** `git stash` is not worktree-local — the stack lives in the common `.git` directory. Two agents' stashes crossed: one popped the other's work into its tree. Both recovered. No agent may use `git stash`; `git show <rev>:<path>` and `git checkout HEAD -- <path>` are the worktree-local alternatives. | dangling stash commits `5329291`, `a7be338` (both superseded by committed work) | F-41 (adjacent) | No |
| **F-17's regression guard is narrower than the finding.** `TheInvitationLimiterCannotBeRotatedByAHeaderTest` pins the limiter's *key* by calling the closure directly. Nothing pins that the limiter is attached to the routes: deleting both `throttle:team-invitations` middleware lines from `routes/v1/team.php` leaves 17/17 green. F-17's outcome — an ordinary customer turning the platform into an unbounded mail relay — is reachable again by a one-line route edit the suite would not notice. | `routes/v1/team.php:37,42`; `grep -rn "team-invitations" tests/` returns one file | F-17 (its own closure) | Yes — F-17 is not safely closed until its oracle covers attachment as well as keying |
| **Programme infrastructure, repaired — and I was testing for it wrongly.** `mkworktree.sh` hands each agent a Redis index, but `WorkerHarness::REDIS_DATABASE` was a hard-coded `15` and `setUp()` overrode the ambient `REDIS_DB` with it; each spawned worker subprocess was handed `15` explicitly as well. Every agent's real-worker traffic therefore shared one index. Fixed on the trunk at `0a3bc09`, **after** seven branches had been cut, and each affected agent was told to cherry-pick it. **The check I then used to confirm they had — `git merge-base --is-ancestor 0a3bc09 <branch>` — is the wrong check, because a cherry-pick creates a new SHA and never becomes an ancestor.** Six live branches fail that test and carry the fix by content. Re-tested by grepping the file for `env('REDIS_DB')`: only `f14`, `f29` and `f31` genuinely lack it. The correct rule: **no Queue or Simulation figure from a branch whose `WorkerHarness` lacks `env('REDIS_DB')` is evidence** — by content, not by ancestry. | `git show <branch>:apps/control-plane/tests/Feature/Queue/WorkerHarness.php \| grep "env('REDIS_DB')"` across every live branch | F-41 (same family) | No |
| **Index 15 must never be allocated, and it was.** `WorkerHarness::REDIS_DATABASE` is `15`, and that constant is the **fallback** used whenever `REDIS_DB` is absent or non-numeric. So 15 is where every run that forgets the variable lands, and where every branch still lacking the fix above lands regardless of what it was told. Handing 15 to an agent therefore guarantees a collision while that agent believes it is isolated — and it was handed out. The agent holding it saw three of 151 Simulation tests fail, **a different three on each of three runs**, all shaped as *queued work never ran*; rather than blaming its own change it read `/proc/<pid>/environ` and `cwd` for every live `php artisan` process, found the contention, and re-measured green twice on free indexes. 15 is retired from the pool; the pool is thirteen, and the allocator now refuses rather than double-booking — which it did, correctly, within the hour. | F-04 implementer's process evidence; `mkworktree.sh` `seq 2 14` | F-41 (same family) | No — but any Queue or Simulation figure taken on index 15 is contended and must be re-run |
| **A restore bug has now corrupted three separate agents' breakage measurements.** `git checkout` does not restore *untracked* files, so a mutation living in a newly added test file survives the restore invisibly; one agent then snapshotted that broken state as its baseline. Two of the three caught it only by stopping to ask why a result flattered the work. The rule adopted: commit before mutating, and verify a restore by re-running the baseline green — never by comparing the restore to itself. | three agents' own reports; `git status --porcelain` including untracked is now required at the end of every verification | F-42 (test architecture) | No |
| `PlanCapacity::RELEASED` and `outstandingCouponHolds()` each list order statuses that decide who releases a budget, and two thirds of those statuses could not occur. A plan with `stock_limit = 1` therefore reported its unit claimed for ever once the machine was destroyed or the order fully refunded. Repaired under F-19; recorded here because the *pattern* — a money decision keyed on an unreachable enum case — is what F-23's gate should catch. | `src/Modules/Catalog/.../PlanCapacity.php`; `FulfilOrderOnSettlement::outstandingCouponHolds()` | F-23 (mechanism) | No |
| `PurchaseToActiveServiceTest` and `CouponDiscountIsBoundedByTheRedemptionLimitTest` are fail-open on the build: their estate has no `ComputeNode` with room, so every purchase ends in a `needs_review` job and a `FAILED` service, and the old `assertSame(OrderStatus::Paid, …)` passed anyway because a delivered purchase and one nobody could build read identically. Assertions strengthened under F-19 rather than hidden; giving those fixtures a node with room is a change to what they cover and belongs to whoever owns them. | both test files; F-19 implementer's probe | F-23 / F-24 | No |
| `FulfilOrderOnSettlement::redeem()` swallows a failed coupon redemption — deliberate and documented, since a paying customer must not lose their service over a counter. The consequence is that such an order holds a coupon use indefinitely; F-19's `Refunded`/`Terminated` writers release it at the end of life, but nothing releases it while the order is live, and reconciliation for those swallowed failures does not exist beyond a log line. | `FulfilOrderOnSettlement::redeem()` | F-06 family | No |
| `HostingController::unsuspend` does not touch the `Service` row, so an unsuspended account can sit behind a service still reading `suspended` with a past `retention_ends_at`. F-18 made the *consequence* safe — the sweep now refuses, pinned by a test that fails on the parent — but the service will be logged as a sweep failure on every daily run until somebody notices. `BeginRetentionWindow::cancel()` exists to undo it and the unsuspend path does not call it. | `HostingController::unsuspend`; `TheRetentionSweepTest::a_hosting_account_put_back_by_hand_is_not_swept_away` | F-18 (adjacent) | No |
| `hosting_accounts.suspended_at` is never cleared on termination, and `ReserveHostingNodeCapacity` re-arms a row without clearing it, so a serving account can carry a months-old suspension date. F-18 added a status guard so nothing destructive rests on it, but the data defect stands. | `TerminateHostingAccount`, `ReserveHostingNodeCapacity` | F-18 (adjacent) | No |
| `DecommissionDedicatedServer` decommissions one chassis of a multi-chassis service (`->first()`). Pre-existing; F-12 narrowed its own blast radius back and pins the line, but the root cause is untouched. | `DecommissionDedicatedServer` | none identified | No |
| Two retention windows from two configuration keys — `provisioning.termination.suspended_retention_days` and `hosting.retention.suspended_days`, both defaulting to 30. Now tested rather than argued: when they disagree, the account's window — the one holding the customer's files — decides, and the sweep steps over. | `config/provisioning.php`, `config/hosting.php` | F-18 | No |
| **PHPStan cannot be run in this environment at all.** `apps/control-plane/tools/phpstan/vendor/` contains only `phpstan-deprecation-rules`; there is no `vendor/bin/phpstan`, in the canonical checkout or in any worktree, and `composer install` is forbidden by CLAUDE.md. The command `CLAUDE.md` documents therefore cannot be executed by any agent. Only CI exercises static analysis, so every static-analysis defect in this programme has been found by CI after integration rather than before it. Several branches change value-object constructor arity and add nullable returns, which is precisely its territory. | `ls apps/control-plane/tools/phpstan/vendor/bin` → no such file | F-24 / F-40 (gate mechanism) | No — but it means "green locally" never includes static analysis |
| **A DNS edit whose publish never answered is later settled to `deleted` while the name keeps answering the old value.** `ReconcileZones::settle()` reads "indeterminate, value not in the zone" as the deletion it was waiting for — sound only when the indeterminate came from a *delete*. The row cannot say which operation left it indeterminate; nothing records that. Probe against the simulator: `{"zones":1,"drifts":1,"settled":1}`, row `deleted`, drift severity **warning**, `ZONE STILL HOLDS ["203.0.113.10"]`. The record leaves the customer's listing, the name keeps resolving to the old address, and the only trace is a warning about a record this platform itself wrote. The implementer declined it as a product decision between `needs_review`, `failed` and "stay indeterminate". **Half of it is not a product decision:** recording which operation left the row indeterminate is correct under all three options, and settling an indeterminate row as `deleted` when it cannot tell is wrong under all three. Under adjudication by F-11's verifier; carried to the final re-audit either way, because it is customer-visible and DATA_INTEGRITY. | `ReconcileZones::settle()`; `PublishRecord::handle():86`; `docs/dns.md:179` | none in F-01..F-47 covers it; adjacent to F-11 and F-24 | **Not for F-11's closure — but it must be weighed in the `SOFTWARE_CODE_COMPLETE` adjudication** |
| **A TTL-only DNS edit never reached the provider.** `CloudflareDnsProvider::publish()`'s idempotence short-circuit asked `saysTheSameAs()`, which deliberately excludes TTL, so the PUT was skipped and the row was stamped published. Found by F-11's new field-exhaustive equality table on *unmodified* code (2 of 88 failing), not by anyone looking for it — and the simulator passed both cases throughout, which is the same fake-vs-adapter asymmetry F-11 itself is about, one field further along. Repaired under F-11 with `DnsRecord::isPublishedExactlyAs()`. | `CloudflareAdapterKeepsTheRecordContractTest` at `HEAD~1` | F-11 (repaired there), F-24 (the simulator modelling the convenient case) | No |
| The two DNS implementations disagree about an identified publish that changes a record's `type` or `name`: the adapter narrows its read to the record's own `(type, name)`, finds nothing and POSTs a second record beside the old one; the simulator searches the whole zone by identifier and rewrites in place. Unreachable through the product — `ChangeRecordRequest` accepts neither field — and now pinned so the not-editable marking cannot spread to a field the endpoint does accept. | `neither_the_name_nor_the_type_can_be_edited`; `the_fields_the_contract_suite_calls_editable_are_the_fields_this_endpoint_accepts` | F-24 | No |
| `dns_records`' unique index is `(dns_zone_id, type, name, md5(content))` and excludes `priority`, so the platform's own table cannot hold the two-record shape the provider contract proves the adapter handles. The index and `DnsRecordIdentity`'s notion of identity disagree at that edge. | the migration's index definition vs `DnsRecordIdentity` | F-33 family (uniqueness that does not match the domain's identity) | No |
| **An operator's retry cannot recover any VPS build that failed after reserving node capacity — which is most of them.** `node_capacity_reservations.reservation_key` carries an unconditional unique index, but `ReserveNodeCapacity`'s idempotency guard only reuses a reservation `whereNull('released_at')`. `CompensateFailedJob` releases the reservation on failure, so the retry's second attempt hits `SQLSTATE[23505] … node_capacity_reservations_reservation_key_unique`, burns the remaining attempts on it and lands in `needs_review`. Measured end to end through the admin retry route: `attempts=3/3`, `last_error` is the unique violation. **`RetryProvisioningJob` is the whole operator recovery path for VPS, and it does not work for any failure after placement.** | `2026_01_03_000004…:50`; `ReserveNodeCapacity.php:75-87`; `CompensateFailedJob` | none in F-01..F-47 covers it; belongs to Compute's reservation contract, adjacent to F-15 and F-19 | **Not for F-19's closure — must be weighed in the `SOFTWARE_CODE_COMPLETE` adjudication** |
| `VirtualMachineFactory` draws `provider_id` from `random_int(100, 999999)` against a unique `(cluster_id, provider_id)`, so a test creating many machines on one cluster collides by birthday. Seen once in a full-suite run by two independent agents, passing on isolated re-runs. A flake nobody can reproduce on demand is a flake that will be blamed on whatever change is in flight. | `VirtualMachineFactory:52`; `ListVirtualMachinesEndpointTest::the_page_size_is_bounded_however_much_is_asked_for` | F-42 (test flakiness) | No |
| **`EndExpiredServices` reports success while failing.** `app/Console/Commands/EndExpiredServices.php:36` returns `self::SUCCESS` unconditionally, and the action's catch increments a counter and writes a `Log::warning` and nothing else. Any service/account disagreement the sweep cannot resolve therefore re-fails every night, for ever, behind a green scheduled job — and per F-22 nothing alerts on that log. F-18 removed the one instance this programme created; the mechanism that makes the next one invisible is untouched. | `EndExpiredServices.php:36`; the action's `catch` → `$failed++` → `Log::warning`; `TheRetentionSweepTest` now pins `failed === 1` for the disagreement shape | F-22 (the alerting gap it depends on) | No — but it is what makes any future instance silent |
| **An integration hazard, not a code defect.** `UnsuspendHostingAccount::execute()` early-returns when the status is not `Suspended`, so F-18's new `BeginRetentionWindow::cancel()` cannot heal an account that is *already* `Active` while its service row still carries a closed retention window. Any row that drifted into that shape before the fix ships keeps looping nightly until repaired by hand. Evidence is being gathered on whether the shape is reachable in a deployed database; if it is, integration needs a one-off data check rather than a migration. | `UnsuspendHostingAccount::execute()`'s guard; F-18 verifier's probe | F-18 (its own fix's blind spot) | Not for closure — **for the integration checklist** |
| After the retention sweep successfully ends a hosting service, `service.status` stays `suspended` rather than moving to a terminal state. Seen on `remediation/f18`, which does not carry F-19's `EndOfService::endHosting` change; F-19's branch transitions the service to `Terminated` on that path, so the two branches must be checked together at integration rather than separately. | F-18 verifier's control probe vs `EndOfService::endHosting` on `remediation/f19` | F-19 | No — but it is a cross-branch interaction to verify once merged |
| **An unauthorised certificate authority in a customer's zone is invisible to the drift sweep.** `ReconcileZones::keyOf()` is `type\|name\|content` — no priority, no `data`. That key is what `compare()` stamps into `$matched[]` and what `reportStrangers()` checks against, so two records the platform's own `saysTheSameAs()` calls *different* collapse onto one key and a stranger beside a known record is silently marked matched. CAA content is empty and everything lives in `data`, so with the platform holding `issue letsencrypt.org`, somebody adding `issue attacker-ca.test` through the provider console gives `CAA\|example.test\|` for both. Reproduced: `{"zones":1,"drifts":0,"settled":0}`, orphans 0, for both the MX and the CAA shape. **This is the eighth door in F-11's family, in the file the rework itself touched** — and the rework's own new priority-drift test passes only because its fixture leaves `$matched` empty, so the test was written beside the hole in the same commit that fixed the comparison one layer up. | `ReconcileZones::keyOf()`; verifier probe `VerifierEighthDoorProbeTest.php` | F-11 (dispatched for rework), F-22 (the sweep is the only thing watching a zone) | **Blocking for the DNS product line** |
| **Three record comparisons disagree, and a docblock asserted they were one.** `saysTheSameAs()` is `(type, name, content, priority, data)`; the `dns_records_one_live_value` index is `(zone, type, name, md5(content))`; `PlanZoneImport::keyOf()` is `type\|name\|strtolower(content)`, case-folding where the value object does not. `DnsRecordIdentity`'s class docblock claims the first two are "the same identity", and `DnsProviderContractTestCase` repeats it and adds the third. **This is the review artefact that made the priority-blind reconciliation key look correct**, which is why it is recorded as a defect in its own right rather than as a comment tidy. | `DnsRecordIdentity` docblock; `DnsProviderContractTestCase` docblock; the migration's index | F-11 | No — but it is the cause of the one above |
| **Two customer-facing messages are false in the priority case.** `POST MX example.test → mail.example.test @10` gives 201; `@20` gives 409 `dns.record.duplicate` — *"That name already has a record of this type with this value."* The two records do not have that value; the platform's own comparison says they are different. The zone-import planner refuses the same shape with *"Line N already states this record."* Fails closed, no corruption — and a customer is told two different records are identical by exactly the priority-blindness this finding is about. | `AssertRecordFitsTheZone::assertNotADuplicate()`; `PlanZoneImport::keyOf()` | F-11 (messages dispatched for rework) | No |
| **The platform cannot hold a shape its own contract test proves the adapter handles.** Two MX at one name and host at two priorities: the adapter manages it, `EveryFieldOfADnsRecord` asserts it is legitimate, and the table refuses it at the door because the unique index excludes priority. A product gap rather than a data-integrity defect — recorded so nobody re-derives it. | the index vs `CloudflareAdapterKeepsTheRecordContractTest` | F-11 / F-33 family | No |
| **An exhaustiveness guard that does not cover the case it claims.** `EveryFieldOfADnsRecord::fields()` reflects constructor *parameters*, so a non-promoted `private readonly` assigned in the constructor body is invisible to it: `tests/Unit/Dns` stays 88/88 with a field nothing compares. The guard's entire claim is that the mechanism covers the next field added, and it covers only the next field added *one particular way*. | verifier's non-promoted-field mutation | F-11 (dispatched), F-25/F-42 (guards that do not guard) | No |
| **A refund recorded twice grows `amount_refunded_minor` twice, whenever the `Refund` row cannot be read.** `RecordInvoiceRefund::execute()` computes `$firstRecording = $refund === null \|\| $this->attach(...)`, so a null row is treated as a first recording. `RecordRefundAgainstTheInvoice` passes `Refund::query()->find($event->refundId)`, which can be null. Pre-existing and outside F-08's diff — but F-08's listener-by-listener account asserts this one "reduces nothing twice" without qualification, and now that `retry_after` is correct the surviving duplicate source is queue retries, which is precisely when a row lookup transiently fails. | `RecordInvoiceRefund::execute()`; `RecordRefundAgainstTheInvoice` | F-08 (dispatched — fix the code or qualify the claim), F-01 family (money integrity) | No — but it is money |
| **F-08's invariant is enforced against the config file, not against the deployment.** `HORIZON_PROVISIONING_TIMEOUT`, `REDIS_QUEUE_RETRY_AFTER`, `REDIS_PROVISIONING_RETRY_AFTER` and `REDIS_INFRASTRUCTURE_RETRY_AFTER` are all `env()`-overridable and **none appears in `.env.example`, `.env.testing.example` or any Ansible template**. A deployment setting `REDIS_PROVISIONING_RETRY_AFTER=300`, or raising the provisioning timeout past 5760, reinstates F-08 in production while CI — which sets none of them — stays green. The old code depended on one such variable; the fix depends on three, so the exposure grew. | `grep` over `.env*.example` and `infrastructure/` returns nothing for all four | F-08 (dispatched), F-41 (a suite whose outcome depends on ambient state) | No — but it is how the finding comes back |
| A Horizon wait-key guard that is correct only by accident of today's configuration: `every_supervised_queue_still_has_a_wait_threshold` demands one `waits` key per queue (`redis:a`, `redis:b`), while Horizon's real process key for a supervisor listing two queues is `redis:a,b`. Every supervisor lists exactly one queue today, so the test is right — the day one lists two, it will demand keys Horizon never looks up and pass while the alert is dead. That is the exact failure it was written to prevent. | `WaitTimeCalculator::calculate()` keys vs the test's construction | F-08 (dispatched), F-22 (the alert it guards) | No |
| **The adoption remedy leaves an unmanaged machine and a duplicate-address hazard.** Driving `AdoptOrphanResource` exactly as F-15's runbook instructs: `adopted_status=succeeded, virtual_machines=0, assigned_ips=0, quarantined_ips=1, service_status=active`. The service is active and billable; the machine runs at the hypervisor with a customer address in its cloud-init; the platform holds **zero** machine rows, so there is no power control, console, reinstall, destroy or drift detection for it; and **the address sits quarantined, on a timer to be returned to the pool and handed to the next customer while a live machine is configured with it.** Pre-existing — but F-15's repair is what makes adoption the primary recovery path, so it is now load-bearing. | verifier probe `CASE E`; `AdoptOrphanResource::execute()` writes the job result and syncs the service, never creating a machine row or committing the reservation | F-15 (dispatched), F-12 family (an address issued to two customers) | No — but the measured outcome of a documented remedy is a duplicate address |
| **`vps.create_identity_taken` is terminal and its runbook remedy cannot be performed.** `vmIdFor()` reads `reserved_provider_id` first, so the documented escape hatch — an id named on the payload, "so a migration or an operator can pin a specific id" — is dead code once an identity exists, and it fails *silently*. Nothing in `src/` ever clears the column; `retry` recomputes the same crc32 id because the key is fixed for the life of the order item; `adopt` would attach the stranger. The handler's own docblock puts the collision probability at ~5% at 100 machines and ~39% at 300 — arithmetic independently checked and correct — so this is an operational certainty at scale. | verifier probe `CASE F1`/`F2`; `vmIdFor()`; `reserveProviderIdentity()` is the column's only writer | F-15 (dispatched) | No — but it is a dead end a real operator reaches |
| **A discrimination rule that collapses to the defect it replaced, on a branch no test can reach.** F-15's stranger check claims a machine must match hostname, vCPU *and* memory before the disk is consulted. Measured: `vcpu null => OURS`, `memory null => OURS`, `all shape null => OURS` — because the same docblock also says a null "is not evidence either way". With a provider reporting null shape the rule is **name alone**, which is exactly the residual it was written to close. `FakeComputeProvider` always populates the shape, so no test in the repository can reach the branch — the F-24 pattern, reintroduced by the change that closed the earlier one. | verifier's shape-boundary probe; `whyItIsNotOurs()` | F-15 (dispatched), **F-24** (simulators modelling the convenient case) | No |
| `ProxmoxComputeProvider::toGib()`/`toMib()` are `is_numeric($x) ? intdiv(...) : 0`, so a present-but-unparseable figure becomes **0** while `cpus` and `name` become null. The handler's contract states explicitly that "a null is the absence of an observation, not an observation of absence"; the adapter violates it asymmetrically — over-strict for disk and memory, under-strict for vCPU. | `ProxmoxComputeProvider::toGib()`, `toMib()` vs the handler docblock | F-15 (dispatched) | No |
| The operator surface does not show what the runbooks tell operators to read. `AdminProvisioningPage.tsx` renders neither `reserved_provider_id` nor `reserved_provider_nodes` and `adminQueries.ts` does not declare them; the needs-review list truncates `last_error` to 120 characters while the identity-taken message is 226, so the discriminating detail is cut off; and there is **no Adopt control in the UI at all**, only Retry, although adopt is the runbook's primary remedy. | `AdminProvisioningPage.tsx:116`; `adminQueries.ts` | F-15 (dispatched), F-14 family (the operator surface sits outside every portal quality gate) | No |
| **F-14's repair created a new way to take a working node out of service, and it is unstated.** Nodes licensed before it and refused after: `expires=never`, `unlimited`, `perpetual`, `none`, `-` — a perpetual licence written as a word — and `expires=31/12/2099`, because PHP reads `d/m/Y` as `m/d/Y` (the accepted set is arbitrary and US-centric: `12/31/2099`, `31.12.2099` and `31 Dec 2099` all pass). Pre-existing but in the same family, because `is_numeric` makes every numeric string a unix timestamp: `expires=20991231` → 1970, `expires=2099` → 1970, and **`expires=0`, a very common "no expiry" marker** → 1970 → refused. The fail-open side: `expiry=tomorrow` and `expiry=+1 year` are accepted as future expiries. The asymmetry is the point — for *states* the repair wrote a whitelist and owned it honestly as this repository's vocabulary; for *expiries* there is no equivalent, and the docblock asserts a panel behaviour nobody has evidence for. | verifier's boundary probe against `parseTimestamp()` | F-14 (dispatched) | No — wrong answer in the safe direction, but a new outage path |
| `CpanelHostingProvider::licenceStatus()` never received the `provider_spoke` repair: it still matches `'licen'` against `provider_message` on any exception, which is the exact defect the DirectAdmin docblock describes at length. Less exposed — its command is `loadavg`, so Guzzle's appended URI does not contain "licen" — and it fails closed either way, but the *reason* is misattributed, sending an operator to the vendor about an outage. | `CpanelHostingProvider.php:320-322` | F-14 family; not in F-14's own text | No |
| **`DecommissionDedicatedServer:110` writes the literal string `'unknown'` into `retention_releases_at`** — `$releasesAt?->toIso8601String() ?? 'unknown'`. That is the identical defect class F-18 spent two rounds fixing for shared hosting: a field the published schema and the account resource carry as a **Timestamp**, carrying English prose on one row shape. Found while F-18 was checking whether its siblings agreed on how to handle a missing anchor — and they do not: VPS drifts a real date, Dedicated writes a sentence. | `DecommissionDedicatedServer.php:108-113` vs the OpenAPI `Timestamp` ref | F-18 family; no numbered finding owns it | No — but it is a typed field carrying prose, in a module F-18 did not touch |
| **`tests/Architecture/EveryMessageCodeIsTranslatedTest` does not gate `lang/`.** It scans `web/src/i18n/locales/*.json`, so deleting an Arabic sentence from `lang/ar/errors.php` leaves it 3/3 green. The real gate is `tests/Feature/Api/CustomerErrorCatalogueTest`, confirmed by mutation. Nothing is unguarded — both catalogues *are* checked — but the architecture test's **name** invites exactly the wrong assumption about where that gate lives, and two agents in this programme reached for it before measuring. | verified by deleting the key and running both | F-25 / F-42 (a guard whose name overstates it) | No |
| `CreateHostingAccountHandler:268` writes `status => Active` without clearing `suspended_at`, which together with `ReserveHostingNodeCapacity:219` re-arming the row to `Pending` is what manufactures the serving-account-with-a-stale-date shape. Clearing it at source would remove the shape — and F-18 deliberately did not, because its whole guard is built on the premise that the date may be stale and the *status* decides, so removing the shape would also remove what every new row in its matrix tests. Recorded as evidence rather than acted on. | `CreateHostingAccountHandler:268`; `ReserveHostingNodeCapacity:219` | F-18 (considered and declined, with an argument) | No |
| **An unlanded DNS publish is now reported nowhere.** F-11's corrected `settle()` rightly declines to mark such a row `deleted`, but the `MissingAtProvider` drift branch fires only for `state === Active`, so the row stays `indeterminate` and **no drift record is written**. `needsAttention()` surfaces it to the customer and to ops; the operator drift queue never sees it. Closing it means deciding what kind of drift it is, which is adjacent to the product decision deliberately left untaken. | `ReconcileZones::compare()`, `if ($row->state === DnsState::Active && $found === null)` | F-11 (created by its own repair), F-22 (the queue that would show it) | No |
| **Zone-import preview refusals are English-only and carry no error code.** `$this->refusal()` returns the exception's English message directly, never a translation — so an Arabic customer previewing an import reads English reasons, including the two F-11 just rewrote to be truthful. In direct tension with the rule that every customer-raisable code carries both an English and an Arabic sentence; these have no codes at all, so closing it is a design change to `ZoneImportEntry` rather than an entry in `lang/ar`. | `PlanZoneImport::refusal()`; `ZoneImportEntry` | F-44 family (customer-facing correctness); no numbered finding owns it | No |
| `PlanZoneImport`'s Unchanged-vs-Update check compares only `ttl` and `priority`, and its `fingerprint()` omits `data`, so a change to `data` alone would read as unchanged and would not invalidate a plan. Masked today because the zone-file parser renders CAA's fields into `content`, so a `data` change implies a content change and the key differs first. Latent, not live. | `PlanZoneImport.php:101`; `fingerprint()` | F-11 (recorded, argued, not fixed) | No |
| **The zone-file key folds content's case, and nothing else in the module does.** Harmless for hostname-valued records, since DNS names are case-insensitive — **not** harmless for TXT: a DKIM key differing from the stored one only in case reads as already present, is planned `Unchanged`, and is never published. Blocked from independent repair because the planner's two keys must agree with each other and both are pinned to the unique index. | `PlanZoneImport::keyOf()`; `ParsedRecord::key()` | F-11 (recorded with its blocker) | No |
| **A WHM root API token is dialled at whatever `hosting_nodes.hostname` says, and that column never reaches the endpoint policy.** `RegisterHostingNode::execute()` checks `api_endpoint` only, and only when non-empty; `hostname` is validated as `required|string|max:255|unique` and nothing else, on both the create and the `HOSTING_NODE_EDITABLE` update road. `WhmConnection::forNode()` and `DirectAdminConnection::forNode()` then fall back to `https://{hostname}:{port}` and attach the root token. Measured over the real route: eight poisoned hostnames — `169.254.169.254`, `127.0.0.1`, `localhost`, `0x7f000001`, `metadata.google.internal`, `169.254.169.254.`, `169。254。169。254`, `vault.internal` — **all returned 201**, eight rows written, and `forNode()` built `https://169.254.169.254:2087`. Control in the same run: the identical string in `api_endpoint` gives 409. **This is F-17's shape exactly** — a correct rule not attached to a call path — and the dial is triggered by ordinary customer provisioning onto that node. `ComputeProviderFactory::proxmox()` is the counter-example: it throws rather than falling back. | verifier probe over `POST /api/admin/infrastructure/hosting-nodes`; `WhmConnection::forNode()`; `DirectAdminConnection::forNode()` | F-29 (dispatched), F-17 (same shape) | **Yes — F-29 is not closable while this is open** |
| **An eighth spelling family: `parse_url` rewrites control characters into `_`, and the provider road judges the rewritten host.** `parse_url("https://169.254.169.254\n/")` yields host `169.254.169.254_`, which is on no blocklist, is not an IP literal and is not a numeric form — so it falls through to resolution, resolves to nothing, **the address loop runs zero times**, and the endpoint is accepted. Measured in production mode: the newline, tab and backslash forms all return **201 with the row written verbatim**, against a 409 control. `TrimStrings` does not help — the character is interior. The machine-address road refuses the same bytes because it sees them raw; the asymmetry is the bug. Exploitability today is blocked by curl 8.5.0's strictness — which is precisely the reliance the policy's own docblock forbids (*"relying on a property of one libc"*) — and the contract claims a row is refused before a socket opens, where it is refused at neither. | verifier probe over `POST /api/admin/providers`; `assertProviderEndpoint` | F-29 (dispatched) | **Yes — same** |
| **A citation in `EndpointPolicy` says the opposite of what its sources say.** It offers CPython's `ipaddress` and Rust's `Ipv6Addr::is_global` as evidence that a hand-enumerated list of special-purpose rows leaks, claiming both enumerate the rows inside `2001::/23` by hand and both miss `2001:1::3`. Measured on the installed Python 3.11.15: `_private_networks` holds **`2001::/23` as a whole** — the covering-block approach this very commit adopts — and the hand list is the *globally reachable exceptions*, so `2001:1::3` being absent makes CPython **stricter**, returning `is_private=True`. Rust has the identical shape. The code and its conclusion are right; the evidence sentence is false, and a correct example was available from the same two sources. | Python 3.11.15 `ipaddress._IPv6Constants`; `EndpointPolicy.php:157-163` | F-29 (dispatched) | No — but it is a load-bearing docblock citing sources that contradict it |
| `SystemHostResolver` returns **A records only**, so a name with only AAAA records resolves to nothing and is accepted with no address checked at all. The policy's docblock names this honestly as a real gap — the point recorded here is that an honestly-named gap belongs somewhere a person will find it rather than only in a comment. | `SystemHostResolver`; the policy docblock | F-29 | No |
| **No test in this repository can assert anything about a `CommandStarting` listener by running a real artisan command.** Laravel's console kernel wires `rerouteSymfonyCommandEvents()` only when `! runningUnitTests()`, and `Application::runningUnitTests()` is literally `$this['env'] === 'testing'` (`Foundation/Console/Kernel.php:145-148`). So under `APP_ENV=testing` the event never fires. F-08's implementer hit this while proving its new boot guard: **its first run passed, and the guard had not executed at all.** It caught that by probing whether the event fired rather than trusting the green, then measured the end-to-end link by hand outside the test environment. The direction is the right way round — deployments are what need protecting — but anyone writing such a test gets a false pass. | `Foundation/Console/Kernel.php:145-148`; the implementer's own probe | F-25 / F-42 (a test that cannot fail), F-41 (ambient state deciding a suite's outcome) | No — but it silently voids a whole class of test |
| **Five queued classes outside `payments` carry `tries > 1` with no backoff ladder**, so each retries five or three times inside a single outage with no wait between attempts: `InstallWordPressOnceTheAccountExists` (provisioning, tries 5), `EnforceServiceStateForSubscription` (provisioning, tries 3), and `NotifyOnProvisioningOutcome`, `NotifyOnSubscriptionChange`, `NotifyOnBillingEvent` (notifications, tries 3). The provisioning pair is the sharper half: those supervisors are `tries => 1` at supervisor level, so a listener-level `tries` 5 with no ladder is five attempts inside one outage **on the queue that builds customers' machines**. Named by F-08's implementer in its new architecture test's docblock rather than hidden behind a narrower predicate; widening the rule reaches five modules this round has no finding about. | the new `EveryRetriedPaymentsListenerWaitsBetweenAttempts` docblock; the five classes | F-08 (scoped out deliberately); no numbered finding owns the rest | No |
| `RecordInvoiceRefund::execute($invoice, $amount)` with no refund row still books on every call, by design — three callers and an operator reconciliation path depend on being counted every time, exactly as the dunning counter treats an unkeyed caller. F-08 closed the *queued* path that reached it with a missing row, and documented the remaining trap in bold at the parameter. Closing the rest needs an idempotency key from the caller, not a change here. | `RecordInvoiceRefund::execute()`; `RecordRefundAgainstTheInvoice` | F-08 (named, argued, bounded) | No |
| **No DKIM, DMARC or ACME DNS-01 record can exist on this platform.** `DomainName::assertLabel()` permits only letters, digits and hyphens, so `POST /records` with `sel._domainkey.example.test` returns **422** — and the same refusal kills `_dmarc` and `_acme-challenge`. Every mail-authentication and certificate-issuance convention depends on underscore labels. Found incidentally: F-11's own new documentation offered *"a DKIM key, most plausibly"* as its worked example, and the example is unreachable on the product it documents. | probe stack trace `DnsRecordRules.php:55 → DomainName.php:100` | none in F-01..F-47 covers it | **Not for any finding's closure — but a DNS product that cannot hold a DKIM key bears on `READY_TO_SELL`** |
| **A zone-import preview labels a changed TXT value "unchanged" and never publishes it.** The import planner folds content's case while the database index does not, so a customer replacing `…p=abcdefGHIJ` with `…p=ABCDEFghij` is shown their new value marked unchanged and the old one keeps answering. The deferral that left this open argues the planner's key is pinned to the index — true for priority and data, and **not true of the case-fold**, because the index is case-sensitive, so a case-differing content is a different key and the resulting ADD is accepted rather than refused. Making both sides case-sensitive leaves 334 tests green. | verifier's M22; `PlanZoneImport::keyOf()`; `ParsedRecord::key()` | F-11 (dispatched) | No |
| A customer cannot round-trip their own zone through the import planner. Two TXT records differing only in case are both accepted and both live — the index tells them apart perfectly — but re-importing that zone's own two lines refuses one of them, and a single refused line makes the whole plan inapplicable. The refusal message blames the zone (*"this zone does not tell the two apart"*) when it is the planner's key that does not. | verifier probe; `whyItCollides()`'s third branch | F-11 (dispatched) | No |
| **Five ways to record an unlicensed node as licensed, none of them previously named**, all found by inventorying the transformations a provider answer passes through before any rule sees it — rather than by inspecting the rules, which five rounds had already done. Live at `d823eba`: a **percent-encoded key alias** (`%73tatus` *is* `status`, a repeat the body's own text never spells as one); **key mangling**, where `expire.date`, `expire date` and `expire+date` all become the key the expiry rule reads; **`max_input_vars` truncation** at 1000 pairs, which is *not* a repeat and which the repeat gate alone would not have caught; **relative-time expiries**, where `expiry=tomorrow` resolves against the clock and so sold the node **on every sync, for ever**; and **`d/m/Y` read as `m/d/Y`**, where `12/01/2026` meaning 12 January is read as 1 December and **sells an already-expired licence**. | F-14's transformation inventory, each reproduced end to end through `SyncHostingNodeHealth` | F-14 (closed there, in verification) | No — but they are the argument for inventorying transformations rather than auditing rules |
| **Three transformations that discard data and are still unrefused**, named by F-14's implementer rather than left to be found: **key case is not normalised**, so `STATUS=expired&status=active` never reads the panel's word and the node sells — the inherent limit of a key whitelist, not a `parse_str` artefact; a **repeat on `text`/`details`/`result`** is destructive, which changes only the reason on the row (both outcomes refuse, so it misattributes rather than sells); and a **512-character truncation** applied before the `'licen'` test, so a panel whose error body puts enough preamble before "license expired" is recorded as an outage instead of a lapsed licence. | F-14's inventory, T10/T19/T20, each measured | key-vocabulary and reason-attribution findings; none numbered | No |
| `CpanelHostingProvider::licenceStatus()` still matches `'licen'` against any exception's message, the defect repaired in its DirectAdmin sibling — and the repair is **not** a one-line port, because that file has no `provider_spoke` at all, so the flag must first be threaded through its own parse and call paths. Declined inside a licence round on the argument that doing it there is how scope creep produces an unverified regression in a sibling adapter's untested bands. Both branches return `valid: false`, so it misattributes a reason rather than selling a node. | `CpanelHostingProvider.php:320-322`; `grep provider_spoke` returns nothing in that file | F-14 family; unnumbered | No |
| `DirectAdminHostingProvider::nodeHealth()` keeps a first-match read the licence path gave up, and `load_average` is a **hard exclusion threshold** in the scheduler — so a panel answering `loadavg1=0.05&load1=9.9` reports an idle node that is melting, and a paid order is placed on it. Argued as a different rule-shape from the licence keys (alternative spellings of one reading, rather than distinct claims that can contradict), so first-match is defensible there in a way it was not there. Proposed remedy if taken up: take the **highest** value across every spelling present, the mirror of "the earliest expiry decides". | `nodeHealth()`'s `firstFloat()`; `HostingNodeScheduler:234` | a capacity/scheduler finding; unnumbered | No — a capacity defect, not F-14's outcome |
| **There is no operator route for a single IP address or a single virtual machine.** Both follow-ups the VPS adoption remedy requires — inserting the machine row so the platform can power, console or destroy it, and marking the quarantined address unavailable so its timer cannot expire under a live machine — are **DBA work today**. F-15's runbook now says so in those words rather than implying a portal path, after its first draft wrote *"record the machine in inventory by hand"* as though a route existed and the implementer grepped and found none. The runbook's closing instruction is the honest one: *"If you cannot get both done in the same shift, do not adopt. A job in review is one customer waiting; an expired quarantine on a live machine is two customers on one address, and the second one is not waiting for anybody."* | `grep` for a per-address or per-VM admin route returns nothing | F-12 family (the address half), F-02 family (the operator surface) | No — but it makes a documented remedy unperformable from the product |
| **Adoption cannot complete a VPS, and adoption is the only exit.** `AdoptOrphanResource` is generic and shared with shared hosting; it creates no machine row, and — the point that decides it — **the address it would need to commit has already been released into quarantine, so there is no live reservation left to commit.** Closing it needs a per-kind completion step *and* an IPAM capability to re-reserve a *specific quarantined* address. Deferred by F-15 with that argument and pinned as a characterization test, so the runbook's warning is a measurement and closing either gap breaks the test and points at the paragraph. Load-bearing because `retry` is permanently refused once a provider reference exists, so "destroy the stray and rebuild" is not available. | `CASE E` probe; `adopting_a_vps_leaves_it_unmanaged_and_its_address_in_quarantine` | **F-12 family** | No — but it is the only door out of a state a real operator reaches |
| **A closed cancelled retention window behind a `pending` or `failed` hosting account is an unrepaired nightly sweep failure.** Measured `failed=1` on each run — it costs the sweep a failure exactly as the drift F-18 repaired does, but nobody came back and the repair F-18 prescribes is the wrong one for it. It needs a decision about what such a row means rather than a repair. Documented in the docblock by F-18's implementer, which flagged that nothing owns it. | F-18's twelve-shape fixture set, shapes `fxg`/`fxh`/`fxl` | F-22 (the sweep failure nobody sees); no numbered finding owns the row shape | No |
| **A second retirement of one dedicated machine writes a second audit entry.** `AbstractStateMachine::canTransition()` treats `from === to` as legal by deliberate design — *"A no-op transition is not an error… Callers use this to make retries safe"* — so a redelivered retire returns 200 and the audit recorder writes another `dedicated_server.retired` entry under a second operator's name: two records of one machine leaving the fleet once. Not a leak. F-12's implementer declined to fix it by refusing the second call, since that would contradict the retry-safety the no-op exists for; the fix, if wanted, is idempotence in the audit recorder rather than a guard at the action. | `AbstractStateMachine.php:18-22`; probe returned 200 where 409 was expected | F-12 (named, argued, declined) | No |
| `AuditAction::isAnAssertionAboutTheWorld()` has **no production consumer** — `grep` over `src/` and `app/` returns only its definition. It is the mechanism by which a reviewer separates an operator's assertions from the platform's observations after an incident, and it is read by tests alone. Whether it should have a production reader, such as an audit filter, is a product question nobody has asked. | `grep -rn isAnAssertionAboutTheWorld src/ app/` | F-23 family (declarations with no reader) | No |
| The assertion *tally* for a combined `Orders + Security + Architecture` run is not a stable measurement: two architecture tests enumerate `get_declared_classes()`, so the count depends on autoload order. Test counts are stable; assertion counts jitter. | `tests/Architecture/EveryPreparedCategoryHasAContractTest.php`, `EveryCompleteProductHasARealAdapterTest.php` | none | No |
| `SecretRedactor::isSecretKey()` matches by substring and `security.redacted_keys` contains `pan` (card PAN), so `metadata.panel` is stored as `[redacted]` and an operator cannot read which control panel an account was built on. Any future key containing `pan`, `auth`, `card` or `token` as a substring is eaten the same way. | `src/Modules/Shared/Infrastructure/Logging/SecretRedactor.php:40,177-191` | F-45 family | No |
| Shared-hosting checkout never asks for a domain: `PlaceOrderRequest` has no domain field and `CheckoutLine` carries only `planId`/`quantity`. | `src/Modules/Orders/Http/Requests/PlaceOrderRequest.php:46-71`, `src/Modules/Orders/Application/DTOs/CheckoutLine.php:16-22` | F-04 (its own domain limb) | Adjudication pending — decides CLOSED vs PARTIAL |
| `ProviderCatalogue` declared Proxmox capable of `reinstall` and `templates` while the tester could never report either, and no gate checks that a driver with a tester can in principle be *assessed* on the capabilities its catalogue claims. | `src/Modules/Providers/.../ProviderCatalogue.php:65` vs `ProxmoxConnectionTester.php:73-83` | F-23 / F-24 (mechanism) | No |
| `ManagedServer` and `DedicatedServer` are two unrelated machine inventories, both presented as "servers" on the operator surface. | `src/Modules/Infrastructure/Infrastructure/Models/ManagedServer.php`, `src/Modules/Dedicated/Infrastructure/Models/DedicatedServer.php`, `routes/api_admin.php` | none identified | No |
| `DELETE /api/admin/infrastructure/templates/{template}` is the only hard delete on the inventory surface; a paid service carries its template's id in `resources.template_id`. | `src/Modules/Compute/Http/Controllers/VmTemplateController.php` | F-02 (adjacent; pre-existing) | No |
| The browser suite has no guard against a spec leaving the shared seeded estate ambiguous for the specs after it. One spec did, and it surfaced as four unrelated money journeys failing. | `apps/web/playwright.config.ts` (`workers: 1`), `apps/web/e2e/support/helpers.ts` | F-42 (test architecture) | No |
| Two-factor authentication is opt-in per user with no policy that can require it of staff; an operator holding `role.manage` may have no second factor. | `src/Modules/Identity`, operator list surface | F-46 (account-security notifications) | No |
| **A green test says provisioning is never retried by the queue, and it is — 5 and 3 times.** `config/horizon.php` writes the policy plainly: *"One attempt. Retrying is the provisioning engine's decision, not the queue's: a job whose provider call may have created a machine must never be re-executed by a worker that cannot know whether it did."* The supervisor default **loses**: `Worker::markJobAsFailedIfWillExceedMaxAttempts` takes `$job->maxTries()` over the worker option, and `Events\Dispatcher::propagateListenerOptions` falls back to the listener's own `$tries`. Measured against the real classes: `InstallWordPressOnceTheAccountExists` → 5, `EnforceServiceStateForSubscription` → 3. Both converge in practice (`lockForUpdate` + `permitsInstallation()` + a unique idempotency key; `$alreadyStopped` / null-`retention_ends_at` guards), so this is **false assurance rather than a live double-build** — which is why it is recorded here and handed to F-08's rework as a claim to correct rather than as a widening of the ladder rule. | F-08 verifier ran the framework's own path against the real listeners | F-08 (its own branch's test) | No — but a test whose name asserts the opposite of the behaviour is the exact class this programme exists to find |
| `WorkerHarness` turns a legitimate skip into an error: with `CI` unset and Redis unreachable, `setUp()` calls `markTestSkipped()` but `tearDown()` still runs `emptyTheCommittedDatabase()` before the `queue_test` connection is configured. Measured `skipped=2, errors=2 ("Database connection [queue_test] not configured.")`. Pre-existing, harmless under `CI=1` (which fails loudly instead), noisy on a developer machine — and it is part of why a dead Redis never read as a clean green. | F-08 verifier's skip-guard probe at `REDIS_PORT=6399` | F-41 / F-42 (test mechanism) | No |
| The two new queue connections `redis-provisioning` and `redis-infrastructure` are **never exercised by a worker in any test** — every real-worker test runs `queue:work redis`, and the two appear in tests only as `config()->set` values. The load-bearing claim that the three connections share Redis keys was true and untested; the verifier closed the gap by hand (a message pushed on `redis` onto `provisioning` reports `size()==1` on both connections, one key) but did not add a test. | F-08 verifier's manual check | F-08 (adjacent) | No |
| `scripts/serve.sh` names two queues nothing produces to — `critical` and `monitoring`. The architecture test that maps queues to supervisors passes, so they are simply stale. | `grep` over `src/` and `app/` finds no producer | none identified | No |
| **An operator remedy the code advertises is a no-op.** `CreateHostingAccountHandler`'s docblock tells an operator they can resolve a domain conflict *"by correcting the name on this job"*. The re-arm writes `forceFill(['status' => Pending])->save()` — status only. On retry the row is re-armed carrying its **old** domain and goes Active serving the name the operator just corrected away from, and `HostingDomainConflictException::forDomain($primaryDomain)` would name the requested domain rather than the colliding one. The implementer left it alone deliberately: I had asked for the minimum and specified moving one `save()`, and widening scope unprompted is what produced R3 in the first place. It is also why R6a's mutation is only incidentally caught — with the in-transaction check deleted, a retry corrected to a taken name would pass and build under the stale one. | re-arm SQL captured in F-04's R4 reproduction: `set "status" = pending, "updated_at" = …` and nothing else | F-04 (adjacent) | Adjudication pending — the F-04 verifier decides whether the advertised remedy must work before F-04 closes |
| **A comment promises an operator a diagnosis the code cannot give.** `ProviderConsoleUpstreamResolver.php:47-56` says the provider's message *"is kept here so an operator reading the gateway's log knows whether the cluster refused, was unreachable, or has no such machine"*. It passes `$e->errorCode()`, and `ComputeProviderException::errorCode()` returns the constant `'compute.provider_request_failed'` for **every** failure mode, so the operator can distinguish none of the three. | F-28 implementer; `ComputeProviderException::errorCode():132-135` | F-27 family (customer/operator message truth) | No |
| Neither of `GatewayServer`'s two log sites passes through `SecretRedactor`, against `CLAUDE.md`'s rule that provider messages are redacted before being stored or logged: `:279-282` logs an arbitrary `Throwable::getMessage()` from inside the read loop and `:505-509` logs `stream_socket_client`'s error string. The implementer traced both and found **no path that puts a credential in either string today** — the token is not sent until the socket is up, and the vncticket lives in `ConsoleUpstream` rather than in the exceptions these catch. Unredacted by construction, not a live leak. | F-28 implementer's trace | F-28 (adjacent) | No |
| `FakeComputeProvider::consoleEndpoint()` returns `verifyTls: false` unconditionally — harmless, since the fake is refused in production and its host is fictional, but it is the last hard-coded `false` on this path and reads as a precedent to the next person here. | `FakeComputeProvider:508` | F-24 | No |
| **Every real VPS may ship with a 2–4 GiB disk instead of the plan's 20/40/80/160, and nothing in-tree can see it.** `ProxmoxComputeProvider::createParameters()` sends `scsi0 = <storage>:<diskGib>,import-from=<template>` and **nothing in the create path ever resizes** — `resizeVm` has exactly one caller, `ResizeVpsHandler`, reachable only from `ApplyPlanChange`. If Proxmox takes the imported image's size, which is F-15's own written premise, the customer receives the image's disk rather than the one they bought. It is invisible in-tree because `FakeComputeProvider` records the requested `diskGib` verbatim — the F-24 pattern, on a customer-visible field that money was taken for. | F-15 verifier: `grep -rn "resizeVm" src/` → one caller; `createParameters()` | F-24 (simulator models the convenient case) | **Yes, for the final adjudication** — this is a billing-correctness question, not a test gap |
| **A `ProvisioningTimedOut` quarantine is permanent, so every adopted VPS strands one public IPv4 for ever.** `requiresOperatorClearance()` is true, `ReleaseQuarantinedAddresses` excludes exactly those reasons, and `IpAllocator` only selects `status = available`. Measured with the clock advanced five years: `sweepReleased=0 statusAfter5y=quarantined`. This is F-34 (`ReleaseReason::OperatorAction` has no callers) restated with evidence, and it matters here because F-15's runbook tells an operator the opposite — that the quarantine expires and the address is handed to the next customer — and prescribes a DBA edit to guard against that non-event, while the real cost (stranded for ever, the adopted machine holding no assignment) goes unstated. | F-15 verifier's PROBE4 | F-34 (its own subject) | No — but F-34 is larger than its one-line statement |
| **The same unreadable-body defect exists on the surface an operator uses to decide whether a node may join the fleet at all.** `DirectAdminConnectionTester` parses DirectAdmin bodies with its own `urlEncoded()` and has **none** of F-14's gates — bare `parse_str`, no repeat check, no pair count, no ambiguity rule. Measured: `%00error=1&text=License+expired&error=0` at `/CMD_API_LICENSE` hides a licence refusal from the credential centre, which then reports the node fine. F-14 names the SharedHosting adapter and this is the Providers module's own copy of the same reading problem; the duplication is deliberate and documented in `READ_FIELDS`, so the implementer did not touch it. **It wants its own finding and the namespace has none to give.** | F-14 implementer's probe; `DirectAdminConnectionTester.php:267` | F-14 (same mechanism, different module) | **Yes, for the final adjudication** — the F-14 verifier is asked whether F-14 closes with it open |
| The leading-NUL class was **wider than the licence answer**, and neither the audit nor I had named it. Measured on the pre-fix tree: `%00list[]=alice&list[]=bob` on `CMD_API_SHOW_USERS` returned **one** account silently, which `ReconcileHostingNodes` would record as drift — an account missing at the panel on a node where it is present; and `%00loadavg1=9.9&loadavg1=0.1&version=1.665` on `CMD_API_SYSTEM_INFO` yielded `loadOne = 0.1`, the platform reading the idle figure off a busy node and treating it as the best scheduling candidate in the fleet. Both are closed by F-14's round-seven gate and both are now asserted. | F-14 implementer's probes at `3b29308` | F-14 (closed by it) | No |
| `CMD_API_SHOW_USER_USAGE` has no entry in `GENERIC_READ_FIELDS`, so a single field settles it — `quota=100` alone is accepted as a usage answer. It is the one command whose nominal check has no generic list at all, and that asymmetry is undeclared. Judged below the bar for round seven and recorded instead. | F-14 implementer | F-14 (adjacent) | No |
| `date_parse('2099.01.01')` returns `error_count = 0` with `month` and `day` both `false`. The value is refused, but by the `! is_int($parts['month'])` guard rather than by the error count — so **the error count alone is not a sufficient readability test**, and a future simplification that trusted it would open a door. | F-14 implementer's measurement on the installed PHP | F-14 (mechanism) | No |
| **The whole mapping band is invisible on the command an operator types first.** `InfrastructurePreflightService::estate()` runs providers, dependencies, naming and per-family product findings, and **never calls `MappingChain::inspect()`**. Measured: `infra:preflight --mode=simulation` with no option emits **zero `mapping.*` findings of any kind** — no cluster, nodes, storage, capacity, template, addresses, hosting nodes, packages or TLDs. The readiness engine does not cover the gap: on the same fixture the vps verdict was *"not_ready: compute: No compute provider is registered"*, driven by provider rows alone. So an estate with every provider green and no IP pool reports a clean estate-scope run. F-35's implementer left it deliberately — making the estate scope run mappings adds findings to every estate report and can flip its exit code, which is well past minimal for a one-sentence finding — and calls it *"the largest thing I found"*. **This is strictly larger than F-35 itself and no finding owns it.** | F-35 implementer's measured estate-scope output | F-35 (its own closure is arguable) | **Yes, for the final adjudication** — and the F-35 verifier is asked whether F-35 can close while it stands |
| **The test that documents F-35's residual gap is itself an instance of it.** Every green `mapping.network` fixture in `APreflightNeverDropsACheckSilentlyTest` uses `IpPool::factory()->create()`, whose default is public scope, active, and **zero subnets** — so every estate that file calls green is one `IpAllocator::subnetIdsFor()` would return `[]` for. The test is correct about what it asserts (the check does pass, and must), but the fixture is the third of the three shapes the same file documents as still passing wrongly. Disclosed by the implementer unprompted. **It matters for whoever narrows `addressFinding()`**: doing so will turn four assertions in that file red, and that is the right outcome rather than a regression. | F-35 implementer's own disclosure | F-35's R1 follow-up | No — but it scopes that follow-up |
| `refreshDatabaseForShape()` does not clear `Subnet`, `ProviderInstance` or `DedicatedServer` — harmless today because no shape creates any, but it is a helper named for a guarantee it only partly provides, and the first shape that seeds a subnet (which the R1 repair will want) inherits them across loop iterations. | F-35 implementer | F-42 (test mechanism) | No |
| `a_run_in_which_nothing_was_established_is_not_a_pass` is **the only test in its file that builds a `PreflightReport` by hand** rather than running the service — which is why its name could drift from its assertion with nothing noticing: there is no command output behind it to contradict it. | F-35 implementer | F-23 (mechanism) | No |
| **A node, provider or BMC can no longer be registered before its DNS exists.** F-29's empty-resolution refusal is the half of the AAAA fix that carries the weight — a name the resolver cannot see an address for is refused rather than accepted — and its cost is that registration now requires the name to resolve. The implementer calls this the intended reading (*"the row is the thing that gets dialled"*) and records the escape (register the address instead) in the policy, the resolver and `docs/security.md`. **It is a real change to what an operator can do on day one** and belongs in front of whoever writes the runbook. | F-29 round four, recorded in three places | F-29 (its own cost) | No — but it is an operational consequence, not only a code change |
| **A residual the resolver cannot close without an undeclared extension.** An AAAA record that exists *only* in `/etc/hosts` or a non-DNS `nsswitch` source is invisible to the new union: `gethostbynamel` can see it and cannot report it; `dns_get_record` can report it and does not look there. Closing it properly needs `getaddrinfo`, which PHP exposes only through **ext-sockets**, absent from `composer.json` — and this programme forbids adding a dependency as a side effect. The empty-answer refusal is what makes the residual safe rather than open. | F-29 round four's own disclosure | F-29 (named bound) | No |
| **Three of seven `\z` anchors in `EndpointPolicy` cannot be pinned, and the disclosure covering one read as a guarantee about the other two.** Turning each anchor back into `$` in turn: four fail one named line each; `HOST_CHARACTERS`, `NUMERIC_LABEL` and `HOST_LABEL` fail nothing, because all three sit behind `canonicalHost`'s control-character refusal which has already rejected every host containing a newline. The commit had disclosed one and not the other two. Both the test's sentence and the policy's now say four, name the three, and say what holds them up instead. | F-29 round four's own measurement of all seven | F-29 (mechanism) | No |
| **The gate and the reader are different parsers, and that is the shape behind two of F-14's rejections.** `isAmbiguousNumericDate()` and the prefix rule reason about `date_parse()`, which returns components and *reports* a relative part without applying it. `parseTimestamp()` returns `CarbonImmutable::parse()`, which **applies** it. So a value can pass every gate on what `date_parse` says it contains and land on the row as a different day. **I nearly recorded the opposite**: my first verification used `date_parse` alone, saw no movement, and would have contradicted a correct report — corrected by re-running through the constructor the adapter actually uses. Any future rule on this surface must be stated over the parser that produces the stored value. | measured: `date_parse('Fri, 31 Dec 2099')` reports 2099-12-31 while `CarbonImmutable::parse` of the same string yields 2100-01-01 | F-14 (mechanism) | No — but it is the reason two rounds were rejected |
| **Cross-agent interference: one verifier ran `pkill -f "artisan test"` during cleanup** and disclosed that it may have killed test runs belonging to other worktrees. Three agents were running at the time and all three have been told, with the specific risk named — a killed run can leave rows behind that cascade into unrelated failures, and for one of them a killed run is indistinguishable from the injected-throw result its brief asks it to produce. The isolation scheme gives each agent its own database, Redis index and worktree; **it does not give them their own process table**, and no brief had said so. | the verifier's own disclosure, section 5 | F-41 family | No — but it is a seventh isolation gap and briefs now warn about it |
| `parseTimestamp()` checks `error_count > 0` and **not** `warning_count`, so an invalid calendar date is silently rolled **forward**: `2026-09-31` → 2026-10-01, `2026-02-31` → 2026-03-03, `31-09-2026` → 2026-10-01. Bounded at ≤ 3 days and **pre-existing** — untouched across the whole round-eight range — so it is not a defect in a rule that commit wrote, but it is the same harm class and it sits two lines from the guards that were added. | F-14 round nine, measured | F-14 (adjacent) | No |
| **The crash salvage would have shipped a defect, which settles that decision.** When the container restart destroyed seven reworks, I saved every uncommitted diff and then **discarded the trees**, offering the patches to the re-dispatched agents as untrusted material to read and not build on. F-14's replacement re-derived everything, then read the salvaged patch afterwards and reported: it used `strlen($matches[3]) <= 1`, **which accepts a two-digit year** — so `30-01-12` falls through to the over-twelve guard, `30 > 12`, and the node still sells. Its prefix rule `^[^0-9]*` also never matches an ISO date, missing the `V2099-01-01` case entirely. The salvaged work looked like a fix and was not one. | F-14 round eight's own disclosure, after finishing | none — programme process | No |
| **Programme process, mine — a second relay error, caught the same way.** I told F-18's implementer that `HostingAccountStatus::isTerminal()` *"already carries the predicate and two other actions already use it"*, relaying a verifier's sentence as a measured fact. The implementer counted across `src/`: `isTerminal()` has **zero** callers; its siblings `existsAtPanel()` and `occupiesNodeCapacity()` have eight between them. It corrected me in my own direction — the point stands and is sharper — and wrote the accurate version into the docblock: *"an unused predicate beside a hand-written equality that needs it is the shape of a decision half taken."* Two relay errors of mine in one day, both caught by implementers who counted rather than believed. | F-18 implementer's count across `src/` | none | No |
| **Thirteen concurrent agents on one host is past the point of diminishing returns.** F-18's implementer reports load ~12 with several sibling worktrees running full suites, roughly **tripling wall-clock**, and the harness killed one of its backgrounded band runners at exit 144 mid-pass. Correctness held — every invocation was single and against its own database, and it verified the tree hash was identical across the two stretches — but the isolation scheme protects correctness, not throughput, and nothing in it bounds contention. | F-18 implementer's disclosure; load average and the exit-144 kill | F-41 family | No — but it is a reason to run fewer agents, not more |
| **Programme process, mine.** I relayed two mutation identifiers (M6, M10) from a verifier's report to an implementer **without their definitions**, having copied the ids and not the rows. The implementer said so plainly, re-derived them as the family it could infer, got the same answer for the same reason, and offered to re-run the originals if they differed. They were in the same family (a drop guarded so it fires only where another check already makes the band red), so the answer stands — but a number without its definition is not evidence, and I passed one on as if it were. | F-35 implementer's report, section 3 | none | No |
| **CORRECTED — I told four agents a false reason for a cherry-pick, and the fifth checked.** My briefs said `0a3bc09` *"is the fix that makes `WorkerHarness` fail instead of skip when Redis is unreachable and `CI` is set"*. F-29's verifier read the commit and said so; I then read it myself. `0a3bc09` adds `redisDatabase()` and replaces the hard-coded `self::REDIS_DATABASE` at three sites — it touches neither `runningInCi()` nor `markTestSkipped()`, which were already in `setUp()`. **The instruction was right and the reason was wrong**: the cherry-pick is required because without it the harness hard-codes index 15 and a checkout's `REDIS_DB` is ignored, so its workers `flushdb` another checkout's keys. No measurement is invalidated. The ledger never carried the error — only the briefs did. | `git show 0a3bc09` — one added method, three call-site replacements | F-41 (adjacent) | No |
| **Nothing in this repository catches a production class importing a test class, and the formatter creates them.** Reproduced end to end by F-26's verifier: putting `{@see \Tests\Feature\Dns\ClaimingAZoneTest::…}` into a `Dns/Domain/` class's docblock and running `vendor/bin/pint` on it makes pint report `fixed` with the `fully_qualified_strict_types` fixer and **add `use Tests\Feature\Dns\ClaimingAZoneTest;`** to the production file. It then inserted both that and a cross-module `use Lynomia\Modules\Infrastructure\…` into the same class and ran `tests/Architecture/LayeringTest.php` → **9/9 passed**, whole suite **124/124**. `LayeringTest` forbids `\Http\`, `Illuminate\Http\` and `\Infrastructure\Providers\` only, and its own comment says the stricter rule is deliberately not enforced. The workaround (writing FQNs as plain prose) is correct and relies on every future author knowing the trap. | F-26 verifier's reproduction, reverted | F-40 (cross-module imports unenforced) / F-23 | No |
| Reserved-zone changes are **never applied retroactively**: nothing re-checks existing `dns_zones` rows when `DNS_RESERVED_ZONES` or `APP_URL` changes, so an operator who sets `APP_URL` after customers have claimed names keeps the exposure silently, and `PublishZone` will publish those rows. No command, no reconcile hook, no mention in `docs/dns.md`. | F-26 verifier | F-26 (adjacent) | No |
| **A customer can claim a zone beneath another customer's zone**, and `zoneFor()` resolves the deeper name to the second customer's zone. Reproduced through the route on the post-fix tree: A claims `victim.test` (201), B claims `db.victim.test` (201). F-26's mechanism with a different pair of principals. **Adjudicated out of F-26 and into its own finding**, on grounds the verifier established rather than assumed: the platform's zone is a trust anchor every customer follows and a customer's is not; a claim confers nothing until a registrar delegates; and `PublishRecord` writes into the record's own `provider_zone_id` while `DnsRecordRules` confines records to their zone, so **there is no cross-tenant write primitive inside the platform**. The opposite reading — that the mechanism must not remain exploitable anywhere — was stated and explicitly not rested on. Legitimate sub-delegation between unrelated accounts is a real DNS pattern and refusing it is product policy this repository does not settle. | F-26 verifier's reproduction + `customer_id` assertion | none — the namespace has none to give | **Yes, for the final adjudication** |
| **CORRECTED — I recorded a contradiction that a measurement says is not there.** I wrote that `PreflightReport::passed()` and `CheckStatus::NotTested`'s docblock *"state opposite intents in so many words"*, taking F-35's implementer at its word. F-35's verifier measured it and **neither is wrong as written**: the `NotTested` docblock is about the *status* and is implemented (`established()` returns false; `overallStatus()` returns `NotTested` when nothing was established), and `passed()` is documented as a different question, with `PreflightReport`'s own docblock setting out the aggregation rule. **The defect is at the seam, and it is real**: `findings = [pass, notTested]` yields `overall_status: "pass"`, `passed: true`, exit 0; and `findings = [notTested, notApplicable]` yields one JSON document saying `overall_status: "not_tested"` **and** `passed: true`, with `InfrastructurePreflightCommand:93,98` returning SUCCESS off `passed()` alone. And the existing test named `a_run_in_which_nothing_was_established_is_not_a_pass` asserts **only** `overallStatus()` and never calls `passed()` — its name over-claims by exactly the gap. | F-35 verifier's measured JSON on both finding sets | F-35 (adjacent) / F-23 | Adjudication pending |
| **CORRECTED — the `dependency.backup_verification` drop cannot produce a passing report.** F-35's implementer named it as the nearest living relative of F-35 (*"a real false-green road"*) and I let that stand. The verifier measured it: the drop is real — `DependencyChain::backups()` returns early when `producedSeries()` is null — but the **same** null makes `monitoring()` emit `dependency.monitoring` as a **FAIL**, which blocks, and both calls happen inside one `inspect()`. So the run that drops the check cannot pass. (`MetricsRegistry::collect()` also catches per-collector throwables, making null close to unreachable in this build.) The mechanism was named right and the instance wrong. | F-35 verifier; `DependencyChain.php:220-228` | F-35 (adjacent) | No |
| **The provider chain does drop rungs, against the sentence F-35's own inventory uses to define the good idiom.** `ProviderChain.php:106` returns a bare `fail('provider.configuration')` when the catalogue has no adapter for the row's driver, dropping `provider.credential`, `.endpoint`, `.identity`, `.capabilities` and `.licence` with no `notTestedBelow()` — the one bare `return [PreflightFinding::fail(` in a file whose every other exit spreads it. The band is red so it is not a false green, but the inventory's whole value is that it is true, which is why it is blocking F-35's closure rather than sitting here alone. | F-35 verifier's grep of `ProviderChain.php` | F-35 (its own inventory) | Yes — blocking F-35 |
| `InfrastructurePreflightService::machine()` emits `machine.bmc` BLOCKED and returns when no BMC is bound, dropping the BMC's whole `provider.*` band — and the new inventory's scope table lists machine scope as emitting `machine.*` and `provider.*` unconditionally. Red band, so not a false green. | F-35 verifier | F-35 (adjacent) | No |
| **A green `mapping.machine` is not evidence that one dedicated server can be sold.** `MappingChain::dedicated()` counts `ManagedServer` (table `managed_servers`), while the order pipeline hands a customer a chassis through `ReserveDedicatedServer`, which reads `DedicatedServer` (table `dedicated_servers`) — a different table filled by a different action. Same shape as F-35 (preflight answering a question the pipeline does not ask) but a correctness defect rather than a reachability one, so the new completeness rule cannot see it. This sharpens the previously recorded "two unrelated machine inventories both presented as servers" into a measured consequence. | F-35 implementer; `MappingChain::dedicated()` vs `ReserveDedicatedServer` | none identified | Adjudication pending |
| **A documented command cannot be run.** `CLAUDE.md` tells every contributor to run `tools/phpstan/vendor/bin/phpstan analyse -c tools/phpstan/phpstan.neon`. That tree holds `larastan` and `phpstan-deprecation-rules` but **not `phpstan/phpstan` itself**, so there is no binary and no `vendor/autoload.php`, and the only repair is `composer install`, which this programme forbids. Two agents reported it honestly rather than claiming a clean run, and I verified it myself. Every brief now says not to attempt it. Whether CI installs its own copy is the question that decides whether this is a broken tools tree or a misleading instruction. | `ls apps/control-plane/tools/phpstan/vendor/phpstan/` → `phpstan-deprecation-rules` only; no `vendor/bin/phpstan` anywhere | F-38 (CI gates that do not test what their names claim) | No — but no agent's report in this programme carries a PHPStan result |
| **Two identical mail exchangers at one name, which F-11's own new method says are one record.** `mx 'Mail.Example.test' pri 10` and `mx 'mail.example.test' pri 10` are both accepted: the `md5(content)` index differs, `assertNotADuplicate()` compares content byte-exactly, and `saysTheSameAs()` calls them different records — while `DnsRecordType::contentIsCaseInsensitive(MX)`, the statement F-11's rework added, says they are the same. A later replace-import of the customer's own zone folds them to one key and silently plans a REMOVE for one. The verifier's conclusion, which I accept: the fold was a planner-local convenience before that commit and is now a statement about record identity, honoured at one of the six sites that decide sameness. | F-11 verifier's API calls | F-11 (its own closure) | Yes — the verifier holds F-11 cannot close while it stands |
| **The platform can hold no `_`-prefixed name at all**, so a customer cannot publish a DMARC policy and cannot complete an ACME **DNS-01** challenge — in a module that ships CAA records specifically to govern certificate issuance. `DomainName::assertLabel()` enforces `^[a-z0-9]([a-z0-9-]*[a-z0-9])?$`. Previously recorded as a DKIM example being unreachable; the product consequence is much larger than DKIM and was recorded nowhere. | `DomainName.php:99`; `sel._domainkey.example.test` → 422 | none identified | Yes, for the final adjudication |
| Zone-import refusal reasons are hard-coded English literals with no message code and no `lang/` entry, so an Arabic-locale customer is shown English. Adjacent to the false message F-11's rework deleted, and invisible to `CustomerErrorCatalogueTest`, which only sees catalogued codes. | `PlanZoneImport`'s `ZoneImportEntry` reasons | F-46 / F-27 family | No |
| `CloudflareDnsProvider::findZone()` takes `array_values($body['result'])[0]` after a `name=` query — a first-match collapse at **zone** level, the same shape F-11 was raised about at record level. Almost certainly safe (Cloudflare returns at most one zone per name per account) but unasserted and absent from the 24-row inventory. | `CloudflareDnsProvider::findZone()` | F-11 (adjacent) / F-24 | No |
| **`DnsRecord::of()` normalises a record's name and not its content.** A CNAME or MX target typed `Target.Example.test` is stored verbatim, while `ZoneFileParser` lowercases every host name it reads out of a file. The same record therefore has two spellings depending on which door it came through, and that asymmetry is the whole reason F-11's case narrowing has to be type-aware rather than blanket. Normalising on write is the cleaner fix and changes stored data and what customers see, so it is not F-11's. | F-11 implementer's probe: `create CNAME alias.example.test -> "Target.Example.test"` returns 201 and stores it verbatim | F-11 (adjacent) | Adjudication pending — the F-11 verifier decides whether F-11 can close while it stands |
| A CAA duplicate's refusal code is pinned by nothing: changing it from `dns.record.duplicate` to `dns.record.one_value_per_name` is caught by no test. Both are refusals, so the accept/refuse column is unaffected — but it is the same shape as the two false customer-facing messages F-11 removed, a customer-facing sentence with no test behind it. | F-11 implementer's mutation M13 | F-23 (mechanism: the architecture suite asserts reachability for translations but not for which message a refusal carries) | No |
| **The platform dials seven addresses it never re-checks.** `EndpointPolicy`'s docblock claimed *"checked at registration and again at use, so a row that arrived by a road this did not guard is still refused before a socket opens."* That was false for hosting nodes and compute clusters: `WhmConnection::forNode`, `DirectAdminConnection::forNode`, `ComputeProviderFactory::proxmox`, `BackupProviderFactory`, `DedicatedProviderFactory::connectionFor`, `BmcEndpoint::url` and the console gateway's `ConsoleUpstream::socketAddress` all open a credentialed connection without re-asking the policy. A row placed by a seeder, an import or direct SQL is dialled. F-29's rework corrected the docblock and `docs/security.md` rather than closing the gap, arguing a second check puts a DNS round trip inside provider construction. **The gap itself is open.** | F-29 implementer's 24-row call-path inventory, rows 9, 10, 13, 14, 15, 18, 19 | F-29 (its own closure) | Adjudication pending — the F-29 verifier decides whether this is honest scoping or an open limb |
| **A name with only an AAAA record is never checked.** `SystemHostResolver` uses `gethostbynamel`, which returns A records only. A name that resolves to no A record resolves to nothing, the address loop runs zero times, and the name is accepted — including a name pointed at an IPv6 metadata address. Recorded in `docs/security.md` under "Where the platform may open a connection" rather than fixed, because closing it changes what every fixture name resolves to. | `SystemHostResolver`; F-29 implementer's measurement | F-29 (adjacent) | Adjudication pending |
| **NAT64 is an unanswered topology question, not a footnote.** An IPv6-only management network reaching IPv4-only BMCs through NAT64 cannot register those BMCs by their translated addresses, because `::/8` refuses them. The dotted-tail spelling was already refused and `64:ff9b::a9fe:a9fe` is the attack the refusal exists for, so widening `::/8` is the wrong answer; the right one is a configured NAT64 prefix whose embedded IPv4 address is judged by these same rules — a feature, not a repair. | `EndpointPolicy`'s `::/8` rationale; `docs/security.md` | F-29 (adjacent) | No — but it bounds which estates can be onboarded |
| `parse_url`'s rewrite set on PHP 8.4.19 is exactly `\x00–\x1F` ∪ `\x7F`, measured across all 256 byte values rather than assumed. Space and backslash are carried into the host verbatim, which is why a "host is a substring of the original" test is not sufficient and why F-29's fix needed two independent rules. Worth keeping because two rounds reasoned about this set from memory and both were wrong. | F-29 implementer's 256-byte fuzz | F-29 (mechanism) | No |

## The container restart, and exactly what it cost

The session's container was restarted with thirteen agents running. All thirteen
died. This section records what survived, what did not, and what I did about it,
because a programme whose ledger is silent about its own outage is not a record.

**Structurally, everything survived.** The repository, all thirteen worktrees,
every `lynomia_test_*` database and the provisioning scripts were intact.
PostgreSQL and Redis were down and I restarted both.

**Two reworks had committed and are safe:**

| Branch | Head | What landed |
|---|---|---|
| `remediation/f08` | `c5c98e0` | *"check the plan Horizon runs, and the worker an operator types"* — both blockers addressed |
| `remediation/f19` | `0c648ed` | *"A purchase is what was bought, and it ends where an operator can reach it"* |

**Seven reworks and four verifications were lost.** F-04, F-11, F-14, F-15,
F-26, F-29 and F-31 had uncommitted working trees; F-12, F-13, F-18 and F-35 had
verifications in flight, and a verification produces no commit at all, so those
are lost entire.

**The uncommitted trees were discarded rather than salvaged, and that is the
important decision here.** Every one of those agents was running an enumerated
mutation sweep. An agent killed mid-sweep leaves a tree that holds *either* a fix
*or* an applied mutation, with nothing to say which — and this programme has
already been burned four separate times by exactly that ambiguity, under the
heading of `git checkout` not restoring untracked files. A tree that might carry
a mutation and might carry a repair, indistinguishably, is worth less than no
tree at all.

So every diff was first saved to `scratchpad/crash-salvage/` — 495KB across
eleven branches, tracked patches and untracked files both — and then every
worktree was reset to its branch head and cleaned, with `.env` and `.env.testing`
preserved because they are untracked and `git clean` would have taken them. The
salvage is offered to the re-dispatched agents as **untrusted** material they may
read for ideas and must not build on.

**What this says about the programme's design.** The isolation scheme was built
to stop agents corrupting each other, and it did its job — nothing cross-
contaminated. What it does not do is bound the loss when the host goes away, and
the shape of that loss is lopsided: an implementer's work is durable the moment
it commits, and a verifier's work is durable only when it is reported. Four
verifications died with nothing to show, having done the full measurement.
Verifiers are now asked to report interim findings rather than hold everything
for a final hand-back.

## The weekly limit, and what a second stoppage taught that the first did not

On 24 September the programme stopped again, and this time not because the host
went away. All twelve running agents were terminated inside about ninety seconds
of each other by an **account-wide weekly rate limit** (HTTP 429), mid-turn,
with no warning and no opportunity to flush. It is worth recording separately
from the container restart because the failure is a different shape and it
falsifies part of what I concluded from the first one.

What it cost, measured rather than estimated:

| Agent | State when killed | Survived as | Lost |
|---|---|---|---|
| F-08 rework | *"Waiting for the final suite."* | 6 commits, clean tree | the final suite figure only |
| F-19 rework | *"Now the deliberate breakage sweep."* | 4 commits, 2 uncommitted files | the sweep, not yet begun |
| F-14 round ten | *"Now I'll write the fix."* | 6 commits, 1 uncommitted file | a partially written fix |
| F-29 verification | *"Full suite baseline: 4029/4029, 144517 assertions, no `skipped` key. Now the mutation sweep."* | that one banked sentence | the sweep, not yet begun |
| F-13, F-18, F-12 verifications | first tool calls | nothing | a few minutes each |
| F-04, F-11, F-15, F-26, F-31 reworks | first tool calls | nothing | a few minutes each |

Three lessons, one of which corrects me.

**The interim-reporting rule worked, and it is the only thing that worked.**
After the container restart I asked every verifier to report findings as it went
rather than hold them for a final hand-back. F-29's verifier had done exactly
that one sentence before it died, so its full-suite baseline — the most
expensive single measurement in a verification, and the one everything else is
compared against — is banked and did not have to be re-run. That one sentence is
the whole of what eleven agent-hours left behind. Every other agent that was
past its first tool call left commits or nothing.

**My conclusion that "an implementer's work is durable the moment it commits"
was too comfortable.** It is true, and it is not sufficient: two of the three
implementers had uncommitted work in the tree at the moment of the kill, and one
of those was a half-written fix to a production file. Durability is a property
of the *habit*, not of the mechanism. Commit-as-you-go is now stated in every
brief as a first instruction rather than a closing one, and I snapshot every
dirty tree myself before resuming an agent, because an agent resuming into a
tree it half-edited cannot always tell its own unfinished work from the
finished work beside it.

**A snapshot is not a diagnosis, and this one carried a trap.** F-29's verifier
had a modified `SystemHostResolver.php` in its tree. If that was a mutation it
had applied when the kill landed, then its banked baseline was taken *before*
the mutation and describes a tree that no longer exists — and a verifier that
resumes and sweeps against a stale baseline produces a report that is wrong in
a way nobody downstream can see. It was told to establish which case it is
before touching anything, and to revert and restate rather than carry the
number forward. The general rule: **a banked measurement is only banked
together with the tree state it was taken against.**

**What I did differently on resumption.** The agents were resumed from their own
transcripts rather than re-dispatched cold, so the four furthest along kept
everything they knew; a cold re-dispatch would have thrown away the same work a
second time for no reason. And I resumed four rather than twelve. The
twelve-agent cap was set on measured evidence about wall-clock and worker
contention, and it is still right for those reasons — but it is now also the
thing that converted one account-wide limit into twelve simultaneous
terminations. Concurrency concentrates a shared failure as efficiently as it
distributes work, and the programme had no staggering of any kind.


## History

| Date | Event |
|---|---|
| Entry | HEAD `6a7583523833ecdaea84136fdb0eb1c52ff4f121`; 10 findings accepted closed; 36 open; F-45 deferred to adjudication. |
| Wave 1 + early Wave 3 | Nine findings implemented and put through independent red-team verification. **Not one was accepted as submitted**: two rejections (F-14, twice) and five "upheld with reservations". Every rejection reproduced the original finding's own outcome against the repaired code. |
| Programme infrastructure | Two isolation defects found and fixed mid-flight, both of the same family as F-41. A shared `vendor/` autoloader had every worktree — and the canonical checkout — executing one agent's uncommitted source while reporting its own suites green; caught only because a deliberate breakage failed to break anything. A shared Redis index had the real-worker suites deleting each other's queued messages and blaming the change under test; three agents found it independently and one proved it by moving to a private index and watching 15 failures become none. `git stash` is also shared across worktrees and is now prohibited. |
| Programme infrastructure | A fourth isolation defect, disclosed by the agent that caused it: F-29's implementer ran a foreground test band while a background band was still migrating the **same** database, and got five spurious errors (`relation "permissions" does not exist`, plus a migration deadlock). It caught this itself, re-ran strictly serially, and **reported the bad run rather than only the good one**. `CLAUDE.md` already says never to run two `php artisan test` invocations against one database; every brief now says it too, because a same-database collision is indistinguishable from a real failure in the change under test. |

## Adjudications I made where a verifier deferred to me

A verifier that hands the call up rather than making it by omission is doing
its job. Recording the reasoning here so the decision can be argued with.

### F-18, round four — verifier returned UPHELD WITH RESERVATIONS; I agreed, and why

Its own framing: *"Under a standard of 'the guard is correct and pinned'
F-18 is UPHELD outright… Under the standard this branch set for itself —
that a false sentence in the repository is a defect to be corrected — a
tenth, eleventh and twelfth remain. I have taken the second standard,
because the branch chose it."*

**Agreed, and for that reason.** CLAUDE.md already makes a comment that
contradicts the code a defect; a branch that spent a round finding nine
such sentences in its own prose does not get to stop at the tenth. But the
distinction matters for what happens next, so it is recorded: the guard
itself is closed, and what is open is the truthfulness of what surrounds
it — except for two items that are defects under either standard, and are
the reason this is a rework rather than a closure:

- **A prescribed operator repair query that over-matches.** It returns
  three shapes that are not the drift, and running the `cancel()` it
  prescribes on them cancels a retention window that is still running. One
  over-match is reachable from the branch's own deliberate design: the
  termination action calls the panel before writing the row, so a process
  that dies in between leaves exactly the shape the query matches — and
  the repair would file a service whose data is already destroyed as a
  returning customer. **A remediation handed to an operator is code.**
- **A test that does not test what it says it tests.** The drift oracle
  asserts the distance between two asks and nothing about the magnitude,
  so doubling the window, changing its unit, or adding a year all survive
  46/46 — while the commit message claims precisely those cases fail.

### F-14, round five — verifier returned UPHELD WITH RESERVATIONS; I rejected

Its own framing: *"If the adjudicator's standard is 'no licence answer
containing a non-serving word or a past date may reach `valid: true`',
reservation #1 is a rejection — I state it that plainly so the call is
theirs, not mine by omission."*

**Rejected.** F-14 is a finding about an outcome — an unreadable or expired
node recorded licensed and schedulable for paid orders — and the verifier's
own words are that its first row is *"F-14's headline outcome verbatim"*.
That the mechanism is `parse_str` upstream of every rule under review,
rather than a first-match read inside one, does not change what the platform
does with the body. Three further considerations, none of which changed the
answer:

- *It is unchanged from the parent, so it is not a regression.* True, and
  irrelevant: F-14 exists to close this class, not to avoid adding to it.
- *The commit withdrew the exhaustion claim, so it did not promise this.*
  True, and it is why this is a rejection of the finding rather than a
  criticism of the commit. The guarantee offered was verified and holds.
- *Reachability needs a panel that repeats a key.* A panel that contradicts
  itself inside one answer is the premise of doors 7, 8 and 9, all of which
  were accepted as worth closing.

The fix is cheap and sits where the raw body is already in hand. The
deliverable I asked for alongside it is an inventory of **every
transformation the raw body passes through before a rule sees it** —
`parse_str` was upstream of everything anybody had examined for five rounds,
and an eleventh door is likelier than not without one.

### F-14, round six — verifier REJECTED, and handed one call up to me

The verifier rejected on D1 by itself and explicitly declined to rest the
verdict on D7, stating the standard it needed from me rather than guessing it:

> *"Leading-NUL keys destroy the value **and** the gate's own naming of it,
> and sell. **This one turns on a standard only you can set.** If 'destroyed
> vs. merely unread' is the line the new gate owns — which is the
> implementer's own framing — it falls on the gate's side and is door eleven.
> If the test is 'a key spelling the platform would never read anyway', it is
> T10's owned family."*

That is the right way to hand a call up: the question, both answers, what
each implies, and a statement of which one it would not rest a verdict on.

**Ruling: D7 is IN, on the implementer's own criterion.** The line the gate
draws is *destroyed* versus *merely unread*. `%00status=expired` parses to
`[]` — the pair vanishes whole, and the gate's own naming derivation vanishes
with it, so `named['status'] = 1`, `held = 1`, and no loss is reported. The
panel said "expired" and the platform never learns it said anything. That is
not T10's family: T10's value survives in `$fields` and the platform simply
reads a fixed set of key spellings, which is an owned whitelist limit with the
evidence still on the row. Here the evidence is gone.

Two things made the ruling easy rather than close. First, the asymmetry: a NUL
*suffix* is already caught and a NUL *prefix* is not, so the gate is not
drawing a principled line here — it is blind in one direction and does not
know it. Second, **the mechanism that catches it is already in the file**. The
`max_input_vars` guard counts `substr_count($body,'&')+1`; a dropped pair makes
pairs-sent exceed pairs-parsed, and that comparison catches every whole-pair
drop including spellings neither of us has thought of. The verifier notes that
the pair count's unique contribution today is "mostly suppressing PHP's
warning". This is how it earns its place.

I told the implementer the route but not the implementation, and said why: a
rule keyed on the literal spelling `%00` is a rule that will be wrong for the
next spelling, and this implementer has already been right once to prefer a
transformation-level gate over a spelling-level one.

### F-08, round two — verifier returned UPHELD WITH RESERVATIONS and named the fork

It did not hand the call up by omission. It stated both standards, said which
each implied, and said which it had taken and why:

> *"If the adjudicator's standard is 'the configured defect is closed and
> proven at the code level', this is **UPHELD** outright — everything under
> that heading passes. If the standard is the one R5 itself set — 'a
> deployment can no longer silently reinstate F-08' — then R-1 and R-2 are
> unclosed instances of that very standard and it is **UPHELD WITH
> RESERVATIONS**. I have taken the second, because the rework chose that
> standard for itself in its own commit message."*

**I agree, and for the reason it gives.** The standard is not mine to impose
here — the implementer chose it, in writing, when it argued that the boot check
was worth building at all. A guard whose stated purpose is to stop a deployment
reinstating the defect, and which a deployer can walk around in three lines of
the very configuration file this repository already edits for other purposes,
has not done the thing it was built for. R-1 is not a hypothetical: the verifier
constructed both spellings and measured `violations()` returning `[]` for a
5,700-second build on a 180-second clock.

R-2 is the same failure in the other direction and is harder to call latent:
the check's own docblock names the manual-worker path as its reason for
existing, and `scripts/serve.sh` ships exactly the invocation that walks past
it. Splitting one connection into three made that divergence larger rather than
smaller — before, Horizon and the manual worker were both wrong at 90; now
Horizon is right and the manual worker is off by 5,580 seconds.

Two things in the verdict I want on the record as **upheld**, so the rework does
not re-open them: scoping the ladder rule to `payments` is correct, and naming
the five out-of-scope classes in the test's own docblock is the honest way to
leave scope on the table. The verifier's only amendment is downward — the three
notification listeners are less serious than the implementer's framing, because
`NotifyCustomer` dedupes on a unique index and `DeliverNotification` carries
both `tries=3` and a ladder, and the provisioning pair is Medium rather than
High because neither can build a second machine.

### F-18, round five — verifier returned UPHELD WITH RESERVATIONS; I took the other branch

It named the fork precisely and said which reading it would not rest a verdict on:

> *"Round four's standard was 'defects under any standard: a prescribed
> operator repair query that over-matched, and a drift test that pinned the
> rate while its docblock claimed otherwise.' R1 is the mirror image: a
> prescribed operator repair query that **under**-matches, with a docblock
> claiming a clause is redundant when I measured it discriminating. Under
> that standard applied symmetrically → REJECTED. Under a severity-weighted
> standard → UPHELD WITH RESERVATIONS … I fall on the second, and I name R1
> as the item that flips it."*

**I take the first, and the practical outcome is the same — F-18 goes to
rework — so what is at stake here is only what gets written down.** I am
recording the disagreement rather than quietly adopting the verdict, because
the standard has to be the same one on every finding or it is not a standard.

Its severity argument is reasonable and I want it on the record: the
over-match destroyed retention dates real customers had been given, while the
under-match hides a row shape it verified is not reachable by any supported
flow today, and the repair is one clause or one sentence. Where I part from it
is that the fixture's unreachability makes the *consequence* smaller, not the
*claim* truer. The commit presents "two of six clauses are redundant" as a
**measurement**. One of the two is not, its stated reason is wrong
independently of the fixture, and the justification runs backwards — the
clause can only ever remove rows the status filter wanted.

That is the standard I applied to F-14's fifth rejection four hours earlier,
in that verifier's words: *"it is inside a rule this commit wrote, on a case
its own test comment says is covered."* Both are defects inside a claim the
commit itself makes, rather than gaps it named. Having rejected one on that
ground, I will not uphold the other.

None of this touches what round five established, and the rework has been told
not to re-open it: the security substance of F-18 is closed, both round-four
blockers are genuinely fixed and were reproduced on the parent first, and 18 of
18 behavioural mutations are caught.

### F-29's false claim about its own test: a reservation, and the line I am drawing

F-29's fourth verifier set out both readings of its main finding and deliberately
declined to choose between them, which was the right thing to do. Read strictly
— *a claim the commit makes about itself, falsified* — it is a **rejection**:
the agreement test's docblock says it judges "every shape both of them judge,
judged by both" with "one deliberate difference", and that is false in two
independent ways (nine shapes fed; 376 disagreements in two classes; five
whole-name divergence classes against one asserted). Read by consequence it is a
**reservation**: every divergence runs in the safe direction, the policy is
either the stricter side or accepting something its docblock explicitly intends,
and no address reaches a socket because of it.

**I rule it a blocking reservation.** More usefully, here is the line, because I
have now ruled on five of these and was in danger of ruling case by case:

> **Reject** when the falsity means the code is wrong, or when the commit's
> decision would have been different had the truth been known.
> **Hold open for bounded rework** when the sentence is false but the code
> beneath it is independently proved right.

That line explains the rulings already made rather than being invented to fit
this one. F-14's fifth round was a rejection because the defect was *inside a
rule the commit wrote, on a case its own test comment said was covered* — the
behaviour was wrong. F-18's fifth was a rejection because "two of six clauses are
redundant" was presented as a **measurement** and one of the two was not, with
the justification running backwards: the false sentence was the reason the code
looked right. F-31 was held open for prose because every rule it wrote survived a
19-mutation sweep and 885,452 probes, and only the sentences were wrong. F-29 is
F-31's case: `HOST_LABEL` is correct, and it is correct **on evidence that does
not depend on the agreement test at all** — 2,096 spellings accepted none, and
M13 dies. Strike the false sentence entirely and not one decision in the commit
changes. That is the test I will apply next time.

**What keeps this from being cheap.** The untested class is not harmless in the
abstract: it conceals a live defect in the very module the commit chose not to
reuse. I verified that myself rather than relaying it, and found a distinction
the verification did not draw, which changes which case matters:

    DomainName::fromString("ab\n.example.com")  → ACCEPTED, value() === "ab\n.example.com"   ← the newline SURVIVES
    DomainName::fromString("ab.example.com\n")  → ACCEPTED, value() === "ab.example.com"     ← the newline is stripped

The trailing newline normalises away harmlessly. The **interior** newline is the
one that survives into a `DomainName`'s value, and it is the one a working
agreement test would have caught. So the overstatement has a demonstrated cost,
not a theoretical one — which is why the reservation blocks, even though it does
not reject.

**And one adjudication the verifier asked me to price rather than rule on.** The
empty-answer refusal now runs at *use* time on a customer route: a
`bmc_endpoints.address` row holding a hostname means every power operation does a
synchronous resolve inside the request, and a transient resolver failure is now a
refusal surfacing as a 500 where it used to be a connection failure. I call the
cost **defensible and the design unchanged**. The asymmetry decides it: the
alternative the commit replaced — accepting a name whose address loop ran zero
times — meant *every* refusal the policy makes about a name was conditional on
the resolver having answered. Failing closed is right for a control whose job is
deciding where a credentialled socket may go. It is bounded by per-endpoint
memoisation, and `docs/security.md` must name the use-time cost as it already
names the registration-time one.

### F-18's closure, and what it says about my round-five rejection

F-18 closes, and it is the first finding in this round to do so. The record
should be exact about which part of my round-five rejection survived, because
both halves matter and they point in opposite directions.

**The half I got right.** I rejected on the commit presenting *"two of six
clauses are redundant"* as a measurement when one of the two was not, and on the
justification *"could only ever remove a row that was wanted"* running backwards.
The sixth round rewrote that reasoning, and the independent verification
re-derived the six-predicate analysis by hand and then mechanically and matched
the corrected table exactly — each of the five discriminating clauses having a
fixture that only it excludes, the sixth surviving ablation as documented. The
verification also confirms the original sentence was **strictly false**: the
deleted clause did remove a second row. So the rejection was sound and the
rework answered it.

**The half I had wrong, and it is the more interesting half.** I doubted the
deletion of `AND ha.terminated_at IS NULL` itself. The verifier did not argue
about it — it **built the row that would have proved me right**: a re-used,
rebuilt account whose username *is* present at the panel, so the docblock's
mandatory manual step succeeds while `cancel()` is the wrong repair. Two
variants, identical in every column but one. Measured, the query returns that
hazard **with the clause restored as well as without it**, because the same
shape is reachable with `terminated_at` NULL from a Failed build rather than a
Terminated one. The clause was covering half of a pre-existing hole by accident,
while demonstrably removing a row the nightly sweep fails on. The construction
convened to uphold my objection refuted it instead.

That is the outcome I want from this programme and it is worth naming as such:
the verifier's instruction was to try hardest at the thing I was most doubtful
of, and doing so honestly produced evidence against its own caller. It is now
the sixth of my claims corrected by an agent that measured rather than believed
me, and the only one where the correction came from the agent I had pointed at
the target.

**On the one place where its judgement and my stated test fork, I take its
reading.** The comment guard's docblock says *"a line may open a comment with
`--` only when a space or the end of the line follows it"*, and the enforcement
is line-leading only — so a `--` appended mid-line passes the suite while
breaking the operator's MySQL client. Read strictly, that is a defect inside a
rule the commit wrote, which is my rejection test. The verifier read the
sentence as *"a line that opens with a comment"*, which the surrounding
paragraph and the worked example both support, making it ambiguous rather than
false — and declined to rest a verdict on a reading of an ambiguous sentence.
I agree, and I have told the cleanup to make the question moot by widening the
enforcement rather than by adjudicating the grammar. **An ambiguous sentence is
not a false one, and it is not grounds for rejection.** That is a second line
worth having alongside the first.

### A measurement I published was wrong by a factor of three, and the rework was too polite about it

The fourth verification of F-29 reported **376 label-rule disagreements — 268
underscore and 108 trailing line-feed — over an exhaustive 66,048-label sweep**.
I recorded that in this ledger and relayed it into the rework's brief as fact.

The rework's own sweep returned **111 — 75 underscore and 36 trailing LF** — and
it attributed the gap to its three-character sweep being narrower than the
verification's. That was generous and it was wrong: both sweeps cover the
identical space (256 one-byte labels + 65,536 two-byte + 256 of shape
`a<byte>a` = 66,048), so breadth cannot explain a factor of three.

I measured it myself rather than choosing between two agents:

    swept=66048  disagreements=111  (len1=1  len2=109  len3=1)
    underscore=75  trailingLF=36  other=0

And the arithmetic closes exactly, which is what makes this a settled fact
rather than a third opinion. Underscore: 1 (the bare `_`) + 73 (37² − 36², the
two-byte labels legal under `HOST_LABEL`'s `[a-z0-9_]` but not under
`assertLabel`'s `[a-z0-9]`) + 1 (the `a_a` middle byte) = **75**. Trailing LF:
36, one for each `[a-z0-9]` followed by `\n`, admitted by `$` and refused by
`\z`. No third class exists. **111.**

Three things worth keeping from this.

**The error was mine to publish and mine to correct.** The verification produced
it, but I recorded it without checking arithmetic that takes thirty seconds, and
then handed it to the next agent as an established measurement. That is the
seventh of my claims corrected by an agent that measured, and the first where the
wrong number had already been written into a brief and acted on.

**Deference between agents is a failure mode, not a courtesy.** The rework had
the right answer and talked itself into explaining away the discrepancy rather
than reporting it as a contradiction. Its numbers were reproducible and the
verification's were not; the polite framing nearly buried that. Briefs now say
so explicitly: where your measurement contradicts one I gave you, report the
contradiction rather than reconciling it.

**The figure did not change any verdict, which is exactly why it is dangerous.**
Both counts support the same conclusion — two divergence classes, one of them
undeclared, concealing a live interior-newline defect — so nothing downstream
would have caught it. A wrong number that agrees with the right conclusion
survives every check that looks only at conclusions.

### The eighth correction, and why "four seams" was wrong in a way I could not have noticed

I briefed F-31's rework that a false sentence named one subclass seam where
**four** were open. It came back with **eight**, and the extra four are the
interesting half.

My four were the class's **own** methods — `proxies`, `headers`,
`matchesEveryCaller`, `covers`. The other four are inherited from
`Illuminate\Http\Middleware\TrustProxies` and are equally replaceable on a
subclass of this class: `handle()`, `getTrustedHeaderNames()`,
`setTrustedProxyIpAddressesToSpecificIps()`, and
`setTrustedProxyIpAddresses()` itself — **the very method the false sentence
claimed was closed, on the grounds that it is "still this class's"**. That
reasoning is the error in miniature: a method being this class's decides what a
subclass *inherits*, not what it may *replace*. My count inherited the same
blind spot as the sentence I was asking to have corrected, because I enumerated
the same list it did.

A ninth, `setTrustedProxyIpAddressesToTheCallingIp()`, is inherited and its body
literally is the hole (`setTrustedProxies(['0.0.0.0/0', '::/0'])`), but nothing
reaches it, so it is counted separately rather than among the eight — a
distinction I would have collapsed.

The rework's framing is the right one and I am adopting it: *the four you
relayed were a subset, not wrong in kind.* That is the honest shape of most of
these corrections. Eight of my claims have now been corrected by agents that
measured rather than believed me; this is the second in a single day where the
number I supplied was **not merely inaccurate but constructed by the same
mistake the finding was about**.

### F-18's cleanup, and the one thing in it that brushes the closed control

F-18 is closed. Its three non-blocking residues went to a short cleanup, six
commits, `8e0dff5`..`942a202`. What it delivered is worth recording for three
reasons beyond the fixes themselves.

**It proved the stop condition rather than asserting it.** I had told it that if
anything altered the repair query's returned row set it had gone too far. It
byte-compared the query block between the two commits (identical), and counted
the changed lines in `src/` that are **not** docblock prose: **zero**. The
exact-set oracle still returns the same four rows on every green run. That is
the difference between "I believe I changed nothing executable" and knowing it.

**Both blocking narrowings are now proved closed in both directions**, which is
what I asked for and rarely get: `AND hn.panel = 'fake'` **passed** at the old
tip and **fails** now, naming the lost row; the mid-line `--` comment **passed**
at the old tip and is now caught three ways, with the string-literal handling
checked rather than assumed — `'customer--cancelled'` is preserved and not
flagged, `'it''s--fine'` likewise, and on a line carrying both a literal and a
real comment the opener is found at the right offset. That is why the scan walks
the line tracking quote state instead of being a regex.

**It changed its own mind mid-task and said so, with the reason.** On the third
residue it expected to argue that a textual oracle was too brittle to be worth
it. It looked for a behavioural pin instead — a fixture half a second past the
boundary — and found that **Eloquent's default Postgres date format drops the
fraction**, so the fixture would land exactly on the bound and prove nothing.
Given that, it took the loud-and-wrong over the quiet-and-wrong: the pin's
failure mode is a red test with an instruction in it, the gap's failure mode is
a boundary row the operator is silently never handed. It wrote the brittleness
into the test's own docblock rather than pretending it away. Reasoning of that
shape is worth more than the eight lines it produced.

**And it caught its own overclaim, one screen from the overclaim it had just
fixed.** Reviewing its own diff before handing back, it found that the comment
it had written above the new fixture said the node *"differs from `fxa`'s in
every column the query does not read"* — the exact sentence-shape it had spent
the previous commit correcting. Three columns are in fact identical. Fixed, and
recorded as its own error.

**Cleanup closed at `eae4ff1`.** All three residues shut with passed-before /
fails-now on each; the file band moved 19/78 → 20/129 and the full suite is
3,931/3,931 with `skipped=0`. I commissioned one further commit after
adjudicating the nuance below: the sentence was true but read wider than it
was, and what it read as was a ready-made argument for restoring the deleted
clause. The ledger is programme-internal and temporary — the next engineer to
open `UnsuspendHostingAccount.php` reads that paragraph, not this record — so
the correction belongs in the code, and I specified four facts and no more so
the repair could not introduce a fresh overclaim. I kept the paragraph's
opening clause, which states *why* it exists: a docblock that narrates its own
motive is what stops the next well-meaning edit from deleting it.

**The sentence worth carrying out of this finding** is the cleanup's own closing
note, which is a better statement of the trap than mine: *reachability and
clause coverage are different questions, and I had conflated them.* Its three
verified unreachability routes were all sound. The limit was that the unstamped
variant is not a shape its fixtures build, so it could not have found the edge
of its own observation from inside its own worktree. That is the structural
argument for independent verification stated more precisely than the protocol
states it.

#### The nuance that touches the deleted clause, and why it does not reopen F-18

It flagged, rather than deciding alone, that a rebuilt account row keeps whatever
`terminated_at` the termination wrote — `ReserveHostingNodeCapacity` re-arms with
`forceFill(['status' => Pending])` and clears neither stamp — so the deleted
`AND ha.terminated_at IS NULL` **would** have excluded that shape, and deleting
it therefore widens exposure to it.

That is true of exactly half the shape, and the half it is true of is the half
the verification already measured. The verifier built the hazard in two
variants: `rbT`, rebuilt from a Terminated row and carrying a stale stamp, which
the clause did exclude; and `rbN`, the same shape reached from a **Failed**
build, which carries no stamp and which the clause never touched. The query
returns the hazard either way.

So restoring the clause would trade `fxm` — reachable, wanted, and failing the
nightly sweep today — for `rbT`, which no supported flow can produce, while
still leaving `rbN` open. **The deletion stands.** What makes this worth writing
down is not the conclusion but the route: three parties have now reached it
independently — the implementer by argument, the verifier by building the row
that would have refuted it, and the cleanup by noticing the one fact that looks
like a counterexample and working out why it is not. The paragraph in the code
now says all of this, so nobody reads the new text as a case for restoring the
clause.

**One more correction to a figure I relayed:** the node row has **26** columns,
not the 27 I passed on from the verification. Every other measurement in that
brief reproduced exactly.

### A third line on rejections: what to do with a falsified "cannot"

F-28's verifier did something I want to encourage rather than merely accept: it
found that my two stated lines **point opposite ways** on its blocker, said so
plainly, argued both readings, and declined to choose for me — offering to flip
its verdict to REJECTED if the programme's convention turned out to be the other
one. That is the right handling of a genuine ambiguity in a rule, and it is
better than a confident answer would have been.

The blocker: a test docblock claimed `verify_peer_name` *cannot* be pinned from
that surface, named the operating system's trust store as an obstacle a test
cannot add to, and pointed at a production change as the only remedy. The
verifier pinned it in both directions from exactly that surface with no
production change — and the reason it could is the reason the docblock gave for
why it could not. PHP falls back to OpenSSL's default verify paths, honouring
`SSL_CERT_FILE`, **precisely because the gateway's context names no `cafile`**.

**Ruling: bounded rework, not rejection.** The production code is right and
independently proved right in both directions; the falsity is in a docblock
about *coverage*, not behaviour; and F-31 governs — a false sentence justifying
something left open, over code proved correct, is rework. But the ambiguity was
real, so the rule gains a third clause:

> **A falsified impossibility claim is not automatically a rejection, but it is
> always blocking.** "Cannot" is the one kind of sentence that stops future work
> from being attempted at all, so it is never merely cosmetic, and the burden on
> anyone writing it is to have tried.

The three lines now read together as: *reject* when the code is wrong or the
decision would have been different; *hold open* when the sentence is false but
the code beneath is proved right; *never wave through* a "cannot"; and an
ambiguous sentence is not a false one.

What makes the third clause worth having is the failure mode it names. A false
claim about what the code *does* is caught by the next person who reads the
code. A false claim about what *cannot be done* is self-sealing: it is believed
precisely by the people who would otherwise have disproved it, and the only
thing that breaks it is someone trying anyway. Here the harm was bounded — one
unpinned line and a future round sent to do unnecessary production work — but
the shape scales badly, and this programme has a standing instruction to name
honest bounds, which makes it a shape we will keep producing.

### A false sentence I specified myself, now committed to the repository

The tenth rejection of F-14 carries a correction that is mine rather than an
agent's, and it is the first time one of my errors has reached the source tree
at my own instruction.

I commissioned a docblock sentence to make a warning honest, and I specified the
mechanism as fact: *"`date_parse` takes the first four digits of `10000-01-01`
and drops the fifth, so the gate and the reader agree on the year 2000."* The
implementer verified every clause it could and adopted the wording. The verifier
measured it and it is wrong. I re-measured it myself:

    10000-01-01   err=0 warn=0  y=2000 m=1 d=1  H=10
    99999-01-01   err=0 warn=0  y=2009 m=1 d=1  H=false

timelib reads the leading `10` as an **hour**, not as four digits of a year. And
my sentence was **internally inconsistent on its face**: the first four digits of
`10000` are `1000`, which would give year 1000, not the 2000 I asserted in the
same breath. I did not notice, and neither did anyone downstream, because the
*outcome* I also stated — stored as 2000-01-01, refused by `isPast()` — is
correct, and that is the part everyone checked.

Three things worth keeping.

**The instruction I gave was better than the fact I attached to it.** I told the
implementer to write the warning as a *class* rather than around one instance,
and that was right — doing so is what turned up `99999-01-01`. But I supplied a
mechanism I had not measured, in a brief that told the recipient to measure
rather than believe me. An instruction to verify does not travel with the
authority of the person giving it; **a stated mechanism reads as established
even inside a brief demanding verification**, and mine was neither marked as a
guess nor true.

**The verification that caught it was checking the right thing the wrong way.**
The implementer reported verifying "every clause through the shipped method
before committing the prose". It verified the *outcome* clauses — the parse
result, the storage, the refusal — and those all held. The *mechanism* clause is
not checkable by observing the outcome, because a wrong mechanism and a right
mechanism produce the same 2000-01-01. Checking it needed `H=10`, one field over
in the same array.

**It also breaks the sentence that follows it.** The docblock goes on to say the
accident would stop holding *"if `date_parse` ever kept the digit it currently
drops"* — a failure mode that does not exist, because no digit is being dropped.
So the false mechanism did not sit harmlessly beside a true outcome; it
generated a second false sentence about the future, which is the specific harm
the paragraph was written to prevent.

Round eleven corrects it. This is the tenth of my claims corrected by an agent
that measured, and the only one I had put into the code.

### The quantifier is the part that fails, not the claim

Five findings in, the shape of what gets falsified here is now clear enough to
state as a rule, because it has stopped being a coincidence.

| finding | the sentence that failed |
|---|---|
| F-28 | *"cannot be pinned from this surface"* |
| F-13 | *"would violate `EveryDeclaredCapabilityHasAConsumerTest`"* |
| F-19 | *"the refusal and the ending **cannot** drift apart again"* |
| F-13 | *"the **only** writer of the `ComputeStorage` rows"* (in three places) |
| F-29 | *"a road this pattern has **no equivalent** of"* |
| F-31 | *"**nothing but** `TrustProxiesOnAPhpBuiltWithoutIpv6` extends this"* |
| F-19 | *"**no caller** outside `EndOfService`"* (true in production, false in tests) |

**In every one of these the code beneath was right.** Not once has a falsified
claim of this kind turned out to sit on top of a broken repair — the fix worked,
the reasoning about the fix worked, and the quantifier wrapped around it did not.
That is why five of the six are bounded rework rather than rejections.

The seventh, F-19's, is the first that did **not** cost a round: its verifier
found the sentence, scoped it (true of production, false of three test call
sites), showed the substance unaffected, and returned UPHELD anyway. That is the
pattern working as it should — the rule is not that a quantifier must be deleted,
it is that a quantifier must be **measured**, and a measured one that survives
with a scope word attached is a sentence, not a defect. F-29's author applied it
to itself in the same window, narrowing *"pinned by nothing"* to *"pinned by
nothing worth writing"* after finding a comparison that would in fact kill the
mutant.

So the rule, now in every brief:

> **A sentence containing "only", "exactly one", "nothing else", "never" or
> "cannot" is a measurement, not a description.** Either run the grep and cite
> it, or write the weaker sentence that does not need one.

**The rule worked prospectively within one commit of being written, which is
the first time any rule here has.** F-29's second rework reports that it *nearly
wrote a new false sentence* and caught itself: it was about to say
`HOST_CHARACTERS`' anchor **cannot** be falsified, then checked, and found that
it **can** — three near-miss patterns all anchor with `$`, so the trailing-newline
class separates them as shipped and stops doing so under the mutation. A test
comparing them *would* kill `$`.

What it wrote instead is the distinction that matters, and it is sharper than
the rule I gave it: such a test **would not be a pin**, because it asserts a
relationship nobody maintains between surfaces with no reason to agree. So the
docblock now says *pinned by nothing worth writing* rather than *unreachable by
any test* — a narrower claim, and a true one. It softened the block's opening
"cannot be falsified" to match, on the reasoning that that is *"the sentence a
reader counts from."*

Two smaller self-corrections in the same breath: the near-misses are **three**,
not the two it had been told — it found a third — and the line it cites is in
`RegistrableDomain::parse`, not `fromString`.

This is worth recording because every other instance of the quantifier rule in
this ledger is **retrospective** — a verifier finding a false "only" after the
fact. Here the author applied it to their own unwritten sentence and produced a
better claim than the one they were reaching for. The rule's value is not that
it catches false absolutes in review; it is that it makes the writer ask *"can
I cite this?"* before the sentence exists.

Two things make this more than a style note.

**These sentences are load-bearing in a way ordinary prose is not.** A wrong
description misleads one reader. A wrong *"only"* is what the next round's
scope is derived from — F-13's gap survived three verification rounds because
everyone read the disclosure and routed around an obstacle that did not exist.

**The good version already exists in the same reports.** F-13's own sole-caller
chain is the model: four links, each independently checkable by grep, three
true and one false — and because it was written as a chain of citable claims
rather than a summary, the false link was found in minutes instead of surviving
another round. The habit to keep is not "avoid strong claims", it is **make
strong claims falsifiable and cite the thing that falsifies them.**

### A pattern worth naming: the bound accepted on a reason that is not true

Two findings produced the same shape on the same day, by different agents on
different modules, and it is worth naming before it produces a third.

* **F-28.** A docblock said `verify_peer_name` *cannot* be pinned from that
  surface, naming the OS trust store as an obstacle a test cannot add to. Its
  verifier pinned it from exactly that surface — and the reason it could was the
  reason the docblock gave for why it could not.
* **F-13.** A commit left a real gap open — a token short of `Datastore.Audit`
  declared ready — on the ground that closing it would violate an architecture
  test. I told the rework to test that premise rather than accept it. The test
  refuses a capability **no product requirement names**; naming one satisfies it.
  The obstacle did not exist.

Both are **honestly disclosed bounds**, which is why neither was a rejection and
why both agents were doing what this programme asks. That is exactly what makes
the pattern dangerous. The programme's standing instruction is to name your
bounds rather than hide them, and a named bound is treated — correctly — as good
practice. But **a named bound carries an implicit claim that somebody checked**,
and in both of these nobody had. The disclosure then does the opposite of its
job: it converts an unexamined assumption into a documented decision, and the
next reader inherits it as settled.

So the instruction gains a clause, and it is now in every rework brief:

> **Name your bounds — and when you name one as an impossibility or as an
> obstacle imposed by something else, say what you tried.** A bound whose
> reason has not been tested is a guess wearing a disclosure's clothes.

**The operational form, which F-13's implementer wrote better than I did:**

> Treat any recorded reason of the shape ***"X would violate Y"* as unverified
> until Y has been opened** — and say in the report that you opened it.

That is sharper than my version because it is checkable. "Say what you tried" can
be satisfied with a sentence; "say that you opened Y" names the artifact and
either you did or you did not. In F-13's case the entire bound turned on the
difference between *"refuses a new capability"* and *"refuses an unconsumed
capability"*, which was one file away and had survived three verification rounds
unread. It is now in every rework and verification brief.

The cost of not doing this is asymmetric in a way worth spelling out. An
untested bound that turns out real costs one agent an hour of confirming it. An
untested bound that turns out false costs every subsequent round, because each
one reads the disclosure and routes around the thing that was never actually
shut. F-13's gap survived three verification rounds that way.

### Declining a database constraint that I required on F-04 — and why the two differ

F-33's implementer asked me directly whether to add a PostgreSQL exclusion
constraint as belt and braces, having established it would actually build
(`btree_gist` available, the role superuser) rather than calling it impossible.
It declined and offered to build it on my word.

**Ruling: do not build it.** Its reason (b) is the decisive one and it is right:
the constraint can express **only the in-pool realm**, because the realm
discriminator — `datacenter_id` and `scope` — lives on `ip_pools` and not on
`subnets`. So it would be a **second, partial answer** to "may this block
exist", refusing with a constraint violation where the action answers 422. That
is the precise anti-pattern `RegisterSubnetRequest`'s own docblock names, and
the one this round **just removed**: a weaker duplicate of a rule, disagreeing
with the real one at the edges and surfacing as a 500.

**This looks inconsistent with F-04, where I required exactly such a constraint,
and the distinction is worth stating because it will come up again.**

| | F-04's CHECK | F-33's proposed EXCLUDE |
|---|---|---|
| what it asserts | a **property of the column** — that the stored value is canonical | a **rule about the estate** — which blocks may coexist |
| completeness | **total**: every row, every path, no exceptions | **partial**: one of three realms, the other two inexpressible |
| relation to the action | the action's fold is *how* the property is achieved; the constraint is the property | the action's guard **is** the rule; the constraint is a second, weaker copy of part of it |
| when it fires | only if something bypasses the fold — i.e. a genuine invariant breach | on ordinary estates the action already refuses; it fires only where the two disagree |

So the test is not *"is a database constraint good?"* but **"is the constraint
the same rule, stated completely, or a different and narrower rule wearing the
same name?"** F-04's is the invariant the index depends on. F-33's would be a
subset that answers differently at the boundary — and a subset that disagrees
at the boundary is how this programme's worst defects have been built.

**What I accept as the residual**, named by the implementer rather than
discovered: the guard is at the action, and `LoadReferenceTopologyForSimulation`
(which refuses to run on production) and `SubnetFactory` bypass it, as would a
direct SQL insert. For a finding whose harm is *one address reaching two
customers through the operator route*, the route is the reachable path and a
hand-written INSERT is not a supported flow. Recorded, not required.

The **pre-existing** overlaps an estate may already hold are a different matter
and are genuinely not covered. The implementer identified `infra:preflight` as
the natural home for a report-don't-refuse check and deliberately did not touch
it while F-35 is in bounded rework on that exact surface — which is the right
call on cross-branch grounds, and is now on the integration list.

## Findings closed by another finding's branch

A repair aimed at one finding sometimes closes another outright. Recorded here
so the second is neither dispatched for duplicate work nor left reading `OPEN`
against code that already closes it. A finding in this table still needs its
own independent verification against its own text.

| Finding | Closed by | Evidence | Status |
|---|---|---|---|
| **F-47** — `DedicatedServerStatus::Retired` is a legal transition target from three states, with an `isRetired()` predicate, an exclusion in the inventory sweep, and a concurrency comment in `SyncDedicatedServer.php:56` reasoning about a race against a state no production code can produce. Its only writer was a test factory: three readers against zero production writers. | `remediation/f12` | F-12's new `RetireDedicatedServer` (`POST /api/admin/dedicated/{server}/retire`, `dedicated.manage`, evidence required, audited) is that production writer. F-12's verifier drove it independently: a machine decommissioned, marked failed in the rack, then retired returns 200, the quarantine clock starts and the address comes back. | `CLOSED pending its own verification` — the writer exists and is reachable from a real operator act; what still needs checking at F-47's turn is whether the three *readers* are now truthful, in particular whether `SyncDedicatedServer`'s concurrency comment is still reasoning about an impossible state. **Neither the commit message nor the code names F-47**, which is how this nearly went unnoticed. |

## Product decisions escalated to the user, and their answers

Only decisions the repository genuinely does not settle, where more than one
contract is defensible and the choice changes what the code must do.

| Decision | Context | Answer |
|---|---|---|
| How a shared-hosting customer supplies the domain their account is built for. | The repository asserts a lifecycle step that needs a domain and provides no way to supply one: `PlaceOrderRequest` has no domain field and `CheckoutLine` carries only `planId`/`quantity`. F-04's literal sentence. | **Ask for it at checkout.** Implemented with the portal, both locales, the capability matrix and OpenAPI in scope. |
| What happens to a running machine or hosting account when its order is refunded in full. | F-19 made `Refunded` reachable for the first time, which turned a dormant sentence in `PlanCapacity`'s docblock — that refunded orders release their unit — into live behaviour. Measured consequence: `order=refunded service=active subscription=active CLAIMED=0`, and the last unit of a finite plan sold twice while the first customer's machine was still running. | **Keep the service, release nothing yet.** A refund records the money and nothing else; the plan unit and the coupon hold come back when the *service* ends, not when money moves. Terminating stays a separate act. `PlanCapacity`'s docblock is wrong as written and is corrected with the code. |
