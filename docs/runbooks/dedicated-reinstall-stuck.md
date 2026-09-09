# A dedicated server reinstall is stuck

## What you are seeing

A dedicated reinstall operation that is not progressing.

## What it means

Somewhere between "BMC accepted a boot override" and "the machine came back with
a new OS", something did not happen. The machine may be sitting at a PXE prompt,
or booted into the old system, or off.

## Check first

Ask the BMC, not Lynomia:

```
GET /admin/operations/reinstalls        # rebuilds waiting on a person
```

```bash
php artisan dedicated:sync-inventory --server=<id>
# then, at the BMC's own console: power state, boot device, and the actual screen
```

The BMC's console is the authority. A machine that says "PXE-E53: no boot
filename received" tells you exactly which link failed.

## The usual causes

1. **PXE did not answer.** The install network must be an isolated segment we
   control. If the machine is on a shared or unknown network, stop: never run
   PXE/DHCP where other people's machines can hear it.
2. **The boot override did not persist.** Some BMCs accept a one-shot override
   and drop it on a cold reset.
3. **The machine came back on the old OS.** The reinstall did not start at all.
   This is the safe failure.

## What to do

Settle the operation to reflect what the BMC shows, per
`provider-indeterminate.md`. Then start the reinstall again deliberately, one
machine at a time:

```bash
infrastructure/scripts/preflight.sh production
ansible-playbook -i infrastructure/ansible/inventories/production/hosts.yml \
  infrastructure/ansible/playbooks/reimage-node.yml \
  -e target=<hostname> -e reimage_confirm=<hostname>
```

Both `target` and `reimage_confirm` name one machine, and the play refuses a
group, a wildcard or a list.

## What not to do

Do not "just re-run it for all of them". Do not assume a disk is blank because
the reinstall appeared to start. When a dedicated server reaches end of life, its
disks are wiped before the chassis goes to anyone else — a physical disk is never
returned to another customer without that.
