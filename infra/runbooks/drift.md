# A resource disagrees with its provider

## What you are seeing

`ResourceDriftOpen`, or rows from the drift report.

## What it means

Reconciliation compared what Lynomia believes with what the provider says and
found a difference. Usually somebody changed something directly at the provider.
Occasionally it means an operation half-completed.

## Check first

```bash
php artisan lynomia:drift --open
php artisan lynomia:reconcile --dry-run --resource=<id>
```

The dry run prints what it would change and changes nothing.

## What to do

Read the dry run before executing. Reconciliation resolves toward what Lynomia
believes, so if the drift is because a customer's VM was legitimately resized by
an operator at the hypervisor, reconciling will resize it back. Decide which
side is right first.

```bash
php artisan lynomia:reconcile --resource=<id>       # adopt Lynomia's view
```

If the provider is right and Lynomia is wrong, correct Lynomia rather than the
provider.

## Persistent drift on the same resource

A resource that drifts again immediately after reconciliation has something
outside Lynomia managing it. Find that before reconciling a third time.

## What not to do

Do not reconcile everything at once to clear the alert. Each row is a difference
somebody may have created on purpose.
