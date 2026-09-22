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
| F-04 | Critical | CODE_GAP | `OPEN` — in verification | Password and contact-email limbs repaired and independently verified against a 111-table leak search. Verifier returned **PARTIAL**: two oracle holes (the gate passed with a constant password; "never written down" searched 6 tables of 111), both since closed with the verifier's own attacks; a retry generating a fresh password for a stable username, now documented and pinned. The domain limb was the finding's literal sentence and stayed open — the repository asserts a lifecycle step needing a domain and provides no way to supply one. **Escalated; the user chose "ask for it at checkout"**, now being implemented with the portal, both locales, the capability matrix and OpenAPI in scope. |
| F-13 | High | CODE_GAP | `OPEN` — in verification | Privilege map now derives `reinstall` and `templates` from the calls the adapter makes, bounded by the platform's own Ansible role. Round-two verifier found the one removal that was **fail-open**: a token short of `VM.Config.CDROM` was told it may `create`, where every other doubtful privilege had been kept with the doubt recorded. Accepted in full and restored, in the same three-part shape `Datastore.Audit` uses. The rework's own re-check of its arithmetic found `Sys.Audit` unaccounted for after two rounds — required for `task_polling`, established outside the map because it is a read — so the docblock's "seven omitted" was wrong and the accounting is now twenty of twenty. |
| F-14 | High | CODE_GAP | `OPEN` — **rejected three times** | Four rounds. Each has been reopened by a door adjacent to the one just closed. Round four's verifier re-broke all eight closed doors, found every one caught by a test naming a real business consequence, reproduced every number exactly — and then found a **ninth door one line below the eighth**: `licenceStatesIn()` was rewritten to read every `status`/`state` key, while the expiry keys on the very next line are still read with `firstString()`, the method the same commit message calls one that "stops at the first key that yields a readable value … which is the property `firstString()` cannot have and should not be asked for". `status=active&expires=2099-01-01&expiry=2020-01-01` is recorded licensed, active and schedulable for paid orders with no error — F-14's headline outcome verbatim, unchanged from the parent. The rule is also asymmetric the wrong way: a present-but-unreadable *status* refuses the node; a present-but-unreadable *expiry* sells it. And three data sets named for the expiry rule are inert — they carry no state key, so the state gate refuses them before the expiry is read, which is round one's `status_page`-vs-`status` failure recurring. |

### Wave 2 — concurrency, lifecycle, authorization

| ID | Sev | Class | Status | Notes |
|---|---|---|---|---|
| F-08 | High | CONCURRENCY | `OPEN` — in progress | `retry_after` 90s below every supervisor timeout; six `payments` listeners re-execute concurrently with themselves. Held back until the shared-Redis defect was fixed, because the finding lives in the suites that defect corrupted. |
| F-12 | High | DATA_INTEGRITY | `OPEN` — in verification | Both limbs closed: the address returns through the existing quarantine path and the departing customer's PTR authority ends (a 202 became a 404). Two verification rounds have each found a defect the previous repair introduced — first that matching on the service alone released the address of the *other* chassis on a multi-chassis service, then that holding the window for a holder that does not exist withdrew a service-scoped address from the customer and returned it to nobody. The latter passed the implementer's whole committed suite unchanged: it pinned that the orphan is released, never that it is returned. Round three also drove the `maintenance → retired` transition, which was legal, documented and reachable from nothing, so a machine that dies instead of being resold had no exit. |
| F-15 | High | CODE_GAP | `OPEN` — in rework | A stable creation identity is reserved on the job row before the call that may build, and a retry asks the hypervisor whether the machine exists. Round-two verifier returned **UPHELD WITH RESERVATIONS**: every claimed number reproduced, the simulator refusal is cluster-wide and correct, and six independent concurrency attacks on the append-only node reservation all held. But the round introduced a defect of its own — the node sweep sits inside the `try`, so the first unreachable node abandons it, and because the node list is append-only a decommissioned node can never age out: a machine that moved to a third node was never asked about and the job is `identity_unverifiable` permanently, with a runbook remedy that cannot be performed. A round-one hole also survives narrower: a stranger's machine is claimed on a name match alone (1 vCPU/1 GiB where 2/4096 were ordered), though it now needs both a VMID and a name collision. Neither can build a second machine. |
| F-18 | High | AUTHORIZATION | `OPEN` — in rework | Hosting destruction control inverted; `retentionHasElapsed()` true whenever `suspended_at` is null. Verifier reproduced both halves with its own probe, including watching the **unattended scheduled sweep destroy a live, reactivated, paying customer's account** — `{"ended":1}`, removed from the panel, stamped terminated, no operator in the loop. Returned **UPHELD WITH RESERVATIONS**: five of six R-items upheld with premises independently verified, and the seeded-role claim confirmed by enumerating all eight roles — no seeded role holds `hosting_account.manage` without `service.terminate`, so the controller split is hardening and the predicate plus status guard are what actually closes the finding. One claim proved **false**: the refusal is ordered timestamp-first, so an `Active` account with an unelapsed stale `suspended_at` still gets the retention refusal, contradicting two docblocks that say "suspended is meant literally". Swapping the two blocks passes 38/38 — nothing pins either order. |
| F-19 | High | CODE_GAP | `OPEN` — in rework, **branch red** | Nine of thirteen `OrderStatus` states unwritable; two exclusion clauses unreachable; `completed_at` never stamped. Eight states given writers, `Suspended` removed as fiction — the verifier attacked that removal exhaustively (writers, readers, portal vocabulary, OpenAPI, metrics series, persisted rows, admin filters) and could not break it. It also confirmed both leaking budgets with its own probe against the real handler and a complete estate, and confirmed F-05 and F-06 untouched to the byte. **Returned UPHELD WITH RESERVATIONS with five gaps, three blocking.** The branch is red: `TheVpsGoldenPathTest` was the sixth existing assertion the commit invalidates, missed because `tests/Feature/Simulation` was never run — and the commit edits the very document that test enforces. `Terminated` is reachable only from `Active`, so a build that fails, is retried successfully, or is terminated for abuse leaves the order stuck and **the unit of finite stock never returns** — F-19's own outcome, back. `Paid → Provisioning`/`→ Active` are not edges and the worker can claim before fulfilment advances the order, leaving it at `Paid` for ever with a live machine — and the entire new oracle runs under `Queue::fake`, the one arrangement where that race cannot occur. A full refund released the plan unit while the machine was still running, selling the last unit twice. Shared hosting is not covered at all. |

