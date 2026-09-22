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

### Three isolation defects this program had to find the hard way

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
| F-04 | Critical | CODE_GAP | `OPEN` — in rework | Password, contact-email and domain limbs closed and mutation-verified. The user settled the escalated decision — **the customer is asked for the domain at checkout** — and the uniqueness hole that fix opened is now closed by a **partial unique index over live accounts**. Round three returned **UPHELD WITH RESERVATIONS**: *"Both round-two gaps are genuinely closed."* The verifier's method is worth recording — it attacked the constraint three ways and **noticed that two of its three attacks proved less than they looked**, because a node row lock serialised both transactions so the index never fired; it then isolated the index with two raw `psql` sessions overlapping on an uncommitted tuple and got the real violation. It read the predicate from `pg_indexes` rather than the source, and checked the ownership section by reading every cited source, finding **no invented verification flow**. Eight reservations, two reachable: an idempotency digest that hashes the raw domain rather than the canonical one, so a retry differing only in case or a trailing dot is refused as a conflict that does not exist; and a re-arm path outside the catch that classifies the index's refusal as retryable — the exact classification the commit argues it must never be. |
| F-13 | High | CODE_GAP | `OPEN` — in rework | Privilege map derived from the calls the adapter makes, bounded by the platform's own Ansible role. Round three **re-derived the twenty-privilege accounting independently**, privilege by privilege against the adapter's actual calls, and confirms it: 13 demanded + 6 omitted + 1 outside. On the question I said would decide the finding — whether removing an assertion was a test rewritten to fit — it returned **stronger, and proved it three ways**, including tracing the assertion's history to establish it was **authored by the very commit that introduced the defect**, so it was never a pre-existing invariant. It accepted both judgement calls, and corroborated the implementer's tooling-incident disclosure by hashing its leftover backups against the real git objects. **Held open for a fail-open the repair itself created**: `Sys.Audit` — the privilege moved *out* of the map to make the arithmetic close — is pinned by nothing, and deleting its check entirely survives 1209 tests while letting VPS be declared ready on a token holding no audit privilege at all. |
| F-14 | High | CODE_GAP | `OPEN` — **rejected four times** | Five rounds, each closing a door and leaving an adjacent one. Round five confirmed real progress: `firstString()` is gone from the file entirely and **no first-match read remains on any path to `valid: true`**; nothing the verifier broke survived, every gate having a catcher that names a business consequence; **no inert case remains** in any provider, audited case by case against the gate that actually fires; no defect-pinning test survives a revert (45 of 268 fail across 29 methods, the survivors all declared positive controls); the duplicate-key repair is protected twice, independently; and the previously unenforced decoupling decision is pinned and sole-caught. It is also the first round that **did not overclaim** — it withdrew the exhaustion claim and offered only the structural guarantee it could prove. **Rejected anyway, on my adjudication**, for a tenth door: `parse_str` keeps only the last occurrence of a repeated scalar key, so `status=expired&status=active` and `expires=2020-01-01&expires=2099-01-01` sell the node, and `error=1&text=License+expired&error=0&…` discards the panel's own failure envelope. Which way it falls is purely positional. See the adjudication note below. |

### Wave 2 — concurrency, lifecycle, authorization

