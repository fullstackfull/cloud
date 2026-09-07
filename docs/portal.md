# Portal

The customer-facing single-page application in `apps/web`.

## Implementation status

| Area | Status |
|---|---|
| Sign in, including the two-factor challenge | built |
| Registration, email-verification notice, forgotten and reset password | built |
| Dashboard: billing accounts, security posture | built |
| Profile: name, language, time zone, phone | built |
| Security: second factor, password, signed-in devices, sign-in history | built |
| Catalogue and checkout | built |
| Orders, invoices, subscriptions, wallet | built |
| Services, VPS with power control, dedicated servers, hosting accounts, IP addresses with reverse DNS | built |
| API tokens | built |
| VPS reinstall and console, hosting usage detail, invoice documents | **not built** — the endpoints exist for some of these; the screens do not |
| Operator area: customers with suspend, provisioning queue, infrastructure capacity, payments with refund | built |
| Operator: catalogue editing, IPAM management, audit log | **not built** — inventory and pricing are declared in the repository and applied by Ansible, not typed into a form; there is no audit table yet |

The navigation lists only what is reachable. A link to a page that does not
exist tells a customer the platform can do something it cannot, and they will
open a ticket about it.

## Shape

```
src/
  app/          routing, the two layouts, the auth guards
  components/   presentation with no knowledge of any endpoint
  features/     one directory per area: its hooks and its pages together
  i18n/         catalogues, locale detection, direction
  lib/          the API client, formatting, error translation
```

The operator area is under `features/admin/` and calls `/api/admin` through its
own client in `lib/adminQueries.ts` rather than the customer one. Separate bases
mean a mistyped path cannot land an operator call on a customer endpoint or the
reverse. Hiding the navigation is a courtesy, not a control: every
administrative endpoint checks its own permission server-side, and a customer
who types the URL is redirected and would have been refused by the API anyway.

A feature owns its data access. `features/account/useProfile.ts` is where the
profile and session endpoints live, and nothing outside that directory calls
them. The alternative — a central `services/api.ts` that every page imports —
turns every endpoint into a shared dependency and makes it impossible to see,
from one directory, everything a screen can do.

Components in `components/` never import from `features/`. That is what keeps
them reusable rather than becoming a second place where business rules live.

## Authentication

Sanctum's cookie session, not a bearer token. `lib/api.ts` fetches the CSRF
cookie once per page load, shares one in-flight request between concurrent
callers, and attaches `X-XSRF-TOKEN` to every mutation.

No token is ever placed in `localStorage`. Any script injected into the page can
read web storage; an `HttpOnly` cookie it cannot. The only thing the portal
stores locally is the chosen language.

The two-factor challenge token is held in component state and never persisted.
It is a short-lived single-use credential, and writing it to storage would leave
it readable on a shared machine after the tab is closed.

`safeRedirect` narrows the post-sign-in destination to a same-site path. The
sign-in page is the natural home of an open redirect: a link that lands a
customer on the genuine login form and then bounces them to a copy is convincing
precisely because the first hop was real.

## Language and direction

`lang` and `dir` are set on `<html>` before the first paint, so a right-to-left
customer never sees a flash of left-to-right layout. Direction is a property of
the locale, not a user setting — there is no control to put an Arabic page into
LTR, because there is no reason to want one.

Layout uses CSS logical properties (`ms-`, `me-`, `text-start`) rather than
left and right, so the mirroring is the browser's job.

Three things stay left-to-right inside an Arabic page: email addresses, IP
addresses and any Latin-only identifier. An address laid out right-to-left reads
as nonsense, and a password field mirrors the caret in a way that makes typing
feel broken. `Field` applies this automatically for `email`, `password`, `url`
and `tel`; `DataTable` takes a per-column `ltr` flag.

Numerals are Western in both languages. An invoice total must be unambiguous and
copy-pasteable; `Intl` is asked for `-u-nu-latn` explicitly rather than left to
the locale's default numbering system.

Every language is labelled in its own script in the switcher, never translated:
someone who has landed on a page in a language they cannot read needs to
recognise their own language to get out of it.

## Money

Never a JavaScript number. The API sends integer minor units plus an ISO-4217
code plus a decimal string; the decimal string is parsed only at the final
formatting step and never fed back into arithmetic. A KWD amount beyond a few
million fils would start losing precision as a double, and an invoice that is
wrong by one fil is an invoice the customer is right to dispute.

## Errors

`useApiErrorMessage` turns anything thrown into something a person can act on.
API errors carry a stable code, and the message shown is a translated string
chosen by that code rather than the server's English prose — which is what keeps
the Arabic interface Arabic even when the failure originates server-side. When a
code has no translation yet the server's message is shown, rather than the raw
key.

A network failure is a `NetworkError`, not an `ApiError`: the customer's remedy
is different, so the two must not be reported the same way.

The request id is rendered with the error so that support can find the request
in the logs without asking the customer to reproduce it.

## Tests

```bash
npm run test --workspace=apps/web -- --run
```

Beyond the component behaviour, two suites exist to catch classes of bug that do
not throw:

- **Translation parity** — a missing Arabic key does not fail at runtime;
  i18next falls back to English, so an Arabic customer gets an English sentence
  in the middle of an Arabic page and nothing anywhere reports it. The test also
  checks that interpolation placeholders survive translation.
- **Routing** — that the guards actually gate. Both directions: a signed-out
  visitor cannot reach the security page, and a signed-in one is not shown the
  sign-in form.

- **Plural forms** — English needs two and Arabic needs six, and i18next picks
  between them by key suffix. A pluralised key carrying only `_other` in Arabic
  renders "2 خطة" where the language wants "خطتان": grammatically wrong, and
  invisible to anyone reading the English side. The test requires all six.

There is no browser end-to-end suite. When one is added it will be reported
here; until then, no part of this repository claims to have run one.
