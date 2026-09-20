# Final Independent Multi-Agent Audit — Round 1

**Repository:** `fullstackfull/cloud`
**Branch:** `claude/relaxed-turing-nh8ybf`
**Audited HEAD:** `d89e227`
**Round:** 1 — read-only. No application code, migration, configuration or status was changed.
**Verdict:** **E — SOFTWARE CLOSURE REOPENED — MULTIPLE BLOCKING CLASSES**

---

## Executive summary

This audit set out to disprove the claim that the codebase can be frozen as
software-complete for the approved five-product launch scope (VPS, Dedicated,
Shared Hosting, DNS, Backups) until real infrastructure validation begins. It
could not be upheld. Forty-four agents were executed, including a board whose
only assignment was to reject findings and a board that reproduced findings it
had not made. Both boards strengthened the case rather than weakening it.

The decisive result is not any single defect. It is that four *mechanisms* are
missing, and each one keeps producing defects faster than the individual
instances can be closed:

1. **The declaration layer outran the write layer.** Nine of thirteen
   `OrderStatus` cases can never be written. 144 of 942 enum cases have no
   production writer. `InvoiceItemKind::Proration` and `::Credit` are
   constructed only inside a DTO that nothing consumes — so no invoice in this
   codebase can carry a proration line at all.
2. **Consequences have no owner.** Twelve of roughly two hundred
   `Application/Actions` classes dispatch a domain event. `RefundIssued` does
   not carry an order id, so the listener that would unwind a refunded order
   *cannot be written* against that event. This is a missing mechanism, not a
   missing line.
3. **The test oracle is the simulator.** `FakeDnsProvider` keys records the
   same wrong way the Cloudflare adapter does; `FakeComputeProvider` throws
   before registering a machine, so a double-build is unrepresentable;
   `FakeHostingProvider` never reads the password or domain, so an empty
   credential is invisible. Green tests that cannot fail.
4. **The onboarding path was built to half its own specification.** The
   repository's own design document states, in bold, that no inventory row is a
   source-code edit and maps every one to `Admin → …`. Seven of those endpoints
   do not exist, and three of the five that do `findOrFail` a parent row nothing
   can create. The chain is dead at link one.

The sharpest single piece of evidence in the audit is not a defect. It is that
120 architecture tests and 8,454 assertions pass while nine of thirteen order
states are unreachable — **and one of those tests requires that all nine be
translated into Arabic.** The completeness gates were pointed at the layers that
were finished.

Two findings deserve naming individually. `routes/v1/billing.php` contains, in
one file, the argument for why a plan-change route must not exist without a
purchase review, the route itself seventy-five lines later, and a comment
falsely claiming it "settles money both ways" — and that route discards its
proration in both directions, under-charging upgraders and under-refunding
downgraders. And a single miscapitalised `APP_ENV=Production` defeats every
production guard in the codebase simultaneously, because all of them are exact
string comparisons.

Much of the platform is genuinely well built, and this report says so at
length in *Rejected findings*. The money representation is sound. Cross-tenant
isolation held against 1,180 live probe requests and 74 of 74 endpoints. The
`REAL_INFRA_VERIFIED` promotion path survived a hostile forgery attempt and
should be kept as designed. The cPanel licence handling fails closed at five
independent points. Ansible idempotency is complete across all 35 shell tasks.
None of that is in question. What is in question is whether the remaining work
is configuration, and it is not: it is a development phase.

---

## Method

Forty-four agents in four groups plus two supplementary reviewers, each given
an independent brief, an isolated database, and a scratch directory outside the
git tree. Group D was launched only after the primary groups reported, and was
given the accumulated finding corpus with instructions to attack it:

- **D5 (Reproduction Board)** re-derived eight blocking findings from scratch,
  having made none of them. It reproduced eight of eight, and corrected three.
- **D6 (Rejection Reviewer)** was measured solely on findings destroyed. It
  killed one, downgraded four, and corrected the framing of three more — and
  settled the audit's central question *against its own assignment*.

Every blocking finding published here was re-verified by the coordinator
personally, by running the check rather than trusting the report. Where a
finder's claim did not survive that check, the finding was downgraded, rewritten
or dropped, and this report says so by name.

