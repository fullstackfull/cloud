# Payment webhooks have stopped

## What you are seeing

`WebhookEventsStopped` or `PaymentsFailing`.

## What it means

This is the failure that makes customers pay for nothing. An order is paid at
the gateway, the webhook never arrives, and Lynomia never provisions. The
customer has been charged and has no service.

Quiet is not evidence of no trade. Check before concluding it is a slow evening.

## Check first

```bash
php artisan lynomia:payments --state=pending --older-than=1h
```

Then, at the gateway's own dashboard: are there charges in the last hour that
Lynomia has no record of? That comparison — gateway first, Lynomia second — is
the whole diagnosis.

## What to do

1. If the gateway shows delivery failures, fix the endpoint: TLS, DNS, firewall.
   The gateway will usually redeliver on its own once it succeeds again.
2. For charges that will not redeliver, replay them:

```bash
php artisan lynomia:payments:replay --event=<gateway event id>
```

Replay is idempotent on the gateway's event id, so replaying one twice is safe.

3. Every replayed payment then flows through the normal listener and provisions.
   Verify a sample actually reached a live service rather than assuming.

## What not to do

Do not mark invoices paid by hand to unblock provisioning. The payment record
is what the refund path, the ledger and the customer's receipt are built from.
Do not refund a "duplicate" charge before confirming at the gateway that it is
one — two charges with the same idempotency key are one charge.
