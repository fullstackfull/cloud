variable "name" {
  description = "Machine name. Becomes the Proxmox VM name and the guest hostname, so it must be a valid DNS label."
  type        = string

  validation {
    condition     = can(regex("^[a-z0-9]([a-z0-9-]*[a-z0-9])?$", var.name))
    error_message = "name must be a lowercase DNS label: letters, digits and hyphens, not starting or ending with a hyphen."
  }
}

variable "environment" {
  type = string
}

variable "node_name" {
  description = "The Proxmox node to build on. Named explicitly rather than scheduled: the platform's weighted scheduler places CUSTOMER machines (docs/proxmox.md), and platform machines are placed by a human who knows which node they must not share with the thing they monitor."
  type        = string
}

variable "template_id" {
  description = "VM id of the cloud-init template to clone. Templates are built by automation from a verified upstream image and versioned, so 'which image was this built from' has an answer."
  type        = number
}

variable "cores" {
  type = number
}

variable "memory_mb" {
  type = number
}

variable "disk_gb" {
  description = "Root disk size in GiB. Proxmox can grow a disk and cannot shrink one, so a reduction here is a replace — which frees the current disk. The lifecycle block refuses it."
  type        = number
}

variable "datastore_id" {
  type = string
}

variable "bridge" {
  description = "The bridge this machine attaches to. Never the management bridge: a guest on the management network can reach every BMC in the rack, and a BMC is a complete out-of-band computer with power control and virtual media."
  type        = string
}

variable "vlan_id" {
  description = "VLAN tag, or null for an untagged port. No VLAN ID is hard-coded anywhere in this repository — a VLAN that is management in one datacentre is somebody's production network in another."
  type        = number
  default     = null
}

variable "ipv4_address" {
  description = "Address in CIDR form, e.g. 198.51.100.11/24."
  type        = string

  validation {
    condition     = can(regex("^([0-9]{1,3}\\.){3}[0-9]{1,3}/[0-9]{1,2}$", var.ipv4_address))
    error_message = "ipv4_address must include a prefix length, e.g. 198.51.100.11/24. Cloud-init silently produces an unreachable machine when it is missing."
  }
}

variable "ipv4_gateway" {
  type = string
}

variable "ssh_authorized_keys" {
  description = "Public keys only. Cloud-init user data is stored in Proxmox and in the OpenTofu state file, both in plaintext."
  type        = list(string)
  default     = []
}

variable "username" {
  description = "Account cloud-init creates. Password authentication is never configured: the hardening role disables it anyway, and a password set here would be written to state."
  type        = string
  default     = "lynomia"
}

variable "start_on_boot" {
  type    = bool
  default = true
}
