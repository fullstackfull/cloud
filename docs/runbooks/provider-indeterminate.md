# An operation's outcome is unknown

## What you are seeing

`ProviderTasksIndeterminate`, or an operation sitting in `indeterminate`.

## What it means

Lynomia asked a provider to do something and never learned whether it happened.
A VM may exist. A domain may be registered. A card may be charged. The platform
deliberately does not guess, and deliberately does not retry: the Timeout Rule
says an indeterminate destructive or money operation is never retried
automatically. That is why this is a person's job.

## Check first

The operator portal is where these live:

```
GET /admin/operations/reinstalls          # VPS and dedicated rebuilds in review
GET /admin/domains/operations             # registrar operations
GET /admin/drift                          # resources that disagree with a provider
```

Each row names the provider, the resource and the provider's task reference, if
one was returned before the timeout. These are also what the poll commands
refresh:

```bash
php artisan compute:poll-tasks       # did accepted hypervisor tasks finish
php artisan domains:reconcile        # settle uncertain names against the registry
php artisan backups:reconcile        # in-flight backups
php artisan payments:reconcile --older-than=60
```

## What to do — in this order

1. **Ask the provider what is true.** Not Lynomia. The provider's own console or
   API is the authority here.
   - Proxmox: does the VMID exist, and does its config match what was ordered?
   - Registrar: is the domain registered, and to whom?
   - Payment gateway: is there a charge with that idempotency key?
2. **Record what you found** against the operation.
3. **Settle it** to match reality, from the operator portal:

```
POST /admin/operations/reinstalls/{type}/{operation}/resolve
```

Settling as completed makes Lynomia adopt what the provider already has, and
tells the customer. Settling as not-completed releases the order to be retried
cleanly. The settlement is recorded as an audit entry in the same transaction as
the state change.

There is deliberately no CLI for this, and no bulk form of it. Settling is a
person stating what they found at a provider, one operation at a time.

## What not to do

Do not settle from a guess. Do not settle a batch. There is no Force Success in
this platform, and adding one is how a customer gets billed for a VM that was
never created — or gets a second one they did not order.
