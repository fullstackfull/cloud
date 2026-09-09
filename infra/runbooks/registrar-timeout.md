# The registrar timed out

## What you are seeing

`DomainOperationsStuck`, or domain operations sitting in `running` /
`awaiting_registry`.

## What it means

Registrar operations are money operations against a global namespace. A retry
that succeeds twice registers a domain twice, or charges twice for one name.
This is precisely the case the Timeout Rule exists for.

## Check first

```bash
php artisan lynomia:domains:operations --state=running
php artisan lynomia:domains:operations --state=indeterminate
```

`awaiting_registry` is not a failure. Transfers legitimately sit there for days
while the losing registrar's window runs. Check the operation's kind before
treating it as stuck.

## What to do

1. Ask the registrar directly whether the name is registered, and to whom. Their
   whois and their API are the authority, not Lynomia.
2. Settle the operation to match, per `provider-indeterminate.md`.

For a registration that did complete at the registrar but not in Lynomia,
settling as succeeded adopts it — the customer keeps the name they paid for, and
no second registration is attempted.

## Registrar unreachable entirely

Search and availability degrade to `UNKNOWN`, which the product displays as
unknown rather than as available. That is correct behaviour, not a bug to work
around: selling a name whose availability you could not confirm is how a customer
pays for something somebody else owns.

## What not to do

Do not retry a registration whose outcome you have not confirmed. Do not tell a
customer a name is available because search failed open — it does not fail open,
and it should not.
