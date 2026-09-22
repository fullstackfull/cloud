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

### Five isolation defects this program had to find the hard way

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
| F-04 | Critical | CODE_GAP | `OPEN` — in verification, fourth round | Password, contact-email and domain limbs closed and mutation-verified; the user settled the escalated decision (**the customer is asked for the domain at checkout**) and the uniqueness hole that fix opened is closed by a partial unique index over live accounts. Round three returned UPHELD WITH RESERVATIONS with two reachable defects, and the rework closed both. **R3, customer-reachable**: the idempotency digest hashed the raw domain while `order_items.domain` stores the canonical one, so a retry differing only in case, a trailing dot or a leading space was refused as a changed basket — reproduced on all four spellings, all producing the byte-identical stored name. The root cause was three copies of one rule and a fourth reader that needed it; there is now one `CheckoutLine::canonicalDomain()` and a grep proving it is the only domain canonicaliser in the module. **R4, operator-reachable**: the re-arm `update` sat outside the catch, so a lost race threw `UniqueConstraintViolationException` raw, and the implementer traced the consequence rather than asserting it — `classifyUnknown()` with a null `remote_job_id` returns `FailureClass::Transient`, i.e. the index's refusal was classified **retryable**, the exact classification the commit argues it must never be. On R2 it chose **both** remedies over my either/or, on the argument that correcting the claim alone leaves the edge untested and adding the twin alone leaves a docblock contradicting its own test. Three of R6's survivors are now caught, and it **flagged that one of the three is caught only incidentally** — deleting the in-transaction check changes no observable refusal, because the index and its catch produce the identical exception, so what the mutation actually removes is the read its race test hangs its interleaving on; it reordered that test's assertions so the failure reports the edit accurately instead of blaming the action. 33 claims re-read, 6 corrected, one of them wider than its test and found by R4 rather than by me. |
| F-13 | High | CODE_GAP | `OPEN` — in rework | Privilege map derived from the calls the adapter makes, bounded by the platform's own Ansible role. Round three **re-derived the twenty-privilege accounting independently**, privilege by privilege against the adapter's actual calls, and confirms it: 13 demanded + 6 omitted + 1 outside. On the question I said would decide the finding — whether removing an assertion was a test rewritten to fit — it returned **stronger, and proved it three ways**, including tracing the assertion's history to establish it was **authored by the very commit that introduced the defect**, so it was never a pre-existing invariant. It accepted both judgement calls, and corroborated the implementer's tooling-incident disclosure by hashing its leftover backups against the real git objects. **Held open for a fail-open the repair itself created**: `Sys.Audit` — the privilege moved *out* of the map to make the arithmetic close — is pinned by nothing, and deleting its check entirely survives 1209 tests while letting VPS be declared ready on a token holding no audit privilege at all. |
| F-14 | High | CODE_GAP | `OPEN` — in verification, **round seven**, rejected five times | Round six rejected `3b29308` for a door inside a rule that commit wrote — `isAmbiguousSlashDate()` matched `/` only while `-` and `.` are read as d-m-Y — and handed the leading-NUL question up to me rather than guessing it; I ruled it in on the implementer's own *destroyed versus merely unread* criterion, and told it the **route** (pairs-sent versus pairs-parsed) without the implementation, because a rule keyed on the literal spelling `%00` will be wrong for the next spelling. The rework **accepted all seven items and refused none**, and improved on the instruction in one respect it argued for: the count is taken over the **whole body** rather than the declared key list, because a pair that vanishes has no key to compare, so a per-key version of it cannot exist. It rewrote the `max_input_vars` guard in terms of the same helper so the two guards **cannot disagree about what a field is** and let a body fall through the gap between them. On the ambiguity rule it required the same separator twice via a backreference, on the argument that a value punctuated two ways is not a date PHP reads at all and matching it would claim a judgement the rule has not made; and it checked the accepted set **before** widening, which is the discipline that was missing when my own literal instruction would have refused every DirectAdmin account listing in the fleet. On D3 it **declined to drop the rule** and checked the other direction first, finding the rule load-bearing and the docblock false in *both* directions. 17 mutations, all caught, including re-runs of all four previous survivors — and it reports honestly that **two die on PHP's own warning surfacing before the sentence assertion runs**, rather than counting them as clean catches. 974 tests across five bands, skipped=0. |

