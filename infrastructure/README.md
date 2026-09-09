# Infrastructure

Everything in this directory configures machines the platform runs on. Nothing
in it configures a machine a customer runs on.

```text
infrastructure/
├── ansible/        configuration management for platform hosts
│   ├── ansible.cfg
│   ├── requirements.yml       deliberately empty — see "No collections"
│   ├── inventories/           EXAMPLES ONLY, with unroutable addresses
│   ├── group_vars/            fleet-wide policy an inventory must not weaken
│   ├── playbooks/             one per operation, all --check/--diff capable
│   └── roles/                 one role per concern, idempotent
├── tofu/           OpenTofu for the platform's OWN VMs and DNS
├── monitoring/     the observability stack's configuration (deployed by Ansible)
├── scripts/        checks that keep the above honest, run by CI
└── README.md
```

### Subsystem directories that are named elsewhere and do not exist

`proxmox/`, `pbs/`, `pxe/`, `networking/`, `security/`, `dedicated/` and
`hosting/` are referred to in places around this repository as though they were
here. They are not, and this listing no longer implies otherwise —
`scripts/validate-monitoring.py` fails the build if this tree names a directory
that is missing.

One of those absences has teeth. `monitoring/README.md` declares a textfile
collector contract for `lynomia_backup_*` and says the collector belongs to
`infrastructure/pbs`. Nothing implements it, so the six alerts in
`monitoring/prometheus/rules/backups.yml` — including the two that exist to
catch an unverified backup, the failure that looks exactly like success until a
restore — cannot fire. The same check names that gap on every run, so it stays
visible until something writes those series. See
`docs/phase-30b-real-infrastructure-validation.md` section Q.

---

## Separation of roles

Each role in the table is a separate machine. The separations are not
aspirational tidiness: several of them are load-bearing for security or for
recovery, and the automation refuses to collapse those.

| Group | Runs | Configured by |
|---|---|---|
| `control_plane` | Laravel API, Horizon workers, scheduler, Nginx, PHP-FPM | `playbooks/control-plane.yml` |
| `database` | PostgreSQL 18 primary and replica | `playbooks/database.yml` |
| `redis` | Redis: queues, cache, sessions | `playbooks/redis.yml` |
| `monitoring` | Prometheus, Alertmanager, Grafana, Loki, Alloy | `playbooks/monitoring.yml` |
| `proxmox` | Proxmox VE hypervisors — bare metal | `playbooks/proxmox-postinstall.yml` |
| `pbs` | Proxmox Backup Server — dedicated host, separate storage | `playbooks/pbs-configure.yml` |
| `hosting` | cPanel / DirectAdmin nodes | `playbooks/hosting-preflight.yml` |
| `pxe` | iPXE and DHCP — provisioning VLAN only | `playbooks/pxe.yml` |
| `firewall` | OPNsense edge — **aliases only**, see below | `playbooks/firewall.yml` |
| `dedicated` | customer physical servers | **nothing. Inventory only.** |

`dedicated` is in the inventory so monitoring and documentation can name those
machines. No play targets the group, and none should be written that does: a
dedicated server runs the customer's operating system and the customer's data,
the platform does not back it up, and there would be nothing to restore after an
overwritten configuration. Those hosts carry `lynomia_never_configure: true`,
and every role's opening guard refuses a host that has it.

---

## Why the control plane runs nowhere else

`playbooks/control-plane.yml` refuses to install onto a host that is also in
`proxmox`, `pbs`, `hosting`, `firewall`, `pxe` or `dedicated`. Not a warning —
the play stops before it writes anything. Each collision fails for its own
reason, and none of them is theoretical:

**On a hypervisor.** The control plane is the tool you use to migrate machines
off a failing node, to drain it, and to see which customers are affected. If it
lives on that node, it fails at the same instant the node does. You lose the
hypervisor and the only thing that could have moved anything off it, together,
and you find out from a customer.

**On a hosting node.** A shared-hosting node runs arbitrary customer PHP. That
is the whole product. One account's runaway process, one plugin with a memory
leak, one site under a traffic spike — and the node's load makes the platform's
API unresponsive too. Billing, provisioning, the portals and the support system
now share a blast radius with a WordPress installation nobody at the provider
has ever seen. The panel also expects to own the machine's web server, mail
stack and firewall, which is a direct fight with Nginx and nftables.

**On the firewall.** Reconfiguring the edge can remove the management path.
If the control plane is behind — or on — the device that just became
unreachable, the tool that would revert the change is on the far side of the
change. Recovery becomes a console session, or a drive to the facility.

