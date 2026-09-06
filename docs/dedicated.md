# Dedicated servers

## What is different about physical hardware

A VPS is created on demand. A dedicated server already exists, sitting in a rack, and
either it is free or it is not. That single difference shapes the whole subsystem:

- **Stock is finite and specific.** A customer ordering a particular hardware profile
  cannot be served by "something similar".
- **Provisioning takes tens of minutes**, not seconds — an unattended OS install over the
  network.
- **Failures are physical.** A retry does not fix a dead disk.
- **Access to the out-of-band controller is access to the machine**, completely, at the
  hardware level.

Because of the first point, orders for dedicated servers do not provision automatically
when no matching machine is free: they go to `MANUAL_REVIEW` and wait for an operator,
rather than failing and refunding a customer who is content to wait a day.

## Inventory

```text
Server → Manufacturer, Model, Serial, Asset tag, Rack, Rack unit
         CPU, RAM, Disk, RAID controller, NIC
         BMC endpoint (iLO / IPMI / Redfish)
         Power state, Provisioning state, Customer assignment
```

States: `AVAILABLE`, `RESERVED`, `PROVISIONING`, `ACTIVE`, `MAINTENANCE`, `FAILED`,
`RETIRED`.

`RESERVED` is held by a specific order. Reservation and assignment are transactional with a
row lock, because two operators approving two orders in the same minute must not both be
handed the same machine.

`RETIRED` exists so that hardware history survives decommissioning. When a customer asks
what happened to their old server, or an auditor asks where a serial number went, deleting
the row destroys the only answer.

## Out-of-band management

Prefer **Redfish** where supported — it is a specified HTTP API with real error semantics.
Fall back to vendor-specific iLO where Redfish is incomplete, and to **IPMI last**, only
where nothing better exists.

All three sit behind one `DedicatedProvider` interface, so ordering, billing and
provisioning contain no vendor branch.

Supported operations: hardware health, power on, power off, graceful shutdown, reset, boot
order, one-time PXE boot, virtual media where supported, and firmware/hardware inventory.

### IPMI is a fallback, and it is dangerous to build carelessly

`ipmitool` takes its password on the command line. There is no alternative — the tool
provides no other mechanism — which means two rules are absolute:

1. **Never shell-concatenate an untrusted value into an IPMI command.** Arguments are
   passed as an array, never as an interpolated string. A hostname from inventory that
   contains a shell metacharacter is otherwise a command injection with root on the BMC.
2. **The password reaches the logs unless it is stripped.** The secret redactor matches
   `-P <value>` and `--password <value>` for exactly this reason: a command echoed into an
   error message would otherwise print a BMC credential verbatim.

### BMC network placement

BMC interfaces are on an isolated management network and are never routable from a
customer network. An iLO or IPMI interface is a complete out-of-band computer with power
control and virtual media: reachable from a customer VLAN, it hands over the physical host
and every tenant that shares it.

Customers do not receive BMC credentials. Where remote console or power control is offered
as a product feature, it is proxied through the platform with authorisation and audit —
never by handing over the underlying credential.

## OS provisioning

```text
Customer pays
     ↓
Reserve a matching server        (transactional; no machine, no charge to provisioning)
     ↓
Reserve IP addresses             (transactional; see docs/ipam.md)
     ↓
Set one-time PXE boot via Redfish/iLO
     ↓
Power cycle
     ↓
Unattended OS install from the provisioning VLAN
     ↓
Apply SSH key and credentials
     ↓
Install monitoring agent
     ↓
Verify connectivity
     ↓
Mark ACTIVE
```

**One-time** PXE boot, not a permanent boot-order change. A machine left with PXE first in
its boot order reinstalls itself the next time it reboots for any reason — which is a
customer's entire server erased by a power cut.

Supported unattended installs: Ubuntu (autoinstall), Debian (preseed), AlmaLinux and Rocky
Linux (kickstart).

### The provisioning VLAN

DHCP and PXE run **only** on the provisioning VLAN. A DHCP server on a production LAN
takes the network down; a PXE server on one reinstalls whatever reboots. The isolation
means this can be a cabling mistake but never a configuration mistake.

## Safety rules for automation

Automation never formats, repartitions, resets RAID, wipes or reinstalls a machine unless
that machine is declared in inventory as an installation target with `allow_reimage: true`.

Every destructive role runs a preflight that reports what it would do, and every playbook
supports `--check` and `--dry-run`. The cost of a mistake here is a customer's data, not a
rollback.

## Hardware failure

A dedicated server is `FAILED` when the hardware is faulty, not when it is unreachable.
Distinguishing the two takes an out-of-band check, which is one of the things the BMC is
for.

On confirmed failure: raise an incident linked to the server and the customer's service,
notify the customer, and decide between repair and replacement. A replacement is a new
machine with a new provisioning cycle, not an edit to the existing row — the old machine's
history stays attached to the old machine.