### Wave 2 — concurrency, lifecycle, authorization

| ID | Sev | Class | Status | Notes |
|---|---|---|---|---|
| F-08 | High | CONCURRENCY | `OPEN` — in rework | `retry_after` below every supervisor timeout; seven `payments` listeners re-executing concurrently with themselves. Round two's verification is the most thorough this programme has produced and it took nothing on trust: it wrote its **own** probe — not the branch's — with no `sleep`, two real `queue:work` OS processes, the real listener on the real Redis queue, and a third connection holding `SELECT … FOR UPDATE` on the exact row to make the job slow honestly, and reproduced *one declined card counted twice*. It **re-measured the unscaled figures the implementer said it had not**, getting three executions with 28.8 s and 28.0 s overlaps. It reproduced the refund doubling 3,000 → 6,000 on the parent and confirmed it closed, re-derived the Horizon pool-keying line by line and confirmed the implementer's correction of me was right, killed 15 mutations with named sole-catchers, and matched every claimed figure exactly (778 tests, skipped=0). **The defect is closed. The finding is not**, and the verifier put the choice to me rather than making it by omission — see the adjudication below. Held open on two doors in the new deployment check, both in the dimension the check exists to close: `horizon.environments` overrides `horizon.defaults` and is not read by either call site, so a three-line block there reinstates F-08 verbatim with a green build and a worker that starts happily; and the check validates the configuration rather than the invocation, so `queue:work redis --queue=provisioning` starts unrefused and reserves at 180 s under a 5,400 s job — a shape `scripts/serve.sh` ships. Also held: a green test named `provisioning_is_never_retried_by_the_queue` asserts a supervisor default that **loses** to the listener's own `$tries`, so the queue retries provisioning work 5 and 3 times against a written policy of one attempt; and the unguarded `null` in `RecordInvoiceRefund` is justified by an operator path that does not exist. |
| F-12 | High | DATA_INTEGRITY | `OPEN` — in verification, fourth round | Both limbs closed; three verification rounds, each finding a defect the previous repair introduced. Round four's deliverable was a **complete pinning inventory** — every guard, condition and filter added across all three rounds, classified by mutating it: **33 mutations**, six survivors found, five closed with tests and the sixth argued. It also **refused a test I asked for, correctly**: the state-machine assertion is not merely unpinned but *provably inert*, because `from === to` is a legal no-op by deliberate design for retry safety, so asserting that a second retirement is refused would put a 409 in front of the very retry-safety the no-op exists for. It recorded the inertness with its mechanism instead, and pinned the two table properties the inertness rests on so a change to either fails and points at the line. It also found the false comment was false in **both** orders rather than one, and closed the latent shape where a machine that dies while the customer is on it could not be decommissioned at all. |
| F-15 | High | CODE_GAP | `OPEN` — in verification | Four rounds, each finding a defect the previous repair introduced. Round three caught **all seventeen** of its own mutations and found an eighth door that failed **open**: the reservation recorded a VMID and a node list but no cluster, so a job repointed between attempts built a second machine on the second cluster. The rework records the cluster in the same statement as the id, and **refuses rather than follows** it — arguing the build would still go ahead on the payload's cluster and the handler cannot establish that whoever moved the job knew a call had been made. It also **removed the disk comparison entirely rather than retuning it**, on the argument that no tolerance covers both directions, since a legitimate observation ranges from the image's size to the plan's and the handler cannot tell which moment it sees. **31 claim-bearing statements checked, 14 corrected — eight of them its own new claims**, including a character count asserted without measuring and a remedy for which no route exists. |
| F-18 | High | AUTHORIZATION | `OPEN` — in rework | Hosting destruction control inverted. The security substance is closed and heavily pinned: round five reproduced **both** round-four blockers on the parent with its own twenty fixture shapes before confirming the repairs, independently confirmed the 8 → 2 figure, and caught 18 of 18 behavioural mutations. It returned UPHELD WITH RESERVATIONS and **named the fork rather than deciding by omission** — see the adjudication below; I took the other branch of it. Held open on R1: a clause the commit calls redundant **as a measured finding** in fact discriminates — ablating `ha.terminated_at IS NULL` returns a serving row carrying a stale stamp that the sweep is **failing on every night**, so the operator running the prescribed query concludes there is nothing to repair. Its stated reason is wrong independently of the fixture (`ReserveHostingNodeCapacity` re-arms a row without clearing the stamp, in the same class whose atomicity is cited), and the justification is backwards: the clause can only ever *remove* rows the status filter wanted. Also held: the deliberate one-character `<=` deviation is entirely unpinned; the reflection pin is **one-directional**, so a narrowing predicate true of both wanted rows can be added and nothing notices — in production `AND ha.ssl_status = 'active'` would silently drop every drifted account whose certificate is pending; a `--`-prefixed line passes the extractor but is a **syntax error in the operator's client**; and the mandatory manual step the docblock orders cannot be performed from the query's own output. |
| F-19 | High | CODE_GAP | `OPEN` — **rejected**, in rework | Nine of thirteen `OrderStatus` states unwritable; two exclusion clauses unreachable; `completed_at` never stamped. Round two **reproduced the original defect and all five round-one blockers against the parent first**, so the rejection is not a disagreement about what the finding is. It **upholds the widened table's design argument** — two independent workers do order the writes non-deterministically, and mutation M02 proves the widening is load-bearing rather than cosmetic — and found **no key/field mismatch**, with all 23 behavioural methods failing on a revert. It rejected for four reproduced constructions. The derivation reads **Service rows rather than the order's lines**, and `ProvisionOrderedService` creates those one at a time inside the fulfilment loop: a two-line basket whose second line becomes unplaceable reaches `active` with `completed_at` stamped and `needsAttention()` false over a line that was never built — and **which way it falls depends on `$order->items`' order, because the relation has no `orderBy`**. The same seam throws an unguarded `IllegalStateTransitionException` out of a queued listener, against a docblock saying that is precisely what asking `canTransition` avoids. The reason-overflow the commit fixed is **still present in a sibling writer** (`UnwindTheOrderOnFullRefund`, `SQLSTATE[22001]` at 250 characters, inside a `tries=5` payments listener). And F-19's own money consequence survives on three reachable paths: a permanently failed build holds its plan unit for ever with **no production surface able to release it** — `TerminateVpsService` refuses, the admin route answers 409, `EndExpiredServices` sweeps only `suspended` and swallows the throw — while the branch's own oracle reaches that state by calling `TransitionService` directly, a seam no operator has. The new `PlanCapacity` docblock says a line is released *"for as long as nothing has been built for it"*; the implementation codes that as *no services row exists*, and fulfilment always creates one, so the clause is **dead for every paid order**. Three rules survive the entire focused regression unpinned: `isDelivered()`, the payment-failure guard, and the coupon-hold half. |