### The backend suite's state, and why this report does not publish a number

Before the audit fan-out, the full backend suite measured **3,750 / 3,750** on
this tree. After it, two full runs on the same unchanged commit produced **55**
and **47** failures with *different, overlapping* failing sets — and targeted
re-runs of the failing areas pass in isolation (`tests/Feature/Vps` 141/141).

The failure signatures are not assertion-logic mismatches. They are foreign row
counts (a test asserting "nothing queued" finding 16), and — in one attempted
isolation run — `WorkerHarness` correctly refusing to empty a database whose
name did not identify it as a test database. Three things make a clean
measurement hard in this environment and are themselves findings:
`phpunit.xml` pins nothing (F-41), `WorkerHarness` requires a conventionally
named database, and roughly forty agents created, migrated and dropped
databases and Redis keys on one host for several hours.

**This report therefore publishes no post-audit pass/fail count.** The number it
stands behind is the pre-fan-out 3,750 / 3,750; the post-fan-out runs are
recorded as unmeasured rather than as red. Re-establishing the baseline on a
dedicated runner is the first thing Round 2 should do, and `tests/Feature/Queue`
×10 contention-free remains specifically owed.

### Method limitations, stated plainly

- **Shared-database contamination (coordinator error).** The primary groups were
  initially pointed at one shared `lynomia_test`, which `phpunit.xml` pins
  without `force="true"`. This produced deadlocks and `migrate:fresh` collisions
  and invalidated an unknown number of early runs. One agent correctly refused
  to file a contaminated 39-failure run. Two agents reported being denied
  permission to create isolated databases. Every Group D agent was given its own
  database, and every blocking finding was re-reproduced in isolation.
- **A11 reported late.** The test-architecture slot stalled for an hour and
  answered only after a direct request. Its findings arrived after the report
  body was drafted and are integrated below; two of them correct this
  coordinator's own prior work.
- **One measurement is owed.** `tests/Feature/Queue` failed 4 of 10 runs under
  heavy cross-agent contention and no contention-free re-run was obtained. It is
  recorded as unresolved, **not** scored as a flake.
- **Provider-side consequences are inferred, not executed.** No real provider
  was contacted. Where a defect's final consequence depends on real Proxmox,
  Cloudflare, WHM or DirectAdmin semantics, this report says so and does not
  assert the consequence.
- **Round 1 is read-only.** No fix was implemented, and no finding here should
  be read as having been tested against its remedy.

---

## Agent roster

| Group | Executed | Notes |
|---|---|---|
| **Group A** — architecture, domain, data, frontend, SRE, migrations | **12 / 12** | A11 reported late, after a direct request |
| **Group B** — provider adapters, IaC, IPAM, DNS, panels, registrar | **12 / 12** | |
| **Group C** — red teams (auth, tenancy, SSRF, money, provisioning, queue, business logic) | **12 / 12** | |
| **Group D** — architecture, security, infrastructure, data/money, reproduction, rejection | **6 / 6** | |
| Supplementary (§29) | **2** | S1 admin operability, S2 customer portal |
| **Total executed** | **44** | |

---

## Scope

| Surface | Scale |
|---|---|
| Control-plane PHP source | 1,352 files, 32 modules, four layers each |
| Backend tests | 456 files, 27 architecture tests |
| Customer portal | 264 TypeScript/TSX files, 54 Playwright specs |
| Infrastructure as code | 149 files (Ansible, OpenTofu, validators, monitoring) |
| Migrations | 59 |
| Documentation | 100 files |
| Admin API | 120 route declarations |

---

## Claimed starting truth

The entry claim under audit was that approved-scope real code gaps stood at
zero, that the five approved products were software-complete, and that what
remained was configuration and real-infrastructure validation. The frozen
statuses were `30B.0-E = NOT READY` and `REAL_INFRA_VERIFIED`,
`REAL_PAYMENT_VERIFIED`, `REAL_REGISTRAR_VERIFIED`, `REAL_HOSTING_VERIFIED`,
`READY_TO_SELL` all `NONE`. **All of those statuses are unchanged by this
audit.** The zero-code-gaps claim is disproved.

---

## Findings by severity

### Critical

