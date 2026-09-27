# A backup failed

## What you are seeing

`lynomia:backups --state=failed` has rows, or a customer's restore point is
missing.

## What it means

One failed backup is a job to re-run. The same backup failing three nights
running is a promise the platform is not keeping.

## Check first

```bash
php artisan backups:reconcile                # in-flight backups vs the provider
php artisan backups:reconcile-inventory      # each datastore vs what we believe
```

then the customer's backups screen, or the operator drift queue, for whether
this is one service or many.

One service failing points at that VM — a guest agent that is not answering,
a disk the hypervisor cannot read. Many services failing points at PBS or the
network; go to `pbs-unavailable.md`.

## What to do

Trigger the backup from the customer's backups screen, or the operator surface
for that service, and watch it. There is no CLI that starts one — a backup is
an operation against a customer's service, and it goes through the same path
whether a person or the scheduler asks for it.

If it fails again with the same error, do not try a third time. Read the
hypervisor's task log for the actual reason.

## A restore that will not restore

A backup that exists but will not restore is worse than a missing one, because
the customer has been told they are protected. Test the restore into a scratch
VM rather than over the customer's live one — always, without exception.

## A restore or verification stuck in review

A row in `needs_review` whose `interrupted_operation` (in
`GET /api/admin/backups/needs-review`; the column behind it is `quarantined_from`) is
`restoring` or `verifying` is an
operation the platform stopped watching after `backups.max_poll_hours`. The archive is
not the problem; the platform's knowledge is.

1. `GET /api/admin/backups/needs-review` for the row, its task id (`restore_task_id` or
   `verification_task_id`), when it started (`restore_started_at` or
   `verification_started_at`), and its `review` token.

   A restore can be in review with no task id: `restore_task_id` is null. That is a
   restore whose start call never gave the platform a handle. Either the call ended
   without an answer (a timeout, a lost response), and `failure_reason` is the message
   of the error the backup adapter raised, with anything that looks like a secret
   redacted; or no handle had arrived `backups.max_poll_hours` after
   `restore_started_at` (the call still waiting, or its process gone), and
   `failure_reason` says the platform stopped tracking it. Either way the restore may
   have started. If the handle arrives after the row went to review,
   it is not written onto the row; it is in the application log, as a warning that
   reads

       A restore handle arrived after its attempt had ended; it was not written onto the row as it now stands.

   carrying `backup_id` (the row's `id`) and `restore_task_id`.
   Search the log for that line with this row's `id`. If there is none, find the task
   on the hypervisor by the machine (`virtual_machine_id`) and the time
   (`restore_started_at`).
2. Read that task's log on the hypervisor or the datastore. A restore may still be
   running: while the row is in review, no other restore of that machine will start.
3. `POST /api/admin/backups/{backup}/resolve` with `verdict` `completed` or `failed`, the
   `evidence` you read, and the `review` token from step 1 (required). It is audited, and
   the customer is told the outcome. If the row has been settled and gone back to review
   for a later attempt since you read it, the verdict is refused (409
   `backup.review_changed`) and nothing is written: start again at step 1.

## What not to do

Do not mark a backup successful in Lynomia to clear a report. The backup screen
is a promise to a customer about what they can recover.
