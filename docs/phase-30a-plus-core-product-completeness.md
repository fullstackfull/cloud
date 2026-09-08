# Phase 30A+ — core product completeness

What this phase set out to do: find every place where Lynomia Cloud describes a
capability it cannot complete, and close it. Eight were named. All eight are
closed, and closing them found four defects nobody had reported and thirty-six
strings a customer had been reading in the wrong language.

Every number here was produced by running the gate named, on the commit named,
in this session. Nothing is inherited and nothing is estimated.

**Branch:** `claude/hv-t6hq1p` · **Baseline:** `6c91d47` · **HEAD:** `5201a86`

---

## A. What changed, in one table

| # | Objective | Commit | State |
| --- | --- | --- | --- |
| 1 | Team / organisation membership | `0e458ac` | `RUNTIME_VERIFIED` |
| 2 | Wallet credit made spendable | `75ec104` | `RUNTIME_VERIFIED` |
| 3 | Support tickets | `fccfc77` | `RUNTIME_VERIFIED` |
| 4 | Backup retention, deletion, reconciliation | `363ab89` | `COMPLETE` (`BLOCKED_CREDENTIALS` at the provider) |
| 5 | Forward DNS | `14ca02e` | `COMPLETE` (`BLOCKED_CREDENTIALS` at the provider) |
| 6 | Customer-initiated termination | `412121e` | `COMPLETE` |
| 7 | Hosting reconciliation | `5313d27` | `COMPLETE` (`BLOCKED_LICENSE` at the panel) |
| 8 | Provider task polling | `d3eb0e1` | `COMPLETE` |

Then the closure work the brief requires, in `d8c0df3`, `32043e2`, `1f6e4f3`,
`a6c6713`, `62403c0` and `5201a86`.

## A2. The measured numbers

Every figure from running the gate on this working tree at `5201a86`.

| Gate | Baseline (`6c91d47`) | Now (`5201a86`) |
| --- | --- | --- |
| Backend tests | 2 109 | **2 302** |
| Backend assertions | 56 120 | **65 124** |
| Architecture gates | 20 | **23** |
| Browser specs | 61 | **94** |
| Browser spec files | 6 | **13** |
| Frontend unit tests | 45 | **54** |
| Backend test files | 254 | **282** |
| Modules | 23 | **24** |
| Documented API operations | 96 | **134** |
| Metric families | 25 | **38** |
| Metric series | 352 | **415** |
| Audit actions | 24 | **43** |
| Domain events | 11 | 11 |
| Scheduled commands | 11 | **17** |
| Migrations | — | 41 |
| PHPStan | 0 errors | **0 errors** |
| Pint | passes | **passes** |

Timings on this machine: backend suite 257 s, browser suite 3.6 min, Redis
queue proofs 49 s, metrics scrape 33 queries in ~40 ms.

## B. Team membership — final status

`RUNTIME_VERIFIED`. A customer is no longer one login.

Every part of the tenancy already existed — a `customer_members` table with a
role and an `accepted_at`, middleware that resolves exactly one account per
request and re-checks membership even for a scoped token, a `roleWithin()` that
refuses an unaccepted invitation. What was missing was anything that *creates* a
membership, so the schema had been describing a feature nobody could use since
Phase 1.

Two roles are new. `Billing` and `Technical` exist because a hosting account
asks two different questions of two different people — who may spend money, and
who may touch the machines — and answering both with "administrator" is how a
finance department ends up able to rebuild a server.

The properties that carry the safety: the token is never stored (a SHA-256 is),
so a resend cannot repeat a link; the invited address is the credential and must
be verified, so a forwarded invitation admits nobody; inviting an address that
already has a Lynomia login is indistinguishable from inviting one that does
not; ownership moves in one transaction with both rows locked, so an account is
never ownerless and never owned twice.

`TheWholeLifeOfATeammateTest` (`a6c6713`) runs the chain the brief names as one
sequence, each step from what the previous one left: invited, accepted with the
token taken from the mail rather than from the database, technical access
working, billing refused, role changed, **both** permissions different on the
very next request, removed, and then told exactly what a stranger is told.

## C. Wallet — final status

`RUNTIME_VERIFIED`. Credit can be spent.

`WalletLedger::debit` had been written, tested and left with no caller since the
ledger was built. It needed no new machinery: a wallet payment writes the same
`transactions` row every other payment writes and goes through the same
`SettleInvoice`.

