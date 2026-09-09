# Domain redemption indeterminate

**Alert:** `DomainRedemptionNeedsReview` — a paid recovery of a lapsed name
has no confirmed answer from the registrar.

## What happened

A customer paid the redemption penalty for a name in `redemption`. The
platform asked the registrar to restore it and the registrar did not answer
before the deadline, or answered in a way the platform could not classify.
The operation is `indeterminate` (or `needs_review`) and the domain row is
`indeterminate`. **The platform will not try again**: the registry may have
restored the name and charged the fee, and a second attempt pays twice.

## Why it matters

The registry's redemption window keeps running. If the name was not
restored, it will be released to anybody when the window closes; if it was,
the customer is waiting to be told. Either way somebody has to look.

## What to do

1. Run the reconciliation, which asks the registrar what it holds:

   ```sh
   php artisan domains:reconcile
   ```

   A registry that answers settles the row: restored names go `active` with
   the registry's expiry; names still lapsed go `expired` and the lifecycle
   sweep walks them on by the registry's clock.

2. If the registrar cannot be asked (`unreachable` in the command's output),
   check the registry's own console for the name. Then resolve the operation
   from the operator domain queue at `/admin/domains` with what you found.

3. If the name was **not** restored and the window is still open, the
   customer may order the recovery again once the operation is resolved as
   failed; the refund of the first penalty is arranged through the invoice.

4. If the window has closed, tell the customer. The name is gone from this
   platform's point of view and nothing here brings it back.

## What not to do

- Do not re-dispatch the job or re-run the payment listener.
- Do not move the domain to `active` by hand without the registry's answer.
- Do not delete the domain row: the invoice refers to it.
