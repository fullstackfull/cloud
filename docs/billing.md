# Billing

## Money

Money is an integer number of **minor units** plus an ISO-4217 currency, wrapped in a
`Money` value object over `brick/money`. It is never a float, never a `decimal`
database column, and never a bare integer once it leaves the persistence layer.

```text
column pair:   amount_minor BIGINT   +   currency CHAR(3)
in code:       Money::ofMinor(12500, 'KWD')   →   "12.500 KWD"
```

### Why not a decimal column

PostgreSQL's `numeric` is exact, but the value still becomes a PHP float the moment it
crosses the driver boundary unless every read is guarded. An integer column cannot be
misread, and the constraint is enforced by the type system rather than by discipline.

### Three-decimal currencies

KWD, BHD, OMR and TND have three minor digits. A platform that assumes two
misstates every Kuwaiti invoice by up to nine fils and, worse, does so silently.
`Money` takes the exponent from the currency, so `12.500 KWD` is 12500 minor units
and `19.99 USD` is 1999 — with no per-currency branch anywhere in the application.

### Rounding is always explicit

Every operation that can lose precision requires a `RoundingMode` argument. There is no
default, so a rounding decision is always a visible decision at the call site:

```php
$vat = $net->multipliedBy('0.15', RoundingMode::HalfUp);   // rate is a string, never 0.15
```

A rate passed as a float would be wrong before arithmetic even began: `0.15` as an IEEE
double is actually 0.1499999999999999944488848768742172978818416595458984375.

## The pricing engine

`PricingEngine::price()` takes lines, a tax rate and an optional discount, and returns a
`PricedOrder` whose totals are **the sum of its lines**, exactly.

### Discounts are allocated, not subtracted

A discount is distributed across lines in proportion to their gross value, using
integer-weighted allocation on minor units. Two properties follow:

1. The allocated parts sum to exactly the discount — no fils created, none lost.
2. Tax, which is computed per line, applies to the correct taxable base.

Subtracting a discount from the order total instead would produce a tax figure that
cannot be attributed to any line, which is both wrong and unauditable.

Lines marked non-discountable — typically setup fees — are excluded from the base a
percentage applies to, so a 10% coupon cannot quietly discount something the catalogue
protects.

A coupon worth more than the order clamps to the order. A negative total would read as
the platform owing the customer money.

### Tax-inclusive pricing

Where a jurisdiction advertises prices with tax included, net is computed by division and
tax is taken as the **remainder**, never rounded a second time:

```text
net = gross ÷ (1 + rate),   tax = gross − net
```

So `net + tax` is always exactly the price the customer was shown.

### Proration

Proration measures whole seconds between the period start and end, so an hourly plan and
a yearly plan share one rule. Both the unused-time credit and the new charge use the same
divisor, which is what makes an upgrade followed by an immediate downgrade net to exactly
zero rather than leaking a fils on each change.

## Invoice numbering

Numbers come from a PostgreSQL sequence, not from `MAX(number) + 1`.

The read-modify-write approach is not merely slower. Under two concurrent renewal
workers it hands both the same number, and one of the two invoices then violates a unique
constraint *after* its line items have been written.

Sequences skip a value when a transaction rolls back. That trade is deliberate: a gap is
an accounting question an operator can answer from the issued-invoice report, whereas a
duplicate invoice number is a legal problem. Where a jurisdiction requires strictly
gapless numbering, that is a reconciliation report over issued invoices — not a change to
how numbers are allocated.

## Immutability of issued documents

An invoice past `draft` is a document the customer has seen and may have filed for tax.
Its number, its line amounts and its `billing_snapshot` are frozen from that moment. A
customer who later corrects their address changes their *account*, not their history.

Corrections to an issued invoice are made by voiding and reissuing, or by a credit note —
never by editing.

## Order lifecycle

```text
DRAFT → PENDING_PAYMENT → PAID → QUEUED_FOR_PROVISIONING → PROVISIONING → ACTIVE
```

with failure paths to `PAYMENT_FAILED`, `PROVISIONING_FAILED`, `MANUAL_REVIEW`,
`SUSPENDED`, `CANCELLED`, `REFUNDED` and `TERMINATED`.

The transition table in `OrderStateMachine` is the entire specification. No code assigns
the status column; every change goes through `TransitionOrder`, which validates the
transition, re-reads the row under lock, and records the actor, reason, context and
correlation ID.

Two rules matter more than the rest:

- **An unpaid order can never reach provisioning.** This is what decides whether the
  platform builds servers it was not paid for.
- **Terminal states have no exits.** A refunded order cannot become active again, which
  would hand a customer a running service they have been repaid for.

## Payment confirmation

A service is provisioned **only** after a server-side confirmation: a webhook whose
signature has been verified, or an explicit retrieve against the provider's API.

Returning to a `?success=true` URL confirms nothing. That URL is under the customer's
control and can be visited without any payment taking place.

Webhook handling is idempotent by construction. The provider's own event ID is written to
`webhook_events` under a unique constraint **before** the event is acted on, so a
redelivery — which every provider does routinely — inserts nothing and therefore captures
nothing a second time.

## Dunning

```text
payment fails  → past_due     (service keeps running)
grace expires  → suspended    (service stops, data retained)
retention ends → terminated   (resources released)
```

`past_due` deliberately keeps the service running. An expired card is not non-payment,
and cutting a customer's servers the moment a renewal declines loses accounts that would
have paid within the day. The grace and termination windows are configurable per
deployment.

## Wallet

