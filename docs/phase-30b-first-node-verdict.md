# Phase 30B — first node verdict

Phase 30B's expansion gate: before any second machine is onboarded, the first
one must have proven the whole path. This document is that gate, and it is
answered before expansion, not after.

## Verdict

**NO GO — the gate has not been reached, because there is no first node.**

## What the gate requires

| # | Requirement | Met |
| --- | --- | --- |
| 1 | One Proxmox node connected read-only, its facts recorded | No |
| 2 | One VPS created through Lynomia from a paid order, with no human touching Proxmox | No |
| 3 | That VM independently confirmed to exist with the ordered resources | No |
| 4 | Start, stop, resize, reinstall, suspend, unsuspend, console proven individually | No |
| 5 | One backup taken and one restore completed | No |
| 6 | Termination proven, with the resource actually gone at the provider | No |
| 7 | A provider timeout survived without duplicate destructive work | No |
| 8 | Monitoring showing that node's real series | No |
| 9 | An operator able to see every indeterminate operation | No |
| 10 | Drift detected and reconciled once, deliberately | No |

Ten of ten unmet. Not one is unmet for a software reason.

## The single blocker

No machine has been made available to the environment running this phase. The
evidence is in `docs/phase-30b-real-infrastructure-inventory.md`: the container's
only routable address is in TEST-NET-1, no RFC1918 management range is
reachable, port 8006 answers nowhere, and no Proxmox credential exists.

`BLOCKED_HARDWARE`, with `BLOCKED_CREDENTIALS` behind it.

## What the gate does not block

Everything that does not need a machine was completed, in the `infrastructure/`
tree that already existed rather than a new one beside it: the four-way safety
classification on every group, `safety_gate` enforcing it at the head of all
eleven playbooks and proven to refuse across ten class-and-action pairs, five
static checks in CI behind one `make infra-validate`, the network flow matrix,
nineteen new runbooks beside the three that were already there, and a CI job
that validates all of it and is mechanically prevented from applying any of it.

Those checks found five things wrong with the tree they were pointed at — see
`docs/phase-30b-real-infrastructure-validation.md` section A2. None of them
changes this verdict, because none of them is about a machine.

## What happens when a node exists

In this order, one at a time, each with its own evidence:

1. Add the node to `infrastructure/ansible/inventories/staging/hosts.yml` with
   `safety_class: DISCOVERY_ONLY`. Not `CONFIGURATION_ALLOWED` — read first.
2. `infrastructure/scripts/preflight.sh staging`, then `playbooks/discover.yml`. Record
   the facts in the inventory document.
3. Create a Proxmox API token with `PVEAuditor` only. Lynomia does not use the
   root password for normal operations, and does not begin with write access.
4. Point staging at it and confirm Lynomia can *read* the cluster: node list,
   storage, existing guests.
5. Only then raise the token's privileges and the host's classification, and
   create one disposable VPS from a real paid order.
6. Work the ten rows above one at a time. Mark each
   `REAL_INFRA_VERIFIED` in `docs/real-infrastructure-verification-matrix.md`
   individually, with its own evidence. Creating a VM does not verify destroying
   one.

Until every row above is yes, no second machine is onboarded — that is what this
gate is for.
