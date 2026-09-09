# Currency change needs review

**Alert:** `CurrencyChangeNeedsReview` — an account country/currency change
an operator approved was not applied, because the last check found a
blocker.

## What happened

A customer asked for their account's country or currency to change. The
platform analysed the account, an operator approved the request, and at the
moment of applying it — in the same request for "now", or from the
five-minute sweep for a scheduled moment — the platform ran the analysis
again and found something priced in the old currency that is still live: an
open invoice, an order between placement and provisioning, a domain
operation with the registrar, an active or past-due subscription, or a
wallet balance. **Nothing was applied.** The row is `needs_review`, the
blockers are on it, the customer has been told the change is on hold, and
the audit row `account.country_currency_change.blocked` says why.

## Why it matters

Nothing already issued is ever converted. Applying the change would have
left an invoice, an order or a subscription in a currency the account no
longer holds — a silent repricing at the next renewal, or an invoice the
customer cannot pay from the wallet they have. Holding is the correct
outcome; what is left is the decision.

## What to do

1. Open the queue at `/admin/customers/country-currency-changes` (or
   `GET /api/admin/customers/country-currency-changes?state=needs_review`).
   The blockers are listed on the row in words.

2. Decide with the customer:

   - The blocker will clear on its own (an invoice about to be paid, an
     order about to provision): wait, then **approve again**. Approval
     re-runs the analysis and refuses while the blocker stands, so approving
     early costs nothing and applies nothing.
   - The blocker is a subscription the customer wants to keep: **reject**
     with a note. The customer keeps the old currency; they may ask again
     once the subscription has ended.
   - The customer no longer wants the change: they withdraw it, or you
     reject it.

3. Do not edit the customer's `country` or `currency` columns by hand. The
   applying action is the one place they are written after registration,
   and it is what checks the facts and writes the audit row.

## What not to do

Do not convert anything. Do not void an invoice to clear a blocker without
the customer asking. Do not delete the row: it is the record of the ask and
of the hold, and the customer's notification points at it.
