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

```bash
php artisan lynomia:operations --state=indeterminate
```

Each row names the provider, the resource and the provider's task reference,
if one was returned before the timeout.

## What to do — in this order

1. **Ask the provider what is true.** Not Lynomia. The provider's own console or
   API is the authority here.
   - Proxmox: does the VMID exist, and does its config match what was ordered?
   - Registrar: is the domain registered, and to whom?
   - Payment gateway: is there a charge with that idempotency key?
2. **Record what you found** against the operation.
3. **Settle it** to match reality:

```bash
php artisan lynomia:operations:settle <id> --outcome=succeeded   # the provider did it
php artisan lynomia:operations:settle <id> --outcome=failed      # the provider did not
```

Settling as succeeded makes Lynomia adopt the resource the provider already has.
Settling as failed releases the order to be retried cleanly.

## What not to do

Do not settle from a guess. Do not settle a batch. There is no Force Success in
this platform, and adding one is how a customer gets billed for a VM that was
never created — or gets a second one they did not order.