Two orderings carry the safety, and one took a failing test to find: the
idempotency question is asked **before** the payability question, because a
repeat of a *successful* request arrives at an invoice the first one settled,
and checking payability first tells a client its payment failed when it worked.

Six real operating-system processes against one balance prove the rest: exactly
one wins, the balance never goes below zero, the invoice is credited with
exactly what the wallet paid.

## D. Support — final status

`RUNTIME_VERIFIED`. A customer can ask, and the team can answer.

`TheWholeLifeOfATicketTest` runs the brief's chain end to end: opened, seen in
the operator queue, an internal note that changes nothing the customer sees and
does not stop the first-response clock, an answer that hands the ticket back,
a customer reply, a resolution the customer rejects by replying, and a close
after which nothing more may be written on either side.

## E. Backup retention and deletion

`COMPLETE`; `BLOCKED_CREDENTIALS` at the provider. No real Proxmox Backup
Server has ever been asked to remove anything.

Deletion is a decision recorded and acted on later, deliberately: the hour
between asking and acting is what makes a mis-click survivable, and
`POST .../keep` is what makes that hour worth having. Retention prunes on the
plan's terms; reconciliation notices an archive that has silently gone; and
`HoldBackupsThroughRetention` is the producer `protected_until` never had — a
departing customer's window outranks both the customer's own deletion and the
retention sweep.

## F. Forward DNS

`COMPLETE`; `BLOCKED_CREDENTIALS`. No name this platform holds has ever
resolved on the public internet.

Zones and records for A, AAAA, CNAME, MX, TXT and CAA, with per-type rules that
refuse what cannot mean what the customer meant — including addresses in
reserved *and* private ranges, because an A record pointing at `10.0.0.1` is a
support ticket waiting to happen. A released zone can be claimed again: the
uniqueness index is partial on live rows. `ReconcileZones` reports a zone edited
behind the platform's back and repairs nothing.

The brief required this and forbade classifying it `INTENTIONALLY_NOT_A_PRODUCT`.
It is a product.

## G. Customer termination

`COMPLETE`. A customer can end their own service, and is told what that costs
before they confirm it.

Cancelling at period end is one sentence; ending today is a different one behind
the subscription's own reference typed back. Both show the date the data is
destroyed, which is what a retention window is for. `EndExpiredServices` warns
first and ends only what a customer cancelled — never a non-payment suspension,
which stays an operator's decision.

## H. Hosting reconciliation

`COMPLETE`; `BLOCKED_LICENSE`. Five findings, and it repairs none of them.

The worst is the quietest: the platform says the account exists, the panel has
never heard of it, and the customer is paying for a website that is not being
served. A panel that will not answer produces no drift at all, because a sweep
that recorded "missing" for every account on a node whose API was down would
report an outage as data loss.

## I. Provider task polling

`COMPLETE`. A job marked succeeded meant the request was accepted; now it can
mean the machine exists.

A confirmed task is stamped and never asked about again. A failed one puts the
job in front of a person with drift recorded beside it. One still running past
the ceiling does the same, classified as a timeout rather than a failure —
because the task may well still be running, and what has failed is the
platform's ability to keep track of it. Nothing is destroyed, retried or
released on the strength of a failed task.

## J. Capability matrix

`docs/customer-capability-matrix.md` is rewritten. Every `NOT_IMPLEMENTED` row
this phase closed is now a row per capability with the screen, the endpoint, the
action, the queue, the handler, the provider method and the spec that proves it.

Every E2E claim was checked against the spec that makes it. Rows whose browser
coverage does not exist say `TESTED` and leave the E2E column empty — sending
an invitation is `RUNTIME_VERIFIED`, resending one is not, and the matrix says
so rather than rounding up.

An operator section is new, because several customer rows depend on it: a
reconciliation nobody reads is a log file, and a job stuck in review that no
screen shows is a service quietly not working.

## K. Dead method audit

`NoDeadMethodsTest` passes with no unlisted dead method. Of the methods the
brief named:

| Method | Was | Now |
| --- | --- | --- |
| `WalletLedger::debit` | RESERVED | called by `PayInvoiceFromWallet` |
| `BackupProvider::deleteBackup` | RESERVED | called by the deletion and retention sweeps |
| `BackupProvider::listBackups` | RESERVED | called by `ReconcileBackupInventory` |
| DNS zone/record methods | RESERVED | called by the DNS module |
| `HostingProvider::listAccounts` | RESERVED | called by `ReconcileHostingNodes` |
| `ProxmoxComputeProvider::getTask` | RESERVED | called by `PollProviderTasks` |