| ID | Finding | Class |
|---|---|---|
| **F-01** | Every mid-cycle plan change discards its proration in both directions. The platform states the debt (`amount_due_now`) and issues no invoice; `InvoiceItemKind::Proration`/`::Credit` are constructed only inside a DTO nothing consumes, so no invoice can carry a proration line at all. Under-charges upgraders, under-refunds downgraders. Repeatable each period. | MONEY_INTEGRITY |
| **F-02** | No production write path exists for Region, ComputeCluster, Network, IpPool, Subnet, HostingNode, DedicatedServer stock or BmcEndpoint. Three of the five writers that do exist `findOrFail` a parent nothing can create. The platform cannot be brought into a sellable state by an operator. | CODE_GAP |
| **F-03** | No operator account can be created. `super-admin` is seeded with zero permissions and assigned to no user; 11 permissions are held by no role; `role.manage` is on no route. Deploying a machine then requires two distinct Super Admins. | AUTHORIZATION |
| **F-04** | A real shared-hosting order carries no password, no contact email and a `.invalid` primary domain to the panel. `changePassword` is implemented and has no caller. | CODE_GAP |

### High

| ID | Finding | Class |
|---|---|---|
| F-05 | A cancelled order's invoice stays collectible; paying it captures money, provisions nothing, refunds nothing, and lands `FulfilOrderOnSettlement` in `failed_jobs` while the webhook answers 200. | MONEY_INTEGRITY |
| F-06 | `stock_limit` and `per_customer_limit` are read unlocked, outside the write transaction. Reproduced at 8-of-1 and 6-of-1; the coupon control in the same file held at exactly 1-of-8. | CONCURRENCY |
| F-07 | Money moves before feasibility. A hosting plan with no package is orderable, payable and renews forever; `placement_blocked_reason` is written in one place and read nowhere, and there is no admin services index. | MONEY_INTEGRITY + OPERABILITY |
| F-08 | `retry_after` (90s) is below every Horizon supervisor timeout. Six listeners on the `payments` queue carry `tries=5` — settlement, refund, dunning and renewal all re-execute concurrently with themselves. Reproduced: 1 dispatch → 5 executions at 90s intervals. | CONCURRENCY |
| F-09 | A restore is reconciled against the *backup's* finished task id. `restore_task_id` is written and never read; `restored_at` never written. A running or failed restore is reported to the customer as complete — and a second restore can then start over the same disks. | DATA_INTEGRITY |
| F-10 | A datastore-confirmed corrupt archive renders identically to an unchecked one, on a success-badged row, with Restore enabled — directly beneath the code's own comment forbidding exactly that fold. The conflation is enshrined in a passing test. | DATA_INTEGRITY |
| F-11 | The Cloudflare adapter collapses all records at one `(type, name)` onto `$existing[0]`. Round-robin A records and backup MX records are destroyed; two local rows share one provider id; deleting one kills the survivor. `records()` is unpaginated at 100 against a 250 ceiling. The simulator reproduces the defect, so no test can catch it. | DATA_INTEGRITY + TEST_GAP |
| F-12 | A dedicated server's IP assignment is never released. One public IPv4 leaks per lifecycle, and the departing customer retains live control of that address's PTR. | DATA_INTEGRITY |
| F-13 | VPS can never reach `ReadyForProduction` on a real Proxmox cluster: `reinstall` and `templates` are absent from the tester's privilege map and default to `Unknown` forever, while VPS requires both with no `optional` list. Only the simulator can satisfy it. Fails closed. | CODE_GAP |
| F-14 | DirectAdmin gates its "no error field" refusal on mutations only, so every read returns unvalidated `parse_str` output; `licenceStatus()` hardcodes `valid: true`. An unreadable node is recorded licensed and active, and scheduled for paid orders. cPanel fails closed on the same input. | CODE_GAP |
| F-15 | An indeterminate VPS create can be retried into a second machine: the VMID is a fresh `random_int` per attempt and is never persisted, so the "something was built" refusal never fires. Operator path only — automatic retry is correctly blocked. | CODE_GAP |
| F-16 | `APP_ENV=Production` (capital P) defeats the fake-provider boot guard, the reference-topology refusal and the readiness gate simultaneously. Every production guard is an exact string comparison. | SECURITY |
| F-17 | The `team-invitations` throttle bucket is the raw, unvalidated `X-Lynomia-Customer` header; the fallback fires only when it is *absent*, so any garbage value creates a fresh bucket. Unbounded outbound mail from the platform's sending domain, by an ordinary customer. `ResendInvitation` has no cooldown. | SECURITY |
| F-18 | A live hosting account can be destroyed on the weaker of two permissions: `retentionHasElapsed()` returns true whenever `suspended_at` is null, and the second permission is checked only under `force`. The control is exactly inverted, against the route's own written promise. | AUTHORIZATION |
| F-19 | Nine of thirteen `OrderStatus` states can never be written. The order lifecycle stops at Paid. Two of the three exclusion clauses guarding stock and coupon holds are permanently unreachable, and `orders.completed_at` is never stamped. | CODE_GAP |
| F-20 | `data_destroyed` is published by the API and rendered on the Dedicated screen and on no VPS screen; the strings do not exist in either locale. A customer whose VPS rebuild erased the disk is told "The rebuild did not run". | CODE_GAP |
| F-21 | Registration dead-ends silently when `/registration/options` fails: no error, empty country/currency selects, terms with no links, and Create-account stays enabled because `registrationClosed` tests `=== false` on `undefined`. | CODE_GAP |
| F-22 | Critical drift is invisible on every channel: the metric is unalerted, the listener only writes `Log::error`, the log path Alloy ships is not the path Laravel writes, and the Loki ruler is wired to Alertmanager with zero rule files and no rules directory mounted. | OPERABILITY |
| F-23 | The architecture suite enforces translations for states that cannot occur. It asserts reachability for methods, events, capabilities, metrics and translations — and for no enum case or state-machine state. | TEST_GAP |
| F-24 | The provider simulators model the convenient case, making three blocking defects unrepresentable in-tree (DNS record identity, compute double-build, hosting credentials). | TEST_GAP |
| F-25 | `ProductKind` carries no docblock and is pinned by none of the 27 architecture tests. The Prepared-product containment six auditors upheld rests on `Product::from()` throwing a `ValueError` — an accident, not a designed refusal. One added case silently sells a Prepared product. | ARCHITECTURE_RISK |
| F-41 | `phpunit.xml` pins ~30 environment variables without `force="true"`, so every one of them — `CACHE_STORE`, `QUEUE_CONNECTION`, `DB_DATABASE`, `BCRYPT_ROUNDS`, the six fake-provider pins — loses to any ambient variable. Reproduced: one exported variable flips a security test 3/3 pass → 3/3 fail. `RefreshDatabase` then runs `migrate:fresh` with no safe-database guard, while `WorkerHarness` enforces four. | ARCHITECTURE_RISK |

