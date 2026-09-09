# A backup failed

## What you are seeing

`lynomia:backups --state=failed` has rows, or a customer's restore point is
missing.

## What it means

One failed backup is a job to re-run. The same backup failing three nights
running is a promise the platform is not keeping.

## Check first

```bash
php artisan lynomia:backups --state=failed --since=7d
php artisan lynomia:backups --service=<id>        # is this one service or many
```

One service failing points at that VM — a guest agent that is not answering,
a disk the hypervisor cannot read. Many services failing points at PBS or the
network; go to `pbs-unavailable.md`.

## What to do

Re-run the specific backup and watch it:

```bash
php artisan lynomia:backups:run --service=<id>
```

If it fails again with the same error, do not run it a third time. Read the
hypervisor's task log for the actual reason.

## A restore that will not restore

A backup that exists but will not restore is worse than a missing one, because
the customer has been told they are protected. Test the restore into a scratch
VM rather than over the customer's live one — always, without exception.

## What not to do

Do not mark a backup successful in Lynomia to clear a report. The backup screen
is a promise to a customer about what they can recover.
