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
