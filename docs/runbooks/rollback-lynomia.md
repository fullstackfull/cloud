# Roll back Lynomia

## What you are seeing

A deploy that verified badly, or a release that is misbehaving in production.

## What it means

The code can go back. The database usually cannot. Decide which situation you
are in before touching anything.

## Check first

```bash
ls -1t /srv/lynomia/releases/       # the previous release is still on disk
php artisan migrate:status | tail   # did this release run a migration?
```

## If the release ran no migration

Point `current` at the previous release and restart. This is safe and fast.

```bash
export LYNOMIA_RELEASE_REF=<previous sha>
infrastructure/scripts/preflight.sh staging
export LYNOMIA_PREFLIGHT_PASSED_staging=1
infrastructure/scripts/apply.sh staging
infrastructure/scripts/verify.sh staging
```

## If the release ran a migration

Rolling the code back leaves new columns the old code does not know about, which
is usually survivable, and dropped or renamed columns the old code needs, which
is not. Read the migration before deciding. If it only added, roll the code back
and leave the schema. If it removed or renamed, you are doing a database restore
— see `database-restore.md` — and that loses everything written since the backup.

## What not to do

Do not run `migrate:rollback` in production to make a deploy work. It is a
development convenience, and it will drop data that arrived in the minutes since
the deploy.