### Wave 3 — DNS, network, platform security

| ID | Sev | Class | Status | Notes |
|---|---|---|---|---|
| F-11 | High | DATA_INTEGRITY + TEST_GAP | `OPEN` — **rejected**, in rework | Four rounds, each finding a door inside work the previous round declared complete. Round four was briefed to assume a tenth door and **found a tenth and an eleventh**. The tenth is the finding's own subject matter: `CloudflareDnsProvider::payloadFor()` sends `data` and **no `content`** for CAA, `records()` reads `content` as `''`, and every identity comparison in the module compares `content` byte-exactly — so the two sides of the adapter hold two representations of the same record and **no comparison can bridge them**. Measured through the repository's own Cloudflare simulator: a CAA record the platform published correctly is reported as a **Critical `MissingAtProvider` plus an `OrphanAtProvider`, both against the same provider id, on every sweep for ever**, and republishing it creates a duplicate. The repository pins **both** shapes in this same commit and never compares them, and two docblocks the commit added contradict each other on the point — both true, of different sides of the wire, with nothing saying so. The eleventh: `ReconcileZones::settle()` reads `indeterminate_after` in the absence arm and **ignores it eight lines above in the presence arm**, so a delete that timed out and did *not* land marks the record the customer asked to remove as `active`, stamps the provider's id on it, clears the evidence and reports zero drift. Three of four quadrants are covered; the missing fourth is that one. The verifier's own enumeration reached ~32 comparison sites against the inventory's 24, and it **upheld** the deliberate `find()` divergence — including against the identifier-as-fallback variant it judged the likelier edit — plus M13, `IndeterminateAfter::Publish`, the deleted message, the index's case-sensitivity and the pagination fix. It also found 3 of the 6 arms of the new `contentIsCaseInsensitive()` classification unpinned, and that `docs/dns.md`'s "465 tests green" is stale in the tree that ships it. |
| F-26 | Medium | DATA_INTEGRITY | `OPEN` — in verification | Reproduced through `POST /api/v1/dns/zones`, not against the guard in isolation: three names beneath a reserved zone returned 201 and `PublishZone` created the zone **at the provider account the platform itself holds**. The guard asked `$held->isWithin($domain)` and never the converse. The implementer supplies **the argument the finding does not make**, which is the one that matters: `DnsProvider::zoneFor()` walks labels outward from the most specific, so the holder of `db.panel.…` is where a record for `x.db.panel.…` lands — measured against the simulator, and it **explicitly declined to claim anything about real authoritative-service precedence**, which the audit already files as real-infra-only. **The finding's count of three documents is wrong in both directions**: two said children, four said parents, and *none of the six was right about the whole rule* — including `config/dns.php`, which **contradicted itself inside a single sentence** (the clause promises children, the justification describes parents), which is probably how this survived review. On the empty default it rejected a literal default (citing `DnsSuffix`'s own "WHY THERE IS NO DEFAULT") and rejected refusing to boot, and **derives** from `app.url` and `app.frontend_url` with `DNS_RESERVED_ZONES` adding rather than replacing — on the ground that a deployment has already told the platform two of its own names and reading them invents nothing. It states the limit it does not close (a sibling host is unprotected without a public suffix list) and **gave that decision its own test row so a future change has to argue with it**. 13 mutations, 0 survivors — two survived a first pass and it says so with the diagnosis. It could not run PHPStan and said so rather than glossing it. |
| F-28 | Medium | SECURITY | `OPEN` — in implementation | Console socket TLS sourced from the global key while every sibling is per-cluster, and the socket carries the API token. Briefed against all three of this repository's secret rules rather than only the one the finding names — no plaintext in Git, logs or the database; credentials held by reference through the secret resolver; provider messages through `SecretRedactor`. A one-cluster fixture cannot distinguish a per-cluster lookup from a global one, so the oracle must be a two-cluster estate with different material. Adjacent and explicitly not this branch's to close: `ConsoleUpstream::socketAddress()` opens a raw `ssl://host:port` to an address derived from `compute_clusters.api_endpoint` with no endpoint-policy check at use — see the discovered table. |
| F-29 | Medium | SECURITY | `OPEN` — in verification | All seven families confirmed and refused; the round-one loosening closed with one `::/8` entry. Round two remains the most rigorous verification of this programme — subsumption re-derived as *forced* rather than empirical, 16,000 random addresses probed for over-strictness, the whole IANA IPv6 special-purpose registry transcribed by hand, 19 of 19 mutations killed — and it still returned **UPHELD WITH RESERVATIONS**, closing *"I would not close F-29 while 2 and 3 are open."* The rework closed both, and **neither fix is the one I prescribed**. For the `parse_url` rewrite I offered two candidate rules; the implementer fuzzed all 256 byte values in the host position and found each misses a case — refusing `/[\x00-\x20\x7F]/` misses the backslash, and the substring test misses both space and backslash and, run after the raw check, fires on nothing at all. So it split the defect in two: `\n` is a *rewrite*, the backslash is a *disagreement about where the host starts* (PHP reads `\169.254.169.254` as the host; WHATWG reads `\` as `/`, making the authority empty). Two independent rules, with `_` deliberately left legal so neither can catch the other's case and both stay falsifiable. It also found a **third write road I had not named** — `PUT /api/admin/infrastructure/hosting-nodes/{id}` with only `hostname` returned 200 with `169.254.169.254` — and **corrected the previous verifier's own finding**: the machine-address road refuses `\n` and `\t` but *not* the backslash, measured at 201 on the audited parent, which is why the fix had to be in two places. Rows written across the eight poisoned hostnames: 8 → 0. **It declines to declare F-29 closed**, and publishes a 24-row call-path inventory showing why: the class docblock's claim *"checked at registration and again at use"* was **false** for hosting nodes and compute clusters — seven paths dial with a credential attached and no second check. It corrected the docblock and `docs/security.md` rather than closing the gap, on the ground that a second check puts a DNS round trip inside provider construction. That gap, the AAAA blind spot and the NAT64 topology question are the verifier's to adjudicate. 20 mutations, 19 killed; the survivor (`$` vs `\z`) is argued unpinnable and said so in place. 1455 tests / 25785 assertions / skipped=0. |
| F-31 | Medium | SECURITY | `OPEN` — rework landed, verification queued | `env()` resolved before the environment loads, so the setting never took effect. Four verification rounds; the fourth verified the *"no code behaviour changed"* claim **mechanically** (`php -w` token hashes identical) and returned seven reservations, one of them a behaviour bug. **The rework accepted all seven and refused none**, and reproduced the blocker against **genuine vendor code** rather than by argument: it replaced exactly the one condition a `--disable-ipv6` build changes in `IpUtils.php`, left the real throw and everything else untouched, and drove the real middleware — `proxies()` returned `[]` for a plain IPv4 list, and the limiter suite went 26 passed → 18 passed, 8 failed, every failure a row asserting an entry *is* honoured, with no 500 and no log line. F-31 in full, from a compile flag. Vendor restored and byte-compared. It chose **not to ask the question** (skip an IPv6 canary pair when the entry has no colon) over narrowing the catch or letting the throw propagate, and argued both rejections: propagating reinstates the `/up` 500 that an earlier commit exists to fix, and *worse*, because the throw is unconditional on such a build; narrowing the catch leaves a useless probe and makes every IPv4 entry's survival depend on Symfony throwing one class from one site for ever. The no-op is established **by construction** — `checkIp6()` validates the entry with `FILTER_FLAG_IPV6` before touching a netmask, so a colon-free string could only ever return false — **and** by a 64-form corpus classifying byte-identically with and without the skip. It also closed D1 by completing a rule the code already had (`CANARIES` was one pair per address family; the IPv4-mapped family had none) and **disclosed that the fix changes behaviour beyond the range it was added for**, correcting a docblock that promised otherwise. |
| F-33 | Medium | DATA_INTEGRITY | `OPEN` | Overlapping subnets accepted; same address issuable to two customers. No longer latent — F-02 made subnet creation reachable. |
| F-35 | Medium | OPERABILITY | `OPEN` — in verification | Preflight's address check was unreachable whenever a template existed. Reproduced **through the artisan command an operator runs**: `MappingChain::compute()` answered five questions and then `return $findings;` on finding an installable template, with the address check below that return. The reachability matrix the implementer measured is the whole finding in four cells — the check ran **only on estates already failing for another reason**, so it could never be the sole thing between an operator and a green mapping band. It also found a **second defect in the same three lines**: the check counted all pools while `is_active` is an operator's kill switch and `IpAllocator::subnetIdsFor()` returns no subnets for an inactive pool, so an estate switched off for renumbering counted green — *"making the check reachable while leaving that condition would have closed F-35 one flag away from the identical false green"* — and it **invited me to back that half out** rather than assuming the licence. Most instructive: **the repository had written the defect down as intended behaviour**. `TheVpsGoldenPathTest` carried *"Five checks, not six: `mapping.network` is emitted only when there is no address pool at all"* — false; it was emitted only when no installable *template* existed. Somebody looked at the missing check, wrote a paragraph explaining why its absence was correct, and was wrong about the mechanism. The inventory I asked for lives in a **test's class docblock** rather than a document, with a sibling row that fails the moment any check stops being reached on a green estate — so it cannot go stale in silence, which is the failure mode that made me ask for it. 11 mutations, 11 caught; four of them exist only to prove the completeness rule is not vacuous on four product shapes. One survivor it declines to claim: `mapping.tld` is **entirely untested** — erasing its enabled-and-open condition leaves 183/183 green. |

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
| **The same unreadable-body defect exists on the surface an operator uses to decide whether a node may join the fleet at all.** `DirectAdminConnectionTester` parses DirectAdmin bodies with its own `urlEncoded()` and has **none** of F-14's gates — bare `parse_str`, no repeat check, no pair count, no ambiguity rule. Measured: `%00error=1&text=License+expired&error=0` at `/CMD_API_LICENSE` hides a licence refusal from the credential centre, which then reports the node fine. F-14 names the SharedHosting adapter and this is the Providers module's own copy of the same reading problem; the duplication is deliberate and documented in `READ_FIELDS`, so the implementer did not touch it. **It wants its own finding and the namespace has none to give.** | F-14 implementer's probe; `DirectAdminConnectionTester.php:267` | F-14 (same mechanism, different module) | **Yes, for the final adjudication** — the F-14 verifier is asked whether F-14 closes with it open |
| The leading-NUL class was **wider than the licence answer**, and neither the audit nor I had named it. Measured on the pre-fix tree: `%00list[]=alice&list[]=bob` on `CMD_API_SHOW_USERS` returned **one** account silently, which `ReconcileHostingNodes` would record as drift — an account missing at the panel on a node where it is present; and `%00loadavg1=9.9&loadavg1=0.1&version=1.665` on `CMD_API_SYSTEM_INFO` yielded `loadOne = 0.1`, the platform reading the idle figure off a busy node and treating it as the best scheduling candidate in the fleet. Both are closed by F-14's round-seven gate and both are now asserted. | F-14 implementer's probes at `3b29308` | F-14 (closed by it) | No |
| `CMD_API_SHOW_USER_USAGE` has no entry in `GENERIC_READ_FIELDS`, so a single field settles it — `quota=100` alone is accepted as a usage answer. It is the one command whose nominal check has no generic list at all, and that asymmetry is undeclared. Judged below the bar for round seven and recorded instead. | F-14 implementer | F-14 (adjacent) | No |
| `date_parse('2099.01.01')` returns `error_count = 0` with `month` and `day` both `false`. The value is refused, but by the `! is_int($parts['month'])` guard rather than by the error count — so **the error count alone is not a sufficient readability test**, and a future simplification that trusted it would open a door. | F-14 implementer's measurement on the installed PHP | F-14 (mechanism) | No |
| **The whole mapping band is invisible on the command an operator types first.** `InfrastructurePreflightService::estate()` runs providers, dependencies, naming and per-family product findings, and **never calls `MappingChain::inspect()`**. Measured: `infra:preflight --mode=simulation` with no option emits **zero `mapping.*` findings of any kind** — no cluster, nodes, storage, capacity, template, addresses, hosting nodes, packages or TLDs. The readiness engine does not cover the gap: on the same fixture the vps verdict was *"not_ready: compute: No compute provider is registered"*, driven by provider rows alone. So an estate with every provider green and no IP pool reports a clean estate-scope run. F-35's implementer left it deliberately — making the estate scope run mappings adds findings to every estate report and can flip its exit code, which is well past minimal for a one-sentence finding — and calls it *"the largest thing I found"*. **This is strictly larger than F-35 itself and no finding owns it.** | F-35 implementer's measured estate-scope output | F-35 (its own closure is arguable) | **Yes, for the final adjudication** — and the F-35 verifier is asked whether F-35 can close while it stands |
| **`NOT_TESTED` does not block a passing preflight, against its own enum's stated doctrine.** `PreflightReport::passed()` is `blockers() === []`, and `blockers()` filters on Fail|Blocked only. The CLI's **exit code** and the API's `passed` field both come from `passed()`, so a preflight in which a check was never established still exits 0 provided nothing else failed. `CheckStatus::NotTested`'s docblock says the opposite in so many words: *"NOT_TESTED is never a pass… an absent answer must not read like a good one."* The enum's doctrine and the arithmetic disagree, and it is the load-bearing assumption under F-35 and under `dependency.backup_verification`, which becomes `NOT_TESTED` when the metrics registry throws. | `PreflightReport::passed()`; `CheckStatus::blocking()`; `CheckStatus::NotTested`'s docblock | F-35 (adjacent) / F-23 | Adjudication pending |
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
