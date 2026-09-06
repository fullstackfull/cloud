output "record_names" {
  description = "Fully-qualified names created in this zone."
  value       = { for key, record in cloudflare_dns_record.this : key => record.name }
}

output "record_ids" {
  value = { for key, record in cloudflare_dns_record.this : key => record.id }
}
