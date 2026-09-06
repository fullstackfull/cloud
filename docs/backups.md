# Backups

## What is backed up, and by what

| What | Mechanism | Frequency | Retention |
|---|---|---|---|
| Control-plane database | `pg_basebackup` + continuous WAL archiving | Continuous | 30 days PITR |
| Application configuration | Git, plus the deployment secret store | On change | Full history |
| Customer VMs | Proxmox Backup Server | Nightly | Per plan |
| Hosting accounts | Panel-native backups to remote storage | Nightly | Per plan |
| Monitoring configuration | Git | On change | Full history |
| Provider credentials | Secret store's own backup | Per store policy | Per store policy |

**Dedicated servers are not backed up by the platform.** Customers own their data on
dedicated hardware. This is stated in the service description rather than left as an
assumption a customer discovers after a disk failure.

## The rule that makes the rest meaningful

**A backup that has never been restored is a hypothesis.**

Every backup class therefore has a scheduled restore test, and the platform reports the
date of the last *successful* restore — not the last successful backup. A green backup
job with a broken restore path is the most expensive kind of false confidence a hosting
provider can carry.

## Proxmox Backup Server

PBS runs on dedicated hardware with its own storage. Not on a hypervisor, and not on
storage shared with the machines it protects — a backup that dies with the thing it backs
up is not a backup.

Configured per datastore:

- **Backup jobs** — schedule, which pools or VMs, and the retention to apply.
- **Prune** — how many daily, weekly, monthly and yearly copies to keep.
- **Garbage collection** — reclaims space from pruned chunks. Without it the datastore
  fills and backups start failing, which the alert catches but the customer feels.
- **Verification** — re-reads and checks stored chunks. This is what turns "the job
  reported success" into "the data is readable".
- **Restore tests** — a scheduled restore of a sample VM into a scratch pool.

Verification and restore testing are not optional extras. Deduplicating backup storage
means a single corrupted chunk can affect many backups at once, and only verification
finds it before a restore does.

## PostgreSQL

Continuous WAL archiving to storage separate from the primary, with periodic base backups.
This gives point-in-time recovery, which matters more than nightly dumps: the realistic
database disaster is not "the host died" but "a bad migration or a bad query ran at
14:07", and recovery means rewinding to 14:06.

Backups are encrypted at rest and copied off-host. An encrypted backup whose key is stored
only on the machine being backed up is not recoverable — the key lives in the secret store.

## Retention and the customer promise

Retention is configurable per plan, and what is configured must match what has been sold.
A plan advertising thirty days of backups and configured for seven is a commitment the
platform will fail to honour at exactly the worst moment.

## Restore procedures

Restores are documented in `docs/runbooks/` and rehearsed on the schedule in
`docs/disaster-recovery.md`.

Two rules for any restore:

1. **Restore to a scratch location first**, verify, then swap. Restoring directly over the
   live copy turns a recoverable problem into two problems.
2. **Record the elapsed time.** It is the only honest source for the RTO figures, and if it
   exceeds the published RTO then the published figure is wrong and gets corrected.

## Encryption and access

Backups contain everything the platform knows: customer details, invoices, service
configuration, and the contents of customer machines. They are encrypted at rest, access
is restricted to the smallest set of operators who need it, and every access is audited —
backup storage is a copy of production with none of production's access controls, and it
gets treated accordingly.
