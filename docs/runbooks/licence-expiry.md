# A licence is expiring or has expired

## What you are seeing

`LicenceExpiring` (within thirty days) or `LicenceExpired`, or the same on the
Licences screen.

## What it means

Licence states follow the calendar. The nightly sweep moves a licence to
*expiring* thirty days out and to *expired* the day after its term, and each
change reassesses every provider under it. An expired licence makes those
providers not ready; one that was serving stops working at the vendor.

## Fix

Renew with the vendor. Then, on the Licences screen, **Renew** the licence with
the new expiry date. That clears any invalidation, recomputes the state and
reassesses the providers under it at once rather than at the next sweep.

To move states now rather than tonight:

```bash
php artisan licences:refresh
```

## Do not

- Look for a control that declares an expired licence active. There is none:
  the only override goes the other way (marking a licence the vendor rejected
  as invalid). A renewal is a new fact from the vendor.
