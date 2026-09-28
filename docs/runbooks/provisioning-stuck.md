# Runbook — provisioning stuck

**When to use this:** an order is paid but the service never became active, or a
provisioning job has been running far longer than its type should take.

## 0. Read the order's own history first

Every status change is recorded with who caused it and why. That is faster than any log
search and it is authoritative.

```sql
SELECT from_status, to_status, actor_type, reason, correlation_id, created_at
FROM order_transitions
WHERE order_id = '<ORDER_ULID>'
ORDER BY created_at;
```

Where it stopped tells you which section below applies.

## 1. Stopped at `paid` — the job was never dispatched

The payment landed, but nothing picked it up. Usually Horizon was not running, or the
`provisioning` queue is not in its supervisor's queue list.

```bash
php artisan horizon:status
php artisan queue:monitor provisioning:100
```

Check whether the job exists at all:

```sql
SELECT id, status, attempts, last_error FROM provisioning_jobs WHERE order_id = '<ORDER_ULID>';
```

**No row:** the dispatch never happened. Re-dispatch — the job is idempotent on its
idempotency key, so this cannot create a second resource:

```
POST /admin/provisioning/jobs/{job}/retry
```

Re-driving is an operator action, not a shell command: it is idempotent on the
job's idempotency key so it cannot create a second resource, and it is recorded
as an audit entry against whoever asked for it.

**A row with `attempts = 0`:** it is queued and the worker is not consuming. Fix the
worker; do not re-dispatch.

## 2. Stopped at `provisioning` — the job is in flight or wedged

Find out whether the provider is actually doing something. This is the distinction that
matters: a slow hypervisor is not a failure, and treating it as one is how duplicate VMs
get created.

```sql
SELECT id, provider, remote_job_id, attempts, started_at, last_error
FROM provisioning_jobs WHERE order_id = '<ORDER_ULID>';
```

If `remote_job_id` is set, ask the provider directly:

```bash
php artisan compute:poll-tasks
```

This asks every cluster whether the tasks it accepted actually finished, and
updates the jobs it can settle. What it cannot settle stays visible at
`GET /admin/provisioning/needs-review`.

- **Provider says running:** wait. Note the expected duration for the operation; a disk
  clone of a large template legitimately takes minutes.
- **Provider says finished successfully:** the platform missed the completion. Reconcile
  rather than retry — the resource exists:
  ```bash
  php artisan infrastructure:reconcile --cluster=<CLUSTER>
  ```
  Reconciliation records the difference; it does not change the provider. Work
  the resulting row through `drift.md`.
- **Provider says failed:** go to §3.
- **Provider has no such task:** the task expired from the provider's history. Do **not**
  retry blindly; go to §4 and check for an orphan first.

## 3. Stopped at `provisioning_failed`

Read the attempt records — each one stores the sanitised provider response.

```sql
SELECT attempt_number, error_code, error_message, created_at
FROM provisioning_attempts WHERE provisioning_job_id = '<JOB_ULID>'
ORDER BY attempt_number;
```

Match the cause before retrying:

| Cause | What to do |
|---|---|
| Node out of memory or disk | Put the node in maintenance so the scheduler stops choosing it, then retry — placement will pick another |
| IP pool exhausted | Add capacity or free quarantined addresses. Retrying without capacity just fails again |
| Template missing on the target node | Sync the template, then retry |
| Provider authentication failed | Fix the API token. Every job will be failing, not just this one — check the failure rate before assuming it is one order |
| Timeout after the resource was created | **Do not retry.** Go to §4 |

Retry once the cause is addressed:

```
POST /admin/provisioning/jobs/{job}/retry
```

## 4. Timeout after creation — the dangerous case

A timeout is not a failure. It means the platform stopped waiting, not that the provider
stopped working. Retrying here is how a customer ends up with two VMs and the provider
ends up with one of them unbilled and unmanaged.

Look for the resource before doing anything:

```bash
php artisan infrastructure:reconcile --cluster=<CLUSTER>
```

Reconciliation compares the cluster against what the platform believes and
records anything it finds that Lynomia does not know about. Those land in the
drift queue at `GET /admin/drift`.

- **The resource exists:** adopt it. Never create a second.
  ```
  POST /admin/provisioning/jobs/{job}/adopt
  ```
  Adoption is deliberately a person's decision with an audit entry, taken after
  looking at the provider — not a command that could be run in a loop.
- **It genuinely does not exist:** release the reserved IP and capacity, then retry.

This is why provisioning jobs persist the provider's own job ID *before* the call is
considered in flight: without it, a timeout is unrecoverable and every recovery is a
guess.

## 5. Stopped at `manual_review`

Waiting for a human by design. The `reason` on the transition says which decision is
needed — usually a risk hold, or a dedicated server with no matching hardware free.

Resolve the underlying question, then move it on with a recorded reason. Never move an
order out of review without one; the next person needs to know why it was released.

