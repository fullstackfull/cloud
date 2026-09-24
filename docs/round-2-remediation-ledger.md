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
| F-04 | Critical | CODE_GAP | `OPEN` — in rework | **UPHELD WITH RESERVATIONS**, two blocking, from a **partial** sweep — I asked the verifier to bank its findings rather than risk losing them to context exhaustion, and it named exactly which four rules it did not reach, including the one that is F-04's literal failure mode. It isolated the partial index on two raw `psql` sessions with the predicate read from `pg_indexes`, verified `supersededFingerprint()` frozen by SHA-256 **and checked reachability** (no branch carrying the raw-domain digest is reachable from `main`), enumerated **every** write that can enter the index predicate (exactly two, both inside the one `try`), and swept 32 canonicalisation inputs. Blocking: **the rule that makes the index a guarantee about *names* is pinned by nothing** — `PlaceOrder::domainFor()` can return the raw domain with 324/324 green, and raw SQL proves the consequence, two live accounts for `Example.test`/`example.test` with the migration's own collision scan reporting zero because it groups on the same raw column. *"The index is a guarantee about a byte string, not about a name."* And **the operator remedy the code advertises is a no-op in three shapes**, the worst rebuilding F-04's own sentence: the re-arm writes status only, so an operator who corrects the name gets `successful=true` with the account Active serving the name they corrected away from, and the panel handed it. There is **no operator surface** to perform the advertised remedy at all, and the repository **pins the easy half of the claim** — a green test asserts the remedy from the branch where `$existing` is null and it works. I take the verifier's blocking reading, in its words: *"a claim wider than its test, which is the exact pattern the previous three rounds were convened to remove."* |
| F-13 | High | CODE_GAP | `OPEN` — in verification, fourth round | Privilege map derived from the adapter's actual calls and bounded by the platform's own Ansible role. Round three re-derived the twenty-privilege accounting independently and held the finding open for a fail-open the repair itself created: `Sys.Audit`, moved *out* of the map to make the arithmetic close, was pinned by nothing. **The rework closed it and then generalised it rather than patching it.** It asked the question of the whole of `fromPrivileges()` — every decision made *in code rather than in the map* — and found **eight of nine survive 1209 tests**. Its conclusion is the useful part: the sweep's reach is exactly the pairs in `PRIVILEGES`, because the iteration, the generated case-per-pair provider and the equality oracle all read that one constant — so **anything decided in code is unpinned by construction**, and moving one requirement out of the map moved *four* decisions out of reach at once. It also **corrected the blocker's own statement** (the literal mutation assigns a `bool` where a `CapabilityState` is expected and fails on a type error, which is not the defect), **corrected a correction of mine** (the architecture-test half was wrong; the *"outright"* half was right and an earlier round had missed an `assertNotSame([], …)` that has been there since the guard was written), and **found a fourth wrong number** by widening its grep from cardinals to ordinals after a first pass missed it. Most tellingly, it **measured a paragraph I suggested and found it no longer true** — the counts test it added means the 28-pair deletion sweep now gives 0 survivors even with the oracle weakened — so writing my wording would have shipped a fifth wrong number. The durable fix computes every count from the map and the role file, with the quoted prose above each assertion. |
| F-14 | High | CODE_GAP | `OPEN` — **rejected seven times**, in rework | Round nine rejected round eight, and the rejection turns on a **change of frame rather than a harder sweep**. Round eight swept *bytes* — every single byte, every two- and three-byte sequence — and it is correct in that frame; the verifier reproduced its 59-prefix table **byte for byte including the `J T j t` exclusion**, reproduced its 1,548-row byte-suffix sweep at 0 day-moving accepts, and confirmed its directional claim on a corpus four times the size (161 verdicts change, all accepted → refused). Then it swept **words**, in the two positions round eight's own rules reason about, and both decisions collapse. **The structural reason is that the gate and the reader are different parsers**: the rules reason over `date_parse`, which *reports* a relative part, while `parseTimestamp()` uses `CarbonImmutable::parse`, which **applies** it. I verified this myself — and my first check used the wrong instrument and appeared to contradict the verifier, until I used the constructor the adapter actually uses: `Fri, 31 Dec 2099` → **2100-01-01**, `Mon, 31 Dec 2099` → **2100-01-04**, `31-12-2099 next year` → **2100-12-31**, `31-12-2099 30 days` → **2100-01-30**. So the prefix rule admits **37 day-moving words** and every weekday move is *forward* — and the rule's own docblock says it exists because *"the panel wrote one day and this platform would record another"*, while its written justification cites `Thu, 31 Dec 2099`, safe only because that date happens to be a Thursday. The declined suffix rule is justified by *"a rule with nothing to catch is a rule that will be wrong about something later"* — false by **111 trailing tokens** on its own six bases. And **both survivors it declared unobservable are observable** (Roman numerals and month abbreviations glued to digits; six-to-eight-digit years), with **a third it did not declare at all**. Reproduced end to end on a real `hosting_nodes` row: eight spellings of *yesterday's* date, all licensed and schedulable, against a control with the correct weekday that is refused. |