The wallet ledger is the source of truth; `wallets.balance_minor` is a cache. Every entry
records the balance after it, so the ledger can be audited without replaying it, and a
scheduled reconciliation re-derives the balance and **reports** divergence rather than
silently correcting it — a cache that quietly repairs itself hides the bug that corrupted
it.

Balances are per (customer, currency). They are never mixed and never converted.

### Spending it

Credit is spent **against an invoice**, through `POST /invoices/{invoice}/wallet-credit`.
There is no bare debit endpoint: the floor that stops a balance going negative is enforced
inside `WalletLedger` under a row lock, and a second way in would be a second place for
that check to be missing.

A wallet payment is a payment like any other. It writes the same `transactions` row every
other payment writes — provider `wallet`, kind charge, status succeeded — and hands it to
the same `SettleInvoice`. Nothing about invoices, dunning, overpayment or refunds needed a
second code path, and there is no arithmetic in the wallet module that could disagree with
the arithmetic in the billing one.

**There is no `amount` in the request.** How much is applied is `min(balance, amount due)`,
computed under a lock; a figure from the client would be a second opinion about something
with one right answer. Partial payment is the ordinary case: 30 KWD of credit against a
100 KWD invoice pays 30 and leaves 70 payable by card.

**Order of locks:** invoice → wallet → charge. The external payments path takes charge →
invoice → wallet. The charge in the wallet path is a row that transaction has just
created, which no other transaction can hold, so the two orders cannot form a cycle.

**Repeating the request.** The `Idempotency-Key` header is required, and it is recorded on
the *ledger entry* rather than in a table of its own. The replay is checked before
anything else — before the payability check — because a repeat of a successful request
arrives at an invoice the first one settled, and answering it `invoice.not_payable` would
tell a client its payment failed when it had worked.

### Refunds of mixed payments

**Money goes back the way it came.** An invoice can be paid partly from credit and partly
by card, and each half is its own transaction row. A refund names one of those rows, so
the channel follows from which payment is being reversed: the card half to the card, the
wallet half to the wallet.

The alternatives were considered and rejected:

| Policy | Why not |
| --- | --- |
| Everything to the card | Pays out real money for credit the customer was given rather than paid — the platform converting promotional credit into cash. |
| Everything to the wallet | Takes back money the customer actually paid and hands them a voucher. That is not a refund. |
| Split by ratio | Gives a different answer depending on the order the payments happened to arrive in, and no way to explain the number on a statement. |

A wallet refund makes no network call, because the money never left. It is a credit
through `WalletLedger`, succeeded at once — there is no pending state for a transfer that
cannot fail halfway. Its idempotency key is the refund row's own id, so a retried refund
credits once.

## Changing an account's country or currency

An account is registered with one currency and one country, and everything
it is billed is written in that currency with that country's tax. Both can
change later — but as a **request**, analysed and decided, never as a
setting flipped on a form. The rule that makes the workflow what it is:

**Nothing already written is ever converted.** An invoice keeps the
currency and the tax it was issued with. A payment keeps what was paid. An
order keeps what was ordered. A subscription keeps the currency it was sold
in. A wallet balance is not exchanged. The change sets what the account is
billed in *from that moment on*, and every document before it stands as it
was.

### What a currency change is blocked by

The account must have nothing priced in the old currency that is still
live. Each of these is a blocker, reported in words on the request:

| Blocker | Why | What clears it |
| --- | --- | --- |
| An open invoice in the old currency | It would be paid in a currency the account no longer holds | Pay it, or an operator voids it |
| An order between placement and provisioning | It has a price in the old currency and an invoice on the way | Let it complete, or cancel it |
| A domain operation with the registrar | Its quote and any refund are in the old currency | Let it finish |
| An active, past-due or suspended subscription | A renewal is a new invoice; issuing it in a currency the account does not hold is a silent repricing | End the subscription, then order again from the new price list, knowingly |
| A wallet balance in the old currency | Credit is never exchanged | Spend it, or ask for a refund |
| No price in the new currency anywhere in the catalogue | The account could be billed nothing | An operator prices the catalogue in that currency |

A **country-only** change (same currency) is not blocked by any of these.
It changes the tax rate on invoices issued from then on; invoices already
issued keep the tax they were issued with. Both rates are shown on the
request as a warning.

### The lifecycle

```
requested ─► blocked ◄──────────────┐        (customer re-checks after
    │            │                  │         paying / ending / spending)
    │            └─► awaiting_approval ─► scheduled ─► applied
    │                     │                  │
    │                     └─► rejected       └─► needs_review ─► (approve again | rejected)
    └─► withdrawn (any open state, until applied)
```

- The analysis runs when the customer asks, when they re-check, when an
  operator approves, and once more at the moment of applying. There is no
  stored "analysing" state: a request is `blocked` or `awaiting_approval`
  the moment it exists.
- Approval needs `customer.update` and a note. Approved for now, the
  account changes in the same request. Approved with `apply_at`, the change
  is `scheduled` and `customers:apply-country-currency-changes` applies it
  at that moment — the start of the next billing month, typically.
- A blocker that appears between approval and application (an invoice
  issued, an order placed) holds the change in `needs_review`. Nothing is
  written, the customer is told the change is on hold, the alert
  `CurrencyChangeNeedsReview` fires, and a person decides — see
  `docs/runbooks/currency-change-needs-review.md`.
- One open request per account.

### What is recorded

Audit: `account.country_currency_change.requested`, `.withdrawn`,
`.approved`, `.rejected`, `.applied` (with the before and after), and
`.blocked` (with the blockers, when the last check held it). Notifications:
applied, rejected (with the operator's note), and on hold. Metric:
`lynomia_country_currency_changes_total{state}`.

The applying action is the only place the account's `country` and
`currency` columns are written after registration.
