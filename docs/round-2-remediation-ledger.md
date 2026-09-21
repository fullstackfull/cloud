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
PostgreSQL database `lynomia_<slug>`, and its own scratch directory. `vendor/`
and `node_modules` are symlinked rather than copied — 4.5G between them, and
read-only to every agent. `lynomia_test` and `lynomia_e2e` are reserved for the
coordinator's integration runs.

The database override works because `phpunit.xml` pins `DB_DATABASE` without
`force="true"`, so an ambient variable wins. That is F-41, and this program
depends on the defect it is going to repair; when F-41 closes, this script must
change with it.

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
| F-13 | High | CODE_GAP | `OPEN` — in verification | Privilege map now derives `reinstall` and `templates` from the calls the adapter makes, bounded by the platform's own Ansible role. Verifier returned **UPHELD WITH RESERVATIONS** and reproduced the finding's inverse: a nine-privilege token reached `ReadyForProduction` for VPS and would be refused a real `create` and `resize`. Reworked to fourteen of the role's twenty privileges, and the subset guardrail now parses the role file instead of resting on a hand copy. Two existing test fixtures changed, each adjudicated by the verifier. |
| F-14 | High | CODE_GAP | `OPEN` — in verification, **rejected twice** | Three rounds. The verifier reproduced F-14's own headline outcome — an unreadable node recorded licensed and schedulable — against the repaired code twice, through a different door each time: first `error=0` and generic health vocabulary, then the null-word path where the platform supplied `'active'` for a licence the panel never described. Now: reads validated per command, a state whitelist fail-closed on silence, transport failures no longer classified as licensing ones, the verdict surfaced on `last_sync_error`, and the simulator able to represent a node that is up and cannot say whether it is licensed. |

### Wave 2 — concurrency, lifecycle, authorization

| ID | Sev | Class | Status | Notes |
|---|---|---|---|---|
| F-08 | High | CONCURRENCY | `OPEN` — in progress | `retry_after` 90s below every supervisor timeout; six `payments` listeners re-execute concurrently with themselves. Held back until the shared-Redis defect was fixed, because the finding lives in the suites that defect corrupted. |
| F-12 | High | DATA_INTEGRITY | `OPEN` — in verification | Both limbs closed: the address returns through the existing quarantine path and the departing customer's PTR authority ends (a 202 became a 404). Verifier returned **UPHELD WITH RESERVATIONS** — the branch was red on a gate the implementer had not run, and the quarantine clock demonstrably handed a live address to a second customer after seven days while the machine still carried the previous customer's disks. The window is now held at decommission and started when an operator records the disks erased. The pinning the verifier asked for found a defect the repair had introduced: matching on the service alone released the address of the *other* chassis on a multi-chassis service. |
| F-15 | High | CODE_GAP | `OPEN` — in verification | A stable creation identity is reserved on the job row before the call that may build, and a retry asks the hypervisor whether the machine exists. Verifier returned **UPHELD WITH RESERVATIONS** with two reachable gaps: the existence question is asked per-node while the identity is cluster-scoped, so a machine that moved between attempts reads as absent and is built twice (against the in-tree simulator, which overwrites rather than refuses, the live machine was replaced); and the check tests presence only, so an unrelated machine at the reserved id permanently wedges the job and makes the stranger unadoptable by its owner. |
| F-18 | High | AUTHORIZATION | `OPEN` — in progress | Hosting destruction control inverted; `retentionHasElapsed()` true whenever `suspended_at` is null. |
| F-19 | High | CODE_GAP | `OPEN` — in progress | Nine of thirteen `OrderStatus` states unwritable; two exclusion clauses unreachable; `completed_at` never stamped. |

### Wave 3 — DNS, network, platform security