### Medium

`F-26` DNS reserved-zone guard protects a reserved name and its parents but not
its children, against three documents saying otherwise, and ships with an empty
default. `F-27` `error.details` publishes `$e->context()` verbatim to customers,
contradicting `ErrorCatalogue`'s docblock; reachable today on two customer
routes leaking provider identity and configuration key paths. `F-28` Console
socket TLS is sourced from the global key while every sibling is per-cluster;
the socket carries the API token. `F-29` `EndpointPolicy` accepts seven address
forms it should refuse (operator-only surface). `F-30` Horizon has no
`Horizon::auth()`; 22 routes gated only by `APP_ENV=local`. `F-31`
`trustProxies` resolves `env()` before the environment is loaded, so every
IP-keyed limiter collapses to one bucket behind a load balancer. `F-32`
Withdrawn hosting package chosen by row order in two modules; no index on
`plan_id`. `F-33` Overlapping subnets accepted; same real address issued to two
customers (latent behind F-02). `F-34` Timeout quarantines can never be cleared;
`ReleaseReason::OperatorAction` has no callers. `F-35` Preflight's address check
is unreachable whenever a template exists, so an estate with no IP pools reports
all mapping checks green. `F-36` `domains:reconcile` throws uncaught in
production every three hours. `F-37` Dedicated power operations are invisible to
monitoring and a stale claim poisons its idempotency key forever. `F-38` Five
CI/IaC gates do not test what their names claim. `F-39` Fifteen alert names
appear in runbooks and in no rule file. `F-40` 542 cross-module `Infrastructure`
imports against a `CLAUDE.md` claim that the rule is enforced; only the `Http`
half is. `F-42` The `apps/web` vitest suite is load-triggered flaky (required CI
gate, `retries: 0`, no configured `testTimeout`; 3 of 4 parallel runs red on one
commit, 0 red serial). `F-43` `ConsoleSessionStore::consume()` never compares
`expires_at`, so its expiry tests prove `ArrayStore` and fail under the
production driver. `F-44` `QuarantineLifecycleTest` carries the reboot-race
clock shape in three methods, missed by the prior closure sweep. `F-45` A
WordPress admin password is redacted before it reaches the installer, which
receives the literal `[redacted]`; the guarding test inspects column names, not
values. Out of approved scope and latent, but it ships broken the day a real
installer is wired.

