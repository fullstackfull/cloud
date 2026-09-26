# An operation's outcome is unknown

## What you are seeing

`ProviderTasksIndeterminate`, or an operation sitting in `indeterminate`.

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
and writes it on the job with the cluster and every node an attempt under it
was placed on. Immediately before each create is sent — and not before — it
writes down the name the create is sent with, so `reserved_provider_hostnames`
holds every name a create under the id was sent with, and is **empty until one
is about to be sent**. It can also hold a name nothing was sent with: a worker
that died between writing the name and sending, or a create that failed before
its request left, leaves one behind — and a machine carrying it is judged as
if that create had been sent, which errs the safe way. Every later attempt
asks for the same id — until the job is repointed — and looks under it before
building. So a build whose answer was lost is never retried into a second
machine: the retry finds the first one.

And a machine found at the id when no create under it is recorded as sent is
not taken for this build's, whatever it is called — with one exception. At an
id pinned on the job's payload (`vm_id`), a create may have been sent without
being recorded: the create used a pinned id before it recorded what it sent.
So there, while nothing is recorded, a machine carrying the payload's name and
nothing that contradicts the plan is taken for this build's — unless the
platform reads the job as showing that every earlier attempt ran after names
were recorded. It reads it that way on a job's **first** attempt, which has no
earlier one; and on its **second**, when the first reserved an id, or left a
finding of its own stamped with it (a finding the stale sweep wrote does not
count: the sweep stamps an attempt it did not run). It does not read it that
way on a third attempt or later, nor on a second whose first did neither, and
there a payload-named machine is taken for this build's even when every
earlier attempt in fact ended before sending. When it takes one, `last_error`
says why.

The review list shows, per job, `error_code`, `error_reason`,
`reserved_provider_id`, `reserved_provider_nodes` and
`reserved_provider_hostnames`. The code and reason are the job's **current**
finding: what its last attempt found, about the id it holds now. A row whose
finding is not current shows neither — most often a job repointed since its
last attempt, whose next step is the retry; its `last_error` is still the
last attempt's message, about the id it held then.

```
GET /admin/provisioning/needs-review
```

Look at the reserved id on the listed nodes in the Proxmox console, then find
the row:

| `error_code` | `error_reason` | What it means | What to do |
|---|---|---|---|
| `compute.provider_request_failed` | — | The create was sent and the platform never learned its outcome. With `failure_class` `timeout` the answer was lost: the cluster may have built the machine at the reserved id, or may not, and nothing retries it on its own. (With `transient` the cluster refused it and nothing was built; the job is listed only once the engine's own attempts are spent.) | Look under the reserved id on the listed nodes, then **retry**: the retry looks under that id before it builds, settles as `found_its_own_build` if the machine is there, and builds if nothing is. Repoint is refused. |
| `provisioning.worker_never_settled` | — | A worker took an attempt and never settled it within the job's timeout; the stale sweep moved it here. The create may have been sent. | Look under the reserved id on the listed nodes, then **retry**, as above: the retry finds a build the dead worker left and settles as `found_its_own_build`. If the retry is refused because a provider task was recorded, the create was answered: adopt the machine at the reserved id. Repoint is refused. |
| `vps.create_found_its_own_build` | `named_as_called` | A create under this id is recorded as sent by this build with the name the machine carries (it is in `reserved_provider_hostnames`) — by an earlier attempt, or by another worker holding the job at the same time — and nothing about the machine's vCPU or memory contradicts this build. It is **taken to be** the machine that create built — on its name and shape, which is all the platform matched. Nothing new was built. (If `reserved_provider_hostnames` is empty, the id is pinned on the job's payload and no create under the id is recorded; the name matched is the payload's, taken as sent because an earlier attempt is not read as having run after names were recorded (see above), and `last_error` says why. The platform does not establish whether any create was sent at all, so the look at the node decides.) | Confirm at the node that this is the build and not a machine that took the id afterwards — the node's task history for the id is where to tell the two apart. Then **adopt** it with the reserved id (see below). Retry is refused from here on, deliberately. |
| `vps.create_identity_taken` | `named_otherwise` | A machine holds the id under a name no create under it was sent with. When `reserved_provider_hostnames` is empty no create under the id is recorded as sent, and the machine is not this build's **whatever it is called** — the name this build was about to use included. (At an id pinned on the payload, with no name recorded, a machine carrying the payload's name is reported this way only when the job is read as showing its earlier attempts ran after names were recorded — see above; otherwise it would have been taken for this build's.) Nothing of this build's exists. | Confirm at the node. Then **repoint** the job, which gives it an id it has never held, and **retry** it. Do not adopt it. |
| `vps.create_identity_taken` | `unnamed` | A machine is there and reports no name — what Proxmox does while a create is still writing its config. It may be this build's if a create under the id was sent (`reserved_provider_hostnames` is not empty — or, at an id pinned on the payload where the payload's name is taken as sent, even when it is empty; see above). If none was, it is not, and it is reported this way only because a machine with no name is never read as a stranger's. | Wait for the task on that node to finish and look again, then retry. If a create under the id was sent and the machine is now named as it was sent, the retry settles as `found_its_own_build`; if none was sent, the retry reports it `named_otherwise`, and you can repoint. Repoint is refused until then. |
| `vps.create_identity_taken` | `shape_differs` | A machine carrying a name a create under this id was sent with (or, at an id pinned on the payload where the payload's name is taken as sent, with none recorded, the payload's name), but a different vCPU or memory. Whose it is cannot be established. | Look at it. If it is this build's, adopt it; if it is not, it has to be renamed or removed at the hypervisor before a retry. Repoint is refused. |
| `vps.create_identity_reserved_elsewhere` | — | The job's payload names a different cluster from the one its identity was reserved on. Nothing on the platform edits a payload, so the change came from outside it. | There is no route to put it back from this screen, and nothing on this page is one. Escalate; the reserved cluster is where an earlier build would be. |
| `vps.create_identity_unverifiable` | — | The hypervisor could not be asked what is at the id; nothing was built. The engine retries this on its own. | Nothing, unless it exhausts its attempts — then fix the cluster's reachability and retry. |
| `compute.task_failed`, `compute.task_unconfirmed` | — | The build settled with a machine, and afterwards the hypervisor said its task failed, or the platform stopped waiting for the task to finish (`last_error` says which). `provider_reference` is the id the build was answered with. | Look at that machine at the node. Retry and repoint are refused, because something was built. |

**Repoint** (`POST /admin/provisioning/jobs/{job}/repoint`, evidence
required) is refused unless the job has stopped for review, holds an
identity, has built nothing, and its **last** attempt found the identity taken
`named_otherwise` — about the identity it holds now. A finding from an earlier
attempt licenses nothing: if a worker died since, retry instead, and the retry
tells you what is there now. There is no override.

**Adopt** only a machine you have confirmed at the node is this build's. The
platform claims a machine on a name a create under the id was sent with and a
shape that does not contradict the plan; a machine that took the id after this
build's create built nothing can carry both. Adopting a stranger's machine,
and then the DBA step below, hands this customer another customer's machine.

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
