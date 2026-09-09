# A WordPress site is stuck provisioning

## What you are seeing

`WordPressProvisioningStuck`, or a site that never leaves `installing`.

## What it means

The customer paid for a working site and has a spinner. The chain is long —
hosting account, DNS, SSL, WordPress install, verification probe — and it stops
at the first link that fails.

## Check first

```bash
php artisan lynomia:wordpress --state=installing --older-than=30m
```

For each, work forward along the chain and stop at the first `no`:

```bash
php artisan lynomia:hosting:accounts --site=<id>      # does the account exist
dig +short <domain>                                   # does DNS resolve
curl -sSI https://<domain> | head -3                  # does TLS answer
```

## The usual causes, in order of frequency

1. **DNS has not propagated**, for a domain the customer pointed at us
   themselves. Nothing is broken; the install is waiting correctly. Tell support
   to tell the customer.
2. **SSL issuance failed** because the domain does not yet resolve to our node.
   Same cause, later in the chain.
3. **The hosting account was never created.** Go to
   `hosting-panel-unavailable.md`.
4. **The install itself timed out.** This is indeterminate: WordPress may be
   half-installed. Do not re-run the installer blindly — it looks before it
   installs, but confirm by hand what is in the document root.

## What to do

Once the blocking link is fixed, the site is re-verified by the scheduled
verification pass; you do not need to push it. To check what that pass sees:

```bash
php artisan lynomia:wordpress:verify --site=<id>
```

A site is only called live once the probe has actually seen it answer over
HTTPS. That is why it is still `installing` — the platform has not seen it work.

## What not to do

Do not mark a site ready to clear the alert. The state means "we looked and it
worked", and a customer told their site is live when it is not will find out
before you do.