## 6. A job that changes a machine or an account, stuck in review

Not every job in review is a build. A resize, a package change or a power
change (start, stop, restart) acts on something that already exists, and
**adopt is refused for all of them** (`provisioning.adoption_not_a_build`):
there is nothing to adopt. The finding codes for a resize are in
`provider-indeterminate.md`.

**A resize whose task cannot be asked about.** The job's `result` holds
`resize_task_in_flight` (the task's UPID and node; an attempt that found the
task running named it in its error message too), and its attempts ran out on
`compute.provider_request_failed`: the hypervisor was never able to say what
became of the task.

```sql
SELECT result->'resize_task_in_flight' FROM provisioning_jobs WHERE id = '<JOB_ULID>';
```

Before retrying, look for that task on that node:

- **It is still running:** wait for it to finish, then retry.
- **It finished, or the node has no such task:** retry. The retry asks about
  the task once more, and if that fails too it gives up on the task — recorded
  on the job as `resize_task_unaskable` and logged — and looks at the machine:
  a machine already the shape asked for is recorded as it is and the job
  succeeds; what is still missing is asked for, measured from what the
  hypervisor reports.

Retrying while the task is in fact still running, with a disk growth not yet
landed, grows the disk twice: the look sees the old disk. That is why you look
for the task first.

**A job whose service has ended.** A resize, a package change or a power change
in review on a service that is `terminated` cannot be retried
(`provisioning.retry_after_the_service_ended`) or adopted. Close it:

```
POST /admin/provisioning/jobs/{job}/close      # evidence required
```

The needs-review list marks such a job `closable`, and the portal offers
**Close the job** on it. Closing moves the job to `cancelled` without running
it, records who closed it on the job and in the audit trail
(`provisioning.closed`, with your evidence), and asks nothing of any provider.
It moves money in one case: a resize or package change delivering a **paid**
plan change (its key ends `:invoice:<INVOICE_ULID>`) has what that invoice
still holds returned to the customer's wallet with the close, in the same
transaction - a `subscription.plan_changed` audit entry with the reason
`plan_change_not_delivered_before_the_end`, the change stamped `returned_at`,
and the customer told once. Usually the service's end has already returned it,
and the close returns nothing more; a close whose return fails is refused, so
the job stays on the list. It is refused (409) for a job not in review
(`provisioning.close_not_in_review`), for a build, a destroy, a rebuild or a
WordPress job (`provisioning.close_not_for_this_kind` — a build or a destroy
may have left a resource: §4), and while the service has not ended
(`provisioning.close_service_not_ended` — retry it instead). It needs the
`provisioning.retry` permission, as retry and adopt do.

**A paid plan change whose job stopped on a live service.** A resize or package
change queued under `plan-change:<SUBSCRIPTION>:<PLAN>:invoice:<INVOICE_ULID>`
that is in review or `failed` while its service is still live holds the
customer's payment for the change: nothing returns it automatically while the
service lives (`docs/billing.md`). It is returned without you only if the
service ends first. The customer has been told the payment is held until the
change is made or returned. Decide which:

1. **Complete it.** Make the room or fix the cause, then retry the job
   (`POST /admin/provisioning/jobs/{job}/retry`). A retry that succeeds
   delivers the change, and nothing is returned.
2. **Return it**, when it cannot be delivered. Find the charge on the invoice
   and refund what the invoice still holds:

   ```sql
   SELECT id, provider, amount_minor FROM transactions
    WHERE invoice_id = '<INVOICE_ULID>' AND kind = 'charge' AND status = 'succeeded';
   ```

   ```
   POST /admin/transactions/{transaction}/refunds   # amount_minor, reason required
   ```

   A charge paid from the wallet is refunded to the wallet; a card charge to
   the card. It needs the `payment.refund` permission. Do not retry the job
   afterwards: a retry that then succeeds delivers a change already refunded.
   What the refund does **not** do: move the subscription back to the plan it
   came from - it stays on the new plan, and a renewal bills its price (the
   customer's messages say so). No operator route moves a subscription's plan.
   The customer can change the plan back themselves once the job is not in
   review (a job in review holds every plan change, `service_busy`); a job in
   review on a live service stays there until the service ends, when it is
   closable, and the close then returns nothing more - the refund already took
   what the invoice held.

## 7. If nothing above fits

Pull every log line for the request that created the order:

```bash
grep '<CORRELATION_ID>' storage/logs/lynomia.json | jq -r '[.datetime,.level_name,.message]|@tsv'
```

The correlation ID flows from the HTTP request into the job and into every provider call,
so this is the whole story in one query.

## What never to do

- **Never create a resource by hand to "unblock" a customer.** It will not be in the
  platform's inventory, so it will not be billed, monitored, backed up or destroyed on
  cancellation.
- **Never retry a timed-out creation without checking for an orphan.**
- **Never clear a failed job to make a dashboard green.** The failure is the only record
  of what went wrong.
