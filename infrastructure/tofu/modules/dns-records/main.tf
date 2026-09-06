# =============================================================================
# The platform's own DNS records.
# =============================================================================

resource "cloudflare_dns_record" "this" {
  for_each = var.records

  zone_id = var.zone_id
  name    = each.value.name
  type    = each.value.type
  content = each.value.content
  proxied = each.value.proxied

  # Short by default. A record with a day-long TTL is a record that cannot be
  # moved during an incident: the change is correct within seconds and the
  # internet keeps sending traffic to the dead address for the rest of the day.
  # The cost of a low TTL is a few more queries against an anycast network built
  # to answer them.
  ttl = each.value.proxied ? 1 : each.value.ttl

  comment = coalesce(each.value.comment, "Managed by OpenTofu — infrastructure/tofu")

  lifecycle {
    # Destroying an A record for a live name is an outage that looks like DNS
    # propagation and is therefore diagnosed slowly — so removing one must not
    # be possible as a side effect of a refactor that renamed a map key.
    #
    # Note what `prevent_destroy` actually does: removing an entry from
    # `var.records` makes `tofu plan` FAIL, with an error naming this resource.
    # It does not produce a plan showing "1 to destroy" that an operator can
    # read and accept. Deleting a record on purpose means removing this line for
    # that one apply — that is the friction, and it is the only way through.
    prevent_destroy = true
  }
}
