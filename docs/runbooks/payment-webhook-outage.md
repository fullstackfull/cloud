# Payment webhooks have stopped

## What you are seeing

One of the two alerts that send you here:

- **`PaymentWebhookFailures`** (critical, pages) — more than three webhook events
  from one provider failed processing within 15 minutes. Each is a payment event
  the platform has not recorded.
- **`PaymentWebhooksStopped`** (warning, platform channel) — no webhook event of
  any kind has arrived in the trailing six hours, and that has stayed true for
  an hour. It fires about seven hours after the last event arrived, not six.

A third payments alert, `FailedPaymentsElevated`, is about declines — payment
attempts refused at the gateway — and sends you to `payment-reconciliation.md`,
not here.

## What it means

This is the failure that makes customers pay for nothing. An order is paid at
the gateway, the webhook never arrives, and Lynomia never provisions. The
customer has been charged and has no service.

Quiet is not evidence of no trade. Check before concluding it is a slow evening.

## Check first

```bash
php artisan payments:reconcile --older-than=60
```

That asks the provider about attempts that never produced a webhook, which is
the exact shape of this failure. Then, at the gateway's own dashboard: are there
charges in the last hour that Lynomia has no record of? That comparison —
gateway first, Lynomia second — is the whole diagnosis.

## What to do

1. If the gateway shows delivery failures, fix the endpoint: TLS, DNS, firewall.
   The gateway will usually redeliver on its own once it succeeds again.
2. For charges that will not redeliver, run `payments:reconcile` again once the
   endpoint is healthy. It asks the provider directly rather than waiting for a
   webhook, and it is safe to run repeatedly: settling an already-settled
   attempt is a no-op.

3. Every settled payment then flows through the normal listener and provisions.
   Verify a sample actually reached a live service rather than assuming.

## What not to do

Do not mark invoices paid by hand to unblock provisioning. The payment record
is what the refund path, the ledger and the customer's receipt are built from.
Do not refund a "duplicate" charge before confirming at the gateway that it is
one — two charges with the same idempotency key are one charge.
