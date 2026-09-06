# =============================================================================
# Lynomia Cloud — platform infrastructure.
# =============================================================================
#
# WHAT THIS ROOT MODULE OWNS
#
#   The platform's own long-lived machines and the platform's own DNS records:
#   staging control-plane hosts, the monitoring host, the names those answer to.
#
# WHAT IT MUST NEVER OWN
#
#   Customer virtual machines. The control plane creates, resizes and destroys
#   those through the Proxmox API and records them in its own database, which is
#   the commercial source of truth. If OpenTofu also held them in state, the two
#   would disagree within a day — a customer resizes a machine in the portal,
#   the next `tofu plan` proposes to "correct" it back, and an apply run by
#   somebody who did not read the plan reverts a change the customer paid for.
#   Worse, `tofu destroy` on a state file containing customer machines is a
#   single command that deletes them.
#
#   Reconciliation between the platform and the hypervisors is a modelled
#   concept with an operator in the loop (docs/proxmox.md). A second declarative
#   system with its own opinion is not reconciliation; it is a race.
#
# NOTHING HERE APPLIES AUTOMATICALLY. There is no CI job that runs `tofu apply`.
# See infrastructure/README.md, "Plan, review, then apply".

provider "proxmox" {
  endpoint  = var.proxmox_endpoint
  api_token = var.proxmox_api_token

  # Left at the variable's default of false. Disabling verification on the link
  # that creates and destroys machines is not an acceptable shortcut, even for a
  # self-signed internal certificate.
  insecure = var.proxmox_insecure
}

provider "cloudflare" {
  api_token = var.cloudflare_api_token
}

# -----------------------------------------------------------------------------
# Platform machines
# -----------------------------------------------------------------------------
module "platform_vm" {
  source = "./modules/proxmox-vm"

  for_each = var.platform_vms

  name         = "${var.environment}-${each.key}"
  environment  = var.environment
  node_name    = each.value.node_name
  template_id  = each.value.template_id
  cores        = each.value.cores
  memory_mb    = each.value.memory_mb
  disk_gb      = each.value.disk_gb
  datastore_id = each.value.datastore_id
  bridge       = each.value.bridge
  vlan_id      = each.value.vlan_id
  ipv4_address = each.value.ipv4_address
  ipv4_gateway = each.value.ipv4_gateway

  ssh_authorized_keys = var.ssh_authorized_keys
}

# -----------------------------------------------------------------------------
# The platform's own DNS
# -----------------------------------------------------------------------------
module "platform_dns" {
  source = "./modules/dns-records"

  zone_id = var.cloudflare_zone_id

  records = {
    for key, vm in var.platform_vms : key => {
      name = vm.dns_name
      type = "A"
      # The address without its prefix length. A record containing "/24" is
      # accepted by nobody and rejected in a way that reads like a permissions
      # error.
      content = split("/", vm.ipv4_address)[0]

      # Platform infrastructure is never proxied. Cloudflare's proxy terminates
      # TLS and rewrites the source address, which breaks SSH entirely and makes
      # every log line on the host show a Cloudflare address instead of the
      # operator's. It is right for the customer portal and wrong for a
      # management name.
      proxied = false
    }
    if vm.dns_name != null
  }
}