### Low

Raw i18n keys on the billing panel (labels only — values render correctly);
registration and password-reset timing oracles; setup-fee discountability
mismatch (unreachable: no production coupon writer); negative catalogue price
surfacing as an unhandled vendor exception (unreachable via any API); `:keys`
placeholder in an Arabic validation string for an unused rule.

---

## Findings by subsystem

| Subsystem | Blocking | Notable |
|---|---|---|
| Billing / Subscriptions | F-01, F-05, F-07 | The plan-change route contradicts its own file's design note |
| Orders | F-06, F-19 | The coupon guard is correct 140 lines from the stock guard that is not |
| Provisioning / Compute | F-13, F-15 | Claim J holds for automatic retries; the operator path fails open |
| Shared Hosting | F-04, F-14, F-18 | cPanel adapter is strong; the platform around it is not |
| Backups | F-09, F-10 | Both defects are in the one screen a customer uses to decide if they are safe |
| DNS | F-11, F-26 | Domain layer is excellent; the provider contract cannot express round robin |
| IPAM | F-12, F-33, F-34 | Allocation concurrency is the strongest code in the repository |
| Identity / RBAC | F-03, F-17 | Login timing oracle is correctly closed; register is not |
| Infrastructure / IaC | F-02, F-16, F-38 | Runtime safety machinery is real; CI safety machinery is weaker than its names |
| Observability | F-22, F-39 | Collectors are correct; the rules were never written |
| Portal | F-20, F-21 | Operator surface is outside every quality gate |

---

## Security findings

Eight confirmed, ranked by real-world exploitability. The one to fix first is
**F-17**: it needs no misconfiguration, no operator, no network position and no
special role — an ordinary customer who signed up today can turn the platform
into an unbounded mail relay carrying its sending domain, and the correct
implementation sits fifteen lines below the broken one in the same file.

**F-16** is second and cheapest: one `strtolower()` closes a hole through which
a single miscapitalised environment variable disarms every production guard at
once.

Deliberately **not** claimed: `EndpointPolicy`'s bypasses are real (seven forms,
widened from one) but every consumer is an admin route behind a named
permission, so this is defence-in-depth against an operator typo or a
compromised operator session — not customer-drivable SSRF. The IPv4-mapped-IPv6
half of the original claim is **rejected**: those literals are correctly
refused.

---

## Money/data findings

Nine confirmed. The aggregate exposure is not a single number, because F-01's
loss is bounded per change and repeatable per period, and F-05's and F-07's are
bounded by order value but unbounded in count.

What matters more than the arithmetic is a shape three findings share, which no
individual finder named: **the money or the provider moves, and the platform's
own record of it is written by nothing.** A cancelled order's invoice is never
voided; a refund never reaches the order; a restore's task id is never read. The
architecture board reached the same root from the opposite direction — nine
unwritable order states, and a `RefundIssued` event that cannot carry the id a
listener would need. Two boards, opposite starting points, one cause.

---

## Infrastructure-software findings

The runtime safety machinery is real and survived attack: the `safety_gate`
role refuses what it claims (10/10 self-test), `check-runner-trust.py`
correctly refused the audit's own sandbox by detecting forged certificates, the
reference-topology production refusal is quadruple-guarded, and the
`REAL_INFRA_VERIFIED` promotion path rejected a hand-forged finding that lied
about itself. **Keep all of it.**

