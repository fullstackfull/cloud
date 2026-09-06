# =============================================================================
# Root module inputs.
# =============================================================================

variable "environment" {
  description = "Which environment this state describes. Used in every resource name and tag so a machine can be traced back to the state that owns it."
  type        = string

  validation {
    # `development` is deliberately absent. Development runs against fake
    # providers on a developer's machine (docs/deployment.md); there is no
    # infrastructure for OpenTofu to create, and a root module that accepted
    # "development" would invite someone to point it at real credentials.
    condition     = contains(["staging", "production"], var.environment)
    error_message = "environment must be staging or production."
  }
}

# --- Proxmox ----------------------------------------------------------------

variable "proxmox_endpoint" {
  description = "Proxmox API endpoint, reached over the management network. Never a public address."
  type        = string
}

variable "proxmox_api_token" {
  description = "API token in the form user@realm!tokenid=secret. Supplied from the environment or the secret store, NEVER from a committed tfvars file. Scoped to the provisioning role created by the Ansible proxmox role — never a root password."
  type        = string
  sensitive   = true
}

variable "proxmox_insecure" {
  description = "Skip TLS verification against the Proxmox API. Defaults to false and should stay there: this is the link that creates and destroys machines. Install the certificate instead of disabling the check."
  type        = bool
  default     = false
}

# --- Cloudflare -------------------------------------------------------------

variable "cloudflare_api_token" {
  description = "Cloudflare API token, scoped to DNS edit on the zones below and nothing else. Supplied from the environment or the secret store."
  type        = string
  sensitive   = true
}

variable "cloudflare_zone_id" {
  description = "Zone the platform's own hostnames live in. Customer DNS is NOT managed here — see the note in modules/dns-records."
  type        = string
}

# --- Platform machines ------------------------------------------------------

variable "platform_vms" {
  description = <<-EOT
    The platform's OWN virtual machines: staging control-plane hosts, the
    monitoring host, a build runner. Customer machines are never described here
    — the control plane creates those through the Proxmox API and records them
    in its own database. See infrastructure/README.md, "What OpenTofu owns".
  EOT

  type = map(object({
    node_name    = string
    template_id  = number
    cores        = number
    memory_mb    = number
    disk_gb      = number
    datastore_id = string
    bridge       = string
    vlan_id      = optional(number)
    ipv4_address = string # CIDR, e.g. 198.51.100.11/24
    ipv4_gateway = string
    dns_name     = optional(string)

    # THERE IS DELIBERATELY NO PER-MACHINE `protect` FLAG HERE.
    #
    # `lifecycle.prevent_destroy` only accepts a literal — it cannot read a
    # variable — so an attribute called `protect` could be declared, documented
    # and read by nobody, and setting it to false would change nothing while
    # reading as though it had. A safety flag that does not do what its name
    # says is worse than no flag: somebody eventually relies on it.
    #
    # Every machine this module builds carries `prevent_destroy = true`
    # unconditionally (modules/proxmox-vm/main.tf). Destroying one means editing
    # that line for that apply, which is the friction that makes it a decision.
  }))

  default = {}
}

variable "ssh_authorized_keys" {
  description = "Public keys placed on platform machines by cloud-init. Public keys only; no private key material reaches this repository or the state file."
  type        = list(string)
  default     = []
}
