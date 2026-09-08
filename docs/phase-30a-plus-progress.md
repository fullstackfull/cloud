# Phase 30A+ — progress report

**Branch:** `claude/hv-t6hq1p` · **HEAD:** `fccfc77` · **CI:** run 55,
[`34189805048`](https://github.com/fullstackfull/cloud/actions/runs/34189805048),
all eight jobs `success`.

**Status: three of the eight product gaps are closed. Five are not started, and
this document says so rather than leaving it to be discovered.** This is an
interim report, written because one was asked for; the phase's own deliverable
— `docs/phase-30a-plus-core-product-completeness.md` — is not due until all
eight are closed, and writing it now would be writing a closure report for a
phase that is not closed.

Nothing here is `REAL_INFRA_VERIFIED`. No hypervisor, BMC, panel licence,
payment gateway or DNS token has ever answered this platform.

## Where the eight gaps stand

| # | Gap | Status | Evidence |
| --- | --- | --- | --- |
| 1 | Team / organization membership | `RUNTIME_VERIFIED` | 28 backend tests, 7 browser specs in both languages · `0e458ac` |
| 2 | Wallet credit spending | `RUNTIME_VERIFIED` | 70 wallet tests incl. a six-process race, 3 browser specs · `75ec104` |
| 3 | Support tickets | `RUNTIME_VERIFIED` | 21 backend tests, 6 browser specs · `fccfc77` |
| 4 | Backup retention and deletion | `NOT_IMPLEMENTED` | Not started. `deleteBackup` and `listBackups` still have no caller. |
| 5 | Forward DNS | `NOT_IMPLEMENTED` | Not started. The decision gate has not been answered. |
| 6 | Customer-initiated termination | `NOT_IMPLEMENTED` | Not started. Termination is still an operator act only. |
| 7 | Hosting reconciliation | `NOT_IMPLEMENTED` | Not started. `listAccounts` still has no caller. |
| 8 | Provider task polling | `NOT_IMPLEMENTED` | Not started. A reinstall's task id is still recorded and never polled. |

The supporting work the phase also asks for — the capability-matrix re-audit,
the money audit, the whole-life flow extensions, the security review, the clean
room and the final report — has **not** been started. Each of those is an audit
*of* the eight, and auditing three of eight would produce a document that goes
stale the moment the fourth lands.

## The measured numbers

Every figure produced by running the gate on this working tree at `fccfc77`.

| Gate | At the 30A+ baseline (`6c91d47`) | Now (`fccfc77`) |
| --- | --- | --- |
| Backend tests | 2 109 | **2 175** |
| Backend assertions | 56 120 | **56 407** |
| Browser specs | 61 | **76** |
| Frontend unit tests | 45 | 45 |
| PHPStan | 0 errors | 0 errors |
| Pint | passes | passes |
| Documented API operations | 96 | **124** |
| Backend test files | 254 | 263 |
| Browser spec files | 6 | 9 |
| Modules | 23 | 24 |
| Audit actions | 24 | 35 |

## What each closed gap actually is

### 1. Team membership — `0e458ac`

Every piece of the tenancy already existed and had never been reachable: a
`customer_members` table with a role and an `accepted_at`, a middleware that
resolves exactly one account per request and re-checks membership even for a
scoped token, a `roleWithin()` that refuses an unaccepted invitation. What was
missing was anything that *creates* a membership. Every customer was one login,
and the schema had been describing a feature nobody could use since Phase 1.

Two roles are new. `Billing` and `Technical` exist because a hosting account
asks two different questions of two different people — who may spend money, and
who may touch the machines — and answering both with "administrator" is how a
finance department ends up able to rebuild a server.

Six properties, each a test, and most are about what the feature must not
become: the token is never stored (a SHA-256 is), so a resend cannot repeat a
link and mints a new one; the invited address is the credential and must be
verified, so a forwarded invitation admits nobody; inviting an address that
already has a Lynomia login looks identical to inviting one that does not;
ownership moves in one transaction with both rows locked, so an account is
never ownerless and never owned twice.

### 2. Wallet spending — `75ec104`

`WalletLedger::debit` had been written, tested and left with no caller since the
ledger was built — it was listed in the dead-method gate as *"wallet credit
cannot be spent by anyone yet"*. An overpayment or a promotional credit landed
in a balance the customer could look at and nothing more.

It needed no new machinery. `SettleInvoice` already documented the contract a
wallet payment must meet, so a wallet payment writes the same `transactions`
row every other payment writes and goes through the same settlement action.

Two orderings carry the safety, and one took a failing test to find: the
idempotency question is asked **before** the payability question, because a
repeat of a *successful* request arrives at an invoice the first one settled,
and checking payability first tells a client its payment failed when it had
worked.

Six real operating-system processes, started at one instant against one
balance, prove the rest: exactly one wins, the balance never goes below zero,
and the invoice is credited with exactly what the wallet paid.

Refunds now have a written policy: **money goes back the way it came.** A refund
names one payment, so the channel follows from which payment is being reversed.
The three alternatives are rejected with reasons in `docs/billing.md`.

### 3. Support tickets — `fccfc77`

Support existed as three permissions on a role enum and nothing else. Every
customer role has carried `support.manage` since Phase 1, so the platform had
been telling five kinds of member they may manage support for a system that did
not exist.

The status follows from who spoke and nobody sets it by hand. An internal note
deliberately moves nothing and does not stamp the first-response time, so a
team cannot post a note to itself and record that as having answered a
customer.

Two asymmetries, both deliberate. A customer may **close** and may not
**resolve** — resolved is the team's opinion that the problem is solved, closed
is the account saying it is finished. And a customer may choose low, normal or
high but not **urgent**: urgent is what pages somebody out of hours, and a
priority anyone can self-select stops meaning anything within a month.

Attachments are where a support queue becomes an attack on the people reading
it: the type is read from the bytes, SVG is off the allow list, the stored path
is generated, the name is flattened before it reaches a `Content-Disposition`
header, and downloads go out as attachments with `nosniff`.

## Two things found that were not on the list

**A test that had been silently exempting routes.** `AdminSurfaceTest` asserts
that a customer gets 403 rather than 404 on *every* administrative route. It
substituted a placeholder containing the letter `l`, which Crockford base32
excludes — so every route constraining its parameter with `whereUlid` was
answered 404 by the router before any middleware ran, and was silently exempt
from the assertion. The placeholder is now a valid ULID and a second test
guards it.

**A test double that would have proved the opposite of its name.** The
attachment tests originally used `UploadedFile::fake()`, whose `getMimeType()`
answers from the *filename*. A test written against it proves the platform
trusts the file extension — which is precisely the thing those tests exist to
disprove. They now write real files to disk so the content guesser runs.

## The CI record, including the red run

| Run | Commit | Result |
| --- | --- | --- |
| 52 [`34183894898`](https://github.com/fullstackfull/cloud/actions/runs/34183894898) | `14d9cdb` baseline | `success` |
| 53 [`34185438621`](https://github.com/fullstackfull/cloud/actions/runs/34185438621) | `0e458ac` team | `success` |
| 54 [`34186862799`](https://github.com/fullstackfull/cloud/actions/runs/34186862799) | `75ec104` wallet | **`failure`** |
| 55 [`34189805048`](https://github.com/fullstackfull/cloud/actions/runs/34189805048) | `fccfc77` support | `success` |

Run 54 failed on the **Lint** step and on nothing else — both backend jobs, the
browser suite, PHPStan, the API description, the security checks and the
production guards all passed on that commit. The cause was one ESLint rule
(`consistent-type-imports`) in a browser spec.

It is recorded here because the process failure is worth more than the fix: the
wallet commit was pushed after running the backend suite, the browser suite and
PHPStan, but **before** running ESLint. Local green on three gates out of five
is not local green. The rule was fixed in the next commit, and run 55 is clean.

## What is next, in order

1. **30A+.4** Backup retention and deletion — plan-level policy, a deletion
   request state, a scheduled sweep that marks deleted only after the provider
   confirms, and refusals while a restore or a verification is running.
2. **30A+.5** Forward DNS — answer the decision gate explicitly. The adapter
   already supports exactly the six record types the brief lists, which is an
   argument for implementing rather than for `INTENTIONALLY_NOT_A_PRODUCT`, but
   the decision has not been made and this report does not pre-empt it.
3. **30A+.6** Customer-initiated termination, preserving the retention window
   and the two-act physical-server safety.
4. **30A+.7** Hosting reconciliation — detect and record, never auto-adopt and
   never auto-delete.
5. **30A+.8** Provider task polling, under the Timeout Rule.
6. Then the audits, the whole-life extensions, the security review, the clean
   room, an observed CI run, and the phase's final report.