### Wave 3 — DNS, network, platform security

| ID | Sev | Class | Status | Notes |
|---|---|---|---|---|
| F-11 | High | DATA_INTEGRITY + TEST_GAP | `OPEN` — in verification | Cloudflare collapses all records at one `(type, name)` onto `$existing[0]`; `records()` unpaginated at 100 against a 250 ceiling; the simulator reproduces the defect. First verifier returned **UPHELD WITH RESERVATIONS** and found a seventh breakage — deleting the priority comparison from `DnsRecord::saysTheSameAs()` passed all 160 DNS tests while silently dropping a priority-only publish. Answered not with a seventh hand-written case but with a field-exhaustive equality table that reads `DnsRecord`'s field list off its constructor by reflection, so the next field added is covered by the mechanism rather than by somebody remembering. **That table then found a defect nobody had asked about, on unmodified code: a TTL-only edit never reached the provider**, because the idempotence short-circuit used an equality that deliberately excludes TTL — and the simulator passed it throughout. |
| F-26 | Medium | — | `OPEN` | Reserved-zone guard protects the name and its parents but not its children; empty default. |
| F-28 | Medium | — | `OPEN` | Console socket TLS sourced from the global key while every sibling is per-cluster. |
| F-29 | Medium | SECURITY | `OPEN` — in rework | All seven families confirmed and refused. Verifier returned **UPHELD WITH RESERVATIONS** and found a genuine *loosening*: canonicalising removed a PHP string artefact the old policy had been silently leaning on, so `::ffff:0:169.254.169.254` became an acceptable production machine address. Answered with one `::/8` entry that subsumes every IPv4-in-IPv6 embedding including unnamed ones, replacing four narrower ranges — verified exhaustively over 4000 random addresses with all five removed ranges checked for subsumption at both edges. Unicode closed past what was asked: soft hyphen and zero-width space are *ignored* code points that no list of dot characters would catch. Three hygiene items remain in rework. |
| F-31 | Medium | SECURITY | `OPEN` — in verification | The software defect — `env()` resolved before the environment loads, so the setting never took effect — is repaired at the config seam and the collapse is reproduced and closed. Verifier returned **UPHELD WITH RESERVATIONS**, and corrected the Round-1 record while doing it: the rejection of *"clients can spoof their IP"* was materially wrong on the parent, because the framework's `.on-forge.com` Host fallback trusted any caller and this repository's nginx template serves an arbitrary Host. The rejection's conclusion survives; its stated reason did not. Reservations addressed; a second round found a `TypeError`, now guarded, and established that the HTTP 500 it exposed pre-existed in `isFromTrustedProxy()` with `7a4b246` widening its reach to `/up`. |
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

## Product decisions escalated to the user, and their answers

Only decisions the repository genuinely does not settle, where more than one
contract is defensible and the choice changes what the code must do.

| Decision | Context | Answer |
|---|---|---|
| How a shared-hosting customer supplies the domain their account is built for. | The repository asserts a lifecycle step that needs a domain and provides no way to supply one: `PlaceOrderRequest` has no domain field and `CheckoutLine` carries only `planId`/`quantity`. F-04's literal sentence. | **Ask for it at checkout.** Implemented with the portal, both locales, the capability matrix and OpenAPI in scope. |
| What happens to a running machine or hosting account when its order is refunded in full. | F-19 made `Refunded` reachable for the first time, which turned a dormant sentence in `PlanCapacity`'s docblock — that refunded orders release their unit — into live behaviour. Measured consequence: `order=refunded service=active subscription=active CLAIMED=0`, and the last unit of a finite plan sold twice while the first customer's machine was still running. | **Keep the service, release nothing yet.** A refund records the money and nothing else; the plan unit and the coupon hold come back when the *service* ends, not when money moves. Terminating stays a separate act. `PlanCapacity`'s docblock is wrong as written and is corrected with the code. |