### Wave 2 — concurrency, lifecycle, authorization

| ID | Sev | Class | Status | Notes |
|---|---|---|---|---|
| F-08 | High | CONCURRENCY | `OPEN` — in rework, **third round** | The rework survived the container restart as a commit and was then verified by an agent that had only its commit message to go on, since its author was killed before reporting. **Most of it is genuinely closed and independently re-derived**: the merge semantics quoted from Horizon's own `ProvisioningPlan` (plan second, plan wins, first-match-wins on the pattern); both call sites reading the merged plan, pinned against the **real** `ProvisioningPlan` rather than a re-implementation; the equal case now pinned twice, closing round two's reservation; the two vacuous assertions no longer vacuous; the retraction of *"no test run can make the event fire"* measured rather than asserted; the `tries` precedence proved through a real queue push; and the refund default removed, with **every caller independently re-traced** to confirm the operator path it was defended by does not exist. `serve.sh`'s three workers verified to cover all five producing queues with nothing orphaned. **Held open on one measured blocker**: `horizon:work` and `horizon:supervisor` are in the *configuration* check's command list and **not** in the *invocation* check's — I confirmed both constants myself — so `horizon:work redis --queue=payments --timeout=300` exits 0 where the identical `queue:work` exits 1. On a connection whose `retry_after` is 180, that hands a running payments listener to a second worker: **F-08 verbatim, on the money queue, by one typed command, with both configuration files correct and CI green.** The verifier's sharpest point is that the justification — *"Horizon's own processes are handed their connection and queues from the provisioning plan"* — is an assumption about who invokes the command rather than a property of the command, which is the same reasoning the commit itself rejects three paragraphs later when it deletes a defaulted money parameter. `.env.example` aggravates it by naming both commands as checked. 29 mutations, 24 killed; four survivors are rules no test pins, including Horizon's first-match-wins rule, which the docblock explains at length. |
| F-12 | High | DATA_INTEGRITY | `OPEN` — in verification, fourth round | Both limbs closed; three verification rounds, each finding a defect the previous repair introduced. Round four's deliverable was a **complete pinning inventory** — every guard, condition and filter added across all three rounds, classified by mutating it: **33 mutations**, six survivors found, five closed with tests and the sixth argued. It also **refused a test I asked for, correctly**: the state-machine assertion is not merely unpinned but *provably inert*, because `from === to` is a legal no-op by deliberate design for retry safety, so asserting that a second retirement is refused would put a 409 in front of the very retry-safety the no-op exists for. It recorded the inertness with its mechanism instead, and pinned the two table properties the inertness rests on so a change to either fails and points at the line. It also found the false comment was false in **both** orders rather than one, and closed the latent shape where a machine that dies while the customer is on it could not be decommissioned at all. |
| F-15 | High | CODE_GAP | `OPEN` — **rejected**, in rework | **The original finding is closed and well oracled** — round four reproduced the defect on the parent (two machines), got `already_built` at the tip, reproduced the eighth door independently matching the implementer's own measurement, and confirmed **27 of 30 tests die on a revert** with the three survivors being declared controls. It rejected on two proved grounds, and **what fails is the new surface built beside the fix**. First, the branch is **red**: the new repoint route is undocumented, `docs/openapi.yaml` is stale, and two CI-gated `OpenApiSpecificationTest` rows fail — and the implementer's reported *"390/390 with 12,249 assertions"* matches no band the verifier could find (the whole suite is 3942 / 144,378), so whatever it ran excluded `tests/Feature/Api`. Second, **the ninth door: the remediation's own new route builds the second machine, following the remediation's own runbook**. `RepointReservedIdentity`'s docblock claims `vps.create_identity_taken` *established* the machine is not this build's; it establishes only that it does not match what this job would build **now**, and `refusalFor()`'s own docblock enumerates the counterexamples. With **no database edit and no payload edit** — a silent build, an ordinary hypervisor resize, then the repoint the runbook prescribes — `machines=2`, the customer's own first machine orphaned and unbilled while holding a configured address, and the platform's only handle on it thrown away by the repoint. A variant settles the job green on top of it. |
| F-18 | High | AUTHORIZATION | `OPEN` — in verification, sixth round | The security substance was closed and heavily pinned at round five; I held it open under the symmetric standard for a clause the commit called redundant **as a measurement** which in fact discriminated. **The rework deleted the clause rather than documenting it**, and argued the stronger case: the two columns can diverge only two ways, one already excluded by `ha.status='active'` and the other a row the query wants — *"It was not a safety net; it was the one clause in the query that could lose an operator a row."* It built the fixture itself rather than restating mine, and kept my own qualification in my framing: unreachability *"makes the consequence of the clause small. It did not make the claim about it true."* All six reservations closed, two further than asked — it **fixed** the one-directional oracle rather than only documenting it, and in doing so **found a live hole that was not on my list and is worse than the one that was**: `AND ha.suspended_at IS NULL` survived the fixture set, and `suspended_at` on a serving row is the exact subject of the finding, so that tightening would drop the very account this branch exists for. It also **corrected me on a fact I had relayed as measured**: `isTerminal()` has **zero** callers, not the two I asserted (its siblings have eight between them) — my sentence would have gone into a docblock as a measured claim and been false. 18 mutations caught, 4 deliberate survivors each argued, and the unreproducible eleven-band figure withdrawn and replaced by a twelve-band partition **checked mechanically for completeness** (444 files covered, 444 files in `tests/`): 3930 tests, 137,561 assertions, skipped=0. |
| F-19 | High | CODE_GAP | `OPEN` — **rejected twice**, in rework | The rework survived the container restart as a commit; its author was killed before reporting, and the verifier judged it on the commit message alone. **Round two's blockers 1, 2 and 4 are closed** — the basket is derived from the order's *lines* now, and the verifier established the rule is sound **as counting** because `services.order_item_id` carries an unconditional UNIQUE index, so two services on one line cannot exist; the reason trim moved to `TransitionOrder` and **the boundary itself is pinned** (250 → 255 fails); all twelve `OrderStatus` cases now have a writer and `completed_at` is stamped and pinned. It also verified **no oracle was weakened** by diffing every removed line in all five test files. **Rejected on two constructions, the first of which is a measured regression on the surface this commit added.** The "never built" evidence is the absence of a `virtual_machines` row gated on `status ∈ {Pending, Failed}` — and I confirmed both the constant and the routing myself. A VPS create that **reached the hypervisor and lost the answer** leaves the service `failed`, so `DELETE /api/admin/services/{id}` now answers **202**, writes `terminated`, closes the order as terminal, sells the plan unit on and queues **zero** destroy jobs — where the parent answered 409 and moved nothing. **Shared hosting is worse**: it never has a `virtual_machines` row at all, so every Pending/Failed hosting service passes the test by construction — the node slot leaks for ever and the panel account is left behind, against `CreateHostingAccountHandler`'s own comment that releasing that slot *"would put a second customer's files onto space the first one is already using."* **And the platform already has the right predicate one file away**: `RetryProvisioningJob::whatItBuilt()` refuses a retry when `result.provider_reference` or `remote_job_id` is set. Second blocker: **Dedicated has no ending surface at all** — the controller branches on `Dedicated` before the fixed arm, `DecommissionDedicatedServer` still refuses when no chassis row points at the service, and the sweep never arrives, so the order sits in the operator's queue for ever and the plan unit is gone for the life of the plan. |