The CI safety machinery is substantially weaker than its step names assert. The
OpenTofu step has never parsed a `.tf` file with content and could not under its
pinned version. The inventory validator cannot see hosts declared under
`all.hosts`, which also disables the only credential check that reads an
inventory. The anti-apply check has five bypasses and audits the workflow that
invokes it. Two of the three have a sound partner covering part of the property;
the tofu apply path and inventory-committed credentials have no sound gate at
all.

One correction to an earlier claim carried into this audit: the reference-topology
loader's docblock states every row it writes is stamped `development`. It is not —
only two of sixteen row kinds have an `environment` column.

---

## Configuration/settings findings

`config/hosting.php` declares no `credentials` key while two adapters resolve
credentials from it, with three mutually incompatible shapes across the adapter,
its sibling and the Control Center tester. `DNS_RESERVED_ZONES` ships empty and
is absent from `.env.example`. `config/cors.php` reads `security.allowed_origins`
during alphabetical config load and resolves `null` — fail-closed, so not a
security exposure, but the configured value is silently discarded.
`.env.example` ships `APP_ENV=local` and `APP_DEBUG=true`, and no deployment
path rewrites them.

Onboarding a real node requires editing tracked source. The secret itself need
not be committed — a config entry may read `env()` — so this is a
deployment-process defect, not a secret-in-git defect.

---

## Admin operability findings

Twenty-six of fifty-nine permissions gate no route and are checked nowhere in
code. Eleven are held by no seeded role. The audit trail, invoice voiding,
service termination, dedicated stock return and ticket assignment are reachable
only by curl. There is no admin services index, so a paid-for service that was
never built cannot be found by any operator surface. VM templates are API-only.
DNS and Backups have no operator surface at all.

---

## Customer portal findings

A VPS rebuild that erased the disk tells the customer nothing, and after an
operator verdict tells them the rebuild did not run — while the Dedicated twin
renders the warning correctly, which is what proves it an omission. A corrupt
backup is presented as an unchecked one, in green, with Restore enabled.
Registration dead-ends silently. An indeterminate DNS zone is shown two
contradictory sentences at once, one of which invites the retry the Timeout Rule
exists to prevent. Nothing in the order→provision journey polls, so a customer
who has just paid watches a static screen.

The operator surface sits outside every portal quality gate: the "failed read"
and "form control" gates both scope to a customer-directories list that excludes
it.

---

## Provider-contract findings

Recorded as external contracts, not asserted: Cloudflare's `name=` match
semantics, record pagination limits, zone-creation behaviour for a name held
elsewhere, and `PUT` vs `PATCH` field semantics; Proxmox's `import-from` disk
sizing, UPID URL-encoding, `lock`/`onboot` visibility and detached-volume slot
allocation; WHM's `createacct` behaviour on an empty password and a `.invalid`
domain; DirectAdmin's `CMD_API_LICENSE` field names and login-key session
semantics; the WHM API token scope actually required.

The Proxmox and Cloudflare contract gaps matter most, because F-13 and F-11
cannot be fully closed without them.

---

## Test/flake findings

The backend suite's green is real but narrow, and this audit disproved part of
the prior flake-closure work published by this same coordinator. That is
recorded here rather than quietly corrected.

**What the architecture suite does not establish.** It asserts reachability for
methods, domain events, capabilities, alert metrics, message codes, roles and
translations — and for no enum case and no state-machine state. One of its
tests requires `en`/`ar` translations for all thirteen `OrderStatus` cases,
nine of which cannot be written (F-19/F-23). A mechanical sweep of all 456 PHP
test files found 5 methods with zero assertions (four deliberate and safe),
~70 whose only assertions are negative, and 121 whose every assertion sits
inside an unguarded loop — of which 10 iterate a dynamically discovered set and
are genuinely vacuous if that set is empty.