What is still RESERVED, and why:

| Method | Why it has no caller |
| --- | --- |
| `CloudflareDnsProvider::zoneFor` | The platform matches names against the zones it holds, in its own table. Asking a provider would be a round trip to be told what it just looked up. |
| `Cpanel`/`DirectAdminHostingProvider::changePassword` | Customers reach their panel through single sign-on, so the password is never theirs to change. |
| `ProxmoxComputeProvider::resetVm` | The customer API deliberately offers no hard reset. |
| `Ipmi`/`RedfishDedicatedProvider::powerOff` | The customer API sends an ACPI shutdown; cutting power at the rail is an operator decision. |
| `Ipmi`/`RedfishDedicatedProvider::bootOrder` | Boot order is set for an install and never read back. |
| `ProxmoxBackupProvider::startVerification`, `supportsVerification` | The platform records the verification a provider reports; it does not start one. |
| `IssueInvoice::fromPricedOrder` | A second invoice-issuing path. Removing it means porting the tax and discount tests onto drafts, which is billing-sensitive work that does not belong at the end of a phase about product closure. |

## L. Domain event audit

`EveryDomainEventIsConsumedTest` checks both directions and its `UNCONSUMED`
allow-list is empty. Eleven domain events; every one has a dispatcher and at
least one listener. `ProvisioningJobNeedsReview`, which the task poller raises,
reaches the same listener every other stuck job's does — one door, not two.

## M. Money audit

The brief's fourteen cases, and what proves each.

| Case | Proof |
| --- | --- |
| Full wallet payment | `PayingAnInvoiceFromCreditTest::credit_that_covers_the_whole_invoice_settles_it` |
| Partial wallet + gateway | `CreditAndACardPayOneInvoiceTest` — **new in this phase** |
| Concurrent wallet spending | `WalletRaceTest` — six OS processes, one balance |
| Duplicate wallet request | `WalletRaceTest::six_copies_of_one_request_spend_it_once` |
| Duplicate gateway webhook | `IngestWebhookEventTest` — five separate cases, including concurrent redelivery |
| Refund of a wallet component | `RefundingWhatCreditPaidTest` |
| Refund of a gateway component | `IssueRefundTest`, `ARefundReachesTheInvoiceTest` |
| Overpayment | `SettleInvoiceTest::an_overpayment_is_credited_to_the_customers_wallet` |
| Credit | `WalletLedgerTest` |
| Renewal | `RenewSubscriptionTest`, `RenewDueSubscriptionsTest` |
| Dunning | `AdvanceDunningTest` |
| Plan proration | `ChangeSubscriptionPlanTest`, `PlanChangeEndpointTest` |
| Zero-total order | `PlacingAnOrderIssuesItsInvoiceTest` — a free order fulfils with no invoice |
| Currency mismatch | `credit_is_never_converted_between_currencies`, `a_payment_in_another_currency_is_never_applied` |

The one that was missing is the one a customer actually reaches: a balance that
does not happen to match the invoice. `CreditAndACardPayOneInvoiceTest` pays part
from credit and the rest on a card, and pins both ways that goes wrong — a
gateway asked for the total rather than for what is due (the surplus returns to
the wallet it came from), and a wallet charge that settlement might count twice
because it does not look like a gateway one.

Money is stored in integer minor units throughout. No float touches a balance.

## N. Whole-life tests

| Flow | Test | Ends how |
| --- | --- | --- |
| VPS | `TheWholeLifeOfAVpsTest` | order, pay, provision, operate, resize, suspend, reactivate, back up, delete a backup, rebuild, console, cancel, retention window, terminate |
| Hosting | `TheWholeLifeOfAHostingAccountTest` | with real drift injected into the panel and found by the reconciler, which deliberately does not repair it |
| Dedicated | `TheWholeLifeOfADedicatedServerTest` | to return-to-stock, with the physical half `BLOCKED_HARDWARE` and never claimed |
| DNS | `TheWholeLifeOfADnsZoneTest` | claim, publish, watch somebody edit the zone behind the platform's back, give it up |
| Support | `TheWholeLifeOfATicketTest` | open to close, including a rejected resolution |
| Team | `TheWholeLifeOfATeammateTest` | invited to removed, with permissions checked on the very next request |

