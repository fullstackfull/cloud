# The queue is backing up

## What you are seeing

One of three alerts, or a queue you can see is not moving:

- **`QueueBacklogGrowing`** (warning, platform channel) — a queue has held more
  than 250 jobs and kept growing for 15 minutes.
- **`QueueBacklogSevere`** (critical, pages) — a queue has held more than 2,000
  jobs for 10 minutes. While it fires, it silences `QueueBacklogGrowing` for the
  same queue.
- **`FailedJobsAppearing`** (warning, platform channel) — more than five jobs
  moved to `failed_jobs` in 15 minutes.

No alert watches for a stalled queue as such — case 2 below, no workers and
nothing completing. `lynomia_queue_depth` shows it only as a depth that stops
falling. It reaches the platform channel once enough new work piles up behind
it to trip `QueueBacklogGrowing`, and it pages only once the depth has stayed
above 2,000 long enough to trip `QueueBacklogSevere` — not before. Nothing the
control plane exports says whether a worker is alive.

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
