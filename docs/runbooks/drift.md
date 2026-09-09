# A resource disagrees with its provider

## What you are seeing

`ResourceDriftOpen`, or rows from the drift report.

## What it means

Reconciliation compared what Lynomia believes with what the provider says and
found a difference. Usually somebody changed something directly at the provider.
Occasionally it means an operation half-completed.

## Check first

The drift queue is in the operator portal, worst first:

```
GET /admin/drift
```

To take a fresh look at a cluster:

```bash
php artisan infrastructure:reconcile --cluster=<id>
php artisan hosting:reconcile
php artisan dns:reconcile
php artisan backups:reconcile-inventory
```

## What these commands do, and what they do not

They **detect**. They refresh inventory, compare it with what the platform
believes, and record the difference. None of them changes anything at a
provider.

That is deliberate and it is the most important thing to understand about this
screen. There is no "delete the orphan", no "rebuild the missing machine", and
no button that makes a red row green — because every one of those is a distinct
operation with its own risk and its own permission, and a screen full of
findings is exactly where a one-click remedy gets pressed on the wrong row.

## What to do

Decide which side is right, per row. A customer's VM resized by an operator at
the hypervisor is drift where the *provider* is correct and Lynomia's record
should be updated. A machine that has vanished is drift where Lynomia is correct
and something has gone wrong at the provider.

Record the verdict:

```
POST /admin/drift/{drift}/review
```

Then perform whatever the verdict implies as its own deliberate operation,
through the surface that owns it.

## Persistent drift on the same resource

A resource that reappears immediately after a review has something outside
Lynomia managing it. Find that before recording a third verdict.

## What not to do

Do not review a page of rows to clear the alert. Each one is a difference
somebody may have created on purpose.