## O. Security and adversarial review

`AttackingWhatThisPhaseBuiltTest` is written from the attacker's side: a record
deleted through somebody else's zone, a zone given up through another account's
session, another account's subscription cancelled with the confirmation the
attacker can read off the URL, a service ended before the date its customer was
given, a second cancellation trying to move that date, a TXT record used as a
filesystem, and a task handle pointed at a machine its job does not own.

**The one real defect this pass found was platform-wide and had nothing to do
with DNS.** `ThrottleRequests` keys a numeric `throttle:N,1` limiter on the
caller and nothing else, so every unprefixed numeric limiter in the application
shared one counter per user: claiming a zone and writing four records spent the
zone-deletion allowance, and the next request was a 429 for a reason no client
could see and no log explained. Nine files were fixed and
`OneThrottleBucketPerVerbTest` walks the route table so the next one cannot
happen.

Two behaviours the team lifecycle pinned down rather than assumed: a removed
member gets `tenancy.account_unavailable`, the same answer an account that does
not exist gives, so nobody can enumerate accounts by watching which ids answer
differently; and a spent invitation token answers 409 rather than 404, which
distinguishes it from an invented one and is acceptable only because there are
64 random characters to guess.

Twenty-eight files under `tests/Feature/Security`.

## P. Audit coverage

Every act on the brief's list writes a row. Two did not, and both were found by
this audit rather than reported:

- **Spending wallet credit** wrote a transaction and no audit entry. It is the
  one payment path with no external processor behind it, so there is no
  gateway's own record to fall back on when somebody disputes it months later.
- **Calling a deletion off** wrote nothing, so a trail recorded that a deletion
  was requested and stopped — leaving the next reader certain the archive is
  gone and looking for a reason it is still on the datastore.

Both now record atomically. `RecordActAtomically` gained one capability for the
first: a describer may return null, and the only legitimate null is the replay
of an idempotent request that moved nothing. Three identical wallet payments
write one row, and a test asserts it.

43 audit actions, from 24 at the baseline.

## Q. Observability

Twelve metric families are new across this phase, all bounded, none carrying a
customer id, email, domain, hostname, address, ticket id, service id or task
id — enforced by `MetricsCarryNoIdentifiersTest`, which checks label names *and*
what the values look like.

| Family | Labels |
| --- | --- |
| `lynomia_invitation_total` | state |
| `lynomia_account_member_total` | role |
| `lynomia_wallet_entry_total` | kind |
| `lynomia_support_ticket_total` | status |
| `lynomia_support_backlog_age` | bucket |
| `lynomia_backup_deletion_total` | state |
| `lynomia_backup_retention_total` | disposition |
| `lynomia_service_retention_window_open` | reason |
| `lynomia_open_drift_total` | resource, kind |
| `lynomia_provider_task_total` | state |
| `lynomia_dns_zone_total`, `lynomia_dns_record_total` | state |
| `lynomia_dns_record_by_type_total` | type |

The wallet family reports counts and never balances, for the same reason the
labels carry no identifiers: a scrape endpoint is read by a system with
different access controls from this one.

Four ticket-age buckets come from one query with four FILTERs, and the three
retention dispositions from one query with three. `MetricsQueryBudgetTest`
allows 33 queries for the whole scrape, and the number moves only when a
collector is added.

## R. Queue and scheduler runtime proof

`TheNewSweepsRunOutsideThisProcessTest` runs three artisan commands in their own
processes, handed nothing but an environment, against committed rows, with a
worker somewhere else entirely. The chain the termination work depends on and
which had never run end to end — scheduler, sweep, Redis, worker, hypervisor,
verification — now runs in a test.

`tests/Feature/Queue` against a real Redis: 16 tests, 103 assertions, 49 s.

It found two defects.

**The retention sweep re-ended services it had already ended.** Terminating is
not instant: the VPS path queues a job a worker may not reach for minutes, and
the service stays suspended until it does, so the next daily pass found it
again. The provisioning job's idempotency key stopped a second machine being
destroyed; nothing stopped a second audit entry and a second email telling a
customer their data had been destroyed. `termination_requested_at` is the stamp
that was missing.

