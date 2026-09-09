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
`provider-indeterminate.md`:

```
GET  /admin/operations/reinstalls
POST /admin/operations/reinstalls/dedicated/{operation}/resolve
```

Then start the reinstall again deliberately, from the customer's server in the
operator portal — one machine, with an audit entry against whoever asked for it.

A dedicated reinstall is driven by Lynomia through the BMC, not by Ansible.
There is no playbook for it and no CLI, which is deliberate: a command that
takes a hostname is a command that can take a list of them.

## What not to do

Do not "just re-run it for all of them". Do not assume a disk is blank because
the reinstall appeared to start. When a dedicated server reaches end of life, its
disks are wiped before the chassis goes to anyone else — a physical disk is never
returned to another customer without that.