| ID | Sev | Class | Status | Notes |
|---|---|---|---|---|
| F-08 | High | CONCURRENCY | `OPEN` — in verification | `retry_after` below every supervisor timeout; seven `payments` listeners re-executing concurrently with themselves. Fixed at two layers, then reworked against seven reservations — **two of which were defects in code that moves money**. The refund doubling is measured rather than argued: `amount_refunded_minor` 3,000 → 6,000 on a partial refund, so the invoice states 6.000 KWD went back to a customer who received 3.000, and the generated `amount_due` column agrees with it. The deployment escape hatch was closed **both** ways — documented in `.env.example` and asserted in production at worker start — on the argument that the two fail differently. The rework also corrected the previous round's own diagnosis on a smaller point: Horizon keys a process count per *pool*, and pool count depends on `balance`, so the suggested "fail loudly on more than one queue" would have been wrong half the time. **132 sentences checked, 11 claims corrected at 12 sites**, seven found by the implementer itself. |
| F-12 | High | DATA_INTEGRITY | `OPEN` — in rework | Both limbs closed: the address returns through the existing quarantine path and the departing customer's PTR authority ends. Three verification rounds, the first two each finding a defect the previous repair had introduced. Round three returned **UPHELD WITH RESERVATIONS** and is the strongest result on this finding: the verifier reproduced both limbs on the parent with its own probe through the operator endpoint, established that **23 of 27 added tests are genuine oracles** and the four survivors are deliberate controls, reproduced all four claimed sole-catchers at its own rebuilt baseline — so the implementer's mutation table survives the harness failure it had disclosed — and gave the mechanism for each of the three documented-inert lines rather than resting on an empty mutation result. It also **proved the implementer right to refuse the previous verifier's `>=`**: under `>=` the later row cannot start either, so the address is held for ever. It then built the tie three ways, including an adversarial partner ULID sorting below the original, and got exactly one starter each time. Reservations: a third false comment claim, two invariants promised and not pinned (the same half-pinned shape the implementer had named as its own blind spot), one unpinned state-machine assertion, and one latent pre-existing shape — a machine that fails while the customer is still on it cannot be decommissioned at all. |
| F-15 | High | CODE_GAP | `OPEN` — in rework | A stable creation identity is reserved on the job row before the call that may build, and a retry asks the hypervisor whether the machine exists. Three verification rounds, each finding a defect the previous repair introduced. Round three reproduced the original defect with its own provider decorator (two VMIDs, two machines) and confirmed the implementer's account that `a_retried_job_does_not_commit_capacity_twice` passed on the parent **while building the customer a second server**. **All 17 of its mutations were caught**, 21 of the 22 added tests die on a revert, and on the changed oracle — the most dangerous artefact in this programme — its verdict was *"a correction of a test that was wrong, not a test rewritten to accommodate a fix"*, proved empirically rather than argued. **UPHELD WITH RESERVATIONS** with an eighth door that fails **open**: the reservation records a VMID and a node list but no cluster, so a job repointed between attempts builds a second machine on the second cluster — the cluster-*deleted* case was anticipated and fails closed, the cluster-*changed* case was not. |
| F-18 | High | AUTHORIZATION | `OPEN` — in rework | Hosting destruction control inverted. **The security conclusion is upheld on the evidence**: the round-three blocker reproduced exactly, an instrumented 28-row probe run under both guard orders showed exactly one line differs and **no cell moves between REFUSED and ALLOWED**, the sweep still destroys what it should and is idempotent across three runs, and 903 focused tests are green with `skipped=0`. All nine of the implementer's self-found corrections were checked individually and **all nine stand**, including a trace of `suspended_at` through the file's whole history. The verifier also adjudicated *in the implementer's favour* on redundant-vs-dead, finding the previous round over-broad. Held open for two defects that are not prose: a prescribed operator repair query that over-matches and would cancel a still-running retention window, and a drift test that pins the rate rather than the magnitude while its own docblock claims otherwise. |
| F-19 | High | CODE_GAP | `OPEN` — in verification | Nine of thirteen `OrderStatus` states unwritable; two exclusion clauses unreachable; `completed_at` never stamped. Verification found three blocking gaps including a red branch; all five reproduced and corrected. `Terminated` is now reachable from every state a paid order can occupy, and the race was closed by widening the table rather than by moving the dispatch — the argument being that a *derived* status must describe every state an order can be found in, and narrowing a window between two independent workers is not closing it. Per the user's decision, a full refund now records the money and nothing else: the plan unit and the coupon hold are released when the **service** ends, counted per line, so a basket gives back the machine that ended and holds the one that did not. **Not faking the queue then exposed two production defects in the previous commit**: a listener method named `failed()` collides with Laravel's `CallQueuedListener::failed()`, which hands a three-event listener the wrong event and turns any failure into a `TypeError` — measured as a 500 on the operator retry route; and the derived reason overflowed `order_transitions.reason` (`varchar(255)`), so a capacity refusal threw `SQLSTATE[22001]` five times and the order never learned what happened to its machine. Whole suite 3932/3932. One weakening acknowledged rather than hidden: `ProvisioningFailed → Active` is now permitted, because the guard was in the wrong place and cost an operator's successful retry its order. |

### Wave 3 — DNS, network, platform security

