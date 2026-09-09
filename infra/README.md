# Lynomia infrastructure

This tree is the desired state of the machines Lynomia runs on. It is the only
infrastructure system in this repository; do not start a second one.

What lives here:

| Path | Holds |
| --- | --- |
| `ansible/` | Inventory metadata, roles and playbooks for machines that already boot |
| `opentofu/` | Declarations for resources an API creates for us |
| `pxe/` | Network-install profiles for machines that do not yet have an OS |
| `monitoring/` | Prometheus, Alertmanager, Loki, Alloy and Grafana configuration |
| `runbooks/` | What an operator does when something is broken |
| `scripts/` | The four verbs below, and the checks that keep this tree honest |

What must never live here: passwords, private SSH keys, Proxmox secrets,
BMC or iLO passwords, Cloudflare tokens, registrar credentials, payment gateway
secrets, SMTP credentials, database passwords, or WordPress administrator
credentials. Secrets reach a run from the deployment controller's environment
or from GitHub encrypted secrets — see `docs/phase-30b-real-infrastructure-validation.md`
section E. This phase does not build a secrets manager.

## The four verbs

Every infrastructure action separates into four steps, and each is a different
command. `deploy` is not one of them, because a single word that means
"wipe, install, configure, migrate and delete the old state" hides the moment
where somebody could have said no.

```
scripts/preflight.sh <environment>   # can we reach it, are we allowed to touch it
scripts/plan.sh      <environment>   # what would change — writes nothing
scripts/apply.sh     <environment>   # make the change
scripts/verify.sh    <environment>   # ask the machine what is actually true now
```

`plan.sh` runs Ansible in `--check --diff` and OpenTofu in `plan`. Neither
writes. `apply.sh` refuses to run unless `preflight.sh` passed for the same
environment in the same shell.

## Safety classification

Every host in an inventory carries an explicit `safety_class`:

```
DISCOVERY_ONLY          read facts, change nothing
CONFIGURATION_ALLOWED   change configuration, never touch partitions or firmware
REIMAGE_ALLOWED         may be wiped and reinstalled
DO_NOT_TOUCH            do not connect to it for any write
```

The default is `DO_NOT_TOUCH`. A host with no `safety_class` is a validation
error, not an implicit permission — `scripts/validate-inventory.py` fails the
build for it. A destructive playbook additionally requires `allow_reimage: true`
on the individual host; it will not accept a group, a pattern, or `all`.

An unused-looking disk is not evidence that a disk is spare, and a quiet server
is not evidence that a server is free. Classification comes from a person who
owns the hardware, recorded in `docs/phase-30b-real-infrastructure-inventory.md`.

## Current state

The staging and production inventories contain no hosts. That is a fact about
this repository, not an omission: as of Phase 30B no Lynomia machine has been
made reachable to the environment that runs these playbooks. See
`docs/phase-30b-real-infrastructure-inventory.md` for what was probed and found.

`inventories/examples/` is a fictional inventory used only to exercise the
validator in CI. It is never a deployment target; `scripts/preflight.sh` refuses
it by name.