**On the PXE host.** The machine that can reinstall servers should not also hold
the credentials for every server, every hypervisor and every payment provider. A
compromise of one becomes a compromise of the other, in the direction that ends
with customer machines being reimaged.

**On a customer's dedicated server.** It is not the provider's machine.

The list lives in `group_vars/all.yml` as
`lynomia_control_plane_incompatible_groups`, and `playbooks/preflight.yml`
reports a collision even when no other play is being run — so an inventory that
has drifted into a collision is caught by the read-only check.

---

## Destructive-action guards

Every capability in this repository that can destroy something is off by
default, and can only be switched on **for a named host** — never for a group,
and never for an environment.

| Flag | Guards | What goes wrong if it runs on the wrong machine |
|---|---|---|
| `allow_reimage` | `roles/opnsense` | A ruleset applied to the edge disconnects the datacentre, including the path used to undo it. |
| `opnsense_console_access_confirmed` | `roles/opnsense` | The change is made with nobody watching a console. Five-minute mistake becomes four-hour outage. |
| `proxmox_cluster_join_enabled` | `roles/proxmox_cluster` | `pvecm add` against the wrong cluster destroys `/etc/pve` on the joining node — the configuration of every VM on it. Disks survive; the record of whose disk is whose does not. |
| `proxmox_apply_network` | `roles/proxmox` | `ifreload -a` on a node with running customer machines drops their connectivity, or the management link, mid-play. |
| `proxmox_apply_updates` | `roles/proxmox` | `apt upgrade` restarts what it replaces — `pve-cluster` restarting makes `/etc/pve`, where every guest's configuration lives, briefly unreadable on a node running customer machines. |
| `pxe_allow_serve_dhcp` | `roles/pxe` | A DHCP server on a production LAN takes the network down; a PXE server on one reinstalls whatever reboots. |
| `postgresql_allow_initdb` | `roles/postgresql` | `initdb` over an existing cluster destroys every customer, invoice and service record. |
| `lynomia_never_configure` | every role | Platform automation overwrites a customer's own configuration on hardware the platform does not back up. |

### The precedence trick that makes them per-host only

`playbooks/group_vars` is a symlink to `ansible/group_vars`, so those files load
as **playbook** group_vars. In Ansible's precedence order that places them here:

```text
role defaults/   <   inventory group_vars/   <   ansible/group_vars/   <   inventory host_vars/
  (tunables)          (environment facts)        (invariants)             (per-machine opt-in)
```

The consequence is the design:

- A tunable an operator may reasonably want to change per environment lives in
  `roles/*/defaults/` at the bottom, where anything can override it.
- An invariant lives in `ansible/group_vars/`, above the inventory's group
  variables — so adding `allow_reimage: true` to an environment's group_vars
  has **no effect**.
- A named host in `inventories/*/host_vars/` sits above the invariants, so the
  one machine that genuinely is being re-imaged can still say so.

This is verifiable rather than asserted, and the way to verify it is to arm one
host and watch the ordering hold:

```bash
cd infrastructure/ansible
mkdir -p inventories/staging/host_vars/fw-stg-1.stg.example
printf 'allow_reimage: true\n' > inventories/staging/host_vars/fw-stg-1.stg.example/maintenance.yml

# The host's own declaration beats ansible/group_vars' `allow_reimage: false`,
# so this run gets past the second guard and refuses on the console check.
ansible-playbook -i inventories/staging playbooks/firewall.yml \
    -l fw-stg-1.stg.example --check -c local

# Now try to arm the whole environment instead. Adding `allow_reimage: true` to
# inventories/staging/group_vars/firewall.yml has NO effect: the invariant sits
# above it, and the play refuses at the same guard as production does.
rm -r inventories/staging/host_vars/fw-stg-1.stg.example
```

Note that the committed example inventories arm nothing. `allow_reimage` is an
authorisation for one scheduled piece of work, and an example that ships it set
is an example somebody copies into a real inventory along with a permanently
armed edge firewall.

### What `roles/opnsense` does and does not manage

It exports the current configuration, then writes **aliases** and asks the
appliance to apply. It does **not** write filter rules: there is no code path
that does, and rather than accepting an `opnsense_rules` list and quietly
ignoring it, the role refuses when one is declared. On the edge, "the run said
OK" and "the rule is there" have to be the same statement — a network everybody
believes is blocked and is not is the failure this whole playbook is written
around. Manage filter rules on the appliance until this is implemented and
verified against a real OPNsense.

The role also treats the API's envelope correctly, which matters more here than
the HTTP status does: OPNsense answers a rejected write with **HTTP 200** and
`{"result": "failed"}` in the body, so every response is checked for what it
actually says before anything is applied.

