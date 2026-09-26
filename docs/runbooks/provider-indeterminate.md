# An operation's outcome is unknown

## What you are seeing

One of the two alerts that send you here, or an operation sitting in
`indeterminate` on the operator portal:

- **`ProvisioningJobsAwaitingReview`** — for 30 minutes there has never been a
  moment with no provisioning job in `needs_review`. The rule counts jobs, so
  it need not be the same job throughout; the one you open may be newer.
- **`OrdersInManualReview`** — for two hours there has never been a moment with
  no order held in manual review. The same holds: it counts orders, and need
  not be the same order throughout.

Not every operation of unknown outcome raises either. Registrations, renewals
and transfers do not (see `registrar-timeout.md`), and neither does a provider
task behind a job the platform has already called a success:
`lynomia_provider_task_total` is exported, and no rule reads it.

## What it means

Lynomia asked a provider to do something and never learned whether it happened.
A VM may exist. A domain may be registered. A card may be charged. The platform
deliberately does not guess, and deliberately does not retry: the Timeout Rule
says an indeterminate destructive or money operation is never retried
automatically. That is why this is a person's job.

## Check first

The operator portal is where these live:

```
GET /admin/operations/reinstalls          # VPS and dedicated rebuilds in review
GET /admin/domains/operations             # registrar operations
GET /admin/drift                          # resources that disagree with a provider
```

Each row names the provider, the resource and the provider's task reference, if
one was returned before the timeout. These are also what the poll commands
refresh:

```bash
php artisan compute:poll-tasks       # did accepted hypervisor tasks finish
php artisan domains:reconcile        # settle uncertain names against the registry
php artisan backups:reconcile        # in-flight backups
php artisan payments:reconcile --older-than=60
```

## What to do — in this order

1. **Ask the provider what is true.** Not Lynomia. The provider's own console or
   API is the authority here.
   - Proxmox: does the VMID exist, and does its config match what was ordered?
   - Registrar: is the domain registered, and to whom?
   - Payment gateway: is there a charge with that idempotency key?
2. **Record what you found** against the operation.
3. **Settle it** to match reality, from the operator portal:

```
POST /admin/operations/reinstalls/{type}/{operation}/resolve
```

Settling as completed makes Lynomia adopt what the provider already has, and
tells the customer. Settling as not-completed releases the order to be retried
cleanly. The settlement is recorded as an audit entry in the same transaction as
the state change.

There is deliberately no CLI for this, and no bulk form of it. Settling is a
person stating what they found at a provider, one operation at a time.

## A VPS build in review

A VPS create reserves the hypervisor id it will ask for **before** it calls,
and writes it on the job with the cluster, every node a create under it was
sent to and every name it was sent with. Every later attempt asks for the
same id and looks under it before building. So a build whose answer was lost
is never retried into a second machine: the retry finds the first one.

The review list shows, per job, `error_code`, `error_reason`,
`reserved_provider_id`, `reserved_provider_nodes` and
`reserved_provider_hostnames`:

```
GET /admin/provisioning/needs-review
```

Look at the reserved id on the listed nodes in the Proxmox console, then find
the row:

| `error_code` | `error_reason` | What it means | What to do |
|---|---|---|---|
| `vps.create_found_its_own_build` | `named_as_called` | An earlier attempt built this machine and its answer was lost. Nothing new was built. | Confirm the machine at the node, then **adopt** it with the reserved id (see below). Retry is refused from here on, deliberately. |
| `vps.create_identity_taken` | `named_otherwise` | Somebody else's machine, by name, holds the id. Nothing of this build's exists. | Confirm the name at the node. Then **repoint** the job, which gives it an id it has never held, and **retry** it. |
| `vps.create_identity_taken` | `unnamed` | A machine is there and reports no name — what Proxmox does while a create is still writing its config. It may be this build's. | Wait for the task on that node to finish and look again. If it becomes named as ordered, retry: the retry finds it and settles as `found_its_own_build`. Repoint is refused. |
| `vps.create_identity_taken` | `shape_differs` | A machine named as ordered but with a different vCPU or memory. Whose it is cannot be established. | Look at it. If it is this build's, adopt it; if it is not, it has to be renamed or removed at the hypervisor before a retry. Repoint is refused. |
| `vps.create_identity_reserved_elsewhere` | — | The job's payload names a different cluster from the one its identity was reserved on. Nothing on the platform edits a payload, so the change came from outside it. | There is no route to put it back from this screen, and nothing on this page is one. Escalate; the reserved cluster is where an earlier build would be. |
| `vps.create_identity_unverifiable` | — | The hypervisor could not be asked what is at the id; nothing was built. The engine retries this on its own. | Nothing, unless it exhausts its attempts — then fix the cluster's reachability and retry. |

**Repoint** (`POST /admin/provisioning/jobs/{job}/repoint`, evidence
required) is refused unless the job has stopped for review, holds an
identity, has built nothing, and its **last** attempt found the identity taken
`named_otherwise` — about the identity it holds now. A finding from an earlier
attempt licenses nothing: if a worker died since, retry instead, and the retry
tells you what is there now. There is no override.

**Adopt** records the machine and delivers the service. It does **not** create
the platform's machine record and does **not** commit the address: the
address the first attempt reserved was quarantined when it timed out, and a
timeout's quarantine does not expire on a clock — it waits for a person. So
after adopting, until there is a route for either, a DBA must, in the same
shift:

- insert the `virtual_machines` row for the reserved id, so the platform can
  power, console, reinstall, destroy and watch it for drift; and
- move the quarantined address to an assignment on that machine.

Until both are done the customer's machine runs with an address the platform
shows as quarantined and assigned to nothing, and the platform cannot manage
the machine.

## What not to do

Do not settle from a guess. Do not settle a batch. There is no Force Success in
this platform, and adding one is how a customer gets billed for a VM that was
never created — or gets a second one they did not order.