### Wave 3 — DNS, network, platform security

| ID | Sev | Class | Status | Notes |
|---|---|---|---|---|
| F-11 | High | DATA_INTEGRITY + TEST_GAP | `OPEN` — **rejected**, in rework | Four rounds, each finding a door inside work the previous round declared complete. Round four was briefed to assume a tenth door and **found a tenth and an eleventh**. The tenth is the finding's own subject matter: `CloudflareDnsProvider::payloadFor()` sends `data` and **no `content`** for CAA, `records()` reads `content` as `''`, and every identity comparison in the module compares `content` byte-exactly — so the two sides of the adapter hold two representations of the same record and **no comparison can bridge them**. Measured through the repository's own Cloudflare simulator: a CAA record the platform published correctly is reported as a **Critical `MissingAtProvider` plus an `OrphanAtProvider`, both against the same provider id, on every sweep for ever**, and republishing it creates a duplicate. The repository pins **both** shapes in this same commit and never compares them, and two docblocks the commit added contradict each other on the point — both true, of different sides of the wire, with nothing saying so. The eleventh: `ReconcileZones::settle()` reads `indeterminate_after` in the absence arm and **ignores it eight lines above in the presence arm**, so a delete that timed out and did *not* land marks the record the customer asked to remove as `active`, stamps the provider's id on it, clears the evidence and reports zero drift. Three of four quadrants are covered; the missing fourth is that one. The verifier's own enumeration reached ~32 comparison sites against the inventory's 24, and it **upheld** the deliberate `find()` divergence — including against the identifier-as-fallback variant it judged the likelier edit — plus M13, `IndeterminateAfter::Publish`, the deleted message, the index's case-sensitivity and the pagination fix. It also found 3 of the 6 arms of the new `contentIsCaseInsensitive()` classification unpinned, and that `docs/dns.md`'s "465 tests green" is stale in the tree that ships it. |
| F-26 | Medium | DATA_INTEGRITY | `OPEN` — in rework (short; **no reservation blocking**) | **UPHELD WITH RESERVATIONS**, none blocking. The verifier reverted `ClaimZone` alone, ran its own 54-row probe through the route, and **proved the pre-fix 201 was not cosmetic** — `findZone('db.panel.lynomia.test')` non-null, i.e. the zone really was created in the platform's own provider account, and `zoneFor()` resolving to the customer's zone. Post-fix it could construct no name under a reserved zone that is accepted. 22 mutations against the **shipped** suite with its own probes moved out of the tree: 17 killed, 4 of the 5 survivors provably equivalent. It confirmed the implementer's document arithmetic exactly and found the finding's *"three documents"* wrong in direction too — at most two said children and five statements said parents, so **most of the prose agreed with the defective code**. It settled both adjudications: the sibling limit stays (it measured that the `->parent()` alternative is genuinely unsafe — a platform at `portal.github.io` would reserve `github.io` and, through the new downward rule, refuse every customer zone under it), and customer-to-customer sub-delegation is **its own finding**, on the ground it established independently that `PublishRecord` writes into the record's own `provider_zone_id` and `DnsRecordRules` confines records to their zone, so there is **no cross-tenant write primitive inside the platform**. Reworked for prose: the derived default is **silently empty for the exact configuration `.env.example` ships** while three documents say unconditionally that a fresh install is not an unprotected one; a typo'd `APP_URL` is skipped the same silent way the commit forbids for configured entries; one of the six documents listed as corrected was not edited; and the headline justification (`zoneFor()`) has **zero callers in `src/`**. |
| F-28 | Medium | SECURITY | `OPEN` — verification queued | Console socket TLS sourced from the global key while every sibling is per-cluster. **The finding is correct and the defect is worse than the sentence says: it is bidirectional.** The sharpest statement of it is internal to the method — twenty lines above the defect, `consoleEndpoint()` parses the host, port and scheme **it will dial** out of the cluster's own row, then took the **certificate policy for that host** from a fleet-wide environment variable. Host per-cluster, policy global, in one object describing one socket. Reproduced through `AuthoriseConsoleConnection::execute()` — the action the gateway's socket loop calls — with two Proxmox clusters in one estate: a production cluster asking for verification had it waived by the fleet switch (**security**), and a lab cluster waiving it could not open its socket at all (**functional**). The per-cluster/global inventory yields a clean discriminator the implementer states and I accept: **every provider for which the platform holds a table of endpoints has a per-row flag; Cloudflare has no table, one SaaS host, and a global key is the only thing there is to read — so the console was the single anomaly.** It measured the TLS behaviour on the installed PHP against a self-signed listener on loopback rather than asserting it: `ssl://` defaults verification **on**, and with both flags off it completes a handshake against any certificate at all — and an unverified console socket carries the cluster's full API token plus a root console vncticket. **Three rules were survivors when it arrived and are not now**, including `ComputeProviderFactory`'s `verifyTls: $cluster->verify_tls` — *the line whose own comment says "the row is the only thing that can waive verification"* — which had **no test at all** (mutated to `true`, the pre-existing suite ran 180/180 green). One survivor remains, `verify_peer_name`, argued unpinnable from this surface with the remedy named. A methodology note worth keeping: its first certificate fixture used the wrong CN, so the peer-name check masked the chain check and a mutation survived — it caught that only by enumerating the two flags separately. |
| F-29 | Medium | SECURITY | `OPEN` — in verification, fourth round | All four blockers closed with first-fail proofs, and **the first turned out wider than I described it**: the leading-separator strings were only part of it — `bmc.2130706433` and `bmc.0x7f000001` were accepted with **no separator trick at all**, because one alphabetic label in front of an address is enough to make *"every label numeric"* answer no. `isNumericForm()` now asks about the **last** label, which is the sentence its own docblock had carried since it was written. On my instruction to reconcile with `DomainName`, it **took the rule and refused the class**, with three reasons: that class refuses a name of fewer than two labels while this surface must judge `whm01`; it refuses an all-numeric last label by *throwing*, while this surface must accept `10.66.0.2` and go on to ask what range it is in — *"a value object that cannot represent an address is the wrong instrument for a policy whose main subject is addresses"*; and it raises out of the **Dns** module, which depends on Shared rather than the reverse. A copied rule drifts, so a test **runs both rules against every shape both judge and fails if they disagree**, asserting the one deliberate difference as a difference. The AAAA gap is closed on both halves — the resolver unions the system lookup with `dns_get_record(DNS_A|DNS_AAAA)`, and an empty answer is now a refusal, which is the half that carries the weight. The third road is guarded at `DedicatedProviderFactory::connectionFor()`. And the suite no longer touches real DNS: the stub binds in `TestCase` rather than file by file, *"because 'the files somebody remembered' is the shape of defect this finding is about"*, and it **throws** on an unlisted name rather than answering `[]`, since an empty answer is now a refusal and would otherwise put a plausible sentence on every unlisted name. 22 mutants, 18 killed. |
| F-31 | Medium | SECURITY | `OPEN` — in rework (**prose only**; no behaviour defect found) | Five verification rounds. Round five's opening line is the finding's current state: *"The code is sound. Every rule the commit wrote survived a 19-mutation sweep, my own exhaustive address sweeps, and an independent reproduction of R3 against genuine vendor code. I found **no behaviour defect**. I found **three false sentences**, one of which is the stated basis for leaving a security gap open."* It upheld the *"by construction"* no-op claim **independently and three ways**: by quoting both of `checkIp6()`'s validation gates; by closing an escape hatch the implementer had not named (a poisoned `-v6` cache key, proved impossible by the key's own shape); and by **885,452 probes returning 0 `true` and 0 throws** — the zero-throws half mattering as much, since it shows the skip removed no throw-driven refusal either. It also proved the canary design mathematically tight in both directions. **Held open on prose.** The sentence *"`control-plane.conf.j2` listens on IPv4 only"* is false — I verified the template myself: `listen [::]:80` and `listen [::]:443 ssl`, dual-stack in its only commit — **and the same file argues the opposite fact forty lines earlier**, since the IPv4-mapped canary pair exists precisely because a dual-stack socket presents IPv4 callers that way. The conclusion survives on nginx's default `ipv6only=on`, which is a default and not the guarantee claimed. A second sentence reassures that an IPv6 entry on a no-IPv6 build *"could not match anybody in any case"*; measured, the guard holds but `$request->ip()` throws **uncaught, outside the middleware's catch**, on every request from such a peer — a 500, the exact class the commit's own `/up` table exists to prevent. A third says a subclass *"cannot"* re-open the spoofing hole, naming one seam where four are open. **My ruling on the item the verifier asked me to adjudicate: F-31 closes with `TRUSTED_PROXIES=PRIVATE_SUBNETS` open — the decision is right and only the reason must change.** |
| F-33 | Medium | DATA_INTEGRITY | `OPEN` | Overlapping subnets accepted; same address issuable to two customers. No longer latent — F-02 made subnet creation reachable. |
| F-35 | Medium | OPERABILITY | `OPEN` — in verification, second round | The address check was unreachable whenever a template existed; fixed and pinned by a reachability oracle at the command's output, with no over-strictness found and both ways the finding understates confirmed. Round one held it open on two: an inventory stating a false absolute about the chain it holds up as the good idiom, and a durable rule not live on two of the five products it tabulated. **Both closed.** The rule now runs on all six products that have a chain — `gpu_compute` and `wordpress` were in the constant and in no estate shape, so **F-35's exact defect could be reintroduced for `gpu_compute` with the rule green**; both mutations now die, and the implementer confirmed by restoring the old test file that the two new shapes are the whole of the difference. The false absolute is replaced by a paragraph naming the one bare exit, its line number, the six exits that follow the idiom, and the reason it is not a false green — closing on the point that matters: *"A sentence the code contradicts is worse than the drop it was hiding, because the next reader stops checking."* The blind spot is now stated with its **measured size** (of 8 shapes the rule asserts on 6; of 12 products, the 6 with a chain; of every band carrying FAIL or BLOCKED, none — *"That includes F-35's own estate"*). And it added the contrast nobody asked for that turns the blind spot from a hole into a choice: the *same* guarded drops, moved so they turn a red band **green**, both die. The rule is blind to drops that leave a FAIL standing, not to guarded drops. 22 mutations, 18 dead, 4 survivors all accounted for. |

### Wave 4 — customer-facing correctness

| ID | Sev | Class | Status | Notes |
|---|---|---|---|---|
| F-20 | High | CODE_GAP | `OPEN` | `data_destroyed` rendered on Dedicated and on no VPS screen; strings absent from both locales. |
| F-21 | High | CODE_GAP | `OPEN` | Registration dead-ends silently; Create-account stays enabled because `registrationClosed` tests `=== false` on `undefined`. |
| F-27 | Medium | SECURITY | `OPEN` | `error.details` publishes `$e->context()` verbatim to customers on two live routes. |
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

## Discovered / out of scope

Defects found while repairing a finding, recorded rather than numbered. Repaired
immediately only when closing the active finding truthfully requires it, or when
this program introduced them.

| Description | Evidence | Related F-ID | Blocks? |
|---|---|---|---|
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
