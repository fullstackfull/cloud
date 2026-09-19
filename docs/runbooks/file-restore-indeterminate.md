# File restore indeterminate

**Alert:** `FileRestoreIndeterminate` — a file-level restore into a
customer's machine has no confirmed outcome from the backup provider.

## What happened

A customer chose paths from a completed backup and asked for them to be put
back on the machine. The platform asked the provider to start the restore
and either the provider did not answer before the deadline, or it answered
that the task is unknown, or the task was still unfinished after
`backups.max_poll_hours`. The row is `needs_review`. **The platform will
not try again**: the first restore may still be writing into the machine,
and a second one over it cannot be reasoned about afterwards.

The customer has been notified (`service.file_restore_needs_review`) and
told not to start another restore. The API refuses a new file restore into
that machine while this row is in flight or in review.

## Why it matters

The files the customer asked for are in one of three states — restored,
partly restored, or untouched — and only the provider's own task log says
which. Until a person settles the row, the customer cannot restore anything
else into the machine.

## What to do

1. Find the row:

   ```sh
   php artisan tinker --execute="dump(\Lynomia\Modules\Backups\Infrastructure\Models\BackupFileRestore::query()->where('state','needs_review')->get(['id','virtual_machine_id','node_name','provider_task_id','paths','failure_reason','started_at'])->toArray())"
   ```

2. Look at the provider's task by `provider_task_id` on `node_name`. On a
   Proxmox node that is the task log in the web console or
   `pvesh get /nodes/<node>/tasks/<upid>/status`. (There is no Proxmox
   file-level adapter in this build; a `needs_review` row with the fake
   provider is a test or a development environment.)

3. Settle the row with what you found, from a tinker session, and tell the
   customer through their ticket:

   - The task finished successfully → set `state` to `succeeded` and
     `finished_at` to now.
   - The task failed or never ran → set `state` to `failed` with the
     provider's reason in `failure_reason`. The customer may then start the
     restore again.
   - The task is still running → leave the row alone; the reconciler has
     stopped polling it, so check again by hand until it finishes.

4. If the provider is unreachable for every row, this is `pbs-unavailable`
   or `proxmox-unavailable`, not this runbook.

## What not to do

Do not start the restore again "to be safe". Do not delete the row: it is
the only record that a restore was attempted, and the audit row
`backup.files.restored` points at it.
