# API

> **The authority on this API is `docs/openapi.yaml`.**
>
> It is generated from the route table of the running application by
> `php artisan openapi:generate`, and three things fail the build rather than
> letting it drift: a route with no entry, an entry naming a route that does
> not exist, and a committed file that is not what the generator produces. A
> real OpenAPI 3.1 validator runs over it in CI (`npm run openapi:lint`).
>
> This document explains the conventions those endpoints follow and why they
> are what they are. Where the two disagree, the generated one is right.
>
> | Surface | Status |
> |---|---|
> | `/api/v1` identity, account and API tokens | implemented, 22 operations |
> | `/api/v1` business — catalogue, orders, billing, wallet, services, VPS, backups, dedicated, hosting, IPAM | implemented, 39 operations |
> | `/api/admin` — the operator surface | implemented, 12 operations, each gated on its own permission |
> | `/webhooks/{provider}` | implemented — signature verified before the body is parsed, replays acknowledged without being applied twice |
>
> Not present, and absent because it is not implemented rather than because it
> is undocumented: a support-ticket API, DNS zone management, and restore or
> delete for backups. See `docs/build-status.md`.

## Two surfaces, one implementation

```text
/api/v1/     customer API — portal (cookie session) and public integrations (scoped tokens)
/api/admin/  administrative API — platform guard plus an explicit permission per route
/webhooks/   provider callbacks — signature-authenticated, no session, no CSRF
```

The customer portal is built on `/api/v1` rather than on a private endpoint set. That is
deliberate: there is exactly one implementation of every operation, and anything a customer
can do in the UI they can also automate. A separate internal API drifts from the public one
within a release or two, and the public one is always the poorer for it.

Administrative capability is to live behind its own prefix so that an over-scoped customer
token cannot reach it by accident. Route-level permission checks are the enforcement; the
prefix is defence in depth. No route is registered under `/api/admin` yet.

## Authentication

| Consumer | Mechanism |
|---|---|
| Customer and admin portals | Sanctum SPA cookie session, `HttpOnly` + `Secure` + `SameSite=Lax`, XSRF token on mutations |
| Public customer API | Personal access tokens with explicit scopes |
| Provider webhooks | Provider signature verification only |

A bearer token is never placed in browser storage. Any script injected into the page can
read `localStorage`; an `HttpOnly` cookie it cannot.

### Tokens

Tokens are scoped, rate-limited per token, optionally restricted to a CIDR allow-list, and
bound to exactly one customer account — so a token belonging to a user who administers
several accounts can never act outside the one it was issued for.

Revocation is recorded rather than performed by deletion: the row survives so that "which
token did this?" remains answerable after the token is gone.

## Error contract

Every failure, from every endpoint, has one shape:

```json
{
  "error": {
    "code": "order.already_paid",
    "message": "This order has already been paid.",
    "details": { "order_id": "01JD..." },
    "request_id": "01JDX9Q2K7..."
  }
}
```

- `code` is stable across releases and is what clients branch on.
- `message` is for humans and may be reworded or localised at any time. Never parse it.
- `details` carries structured context; validation failures put field errors under
  `details.fields`.
- `request_id` correlates the failure with the platform's logs. Quote it to support.

Validation failures are `422` with `code: "validation.failed"`.

### Codes that are deliberately indistinguishable

An unknown resource and a resource the caller may not see both return `404`
`resource.not_found`. On an API that exposes ULIDs, the difference between `403` and `404`
is itself an enumeration oracle.

Similarly, `auth.invalid_credentials` is returned identically for an unknown address and a
wrong password.

## Rate limits

| Surface | Limit |
|---|---|
| Login, registration, password reset, two-factor | Tight, keyed on address **and** source IP together |
| Authenticated API tokens | Per-token, configurable, with a tier default |
| Provisioning actions | Lower than general API traffic — each one is expensive and irreversible |
| Webhooks | Generous, per source; signature verification is the security control here, not throttling |

Auth limiters key on address and IP together because keying on the address alone lets
anyone lock a known customer out, and keying on IP alone lets one host spray many
addresses.

Exceeding a limit returns `429` with `Retry-After`.

## Idempotency

Any request that creates a billable resource accepts an idempotency key. Repeating a
request with the same key returns the original result rather than creating a second
resource — which is what makes a double-clicked purchase, a retried mobile request and a
flaky network safe.

Internally the guarantee is a unique constraint, never an `if (exists)` check. The latter
is a race, and the failure it permits is a customer charged twice.

## Versioning

The path carries the version. Within `v1`:

- Adding a field to a response is not a breaking change; clients must ignore unknown
  fields.
- Adding an optional request field is not a breaking change.
- Removing or renaming anything, or narrowing a type, requires `v2`.

New error codes may be introduced. Clients must treat an unrecognised code as a generic
failure of its HTTP class rather than crashing.

## Pagination

List endpoints are cursor-paginated:

```json
{
  "data": [ ... ],
  "meta": { "next_cursor": "eyJpZCI6...", "per_page": 25 }
}
```

Cursors rather than page numbers, because offset pagination over a table that is being
written to skips and duplicates rows — and these tables are written to constantly.

## Money in responses

Money is always an object, never a number:

```json
{ "minor_units": 12500, "currency": "KWD", "amount": "12.500" }
```

`minor_units` is what arithmetic should use. `amount` is an exact decimal string for
display. Neither is a JSON number: a KWD amount beyond a few million fils loses precision
as an IEEE double, and JSON numbers are doubles in most clients.

## OpenAPI

There is no generated specification yet, and no generated client. The portal talks to the
API through a hand-written client in `apps/web/src/lib/api.ts`.

This is worth stating plainly because the alternative is attractive enough to assume: a
specification generated from the routes and their form requests, feeding a typed client, so
that a breaking change to an endpoint fails the frontend's typecheck in CI rather than at
runtime in front of a customer. That is the intent. It is not the state. Generating it now,
against an API that is still only the identity surface, would produce a contract that has to
be thrown away as soon as the business endpoints land — so it is deferred until those
endpoints exist, and until then nothing in this repository claims a specification it does
not produce.

`packages/shared-types` and `packages/api-client` are empty workspace placeholders reserved
for that generated output. They export nothing today.