---

## Inventories are examples

`inventories/{development,staging,production}/hosts.yml` describe **no machine
that exists**. Every address is from a range the RFCs reserve for documentation
and every name is in a domain that will never be delegated:

| Range | Reserved by |
|---|---|
| `192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24` | RFC 5737 |
| `2001:db8::/32` | RFC 3849 |
| `.example`, `.invalid` | RFC 2606, RFC 6761 |

None of them route anywhere. That is the point: a copied example that still
contains a placeholder fails to connect, instead of configuring a stranger's
machine.

**The real inventory does not belong in this repository.** It contains
management addresses, BMC endpoints and the topology of the provider's own
network — a map of everything worth attacking. Keep it in a private inventory
repository with its own access control, laid out identically, and point `-i` at
it. Note also what the production example does *not* contain: not one
destructive guard flag is set. That is the intended steady state.

---

## Secrets and Ansible Vault

**No password, key, token or real address is committed anywhere in this
directory.** Secrets are referenced by variable name and supplied at run time
from a Vault-encrypted file that is not in the repository:

```text
inventories/<environment>/group_vars/vault.yml       ← encrypted, NOT committed
```

`.gitignore` already excludes `**/vault-password*`, `**/*.vault` and
`**/secrets.yml`. Create the file and supply the password out of band:

```bash
cd infrastructure/ansible

# Create or edit the encrypted file for an environment.
ansible-vault create inventories/staging/group_vars/vault.yml
ansible-vault edit   inventories/staging/group_vars/vault.yml

# Run a playbook with the password read from your own agent or secret store —
# never from a file path committed in ansible.cfg, which is how a fleet ends up
# with one shared vault password nobody can rotate.
ansible-playbook -i inventories/staging playbooks/redis.yml \
    --vault-password-file <(secret-store get staging/ansible-vault-password)
```

Variables the encrypted file is expected to provide:

| Variable | Used by |
|---|---|
| `redis_password` | `roles/redis` — refuses an instance without one, minimum 32 characters |
| `hardening_authorized_keys` | `roles/hardening` — refuses to disable passwords with no key to replace them |
| `hosting_license_identity` | `roles/hosting` — absence reports `LICENSE_REQUIRED` |
| `opnsense_api_key`, `opnsense_api_secret` | `roles/opnsense` |

Each playbook loads the file if it is present and each role refuses with a
specific message if the value it needs is missing. Nothing proceeds with an
empty password.

Two mechanical protections back this up. Tasks that render a secret into a file
run with `no_log`, so `--diff` reports that the file changed without printing
it. And the Proxmox API token is written only to `/root/.lynomia-api-token`
(mode 0600) on the node itself, because Proxmox shows a token secret exactly
once and re-creating the token would invalidate the credential the running
control plane is using.

---

## Every playbook supports `--check` and `--diff`

Meaningfully, not nominally:

- Configuration is written with `template` and `copy`, which produce real diffs.
- Inspection uses `command` with `changed_when: false` and `check_mode: false`,
  so a read produces real output under `--check` and never reports a change.
- Irreversible commands — `pvecm`, `initdb`, `ifreload` — are **skipped** in
  check mode rather than simulated, and a `debug` task states what would have
  happened. There is no dry run for `pvecm add`, and pretending otherwise would
  lie about the one thing `--check` is used to find out.
- Files are validated before they land: `sshd -t`, `nft -c`, `nginx -t`,
  `chronyd -Q`, `dnsmasq --test`, `ifup --no-act`, `promtool check config`. A
  broken sshd config becomes a failed task instead of an unreachable host.

Idempotency is enforced by making absence explicit rather than by skipping
tasks. The scheduler crontab and the PXE DHCP service both compute their state
from the inventory:

```yaml
state: "{{ (inventory_hostname in control_plane_scheduler_hosts) | ternary('present', 'absent') }}"
```

A `when:` guard would leave the old scheduler running after it was moved to
another host, giving the fleet two — and two schedulers invoice a customer
twice.

---

## The timeout rule

**A timeout means the platform stopped waiting. It does not mean the provider
stopped working.**

Nothing here retries a timed-out operation, and nothing releases a resource
because an operation timed out. The resource may exist. Where this matters it is
written into the code rather than left as a convention:

- `lynomia_api_retries: 0` in `group_vars/all.yml`.
- The PBS restore-test script bounds each restore and reports a timeout as a
  **failed restore test** — never retrying, because a retry doubles the load on
  a backup server that is already slow.
