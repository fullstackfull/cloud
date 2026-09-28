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
> | `/api/v1` identity, account and API tokens | implemented |
> | `/api/v1` business — catalogue, orders, billing, wallet, services, VPS, backups, dedicated, hosting, IPAM | implemented |
> | `/api/admin` — the operator surface | implemented; every route behind the staff gate and its own permission, and reachable only from an operator's portal session (a personal access token is refused) |
> | `/webhooks/{provider}` | implemented — signature verified before the body is parsed, replays acknowledged without being applied twice |
>
> Support tickets, DNS zones and records, and backup restore, file restore and
> deletion are routes like the rest and are listed in `docs/openapi.yaml`. What
> each has been verified against — a simulator or a real provider — is recorded
> in `docs/build-status.md`, not here.

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

Administrative capability lives behind its own prefix, `/api/admin`. The prefix is not the
control. Every route there carries the staff gate (`EnsureTheCallerIsStaff`), which refuses a
login holding no staff role and any request a personal access token authenticated, and each
route names the permission it requires.

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

A token is a credential for the customer API only. `/api/admin` refuses any request a personal
access token authenticated, even when the token's holder is staff: operators reach it through the
portal session.

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
  `details.fields`. On any other refusal it holds only the keys the refusing exception's
  class declares, each something the caller already knows — a value they sent, a field on
  their own form, the state of their own resource — and it is absent when there are none.
  The platform's own names — which provider, driver, node, cluster or configuration key was
  involved — are kept out of it; they go to the log, and `request_id` finds them there.
  (Two operator-only refusals in the Control Center, `deployment_refused` and
  `safety_refused`, are built by hand rather than from an exception, and carry their own
  `details`.)
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

A collection comes back in one of three envelopes, and `docs/openapi.yaml`
publishes which for each operation.

**Paged by number.** The list takes `?page=` and `?per_page=` and answers:

```json
{
  "data": [ ... ],
  "meta": { "page": 1, "per_page": 25, "total": 112, "last_page": 5, "max_per_page": 100 }
}
```

That `meta` is `PaginationMeta` in the description. The notification inbox
adds `unread` beside it.

**Walked by cursor.** `GET /api/v1/activity` is the one list paged this way. It
takes `?cursor=` and `?per_page=` and answers:

```json
{
  "data": [ ... ],
  "meta": { "next_cursor": "eyJpZCI6...", "per_page": 25 }
}
```

That `meta` is `CursorPaginationMeta`. `next_cursor` is passed back as `cursor`
for the next page and is null on the last one. The feed is a union of the
account's history that grows at its newest end while it is read, so an offset
would show one row twice and skip another, and it has no total because
counting every source would double the cost of each page. A `per_page` outside
1 to 100 is refused with `validation.failed`.

**Not paged.** The rest return `{ "data": [ ... ] }` in one response. Some add a
`meta` of their own, such as the DNS zone list's `total`.

## Money in responses

Money is always an object, never a number:

```json
{ "minor_units": 12500, "currency": "KWD", "amount": "12.500" }
```

`minor_units` is what arithmetic should use. `amount` is an exact decimal string for
display. Neither is a JSON number: a KWD amount beyond a few million fils loses precision
as an IEEE double, and JSON numbers are doubles in most clients.

## OpenAPI

`docs/openapi.yaml` is the specification: an OpenAPI 3.1 description of every
route the application registers under `api/` and `webhooks/`, 302 operations
when this was written. `php artisan openapi:generate` writes it (from the
repository root, `npm run openapi:generate`), reading the paths, methods and
path parameters from the route table and the meaning from
`apps/control-plane/resources/openapi/` (`operations.php`, `components.php`,
`schemas.php`). The file is not edited by hand: change those and regenerate.
`php artisan openapi:generate --check` fails, and writes nothing, when the
committed file is not what the generator produces, and
`OpenApiSpecificationTest` fails on the same difference in the backend suite.
`npm run openapi:lint` validates it with Redocly, in CI too.

There is no generated client. The portal talks to the API through a
hand-written client in `apps/web/src/lib/api.ts`, with response types written
by hand, mostly in `apps/web/src/lib/types.ts`. `TheClientAndTheDescriptionAgreeTest`
holds them to the description by name: every property of a portal interface
that shares its name with a published schema (or is paired with one in the
test's short alias list) must be one that schema declares. It compares names,
not types.

`packages/shared-types` and `packages/api-client` are empty workspace placeholders reserved
for generated output. They export nothing today.
