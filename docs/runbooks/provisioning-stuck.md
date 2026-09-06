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

```bash
php artisan provisioning:dispatch --order=<ORDER_ULID>
```

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
php artisan proxmox:task --node=<NODE> --upid='<REMOTE_JOB_ID>'
```

- **Provider says running:** wait. Note the expected duration for the operation; a disk
  clone of a large template legitimately takes minutes.
- **Provider says finished successfully:** the platform missed the completion. Reconcile
  rather than retry — the resource exists:
  ```bash
  php artisan provisioning:reconcile --job=<JOB_ULID>
  ```
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

```bash
php artisan provisioning:retry --job=<JOB_ULID>
```

## 4. Timeout after creation — the dangerous case

A timeout is not a failure. It means the platform stopped waiting, not that the provider
stopped working. Retrying here is how a customer ends up with two VMs and the provider
ends up with one of them unbilled and unmanaged.

Look for the resource before doing anything:

```bash
php artisan proxmox:find-orphans --cluster=<CLUSTER> --since='<JOB_STARTED_AT>'
```

- **The resource exists:** adopt it. Never create a second.
  ```bash
  php artisan provisioning:adopt --job=<JOB_ULID> --vmid=<VMID> --node=<NODE>
  ```
- **It genuinely does not exist:** release the reserved IP and capacity, then retry.

This is why provisioning jobs persist the provider's own job ID *before* the call is
considered in flight: without it, a timeout is unrecoverable and every recovery is a
guess.

## 5. Stopped at `manual_review`

Waiting for a human by design. The `reason` on the transition says which decision is
needed — usually a risk hold, or a dedicated server with no matching hardware free.

Resolve the underlying question, then move it on with a recorded reason. Never move an
order out of review without one; the next person needs to know why it was released.

## 6. If nothing above fits

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