| ID | Sev | Class | Status | Notes |
|---|---|---|---|---|
| F-11 | High | DATA_INTEGRITY + TEST_GAP | `OPEN` — in progress | Cloudflare collapses all records at one `(type, name)` onto `$existing[0]`; `records()` unpaginated at 100 against a 250 ceiling; the simulator reproduces the defect. |
| F-26 | Medium | — | `OPEN` | Reserved-zone guard protects the name and its parents but not its children; empty default. |
| F-28 | Medium | — | `OPEN` | Console socket TLS sourced from the global key while every sibling is per-cluster. |
| F-29 | Medium | SECURITY | `OPEN` — in verification | All seven families confirmed and refused. Verifier returned **UPHELD WITH RESERVATIONS** and found a genuine *loosening*: canonicalising removed a PHP string artefact the old policy had been silently leaning on, so `::ffff:0:169.254.169.254` became an acceptable production machine address. Answered with one `::/8` entry that subsumes every IPv4-in-IPv6 embedding including unnamed ones, replacing four narrower ranges. Also closed: Unicode label separators that UTS-46 maps to `.` and this estate's own libidn2-enabled client dials, and a test that performed real outbound DNS and therefore asserted nothing. |
| F-31 | Medium | SECURITY | `OPEN` — in verification | The software defect — `env()` resolved before the environment loads, so the setting never took effect — is repaired at the config seam and the collapse is reproduced and closed. Verifier returned **UPHELD WITH RESERVATIONS**, and corrected the Round-1 record while doing it: the rejection of *"clients can spoof their IP"* was materially wrong on the parent, because the framework's `.on-forge.com` Host fallback trusted any caller and this repository's nginx template serves an arbitrary Host. The rejection's conclusion survives; its stated reason did not. Reservations since addressed: the catch-all filter was an exact-string blocklist that `REMOTE_ADDR` and `1.2.3.4/0` walked past and is now semantic; no test pinned the `env()`→`config()` seam that *is* the defect, and one now does from a fresh process; and a comment claiming the Ansible role deploys this setting was false — no role writes the application `.env` at all. |
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

## Discovered / out of scope

Defects found while repairing a finding, recorded rather than numbered. Repaired
immediately only when closing the active finding truthfully requires it, or when
this program introduced them.

| Description | Evidence | Related F-ID | Blocks? |
|---|---|---|---|
| **Programme infrastructure, now repaired.** The first `mkworktree.sh` symlinked the whole `vendor/`. Composer's autoloader hard-codes an absolute `$baseDir` and PHP resolves `__DIR__` through symlinks, so every worktree loaded the canonical PSR-4 map; then one agent's `composer dump-autoload` wrote through the symlink and repointed `$baseDir` at that agent's worktree. For roughly twenty minutes every other worktree — and the canonical checkout — executed one agent's uncommitted source while reporting its own suites green. Caught by a reviewer when a deliberate breakage failed to fail. Every affected agent re-measured; no conclusion changed. | `vendor/composer/autoload_psr4.php:6` (repaired), `scratchpad/mkworktree.sh` | F-41 (same family: the suite's outcome depending on ambient state rather than on anything Git has) | No, once repaired |
| **Programme infrastructure, live.** `git stash` is not worktree-local — the stack lives in the common `.git` directory. Two agents' stashes crossed: one popped the other's work into its tree. Both recovered. No agent may use `git stash`; `git show <rev>:<path>` and `git checkout HEAD -- <path>` are the worktree-local alternatives. | dangling stash commits `5329291`, `a7be338` (both superseded by committed work) | F-41 (adjacent) | No |
| **F-17's regression guard is narrower than the finding.** `TheInvitationLimiterCannotBeRotatedByAHeaderTest` pins the limiter's *key* by calling the closure directly. Nothing pins that the limiter is attached to the routes: deleting both `throttle:team-invitations` middleware lines from `routes/v1/team.php` leaves 17/17 green. F-17's outcome — an ordinary customer turning the platform into an unbounded mail relay — is reachable again by a one-line route edit the suite would not notice. | `routes/v1/team.php:37,42`; `grep -rn "team-invitations" tests/` returns one file | F-17 (its own closure) | Yes — F-17 is not safely closed until its oracle covers attachment as well as keying |
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