**Fail-open oracles worth naming.** `TheWholeLifeOfAWordPressOrderTest::the_generated_wordpress_password_is_never_written_to_the_site`
inspects column *names*, never a value, and never knows the password — while
the real path is broken in a way it cannot see: the payload is redacted before
insert, so `InstallWordPressHandler` hands the literal string `[redacted]` to
the installer, and `RedactedJsonCast`'s docblock claim that this "fails loudly"
is unimplemented. Latent today (no real `WordPressInstaller` exists, and
WordPress is Prepared, so it is **out of approved scope**), but it ships broken
the day one is wired. `DevelopmentFixturesTest` and two Monitoring tests pass
vacuously on an empty set. `InvitingAColleagueTest` and `CreateVpsHandlerTest`
assert only negatives with no status or success control, so a 403 or a failed
result satisfies them.

**`phpunit.xml` does not pin what it says it pins.** No `<env>` carries
`force="true"`, and PHPUnit's handler only sets a variable when
`getenv($name) === false`. All ~30 "pinned" values — including `CACHE_STORE`,
`QUEUE_CONNECTION`, `DB_DATABASE`, `BCRYPT_ROUNDS` and the six fake-provider
pins — lose to any ambient variable of the same name. Reproduced: exporting
`CACHE_STORE=redis` flips a security test from 3/3 passing to 3/3 failing.
The file's own comment says these are pinned "rather than left to a dotenv
file… A test suite whose outcome depends on a file git does not have is not a
test suite." The dependence moved from an untracked file to the ambient
environment; it was not removed. Compounding: `RefreshDatabase` runs
`migrate:fresh` with no safe-database guard, while `WorkerHarness` in the same
tree documents and enforces four such conditions.

**Corrections to this coordinator's prior flake-closure work.**

- The prior document stated that seven other test files using `now()` in
  fixtures were checked for the reboot-race shape and none had it. That is true
  of those seven and **incomplete as a sweep**. `tests/Feature/Ipam/QuarantineLifecycleTest.php`
  has exactly that shape in three methods — `now()->addDays(7)` read at
  assertion time against a value the application wrote at fixture time, with
  `travel()` calls present but no `freezeTime()`. Day granularity, so it
  misfires only across midnight UTC, but it is the same defect. A targeted
  tree-wide sweep finds exactly two files with the precise shape: the one that
  was fixed, and this one.
- `KNOWN_FLAKES = 0` is **not** true in the sense of "no test here can fail for
  timing reasons". The `apps/web` vitest suite — a required CI gate with
  `retries: 0` — was run four times on the same commit: 3 failed, 12 failed, 4
  failed, and 0 failed, tracking machine load. Serial (`--no-file-parallelism`)
  and low-load parallel runs are 454/454 green, and every individual failing
  file passes in isolation. All failures are timeouts, never assertion
  mismatches, and the failing set differs every run. `vitest.config.ts` sets no
  `testTimeout` and no `retry`, leaving framework defaults nobody chose. This
  is load-triggered and real; whether it fires on a dedicated CI runner today
  is unquantified.
- The reboot-operation contract change itself **holds**. It was re-checked and
  the fix to `ShowHostingAccountEndpointTest` was independently verified as
  genuine (5 passed, 32 assertions, `freezeTime()` correctly placed before the
  fixture write).

**A test that proves the framework rather than the code.**
`ConsoleSessionStore::consume()` never compares `expires_at` to `now()`,
delegating expiry entirely to the cache TTL, though its own docblock lists
"expired" as one of four refusal reasons. The two tests asserting that an
expired permit is refused therefore prove a property of `ArrayStore`; under the
production cache driver they fail 3/3.

**Contention, not flakes.** `WalletRaceTest` failed 18 of 20 runs under
cross-agent load and passed 20 of 20 quiet; `ConsolePermitConcurrencyTest`
passed 20 of 20. Both are rejected as flakes. `tests/Feature/Queue` failed 4 of
10 under 6–12 foreign worker processes sharing one Redis database, and no clean
re-run was obtained — recorded as the one unresolved measurement, not as a
flake.

## Rejected findings

Recorded because a hostile audit that reports only confirmations is not hostile.

- **"Unlimited free compute"** — rejected. `recurring_amount_minor` *is*
  written, so the next renewal charges correctly. The corrected finding is
  worse in a different direction: the proration is discarded symmetrically.
- **"The tofu step validates nothing"** — rejected as stated. `tofu fmt -check
  -recursive` in the same step does walk all twelve `.tf` files and catches
  syntax; semantics are ungated.
