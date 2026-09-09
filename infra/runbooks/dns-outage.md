# DNS is not resolving

## What you are seeing

Customer sites unreachable while the servers are up; DNS operations failing.

## What it means

Separate two things that feel identical: Lynomia cannot *publish* DNS changes
(bad, not urgent), or published DNS is not *resolving* (a customer outage).

## Check first

```bash
dig +short <a customer domain> @1.1.1.1
dig +short <a customer domain> @8.8.8.8         # two resolvers, not one
dig NS <a customer domain> +short               # are the nameservers what we set
curl -sS -o /dev/null -w '%{http_code}\n' https://api.cloudflare.com/client/v4/user/tokens/verify
```

If public resolvers answer correctly, resolution is fine and the problem is
somewhere else — go and check the web server before touching DNS.

## What to do

- Provider API down, resolution fine: nothing is broken for customers. Queue the
  changes, tell support, wait. Do not migrate providers during an outage.
- Nameservers wrong at the registrar: this is a registrar operation, not a DNS
  one. See `registrar-timeout.md`.
- Records missing at the provider: reconcile the zone.

```bash
php artisan dns:reconcile
```

This compares recorded records with what the provider is serving and reports the
difference. It does not rewrite the provider — a customer who edited a record
directly is not silently reverted. Work the result through `drift.md`.

## What not to do

Do not test with one resolver. Do not lower TTLs during an incident — it does
not help now and it costs you the next hour.
