# Runbook — payment reconciliation

**When to use this:** the platform's record of a payment disagrees with the provider's,
or a customer says they paid for a service that is not active.

## 0. Establish what you are looking at

Never start from the customer's account. Start from the money.

```bash
# Everything the platform recorded for this customer, newest first.
php artisan tinker --execute="
  \Lynomia\Modules\Payments\Infrastructure\Models\Transaction::query()
    ->where('customer_id', '<CUSTOMER_ULID>')
    ->latest()->take(20)
    ->get(['id','provider','provider_reference','status','amount_minor','currency','created_at'])
    ->each(fn(\$t) => print(\$t->toJson().PHP_EOL));
"
```

Then look the same charge up in the provider's own dashboard by
`provider_reference`. The provider is authoritative for whether money moved; Lynomia is
authoritative for what the customer is entitled to.

## 1. The four states a disagreement can be in

| Provider says | Lynomia says | Meaning | Action |
|---|---|---|---|
| Captured | Captured | Agreement | Look elsewhere — the fault is downstream, go to §4 |
| Captured | Nothing | A webhook was lost or rejected | §2 |
| Captured | Failed | The webhook arrived out of order | §2 |
| Nothing | Captured | **Stop.** Do not refund. §3 |

## 2. Provider captured, Lynomia did not record it

This is the common case and it is recoverable. Almost always one of:

- the webhook was delivered while the platform was down or deploying;
- the signature failed because `STRIPE_WEBHOOK_SECRET` did not match the endpoint's
  secret (check *which* endpoint — a test-mode secret on a live endpoint fails silently
  from the operator's point of view);
- the event was received but processing threw.

Check whether it was received at all:

```sql
SELECT id, status, attempts, last_error, created_at
FROM webhook_events
WHERE provider = 'stripe' AND provider_event_id = '<EVT_ID>';
```

**If the row exists with `status = 'failed'`:** the payload is stored. Replay it —
processing is idempotent, so a replay cannot double-capture.

**If there is no row:** the event never arrived or was rejected before storage. Do not
hand-write a transaction. Re-drive it from the provider instead, so the amount, currency
and reference come from the provider rather than from a human:

```bash
php artisan payments:retrieve --provider=stripe --reference=<PAYMENT_INTENT_ID>
```

Then confirm the invoice settled and the order advanced:

```sql
SELECT number, status, total_minor, amount_paid_minor, amount_due_minor
FROM invoices WHERE id = '<INVOICE_ULID>';
```

## 3. Lynomia recorded a capture the provider does not have

**Do not issue a refund.** There is no money to return, and a refund against a
non-existent charge either fails or — worse, on some providers — succeeds against a
different charge.

This state means either a fake provider was active in an environment that should not have
had one, or a transaction row was written by hand. Both are incidents, not tickets.

1. Check which provider the environment is actually using:
   ```bash
   php artisan tinker --execute="print_r(config('billing.providers'));"
   ```
   A `fake` here in production should have been refused at boot — if it was not, the
   guard has been bypassed and that is the finding.
2. Preserve the transaction row. Do not delete it; mark it and record why.
3. Suspend the affected service rather than terminating it, so the customer keeps their
   data while the commercial question is settled.
4. Raise an incident. A phantom capture means the platform's records cannot be trusted
   until the cause is known.

## 4. Both agree the payment succeeded, but the service is not active

The money is fine; provisioning is stuck. Check the order's own history first — it
records who or what moved it and why:

```sql
SELECT from_status, to_status, actor_type, reason, correlation_id, created_at
FROM order_transitions WHERE order_id = '<ORDER_ULID>' ORDER BY created_at;
```

- **Stopped at `paid`:** the provisioning job was never dispatched. Check Horizon for a
  failed job with that order's ID and retry it.
- **Stopped at `provisioning_failed`:** read the provisioning attempts for the real
  error. Retrying blindly against a full hypervisor or an exhausted IP pool just fails
  again.
- **Stopped at `manual_review`:** it is waiting for a human by design. The `reason`
  column says which one.

Use the `correlation_id` from the transition to pull every log line for that request:

```bash
grep '<CORRELATION_ID>' storage/logs/lynomia.json | jq .
```

## 5. Duplicate charges

A customer charged twice is an urgent ticket. Confirm it is genuinely a duplicate rather
than two legitimate orders:

```sql
SELECT id, provider_reference, amount_minor, currency, created_at
FROM transactions
WHERE customer_id = '<CUSTOMER_ULID>' AND status = 'succeeded'
ORDER BY created_at;
```

Two rows with **different** `provider_reference` values and the same amount means two
real charges — the idempotency guard did not engage, which is a bug worth finding, not
just a refund to issue. Two rows with the same reference should be impossible: the
unique index prevents it. If you see it, the index is missing on that environment.

Refund the later charge with a recorded reason, then investigate why checkout produced
two payment intents.

## 6. Closing out

Whatever the cause, record on the customer's account what happened and what was done. The
next person to see this account should not have to reconstruct it from logs.
