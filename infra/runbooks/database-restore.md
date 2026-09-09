# Restore the PostgreSQL database

## What you are seeing

Data loss, corruption, or a migration that cannot be undone.

## What it means

Everything written since the backup you restore is gone. This is the most
destructive routine action in the platform. Treat it accordingly.

## Before you start

Stop writing first, or you will restore over live traffic:

```bash
systemctl stop lynomia-worker lynomia-scheduler.timer
systemctl stop php8.4-fpm            # customers see an error, not a wrong balance
```

## Check first

```bash
ls -lt /var/backups/lynomia/*.dump | head    # what do we actually have
pg_restore --list <backup> | head -20        # is it readable
```

A backup you have never listed is not a backup. If the newest file will not
list, work backwards until one does, and record which one in the incident.

## Restore

```bash
createdb -T template0 lynomia_restore
pg_restore -d lynomia_restore -j 4 <backup>       # into a new database first
psql -d lynomia_restore -c 'select count(*) from invoices;'   # sanity
```

Only once the restored copy looks right do you swap it in. Rename, never drop:

```bash
psql -c 'alter database lynomia rename to lynomia_broken_<date>;'
psql -c 'alter database lynomia_restore rename to lynomia;'
```

The broken database stays until somebody has confirmed what was lost.

## Afterwards

Restart services, run `infra/scripts/verify.sh`, then reconcile: the providers
still hold resources the restored database may not know about.

```bash
php artisan lynomia:reconcile --dry-run
```

Read the dry run before executing it. It will propose changes based on a
database that has travelled backwards in time.

## What not to do

Do not restore straight over the live database. Do not skip the reconcile —
a restored database that thinks a customer has no VPS will happily sell them
another one.
