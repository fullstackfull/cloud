# =============================================================================
# Outputs.
# =============================================================================
#
# Outputs are read by humans and by the Ansible inventory that gets written from
# them. Nothing sensitive is emitted: outputs are stored in state in plaintext
# AND printed at the end of every apply, so an output is the least private place
# a value can be put.

output "platform_vm_ids" {
  description = "Proxmox VM id per platform machine. This is the handle every manual operation on the node needs."
  value       = { for key, vm in module.platform_vm : key => vm.vm_id }
}

output "platform_vm_addresses" {
  description = "Management address per platform machine, without the prefix length. These become the ansible_host values in the inventory."
  value       = { for key, vm in module.platform_vm : key => vm.ipv4_address }
}

output "platform_dns_records" {
  description = "Names created in the platform's own zone."
  value       = module.platform_dns.record_names
}

output "ansible_inventory_hint" {
  description = <<-EOT
    A reminder rather than a generated file. The Ansible inventory is written by
    a human and reviewed, because it is also where the destructive guard flags
    live — allow_reimage, pxe_allow_serve_dhcp, proxmox_cluster_join_enabled.
    An inventory generated from OpenTofu state would carry whatever those
    defaulted to, and a guard that arrives by generation is a guard nobody
    decided to set.
  EOT
  value       = "Add the addresses above to infrastructure/ansible/inventories/${var.environment}/ by hand."
}
