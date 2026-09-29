# A hypervisor node is unavailable

## What you are seeing

One of the alerts that send you here, or a node that stopped answering entirely:

- **`NodeDown`** — a host has failed every scrape for two minutes.
- **`HypervisorNodeOffline`** — Proxmox itself reports the node offline.
- **`HypervisorDiskAlmostFull`** — a hypervisor filesystem has had less than 12%
  free for ten minutes.

## What it means

If the node is down, every customer VM on it is down. This is a customer-visible
outage and the clock is running.

## Check first

```bash
ping -c3 <node management address>
ssh <node> uptime                       # is it the host or just the API
php artisan infrastructure:reconcile --cluster=<id>   # then read the drift queue
```

## What to do

1. Establish whether the hardware is up. A node that pings but does not serve is
   a different problem from a node that is off.
2. Get the affected customer count before you start fixing, so support can tell
   people something true.
3. Bring the node back if you can. If you cannot, restoring those VMs elsewhere
   means restoring from PBS onto another node — see `backup-failure.md` for
   whether the backups are current, and `pbs-unavailable.md` if PBS is also out.

## Do not evacuate automatically

There is no automated evacuation in this platform, on purpose. Moving a
customer's VM is a destructive operation with an indeterminate failure mode, and
doing it in bulk during an outage is how one failed node becomes several.

## Capacity warnings

`ComputeNodeCapacityExhausted` fires when a node has been more than 90%
**allocated** on one dimension for 30 minutes — allocation, not use. That is a
purchasing signal, not an incident, and it will not bring you to this page: it
carries only a `runbook_url`, to a capacity-planning page outside this
repository. Placement already refuses to schedule onto a node past its
configured overcommit ratio, so the warning means "buy hardware", not "act now".

## What not to do

Never return a physical disk from a failed node to another customer without
wiping it. That is a data disclosure, not a capacity recovery.
