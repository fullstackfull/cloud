# Deploy Lynomia

## What you are doing

Putting a named commit onto the control plane machines and making it current.

## Before you start

You need: the commit SHA, a green CI run for it, and the deployment controller's
shell. You do not need any credential in your own possession — the controller
holds them.

## The sequence

```bash
export LYNOMIA_RELEASE_REF=<commit sha>

infra/scripts/preflight.sh staging     # reachability + classification, writes nothing
infra/scripts/plan.sh      staging     # shows the diff, writes nothing
export LYNOMIA_PREFLIGHT_PASSED_staging=1
infra/scripts/apply.sh     staging
infra/scripts/verify.sh    staging
```

`apply.sh` refuses to run unless preflight passed in the same shell. That is
deliberate: a preflight from yesterday does not tell you the machine is reachable
now.

## What verify checks

Health endpoint reports database and cache up; no migration is pending; the
worker unit is active; the scheduler timer is active; no fake provider is
enabled. If any of those fail the deploy is not done, regardless of what
`apply.sh` printed.

## If verify fails

Do not re-run apply hoping it settles. Go to `rollback-lynomia.md`.

## What not to do

Do not deploy a ref that is not a commit. `main` is not a release — it is
whatever somebody pushed while you were typing.