| ID | Sev | Class | Status | Notes |
|---|---|---|---|---|
| F-11 | High | DATA_INTEGRITY + TEST_GAP | `OPEN` — in rework | The eighth door is genuinely closed — the verifier reproduced both shapes itself and confirmed they move. It proved the *"strictly finer within a listing"* claim exhaustively: 460,320 pairs, and every apparent merge was a pair sharing an identifier, impossible inside one listing. **Held open because the load-bearing sentence is false**: `ReconcileZones::find()` *is* a fourth comparison that disagrees with the new key — correctly, because noticing that the record under a known id has changed is reconciliation's whole job — but nothing says so, and doing the one-line "unification" the commit message invites leaves **465 tests green** while turning a customer's hijacked record from two drifts into zero. A ninth door held shut by nothing. Also: the 27-site inventory exists only in a report and not in the repository, which is the same failure mode as the docblock that hid the eighth door. |
| F-26 | Medium | — | `OPEN` | Reserved-zone guard protects the name and its parents but not its children; empty default. |
| F-28 | Medium | — | `OPEN` | Console socket TLS sourced from the global key while every sibling is per-cluster. |
| F-29 | Medium | SECURITY | `OPEN` — in rework | All seven families confirmed and refused; the round-one loosening closed with one `::/8` entry. Round two is the most rigorous verification of this programme: it **re-derived the subsumption independently** and showed it is *forced* rather than empirical, since every removed v6 prefix fixes its high byte to `0x00`; probed **16,000 random addresses** for over-strictness and found none in ordinary address space; transcribed the **whole IANA IPv6 special-purpose registry by hand** and confirmed no sibling row is missed and every kept range does real work; killed **19 of 19** mutations; and found all three added tests are sole catchers. **UPHELD WITH RESERVATIONS anyway**, for two holes of the same class the repair left standing — see the discovered table. Its closing position, which I accept: *"I would not close F-29 while 2 and 3 are open."* |
| F-31 | Medium | SECURITY | `OPEN` — in rework | `env()` resolved before the environment loads, so the setting never took effect. Three verification rounds. Round two established the mechanism nobody had pinned: **how the variable is delivered decides whether the bug bites** — an early `env()` still sees a value already in the process environment, and fails only for one living in the `.env` file, which is the production case under `clear_env = yes`. Round three reproduced that asymmetry in its strict form, confirmed the corrected probe's consequence assertion now fails on defective code, verified the cached-config coverage is real rather than flag-and-assert, found **no over-strictness across 37 legitimate entry forms**, and endorsed the nginx adjudication (*"I would not have decided it differently"*). **UPHELD WITH RESERVATIONS** — all six are oracle gaps rather than defects in shipped code, but three of them would let the finding return with nothing going red: whitespace, multi-entry splitting, and a catch-all provider that is entirely one-sided, with no row asserting a legitimate CIDR is honoured. |
| F-33 | Medium | DATA_INTEGRITY | `OPEN` | Overlapping subnets accepted; same address issuable to two customers. No longer latent — F-02 made subnet creation reachable. |
| F-35 | Medium | — | `OPEN` | Preflight's address check unreachable whenever a template exists. |

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

Re-confirmed by enumeration at F-04's third verification: exactly these three,
no more. Later migrations on the same branches deliberately avoided the
timestamp — `remediation/f11` chose `2026_04_16_000000` and `remediation/f04`'s
second chose `2026_04_24_000000`. Implementers have been told **not** to rename
the colliding three themselves, because a rename on one branch would conflict
with the other two's history; the coordinator does it once, at integration.

