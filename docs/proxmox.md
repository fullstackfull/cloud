# Proxmox integration

## Position

Lynomia does not virtualise anything. Proxmox VE creates, runs and destroys machines; the
platform decides *which* machine, *where*, *for whom* and *at what price*, and records the
commercial truth. When the two disagree — and they will — the platform surfaces the drift
rather than assuming either side is right.

## The API is the only interface

Integration is through the official Proxmox API, with token authentication.

- **Never scrape the web UI.** It is not a contract; it changes between point releases and
  a scraper fails silently in ways that look like infrastructure faults.
- **Never a root password.** The control plane authenticates with an API token whose role
  grants only what provisioning needs, so a compromised control plane is not a compromised
  cluster.
- **TLS verification is on.** Disabling it on the link that creates and destroys customer
  machines is not an acceptable shortcut, even for a self-signed internal certificate —
  install the certificate instead.

## Inventory model

```text
Datacenter → Cluster → Node → { Storage, NetworkBridge, ResourcePool }
Template / Image
VirtualMachine
Backup
```

Per node the platform tracks total and allocated CPU, memory and storage; health;
maintenance state; VM count; and the configured overcommit ratio. All of it is
periodically re-read from the cluster rather than inferred from what the platform believes
it created.

## Node states

```text
ACTIVE      accepting new machines
DRAINING    no new machines; existing ones untouched
MAINTENANCE no new machines; operator is working on it
OFFLINE     unreachable or failed
```

`DRAINING` exists so a node can be emptied gradually before planned work without the
disruption of migrating everything at once.

## Placement

The scheduler is a weighted score, not "first node with room". Putting every machine on
the first node that fits produces one hot node and a fleet of idle ones, and concentrates
the blast radius of a single failure.

Scoring considers:

- free memory, and free memory **after** this machine — the constraint that actually binds
- CPU allocation against the configured overcommit ratio
- storage availability on the storage class the plan requires
- customer anti-affinity: a customer buying three machines for redundancy must not get
  three machines on one node, or they have bought none
- customer affinity where latency between their machines matters
- node capacity thresholds, so a node is never filled to the point where the remaining
  headroom cannot absorb a failure elsewhere

Nodes not in `ACTIVE` are excluded before scoring, not penalised during it.

The policy is configuration. Different fleets want different weights, and a scheduler that
requires a deploy to retune is a scheduler nobody retunes.

## Cloud-Init templates

Templates are built by automation, not by hand, so that a node added next year gets
byte-identical images:

1. Download from the distribution's official mirror.
2. **Verify the published checksum and signature.** A silently corrupted or substituted
   image becomes every customer's base system.
3. Install the QEMU guest agent — without it the platform cannot read a machine's actual
   IP, cannot shut it down gracefully, and cannot take a consistent snapshot.
4. Configure Cloud-Init for SSH keys, hostname and network.
5. Clean up machine-specific state (SSH host keys, machine-id, logs). A template that
   retains a machine-id gives every clone the same identity, which breaks DHCP leases and
   some licensing.
6. Convert to a template and record the version.

Version tracking matters because "the customer's machine misbehaves" needs the answer to
"which image was it built from".

Windows images are licence-aware. The platform does not redistribute proprietary operating
systems without valid licensing, and there is no configuration flag that makes it.

## VM lifecycle

Create · Start · Stop · Shutdown · Restart · Reset · Reinstall · Resize · Rename ·
SSH keys · Password reset · Console · Snapshot · Backup · Restore · Metrics · Suspend ·
Unsuspend · Delete.

Two distinctions the API preserves and the UI must not blur:

- **Shutdown vs Stop** — one asks the guest, the other pulls the power. Offering only the
  second loses customer data.
- **Reset vs Restart** — same distinction.

Destructive actions require confirmation naming the resource, because a mis-clicked
"destroy" on a production VM is unrecoverable once the storage is freed.

## Reconciliation

Scheduled workers compare platform state against each cluster:

| Situation | Action |
|---|---|
| Platform says ACTIVE, cluster has no such VM | Flag drift, alert. **Never** create a replacement — the customer may have a running machine the platform lost track of, and a second one doubles the bill and the resource use |
| Cluster has a VM with no platform service | Flag as orphan for an operator |
| Power state differs | Update the read model, record the correction |
| Resource allocation differs | Flag: either a manual change was made on the node, or a resize half-completed |

Nothing in reconciliation deletes a customer resource automatically. Every remedy is an
operator decision, because every automated remedy for drift is one bug away from deleting
production.

## Node installation

Proxmox is bare-metal software; the platform does not install operating systems onto
machines it was not explicitly given.

- **Bare-metal install** is a documented manual or PXE procedure, and the machine must be
  declared in inventory as an installation target.
- **Post-install configuration** is automated and idempotent: repositories, updates,
  hostname, DNS, NTP, networking and bridges, VLAN awareness, certificates, SSH hardening,
  the API token, monitoring agents, and backup configuration.

**Cluster join is never automatic.** Joining a node to the wrong cluster, or forming a
cluster from guessed information, is not recoverable without downtime for every node
already in it. It happens only when explicitly configured for that host, and only after a
preflight that shows what will happen.
