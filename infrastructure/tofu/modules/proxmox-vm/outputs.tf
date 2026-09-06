output "vm_id" {
  description = "Proxmox VM id. Every manual operation on this machine needs it."
  value       = proxmox_virtual_environment_vm.this.vm_id
}

output "name" {
  value = proxmox_virtual_environment_vm.this.name
}

output "node_name" {
  value = proxmox_virtual_environment_vm.this.node_name
}

output "ipv4_address" {
  description = "The configured management address, without its prefix length."
  value       = split("/", var.ipv4_address)[0]
}
