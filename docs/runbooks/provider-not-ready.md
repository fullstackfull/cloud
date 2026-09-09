# An enabled provider is no longer ready

## What you are seeing

`EnabledProviderNotReady`, or a provider on the Providers screen that is
`enabled` with a readiness other than ready-for-production.

## What it means

Somebody enabled it when it was ready. Since then its credential was revoked
or rotated, its licence lapsed or was invalidated, or its endpoint stopped
answering. The platform does not switch a provider off on a key event — that
decision stays with you — so work routed to it will fail at the provider until
you act.

## Check first

```
GET /admin/control-center/providers        # readiness column names the first blocker
```

The blocker is in dependency order: hardware, then licence, then credentials,
then network, then configuration. Fix the one named; the next, if any, appears
when you reassess.

## Fix

- **Credentials**: the Credentials screen says whether the value is on the
  controller. Put it there under the variable name and mark it rotated, or
  attach a different credential.
- **Licence**: renew with the vendor and record the term on the Licences
  screen; or attach a different licence.
- **Network**: run *Test and discover* on the provider. The steps say where it
  failed.

Then *Reassess* the provider, or wait: any change to its credential or licence
reassesses it for you.

## If it cannot be fixed now

*Disable* it with a reason. Nothing new is routed to it, work in flight is not
interrupted, and the products that depend on it show the blocker on the
Readiness screen instead of failing at order time.