- **"A misset `QUEUE_CONNECTION` fails silently"** — rejected. It is one of the
  best-instrumented failures in the tree: warning at 15 minutes, critical at 10.
- **"Raw i18n keys are a blocking defect"** — downgraded to cosmetic. Only the
  four labels degrade; every value renders correctly. The coordinator had
  carried this at Blocking on the finder's word and was wrong to.
- **"IPv4-mapped IPv6 literals bypass `EndpointPolicy`"** — rejected; they are
  correctly refused.
- **"`storage/{path}` is exploitable"** — rejected. Fail-closed, and nothing in
  the application mints a signature for that disk.
- **"`trustProxies` lets clients spoof their IP"** — rejected; the direction is
  the opposite. `X-Forwarded-For` is ignored entirely.
- **"Retries silently double-provision"** (claim J) — holds for automatic
  retries; `FailureClass::Timeout` is excluded before config is read. Only the
  operator path fails open.
- **WordPress credential redaction, PBS verification triggering, oversubscription
  of a compute node, reconciliation destroying resources, coupon
  over-redemption, cancelling a paid order, client-declared payment success,
  cross-tenant access on 74 endpoints, customer access to operator mutations
  across 1,180 live requests** — all attacked, all held.

---

## Duplicates

Collapsed: the inventory write gap (8 finders → F-02); the credential surface
(8 finders → configuration section); stock/per-customer limits (2 independent
reproductions → F-06); refund-never-unwinds (2 → F-19 family); DNS publish
collapse (2 → F-11); money-before-feasibility (2 → F-07); terminal-state
regression (5 findings → one shape, named in *Money/data*). The earlier
client-chosen `units` finding is superseded by F-01.

---

## Real-infra-only findings

Reverse DNS publication (requires delegated `in-addr.arpa`), PBS verification
and textfile collector, Slack attachment rendering, the multi-host
`trustProxies` case, Proxmox console interception, Cloudflare zone precedence
for a child zone, and all provider contract items above. None is claimed as a
software defect.

---

## Prepared-product containment

**Holds today, pinned by nothing.** Every leak path was enumerated and closed
three-deep on the Domains order path; `ProductKind` has three cases and Domains
is not one; no approved product imports the Domains module. But `ProductKind`
carries no docblock and no architecture test, and the containment rests on
`Product::from()` raising a `ValueError`. This is downgraded from a clean pass
to a structural risk. One scheduled command (`domains:reconcile`) does throw in
production every three hours because of a Prepared product.

---

## Five-product matrix

| Product | Software-complete? | Blocking findings |
|---|---|---|
| **VPS** | **No** | F-01, F-02, F-13, F-15, F-20 |
| **Dedicated** | **No** | F-02, F-03, F-12 |
| **Shared Hosting** | **No** | F-02, F-04, F-07, F-14, F-18, F-32 |
| **DNS** | **No** | F-11, F-26 |
| **Backups** | **No** | F-09, F-10 |

---

## Recommended remediation sequence

Round 2 is not authorised by this report. This is the order the evidence
suggests, for review.

1. **F-17** and **F-16** — two small, contained security fixes; hours, not days.
2. **F-01** — issue the proration invoice from the lines the DTO already
   computes, and gate the resize on settlement. Then query `audit_entries` for
   every `plan_changed` with a non-zero `amount_due_now_minor`: the trail is
   complete enough to bill retroactively.
3. **F-09**, **F-10** — both touch a customer's belief about whether their data
   is recoverable.
4. **F-05**, **F-06**, **F-07** — the order/money seam; each has a working
   control elsewhere in the same file to copy.
5. **F-02** with **F-03** — the inventory write path and the operator bootstrap.
   These are one phase, not two tasks.
6. **F-04**, **F-14**, **F-13** — what a real operator hits on day one.
7. The mechanism fixes, which are the only ones that stop the class recurring:
   an architecture test that every declared state has a writer; a
   contract-conformance suite run against both the simulator and the real
   adapter; `orderId` on `RefundIssued`; a `ProductKind` pin.

**Do not** begin 30B.0-E. **Do not** change any `REAL_*` status or
`READY_TO_SELL`. Round 1 ends here.
