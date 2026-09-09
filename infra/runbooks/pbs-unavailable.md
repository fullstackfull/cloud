# Proxmox Backup Server is unavailable

## What you are seeing

Backup jobs failing; restores unavailable.

## What it means

You are accumulating unprotected time. Nothing is broken for customers right
now, and everything is worse if something breaks next.

## Check first

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://<pbs>:8007/api2/json/version
ssh <pbs> 'df -h /mnt/datastore; zpool status'
php artisan lynomia:backups --state=failed --since=24h
```

A full datastore and a failed disk look the same from Lynomia. They are not.

## What to do

- Full datastore: prune expired snapshots per the retention policy. Do not
  delete snapshots that are still inside a customer's retention window to make
  room — that is deleting a backup somebody paid for.
- Failed disk: replace it before running a garbage collect. A GC on a degraded
  pool is a bad afternoon.
- PBS unreachable but healthy: check the network path. Backup traffic uses the
  storage network, not the management network — see
  `docs/phase-30b-network-flow-matrix.md`.

## While it is out

Say so explicitly in the incident record: from timestamp X, no backups were
taken. Customers whose retention window passes during the outage lose coverage
they were told they had.

## What not to do

Do not disable the failing backup jobs to clear the alerts. Do not tell
customers backups are fine because Lynomia's screen shows old, successful ones.