Nothing is broken by this — the three touch unrelated tables, there is no
dependency between them, and Laravel orders by filename so the sequence is
deterministic. But a timestamp is a record of when something was written, and
three files claiming the same instant is a record that is false and that makes
the history harder to read than it needs to be. They are renumbered to distinct
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
| **Programme infrastructure, now repaired.** The first `mkworktree.sh` symlinked the whole `vendor/`. Composer's autoloader hard-codes an absolute `$baseDir` and PHP resolves `__DIR__` through symlinks, so every worktree loaded the canonical PSR-4 map; then one agent's `composer dump-autoload` wrote through the symlink and repointed `$baseDir` at that agent's worktree. For roughly twenty minutes every other worktree — and the canonical checkout — executed one agent's uncommitted source while reporting its own suites green. Caught by a reviewer when a deliberate breakage failed to fail. Every affected agent re-measured; no conclusion changed. | `vendor/composer/autoload_psr4.php:6` (repaired), `scratchpad/mkworktree.sh` | F-41 (same family: the suite's outcome depending on ambient state rather than on anything Git has) | No, once repaired |
| **Programme infrastructure, live.** `git stash` is not worktree-local — the stack lives in the common `.git` directory. Two agents' stashes crossed: one popped the other's work into its tree. Both recovered. No agent may use `git stash`; `git show <rev>:<path>` and `git checkout HEAD -- <path>` are the worktree-local alternatives. | dangling stash commits `5329291`, `a7be338` (both superseded by committed work) | F-41 (adjacent) | No |
| **F-17's regression guard is narrower than the finding.** `TheInvitationLimiterCannotBeRotatedByAHeaderTest` pins the limiter's *key* by calling the closure directly. Nothing pins that the limiter is attached to the routes: deleting both `throttle:team-invitations` middleware lines from `routes/v1/team.php` leaves 17/17 green. F-17's outcome — an ordinary customer turning the platform into an unbounded mail relay — is reachable again by a one-line route edit the suite would not notice. | `routes/v1/team.php:37,42`; `grep -rn "team-invitations" tests/` returns one file | F-17 (its own closure) | Yes — F-17 is not safely closed until its oracle covers attachment as well as keying |
| **Programme infrastructure, repaired on the trunk but not on every branch.** `mkworktree.sh` hands each agent a Redis index, but `WorkerHarness::REDIS_DATABASE` was a hard-coded `15` and `setUp()` overrode the ambient `REDIS_DB` with it; each spawned worker subprocess was handed `15` explicitly as well. Every agent's real-worker traffic therefore shared one index, flushing each other's queues and popping messages for rows they could not see. All seven golden-path `Simulation` files inherit from `WorkerHarness` and were affected too. Fixed at `0a3bc09` — **after seven remediation branches had been cut**, so their Queue and Simulation numbers were load-dependent. Each affected agent has been told to cherry-pick it and re-run or re-label. | `tests/Feature/Queue/WorkerHarness.php:60,159,496,537`; `git merge-base --is-ancestor 0a3bc09 remediation/<slug>` false for f04, f12, f13, f14, f15, f29, f31 | F-41 (same family) | No — but no Queue or Simulation figure from a branch lacking `0a3bc09` is evidence |
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
| The assertion *tally* for a combined `Orders + Security + Architecture` run is not a stable measurement: two architecture tests enumerate `get_declared_classes()`, so the count depends on autoload order. Test counts are stable; assertion counts jitter. | `tests/Architecture/EveryPreparedCategoryHasAContractTest.php`, `EveryCompleteProductHasARealAdapterTest.php` | none | No |
| `SecretRedactor::isSecretKey()` matches by substring and `security.redacted_keys` contains `pan` (card PAN), so `metadata.panel` is stored as `[redacted]` and an operator cannot read which control panel an account was built on. Any future key containing `pan`, `auth`, `card` or `token` as a substring is eaten the same way. | `src/Modules/Shared/Infrastructure/Logging/SecretRedactor.php:40,177-191` | F-45 family | No |
| Shared-hosting checkout never asks for a domain: `PlaceOrderRequest` has no domain field and `CheckoutLine` carries only `planId`/`quantity`. | `src/Modules/Orders/Http/Requests/PlaceOrderRequest.php:46-71`, `src/Modules/Orders/Application/DTOs/CheckoutLine.php:16-22` | F-04 (its own domain limb) | Adjudication pending — decides CLOSED vs PARTIAL |
| `ProviderCatalogue` declared Proxmox capable of `reinstall` and `templates` while the tester could never report either, and no gate checks that a driver with a tester can in principle be *assessed* on the capabilities its catalogue claims. | `src/Modules/Providers/.../ProviderCatalogue.php:65` vs `ProxmoxConnectionTester.php:73-83` | F-23 / F-24 (mechanism) | No |
| `ManagedServer` and `DedicatedServer` are two unrelated machine inventories, both presented as "servers" on the operator surface. | `src/Modules/Infrastructure/Infrastructure/Models/ManagedServer.php`, `src/Modules/Dedicated/Infrastructure/Models/DedicatedServer.php`, `routes/api_admin.php` | none identified | No |
| `DELETE /api/admin/infrastructure/templates/{template}` is the only hard delete on the inventory surface; a paid service carries its template's id in `resources.template_id`. | `src/Modules/Compute/Http/Controllers/VmTemplateController.php` | F-02 (adjacent; pre-existing) | No |
| The browser suite has no guard against a spec leaving the shared seeded estate ambiguous for the specs after it. One spec did, and it surfaced as four unrelated money journeys failing. | `apps/web/playwright.config.ts` (`workers: 1`), `apps/web/e2e/support/helpers.ts` | F-42 (test architecture) | No |
| Two-factor authentication is opt-in per user with no policy that can require it of staff; an operator holding `role.manage` may have no second factor. | `src/Modules/Identity`, operator list surface | F-46 (account-security notifications) | No |

## History

| Date | Event |
|---|---|
| Entry | HEAD `6a7583523833ecdaea84136fdb0eb1c52ff4f121`; 10 findings accepted closed; 36 open; F-45 deferred to adjudication. |
| Wave 1 + early Wave 3 | Nine findings implemented and put through independent red-team verification. **Not one was accepted as submitted**: two rejections (F-14, twice) and five "upheld with reservations". Every rejection reproduced the original finding's own outcome against the repaired code. |
| Programme infrastructure | Two isolation defects found and fixed mid-flight, both of the same family as F-41. A shared `vendor/` autoloader had every worktree — and the canonical checkout — executing one agent's uncommitted source while reporting its own suites green; caught only because a deliberate breakage failed to break anything. A shared Redis index had the real-worker suites deleting each other's queued messages and blaming the change under test; three agents found it independently and one proved it by moving to a private index and watching 15 failures become none. `git stash` is also shared across worktrees and is now prohibited. |

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