**The backup retention sweep ran on two clocks.** It captured `now`, decided
what was due against it, then marked rows with `now()` microseconds later. With
a zero-hour grace period that makes the outcome depend on which side of a
second boundary the two calls land — which is why CI run 58 failed on
PostgreSQL 18 and passed on 16 with identical code.

## S. Browser end-to-end

94 specs across 13 files, from 61 across 6 at the baseline. New in this phase:
`team.e2e.ts`, `wallet-credit.e2e.ts`, `support.e2e.ts`, `backup-deletion.e2e.ts`,
`dns.e2e.ts`, `leaving.e2e.ts`, `reconciliation.e2e.ts`.

`reconciliation.e2e.ts` is the operator proof the brief asks for: reading a
hosting account the panel has never heard of, seeing which resource it is,
closing a second finding with a written reason and watching it leave the open
list, reading the build whose hypervisor task never came back, and reading the
same finding in Arabic rather than as `missing_at_provider`.

**What writing that spec found.** Three of the five drift kinds had no
translation at all, so `orphan_at_provider` reached an operator's screen as its
enum value; the drift table showed which provider disagreed but never which
resource; and seven of the eleven provisioning job kinds were the same. Pulling
that thread found thirty-four more: `StatusBadge` is one component on seventeen
screens looking up one flat namespace, and its fallback turns a missing string
into snake case rather than an error — so every DNS state this phase added,
every backup state past `succeeded`, every dedicated reinstall step, both
invoice endings and every node state had been shipping untranslated, in Arabic
too. All are written now, in both languages, and
`EveryStateAScreenShowsIsTranslatedTest` binds each portal namespace to the
enums that reach it, so the next added case fails a test rather than a
customer's screen.

## T. Clean room

<!-- CLEANROOM -->

## U. GitHub Actions

Pushed and observed, not assumed. Every red run on this branch during the phase
is below with the jobs that failed and what fixed them. None was re-run into
green without a code change, and none is omitted.

