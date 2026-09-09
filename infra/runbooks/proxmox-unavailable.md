# Proxmox is unavailable

## What you are seeing

Compute operations failing or hanging; the cluster's API not answering.

## What it means

New VPS orders cannot be provisioned and existing customers cannot start, stop
or resize. Running VMs are unaffected — a hypervisor API outage is not a customer
outage, and saying so early prevents a panic that makes things worse.

## Check first

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://<node>:8006/api2/json/version
php artisan lynomia:operations --state=running --provider=proxmox
```

Distinguish three cases:
- **API down, node up.** VMs are running. Customers see failures only when acting.
- **Node down.** VMs on it are down. This is a customer outage.
- **Cluster quorum lost.** The API answers but refuses writes.

## What to do

- API down: restart `pveproxy` and `pvedaemon` on the node. Do not reboot the
  node — that turns a control-plane outage into a customer outage.
- Node down: `node-unavailable.md`.
- Quorum lost: this is a cluster problem, not a Lynomia problem. Do not add or
  remove nodes to fix quorum while customer VMs are running on them.

## When it comes back

```bash
php artisan lynomia:operations --state=indeterminate
```

Anything that timed out during the outage is now indeterminate, not failed.
Work each one through `provider-indeterminate.md`.

## What not to do

Do not retry the failed provisioning jobs in bulk. Some of them may have created
a VM before the API stopped answering.