- `roles/opnsense` ends with an explicit instruction not to re-run after a
  timeout: the most likely reason the answer never arrived is that the ruleset
  applied successfully and removed the path it would have come back on.
- The OpenTofu module's `timeout_clone` and `timeout_create` are limits on
  waiting, with a comment saying so. Re-applying after one is how a node ends up
  hosting two of something.

---

## No collections

`requirements.yml` declares no dependencies, and that is deliberate.
Configuration management is what an operator reaches for when infrastructure is
broken; a control repository that cannot start until it has downloaded something
from Galaxy cannot be run on the day the network is the thing being fixed. It
also keeps third-party code from executing as root on every host in the fleet.

The cost is paid in three places, each commented where it occurs: nftables and
fail2ban are templates validated with the tools' own checkers, Proxmox and PBS
are driven through `pveum` / `pvecm` / `proxmox-backup-manager` with explicit
idempotency checks, and CIDR containment is checked with Python's `ipaddress`
module on the controller.

---

## OpenTofu

### What it owns

The platform's **own** long-lived machines and the platform's **own** DNS
records: staging control-plane hosts, the monitoring host, the names they answer
to.

### What it must never own

**Customer virtual machines.** The control plane creates, resizes and destroys
those through the Proxmox API and records them in its own database, which is the
commercial source of truth. If OpenTofu held them in state as well:

- the two would disagree within a day — a customer resizes a machine in the
  portal, the next `tofu plan` proposes to "correct" it back, and an apply run
  by somebody who did not read the plan reverts a change the customer paid for;
- `tofu destroy` would be a single command that deletes customer machines.

Drift between the platform and the hypervisors is a modelled concept with an
operator in the loop (`docs/proxmox.md`). A second declarative system with its
own opinion is not reconciliation; it is a race.

**Customer DNS and reverse DNS.** Those are validated against the IP assignment
table on every change, because a PTR record naming somebody else's domain is a
phishing primitive. A second system writing into the zone bypasses that check.

### Plan, review, then apply

Nothing here applies automatically. There is no CI job that runs `tofu apply`,
and there should not be.

```bash
cd infrastructure/tofu

# 1. Initialise against the environment's own backend. Per environment, not a
#    workspace: running a staging plan against production state should require
#    a different command, not a remembered `workspace select`.
tofu init -reconfigure -backend-config=environments/staging/backend.hcl

# 2. Plan to a file. Planning and applying the same artefact is what makes the
#    review mean anything — a second `tofu apply` would re-plan against whatever
#    the world looks like by then.
tofu plan -var-file=environments/staging/terraform.tfvars -out=staging.tfplan

# 3. Read it. Every `destroy` and every `must be replaced` is a question to
#    answer out loud, because replacement of a Proxmox VM frees its disks.
tofu show staging.tfplan

# 4. Apply exactly what was reviewed.
tofu apply staging.tfplan
```

Credentials come from the environment, never from a committed file:

```bash
export TF_VAR_proxmox_api_token="$(secret-store get staging/proxmox-token)"
export TF_VAR_cloudflare_api_token="$(secret-store get staging/cloudflare-token)"
```

Two safety properties are built into the modules. Platform VMs and DNS records
carry `prevent_destroy = true`, so a change that forces replacement fails the
plan instead of quietly freeing a disk or removing a live A record — removing
that line for one apply is the friction that turns it into a decision. And
provider versions are pinned with `~>` rather than floored, because a provider
that upgrades itself between the plan and the apply has invalidated the plan
somebody just reviewed.

**State is a secret.** It holds cloud-init user data, generated values and the
full topology of the provider's networks in plaintext. The backend must be
encrypted, versioned, access-controlled and **locking** — two concurrent applies
against unlocked state produce two sets of machines and one record of them.

---

## Running things

```bash
# Read-only inspection of a declared fleet. Safe during an incident; that is
# the point of it.
make infra-check ENV=production

# Survey instead of stopping at the first bad host.
cd infrastructure/ansible
ansible-playbook -i inventories/production playbooks/preflight.yml \
    -e preflight_fail_on_violation=false

# Any playbook, reviewed first.
ansible-playbook -i inventories/staging playbooks/<name>.yml --check --diff
ansible-playbook -i inventories/staging playbooks/<name>.yml --diff
```

## Checking this repository

```bash
cd infrastructure/ansible
ansible-playbook --syntax-check -i inventories/production playbooks/*.yml
ansible-lint                      # profile and offline mode pinned in .ansible-lint

cd ../tofu
tofu fmt -check -recursive
tofu init -backend=false && tofu validate    # needs registry.opentofu.org
```
