variable "zone_id" {
  description = "The Cloudflare zone these records live in. One zone per module instance: a module that could write into several zones is a module that can write a record into the wrong one."
  type        = string
}

variable "records" {
  description = <<-EOT
    The platform's OWN records, keyed by a stable name. The key becomes the
    resource address in state, so renaming a key destroys and recreates the
    record — which for an A record on a live name is a brief NXDOMAIN.

    THIS MODULE DOES NOT MANAGE CUSTOMER DNS. Customer records and customer
    reverse DNS are owned by the control plane, which validates every PTR change
    against the IP assignment table before making it — because a PTR record
    naming somebody else's domain is a phishing primitive (docs/networking.md).
    A second system writing into the same zone would bypass that check entirely.
  EOT

  type = map(object({
    name    = string
    type    = string
    content = string
    ttl     = optional(number, 300)
    proxied = optional(bool, false)
    comment = optional(string)
  }))

  validation {
    condition     = alltrue([for r in var.records : contains(["A", "AAAA", "CNAME", "TXT", "MX", "SRV", "CAA"], r.type)])
    error_message = "record type must be one of A, AAAA, CNAME, TXT, MX, SRV, CAA."
  }

  validation {
    # A proxied record hides the origin address, terminates TLS at Cloudflare
    # and rewrites the source address. That is correct for the customer portal
    # and wrong for anything an operator connects to: SSH stops working through
    # it, and every log line on the host shows a Cloudflare address instead of
    # the person who connected.
    condition     = alltrue([for r in var.records : r.proxied == false || contains(["A", "AAAA", "CNAME"], r.type)])
    error_message = "only A, AAAA and CNAME records can be proxied."
  }
}
