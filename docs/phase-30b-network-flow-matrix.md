# Phase 30B — network flow matrix

Which system may talk to which, over what, in which direction, and why. A flow
absent from this table is a flow the firewall should refuse.

None of these paths has been built or tested — see
`docs/phase-30b-real-infrastructure-inventory.md`. This is the design the
firewall rules and the deployment controller are configured against when the
networks exist, and the document a security review reads first.

---

## Networks

| Name | Purpose | Reachable from the internet |
| --- | --- | --- |
| Public | What customers reach: the control plane, customer VPS addresses, hosted sites | Yes |
| Management | Hypervisor APIs, BMC/iLO, panel APIs, PBS | **No** |
| Storage | Backup traffic between hypervisors and PBS | No |
| Install | PXE and DHCP for machines being reimaged | No, and isolated from Management |

The Management network is the one that matters. Anything on it can create,
destroy or read every customer resource on the platform. It is not routed to the
internet, not routed to the Public network, and not reachable from a customer
VPS.

The Install network is separate from Management for one reason: it runs a DHCP
server that answers boot requests. A machine that hears it can be installed. It
must never carry any host that is not deliberately being reimaged, and PXE is
never run on an unknown or shared network.

---

## Flows

### Inbound from the internet

| From | To | Port | Why |
| --- | --- | --- | --- |
| Any | Reverse proxy (Public) | 443/tcp | Customer portal, operator portal, API |
| Any | Reverse proxy (Public) | 80/tcp | Redirect to 443 and ACME challenges only |
| Payment gateway | Control plane `/webhooks/payments` (Public) | 443/tcp | Payment notifications, signature-verified |
| Any | Customer VPS addresses (Public) | Customer's own | What the customer bought |

Nothing else arrives from the internet. In particular: the Proxmox API, the BMC,
the hosting panel APIs, PBS, Prometheus, Grafana, Loki and PostgreSQL accept
nothing from the internet, ever.

### Deployment

| From | To | Port | Why |
| --- | --- | --- | --- |
| GitHub Actions | Nothing on any Lynomia network | — | CI validates; it does not reach the estate |
| Deployment controller | GitHub (outbound) | 443/tcp | Fetches the release it was told to deploy |
| Deployment controller | Control plane hosts (Management) | 22/tcp | Ansible |
| Deployment controller | Proxmox nodes (Management) | 8006/tcp | OpenTofu, discovery |
| Deployment controller | BMC (Management) | 443/tcp | Redfish, inventory and power |

The controller is the only thing that crosses into Management, and it is
initiated from the controller outward. Nothing on the Management network calls
the controller.

### Control plane outbound

| From | To | Port | Why |
| --- | --- | --- | --- |
| Control plane | Proxmox API (Management) | 8006/tcp | Provision, resize, suspend, destroy |
| Control plane | PBS API (Management) | 8007/tcp | Backup and restore |
| Control plane | Hosting panel API (Management) | 2087/2222 tcp | Shared hosting accounts |
| Control plane | BMC/Redfish (Management) | 443/tcp | Dedicated server power and reinstall |
| Control plane | Cloudflare API (internet) | 443/tcp | DNS records, PTR |
| Control plane | Registrar API (internet) | 443/tcp | Domain lifecycle |
| Control plane | Payment gateway API (internet) | 443/tcp | Charges and refunds |
| Control plane | SMTP relay (internet) | 587/tcp | Customer mail |
| Control plane | PostgreSQL | 5432/tcp | Its database |
| Control plane | Redis | 6379/tcp | Queue, cache, locks |

The control plane reaches the Management network. It is therefore the most
valuable host on the estate, and the one whose compromise costs the most. It
holds provider credentials, so it is treated as such: no shell access except
through the controller, no customer workload on it, and its outbound rules are a
list, not a default-allow.

### Console

| From | To | Port | Why |
| --- | --- | --- | --- |
| Customer browser | Console gateway (Public) | 443/tcp | VNC over websocket, short-lived signed ticket |
| Console gateway | Proxmox node (Management) | 5900-5999/tcp | The actual console session |

The console gateway is the one component that deliberately bridges Public and
Management. It brokers a single session, for one VM, for one authenticated
customer, on a ticket that expires. It proxies nothing else and holds no
long-lived credential to the hypervisor.

### Backups

| From | To | Port | Why |
| --- | --- | --- | --- |
| Proxmox node | PBS (Storage) | 8007/tcp | Snapshot data |

Backup traffic uses the Storage network so that a large restore does not starve
the Management network of the very API calls needed to manage the restore.

### Monitoring

| From | To | Port | Why |
| --- | --- | --- | --- |
| Prometheus | Control plane `/metrics` (Management) | 443/tcp | Bearer-token scrape |
| Prometheus | Node exporters (Management) | 9100/tcp | Host metrics |
| Prometheus | Proxmox exporter (Management) | 9221/tcp | Cluster metrics |
| Alloy (on each host) | Loki (Management) | 3100/tcp | Log shipping, outbound |
| Operator browser | Grafana | 443/tcp | Through the VPN, not the internet |
| Alertmanager | SMTP relay (internet) | 587/tcp | Alert mail |

The metrics endpoint carries no customer identifiers — an architecture test
keeps that true — but it does carry commercial aggregates such as MRR, so it is
behind a bearer token rather than open on the Management network.

### Install

| From | To | Port | Why |
| --- | --- | --- | --- |
| Machine being reimaged | PXE/DHCP server (Install) | 67/68 udp, 69 udp, 443/tcp | Netboot and installer image |

This VLAN is brought up for a reimage and taken down afterwards. It has no route
to Management, Public or Storage.

---

## Deliberately refused

| Flow | Why it is refused |
| --- | --- |
| GitHub Actions → any Lynomia host | A pipeline that can reach the estate is a pipeline that can wipe it on a bad merge |
| Internet → Management, in any form | The blast radius is the whole platform |
| Customer VPS → Management | A customer who buys a VM must not be able to reach the API that made it |
| Customer VPS → another customer's VPS, on the internal side | Tenants do not share an L2 segment |
| Control plane → the internet, by default | Its outbound is an allow-list, so a compromised dependency has nowhere to call |
| PXE/DHCP on anything but the Install VLAN | It answers machines that did not ask |

---

## How this is enforced

Not by this document. The firewall rules are Ansible-managed and live in
`infra/ansible/`, and the deployment controller's own reachability is fixed by
which networks its interfaces are on. This table is what those rules are
reviewed against.

None of it is verified. Verification means a port scan from each network segment
showing exactly these flows open and no others, recorded in
`docs/phase-30b-real-infrastructure-validation.md` section AG. That scan has not
been run, because the networks do not exist yet.
