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

The plan's own resources carry the figures, read by `ResolveBackupPolicy`:

| Resource | Meaning | Default when the plan is silent |
| --- | --- | --- |
| `backup_retention_days` | How long a backup is kept before the sweep takes it | `config('backups.retention_days')`, 7 |
| `backup_max_retained` | How many backups of one service are kept, newest first | unlimited |
| `backup_manual_allowance` | How many a customer may take themselves | unchanged from before |
| `backup_scheduled_allowance` | How many the schedule may take | unchanged from before |
| `backup_customer_may_delete` | Whether a customer may ask for one to be removed | true |

A plan that sets none of them still gets the config default rather than infinite retention:
a datastore that only ever grows is a bill nobody agreed to and an outage nobody planned.

## Removing a backup

Three things can ask for a backup to go — a customer, the retention sweep, and an operator
— and all three go through `RequestBackupDeletion`, which **asks nothing of the provider**.
It writes a decision and the sweep acts on it later. The reason for the separation is the
hour in between: a deletion is irreversible, a mis-click is not rare, and
`POST /vps/{vm}/backups/{backup}/keep` turns a `delete_requested` row back into
`succeeded` for as long as nothing has been asked of the datastore. Once the provider has
been told, there is nothing left to call off and `keep` refuses rather than lie.

The states, in the order a row moves through them:

```
succeeded ─▶ delete_requested ─▶ deleting ─▶ deleted
                 │
                 └─▶ succeeded   (kept: the customer changed their mind)
```

`deleted` is stamped only once the datastore has been listed and no longer reports the
archive. `DeleteBackupAtProvider` confirms absence rather than trusting the call it just
made — a provider that answers 200 and keeps the file would otherwise leave the platform
telling a customer their data is gone while it is not, which is the worse of the two lies.
A deletion that cannot be confirmed after `config('backups.deletion_attempts')` tries
goes to `needs_review` for a person, under the Timeout Rule: an indeterminate destructive
operation is never retried into oblivion.

Deletion is refused, with a distinct error code for each, when:

- the archive is still being written, or a restore is reading it;
- the state is not one the platform can account for (`needs_review` — the platform does
  not know whether the archive exists, so it will not claim to have removed it);
- `protected_until` is in the future — the hold the termination path places on a departing
  customer's last backups, which outranks both the customer and the sweep, because the
  retention window exists exactly for somebody who cancelled by mistake;
- the plan says customers may not delete their own backups.

The customer surface (`/backups`) takes the archive's own reference typed back before it
will send the request, and the server compares that string against the row named in the
URL with `hash_equals`. Sending the id the client already holds would make the check pass
by construction, so the portal forwards what the customer typed and nothing else.

### The sweeps

| Command | Schedule | What it does |
| --- | --- | --- |
| `backups:enforce-retention` | hourly at :35 | Marks what is past `expires_at` or beyond `backup_max_retained`, then removes what was marked more than `config('backups.deletion_grace_hours')` ago |
| `backups:reconcile-inventory` | `45 */6 * * *` | Compares what the platform believes with what the datastore lists |

Reconciliation reports and never adopts. Each disagreement becomes a drift row for an
operator, and nothing on a datastore is created or destroyed by the sweep itself:

| What it finds | Recorded as | Severity |
| --- | --- | --- |
| A backup the platform calls available, absent from the datastore | `missing_at_provider` | critical — the customer finds out when they try to restore |
| A backup the platform calls `deleted`, still on the datastore | `orphan_at_provider` | warning |
| An archive on the datastore the platform has no row for | `orphan_at_provider` | warning |

An unrecognised archive is reported and left alone: it may be another system's, and a
sweep that removed what it did not recognise would be a sweep that removes somebody else's
backups. The one case where a listing settles a question rather than raising one is a row
in `deleting` whose archive is now absent — that is the confirmation the deletion was
waiting for, and it becomes `deleted`. A missing archive under any other state is never
quietly marked deleted: the difference between "we removed this" and "this vanished" is
the difference between a policy and an incident.

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
