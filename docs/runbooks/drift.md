# A resource disagrees with its provider

## What you are seeing

One of two alerts, or rows from the drift report.

- **`ResourceDriftOpen`** (critical, pages) — at least one **critical** drift has been
  unresolved for 15 minutes: `lynomia_resource_drift_open{severity="critical"} > 0`. That
  series counts `open` **and** `acknowledged` rows, so acknowledging the row in the
  operator portal does **not** clear the page. Resolving it does. Critical means a
  customer is paying for something the provider does not have, or a suspended service is
  still running.
- **`DriftQueueUnworked`** (warning, platform channel) — drift of any severity has sat
  `open` and unreviewed for a day: `sum(lynomia_open_drift_total) > 0`. That series counts
  `open` rows only, so acknowledging clears it — by design, because a review is what it
  asks for.

The identifiers the alert cannot carry — drift id, service id, provider reference — are in
the control plane's structured log, on the error line *"Critical drift was seen between
the platform and a provider."* That log is on the control-plane host
(`storage/logs/lynomia.json` under the release directory); it is not in Loki unless a
shipper has been installed there, which nothing in this repository does.

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
somebody may have created on purpose. Acknowledging a critical row will not
silence `ResourceDriftOpen` anyway; only a resolution does, and a resolution
recorded without the work behind it is a false one.
