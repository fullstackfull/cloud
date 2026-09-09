# The queue is backing up

## What you are seeing

`QueueBacklog`, `QueueStalled` or `FailedJobsRising`.

## What it means

Three different things wear the same symptom, and they need opposite responses:

1. **More work than workers.** Depth rising, jobs still completing.
2. **No workers.** Depth flat, nothing completing.
3. **One provider is hanging.** Depth rising, workers busy, all on one provider.

## Check first

```bash
php artisan queue:monitor default,provisioning,notifications
systemctl status lynomia-worker
php artisan horizon:status
```

Then open the operator portal's operations queue. It tells case 3 apart from
case 1: if everything still running names the same provider, the queue is fine
and the provider is not.

## What to do

- Case 1: raise `control_plane_worker_processes` and redeploy. Watch depth fall.
- Case 2: `systemctl start lynomia-worker`, then read `journalctl -u lynomia-worker`
  for why it stopped. A worker that exits on boot is usually a bad `.env`.
- Case 3: go to the provider's own runbook. Adding workers makes it worse —
  more requests queue against the same hanging endpoint.

## Failed jobs

```bash
php artisan queue:failed
php artisan queue:retry <id>        # one at a time, after reading the exception
```

## What not to do

Do not `queue:retry all`. Some of those jobs touch money or destroy resources,
and a blanket retry is how one timeout becomes two charges. Read each exception.
Jobs whose outcome is unknown are handled by `provider-indeterminate.md`, not by
retrying.