**Run 67 — [`34235298388`](https://github.com/fullstackfull/cloud/actions/runs/34235298388)**
· commit `5201a86` · event `push` · branch `claude/hv-t6hq1p` · started
2026-09-08T13:58:28Z · **conclusion: `success`**.

| Job | Conclusion | Covers |
| --- | --- | --- |
| Backend (PHP 8.4, **PostgreSQL 16**) | `success` | Pint, migrations from empty, migrations reversible, the full 2 302-test suite |
| Backend (PHP 8.4, **PostgreSQL 18**) | `success` | the same, on the other supported major |
| Static analysis | `success` | PHPStan, 0 errors |
| Frontend | `success` | typecheck, lint, 54 unit tests, production build |
| API description | `success` | `docs/openapi.yaml`, 134 operations |
| Security checks | `success` | PHP and JavaScript dependency audits; committed-secret gate |
| Production guards | `success` | no fake provider outside the development template; no unresolved placeholders |
| Browser end-to-end | `success` | Playwright, 94 specs, against PostgreSQL and Redis services |

### The red runs

**Run 54 — [`34186862799`](https://github.com/fullstackfull/cloud/actions/runs/34186862799)**
· commit `75ec104` · failed job: **Frontend** only, at the lint step.
`wallet-credit.e2e.ts` line 14 used an `import()` type annotation, which
`@typescript-eslint/consistent-type-imports` forbids. A style rule, caught by
the gate that exists to catch it, fixed in the next commit. Green from run 55.

**Run 58 — [`34221867261`](https://github.com/fullstackfull/cloud/actions/runs/34221867261)**
· commit `14ca02e` · failed job: **Backend (PostgreSQL 18)** only; PostgreSQL 16
green in the same run.
`RetentionSweepTest::a_provider_that_accepts_and_keeps_the_archive_is_not_called_deleted`
saw `deletionsAccepted` of 0. The sweep captured `$now`, decided what was due
against it, then marked rows with `now()` microseconds later; with a zero-hour
grace period the outcome depends on which side of a second boundary the two
calls land, which is why identical code passed on the other major. Fixed in
`32043e2` by threading the sweep's own timestamp through
`RequestBackupDeletion::execute()`. **Green in run 61.**

**Run 59 — [`34224607678`](https://github.com/fullstackfull/cloud/actions/runs/34224607678)**
· commit `412121e` · failed job: **Browser end-to-end** only.
`wallet-credit.e2e.ts` found the "use credit" button still present after the
invoice settled. Not reproducible locally across several full runs, and green
in runs 60 and 61 with the same code. **Recorded as unexplained rather than
explained away.** The assertion now checks the paid badge before the absent
button, so a recurrence distinguishes "the settlement failed" from "the list
did not refresh" — two failures that need opposite investigations and looked
identical.

**Run 62 — [`34228553883`](https://github.com/fullstackfull/cloud/actions/runs/34228553883)**
· commit `d8c0df3` · failed jobs: **Backend (PostgreSQL 18)** and **Browser
end-to-end**; PostgreSQL 16, static analysis, frontend, API, security and
production guards all green. The PostgreSQL 18 failure is the two-clock defect
above, still unfixed at this commit. Both were fixed by `32043e2`, which is
**green in run 63** on every job.

**Runs 64, 65 and 66** ·
[`34232614706`](https://github.com/fullstackfull/cloud/actions/runs/34232614706),
[`34233222387`](https://github.com/fullstackfull/cloud/actions/runs/34233222387),
[`34234144767`](https://github.com/fullstackfull/cloud/actions/runs/34234144767)
· commits `1f6e4f3`, `a6c6713`, `62403c0` · failed job in each: **Browser
end-to-end** only; every other job green in all three, including both backend
majors.

One cause carried across three commits. Translating `needs_review` changed what
the screen says, and three specs had been matching the untranslated fallback:
`backup-deletion.e2e.ts` and `portal.e2e.ts` looked for the literal text
"needs review", and in `operations.e2e.ts` the new badge text collided with an
operator alert under Playwright's strict mode. The gate did exactly what it
exists for — the specs were asserting on a bug. Fixed in `5201a86`, which also
changed the label itself, and **green in run 67**.

Runs 52, 53, 55, 56, 57, 60, 61 and 63 were green on every job. Run 49
(`57f24e9`, before this phase's baseline) was cancelled by the push that
superseded it.

## V. Remaining product gaps

None of these is a Phase 30A+ objective. All are stated rather than left to be
discovered.

1. **Domain registration.** DNS is hosted here; the domain is bought elsewhere
   and delegated. Phase 30A++, planned and not built.
2. **WordPress, or any application install.** The hosting account is opened and
   the panel handed over. Also 30A++.
3. **Zone file import and export.** Records are added one at a time. A customer
   migrating fifty records will feel it.
4. **File-level restore.** A restore is the whole machine.
5. **Undoing a deletion after the sweep has run.** The grace period is the only
   window, on purpose.
6. **Changing an account's currency or country.** Both are pinned to the ledger;
   changing either would reprice open subscriptions, so it is an operator act
   through support.

## W. Intentional product decisions

- **Reconciliation never repairs.** Not at the provider and not in this
  platform's own rows. An account whose provenance nobody knows must not be
  handed to a customer as theirs, and on a suspension mismatch, whichever way a
  sweep guessed, half the time it would be switching off somebody who has paid.
- **No hard reset for a VPS, and no rail cut for a chassis.** Both discard
  whatever the guest had not flushed.
- **A failed provider task compensates nothing.** A build whose task failed may
  have left a disk, a machine, or nothing at all, and cleaning up on that guess
  is how the next customer gets an address that still answers for somebody else.
- **The customer closes a ticket; the team resolves one.** Two different acts,
  and only one of them is the customer's to make.
- **A dedicated server is returned to stock in two acts,** because nothing this
  platform can call proves a physical disk was erased.
- **Ownership is transferred, never granted.** Nobody can be invited as owner.

## X. External infrastructure blockers

Unchanged by this phase, and to be repeated in every report:

- **No Proxmox cluster** has created, resized, rebuilt, suspended or destroyed a
  machine for this platform, and none has answered `getTask` about one.
- **No physical server** has been reimaged.
- **No cPanel or DirectAdmin licence** is held, so the hosting reconciliation has
  never compared this platform against a real panel.
- **No payment gateway account** exists. Wallet credit is the one payment path
  proven end to end, and only because the money never leaves the platform.
- **No Cloudflare token** is held. No zone, record or PTR has reached a real
  resolver.
- **No mail has been sent.** Every notification, invitation and ticket reply
  proven here was written to a log.
- **No provider has deleted a backup.**

Nothing in this repository is classified `REAL_INFRA_VERIFIED`, and nothing
should be until a real vendor has answered.

## Y. Phase 30A++ roadmap

`docs/phase-30a-plusplus-domains-wordpress-plan.md` — Lynomia Domains and
WordPress hosting, planned and deliberately not built. The provider boundary for
registration is described and no registrar vendor is named; the `.sy` registry
API is not invented. `docs/build-status.md` places 30A++ between 30A+ and 30B.

## Z. Verdict

The brief asks two questions before sign-off. They are answered here rather
than assumed.

> **Does Lynomia Cloud contain any customer-facing or operator-facing
> capability that appears usable but cannot complete its intended lifecycle?**

**No** — with one qualification stated rather than hidden. Every capability on
the customer surface and the operator surface reaches a provider or completes
in the platform, and the matrix names the test that proves each. The
qualification is that six provider paths reach a *fake*: Proxmox, cPanel,
DirectAdmin, a payment gateway, Cloudflare and a BMC have never answered this
platform. Those are `BLOCKED_CREDENTIALS`, `BLOCKED_LICENSE` and
`BLOCKED_HARDWARE`, listed in §X, and they are not the same thing as a
capability that cannot complete: the platform's half is written, wired, guarded
and tested against a fake that refuses the way a real one would.

The screens are the part that had genuinely stopped short, and this phase found
it: a drift finding an operator could not read, and thirty-six states rendering
as raw enum values on a portal that ships in two languages. Both are closed and
both now have a gate.

> **Does any core capability exist only as a table, permission, adapter method,
> route, UI control, event or job without a real production caller or
> consumer?**

**No.** Nine architecture gates check this from nine directions and all pass:
`NoDeadCapabilitiesTest`, `NoDeadMethodsTest` (including
`no_reserved_method_has_quietly_acquired_a_caller`),
`EveryDomainEventIsConsumedTest`, `EveryAuditActionIsRecordedSomewhereTest`,
`HandlerCoverageTest`, `LayeringTest`, `AdminSurfaceTest`,
`OpenApiSpecificationTest` and, new in this phase,
`EveryStateAScreenShowsIsTranslatedTest`.

Eleven methods remain RESERVED. Each is listed in §K with the reason it has no
caller, and every reason is a product decision rather than "later" — the one
exception being `IssueInvoice::fromPricedOrder`, which is a second path into
the same table and whose removal is billing-sensitive work stated plainly
rather than done at the end of a phase about product closure.

### Exit conditions

| Condition | Required | Actual |
| --- | --- | --- |
| Team membership | `RUNTIME_VERIFIED` | `RUNTIME_VERIFIED` |
| Wallet spending | `RUNTIME_VERIFIED` | `RUNTIME_VERIFIED` |
| Support tickets | `RUNTIME_VERIFIED` | `RUNTIME_VERIFIED` |
| Backup retention / delete | COMPLETE | COMPLETE |
| Forward DNS | COMPLETE | COMPLETE |
| Customer termination | COMPLETE | COMPLETE |
| Hosting reconciliation | COMPLETE | COMPLETE |
| Provider task polling | COMPLETE | COMPLETE |
| Capability matrix | COMPLETE | COMPLETE |
| Dead capability audit | COMPLETE | COMPLETE |
| Domain event audit | COMPLETE | COMPLETE |
| Money audit | COMPLETE | COMPLETE |
| Whole-life flow audit | COMPLETE | COMPLETE |
| Security review | COMPLETE | COMPLETE |
| Audit coverage | COMPLETE | COMPLETE |
| Observability | COMPLETE | COMPLETE |
| Browser E2E | COMPLETE | COMPLETE |
| Real Redis queue proof | COMPLETE | COMPLETE |
| Scheduler proof | COMPLETE | COMPLETE |
| Clean room | COMPLETE | see §T |
| CI | GREEN | GREEN — run 67 |

**Phase 30A+ is closed.** What it was for — finding the places where this
platform described something it could not finish — is done, and the four
defects it uncovered along the way (a platform-wide throttle collision, a sweep
running on two clocks, a termination that repeated itself, and a portal
rendering its own enum values at customers in two languages) were all found by
building the proof rather than by reading the code.

What it is not: proof that any of this works against a real vendor. Nothing
here is `REAL_INFRA_VERIFIED`, and the first thing Phase 30A++ should not do is
assume otherwise.
