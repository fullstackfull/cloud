# OpenTofu

Declarations for resources an API creates for us, rather than machines that
already boot. In this platform that is a short list, and it is short on purpose.

## What belongs here

- The DNS test zone and its delegation
- Cloudflare account-level configuration
- Any object storage or off-site backup bucket

## What does not belong here

Customer resources. A customer's VPS, DNS zone, hosting account and domain are
created by Lynomia at the moment of purchase, from an order, with an audit entry
and an invoice. They are not desired state in a Git repository, and expressing
them here would mean a `tofu apply` could destroy a customer's server because
somebody edited a file.

The line is: OpenTofu owns the infrastructure Lynomia runs on. Lynomia owns
everything Lynomia sells.

## State

State lives in the deployment controller's configured backend, never in this
repository. A `terraform.tfstate` committed to Git contains every value the
providers returned, including secrets — `.gitignore` refuses it and the CI
secret scan fails on it.

## Running

```bash
cd infra/opentofu/environments/staging
tofu init
tofu plan     # infra/scripts/plan.sh runs this for you
tofu apply
```

`tofu apply` is not wired into CI. CI validates and plans; a person applies.
