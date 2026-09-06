# Networking

## Separation of concerns

The platform assumes distinct networks for distinct kinds of traffic. No VLAN ID appears
anywhere in this repository: every value comes from infrastructure inventory, because a
VLAN ID that is correct in one datacentre is someone else's production network in another.

```text
Management     hypervisor and BMC management; never routable from customer networks
Compute        hypervisor-to-hypervisor: migration, cluster traffic
Customer       public customer traffic
Storage        storage backend traffic; latency-sensitive, kept off the customer path
Backup         backup traffic to PBS; bulk, kept off the storage path
Provisioning   PXE/iPXE and DHCP — enabled ONLY here
Monitoring     scrape and log-shipping traffic
```

## The rules that are not negotiable

**BMC interfaces are never reachable from a customer network.** An iLO or IPMI interface
is a complete out-of-band computer with power control and virtual media. Exposing one to a
customer VLAN hands over the physical machine, and by extension every other tenant on it.

**DHCP runs only on the provisioning VLAN.** A rogue DHCP server on a production LAN takes
the network down, and a PXE server on one takes it down while reinstalling whatever
happens to reboot. The provisioning VLAN exists so that this can never be a mistake in
configuration — it has to be a mistake in cabling.

**Proxmox administrative interfaces are not publicly exposed.** They are reached over the
management network, through a VPN or bastion. The control plane talks to the Proxmox API
over that network with an API token, never over the public internet and never with a root
password.

## Anti-spoofing for customer machines

A customer with root on their own VM can set any source address they like. Without
enforcement, that means impersonating another tenant, poisoning ARP for the gateway, or
sourcing an attack from an address the provider will be blamed for.

Enforcement is at the hypervisor, not in the guest:

- IP filtering bound to the addresses actually assigned to that machine.
- MAC filtering on the guest interface, so a guest cannot claim another's MAC.
- Guests are attached only to the bridges their service entitles them to. The management
  bridge is never among them.

## Outbound SMTP

Port 25 is blocked outbound for customer VPS by default, and unblocking is a manual
decision recorded against the account.

This is not a restriction the platform imposes for its own convenience. A newly
provisioned machine with open port 25 is, within hours, a spam source — either because the
customer intended it or because the machine was compromised. The provider's entire IPv4
range gets listed, and every other customer on it loses mail delivery for weeks.

## Firewall architecture

Firewalling exists at three levels, each with a different job:

1. **Edge** — a dedicated firewall appliance (OPNsense where designated). Protects the
   provider's own networks; never touches customer VM policy.
2. **Hypervisor** — Proxmox's own firewall, which is where anti-spoofing and per-VM
   policy live, because it is the only place a customer cannot override.
3. **Host** — nftables on control-plane, database and hosting nodes, default-deny inbound.

OPNsense is never installed or reconfigured by automation unless the target host is
explicitly declared in inventory as `role: firewall` with `allow_reimage: true`. A
mistaken firewall reconfiguration disconnects the entire datacentre — including the
management path needed to fix it.

## Reverse DNS

Customers can set PTR records for addresses assigned to them, and only for those.
Delegation is validated against the assignment table on every change, because a PTR
record naming someone else's domain is a phishing primitive.
