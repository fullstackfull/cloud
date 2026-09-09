# Redis is down

## What you are seeing

Health check reports cache down; queue depth flat; sessions dropping.

## What it means

Redis carries the queue, the cache and the locks. The locks matter most: the
platform uses them to stop two workers acting on the same order. Without Redis,
work stops — which is the correct failure, and better than work proceeding
unlocked.

## Check first

```bash
redis-cli ping
systemctl status redis-server
journalctl -u redis-server --since '30 min ago' | tail -40
df -h /var/lib/redis          # a full disk stops the append-only file
free -m                       # the OOM killer takes Redis first
```

## What to do

Bring Redis back. If it lost its data, that is survivable: queued jobs are
recreated by reconciliation, and the platform's money operations are recorded in
PostgreSQL, not here.

```bash
systemctl start redis-server
redis-cli ping
systemctl restart lynomia-worker
```

Then check for work that was in flight when it stopped:

```bash
php artisan provisioning:detect-stale
php artisan compute:poll-tasks
```

`provisioning:detect-stale` quarantines jobs the platform has stopped waiting
on; `compute:poll-tasks` asks the hypervisor whether accepted tasks actually
finished. Whatever they surface is indeterminate, not failed — see
`provider-indeterminate.md`.

## What not to do

Do not flush Redis to "clear a stuck queue" while workers are running. You will
release locks that are holding back duplicate provisioning.
