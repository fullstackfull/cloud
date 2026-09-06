# =============================================================================
# One platform virtual machine, cloned from a cloud-init template.
# =============================================================================
#
# This module builds the platform's own machines. It never builds a customer's —
# see the note at the top of the root module.

resource "proxmox_virtual_environment_vm" "this" {
  name      = var.name
  node_name = var.node_name
  on_boot   = var.start_on_boot

  description = "Managed by OpenTofu (infrastructure/tofu). Environment: ${var.environment}. Manual changes are reverted by the next apply."

  tags = ["lynomia", var.environment, "opentofu"]

  clone {
    vm_id = var.template_id
    # A full clone, not a linked clone. A linked clone keeps a permanent
    # dependency on the template: deleting or re-versioning the template breaks
    # every machine cloned from it, and the failure appears as disk errors
    # inside the guest rather than as anything naming the template.
    full = true
  }

  # The guest agent is what lets the platform read a machine's actual address,
  # shut it down gracefully, and take a consistent snapshot. Without it, "stop"
  # is the only option and every backup is crash-consistent.
  agent {
    enabled = true
  }

  cpu {
    cores = var.cores
    # `host` exposes the physical CPU's features, which matters for AES-NI and
    # for the container workloads on the monitoring host. It also pins the
    # machine to compatible hardware for live migration — acceptable for
    # platform machines on known nodes, and the reason customer machines use a
    # model chosen by the control plane instead.
    type = "host"
  }

  memory {
    dedicated = var.memory_mb
    # No ballooning on platform machines. A ballooned control-plane host under
    # memory pressure has memory taken from it by the hypervisor at exactly the
    # moment it is busiest, and the symptom is the OOM killer stopping Horizon.
    floating = 0
  }

  disk {
    datastore_id = var.datastore_id
    interface    = "scsi0"
    size         = var.disk_gb
    discard      = "on"
    ssd          = true
  }

  network_device {
    bridge  = var.bridge
    vlan_id = var.vlan_id
  }

  initialization {
    datastore_id = var.datastore_id

    ip_config {
      ipv4 {
        address = var.ipv4_address
        gateway = var.ipv4_gateway
      }
    }

    user_account {
      username = var.username
      keys     = var.ssh_authorized_keys
      # No `password`. Anything set here is written to the Proxmox
      # configuration AND to the OpenTofu state file in plaintext, and the
      # platform disables password authentication on every host anyway.
    }
  }

  lifecycle {
    # WHY prevent_destroy IS ON BY DEFAULT
    #
    # Several of these attributes force replacement when they change, and
    # "replace" on a Proxmox VM means destroy-then-create: the disks are freed.
    # For the monitoring host that is the whole metrics history; for a staging
    # database it is the data somebody was mid-way through testing against.
    #
    # A change that genuinely requires replacement is a decision, made with a
    # backup taken first. Removing this line for that one apply is the friction
    # that makes it a decision instead of a side effect of editing a number.
    prevent_destroy = true

    ignore_changes = [
      # Proxmox rewrites this on every boot with the agent's view of the guest.
      # Left unignored, every plan proposes a change that is not a change.
      initialization[0].user_account[0].keys,
    ]
  }

  # Building a machine takes minutes; the API answers in milliseconds. These are
  # limits on how long OpenTofu waits, NOT retries. A timeout here means the
  # tool stopped waiting — the machine may well exist. Do not re-run blindly:
  # `tofu plan` first, and let it tell you what is actually there. Re-applying
  # after a timeout is how a customer's node ends up hosting two of something.
  timeout_clone       = 1800
  timeout_create      = 1800
  timeout_start_vm    = 300
  timeout_shutdown_vm = 300
}
