# Lynomia Cloud — control plane

A Laravel 13 / PHP 8.4 modular monolith on PostgreSQL and Redis. It is the
control plane for a hosting business: orders, billing, provisioning, DNS,
domains, backups and the operator-facing Control Center. The customer portal
is a React 19 + Vite application in `apps/web`; infrastructure as code is in
`infrastructure/`; `docs/` holds the authoritative written record.

`CLAUDE.md` and this file are the same text, and a test keeps them identical.
Edit one and the other must match.

## Layout

Business code lives in `src/Modules/<Module>/`, each with the same four
layers:

- `Domain/` — enums, value objects, DTOs, contracts, state machines,
  exceptions. No framework, no Eloquent, no HTTP.
- `Application/` — actions, jobs, services. One action per business
  operation; it is where a transaction begins and ends.
- `Infrastructure/` — Eloquent models, provider adapters, factories,
  notifications.
- `Http/` — controllers, form requests, resources.

`app/` holds only the framework wiring and console commands. A module never
reaches into another module's `Infrastructure` or `Http`; cross-module work
goes through the other module's `Domain` contracts or `Application` actions.
`tests/Architecture/LayeringTest` enforces this, including references in
docblocks.

## Running things

```sh
APP_ENV=testing php artisan test              # the whole backend suite
APP_ENV=testing php artisan test tests/Feature/Billing   # one directory
vendor/bin/pint                               # formatting, before every commit
tools/phpstan/vendor/bin/phpstan analyse -c tools/phpstan/phpstan.neon
```

`APP_ENV=testing` matters: `phpunit.xml` and `.env.testing` point at the
`lynomia_test` database, and `.env` points at `lynomia`. A census or a
migration run without it touches the wrong one.

**Never run two `php artisan test` invocations at once against the same
database.** They share `lynomia_test`, and the failures that come back are
noise about each other rather than about the code. Killing a run mid-flight
leaves rows behind for the same reason — `php artisan migrate:fresh` on the
test database clears it.

In `apps/web`: `npx vitest run`, `npx eslint src --max-warnings=0`,
`npx tsc --noEmit`, `npx vite build`, and `npx playwright test` for the
browser specs. `npm run openapi:lint` from the repository root validates
`docs/openapi.yaml`.

## The gates are the design

`tests/Architecture/` is not a style checker. Each test encodes an invariant
somebody was burned by, and the failure message says which one and why. When
one fails, read it and fix the cause; do not adjust the assertion to match new
behaviour unless the invariant itself has genuinely changed, and then say so in
the commit.

The same goes for a comment that contradicts the code: one of them is a defect.
Long explanatory docblocks in this repository are load-bearing — they record
the failure a piece of code exists to prevent — so correct them with the code
rather than leaving them behind.

## Providers, and what "real" means

Every external system sits behind a `Domain/Contracts` interface with at
least two implementations: a real adapter, and a controlled simulator used to
rehearse the lifecycle. `ProviderCatalogue` lists every driver the platform
has an adapter for and declares which of its category's capabilities that
adapter can actually perform.

- A controlled driver must never be registered in production, and never
  counts as evidence that a capability exists.
- `Product::softwareState()` says whether a product's software is
  `complete`, `prepared` (built, outside the approved launch scope) or
  `readiness_only`. `ProductSellability` is the single answer to "may this be
  sold now?", read by both the order guard and the catalogue.
- No real provider is verified. `REAL_INFRA_VERIFIED`,
  `REAL_PAYMENT_VERIFIED`, `REAL_REGISTRAR_VERIFIED`,
  `REAL_HOSTING_VERIFIED` and `READY_TO_SELL` are all `NONE`, and changing one
  is a business decision recorded in `docs/`, not a code change.

Do not make real provider calls, use real credentials, publish DNS, charge a
payment, register a domain, or run an infrastructure apply. Work against the
simulators.

## Secrets and data

Secrets never enter Git, logs, or the database in plaintext; credentials are
held by reference and resolved through the secret resolver. Provider messages
are passed through `SecretRedactor` before they are stored or logged.
Customer-facing errors carry a code and a sentence and disclose nothing about
other accounts or about deployment state.

## Changes

Migrations are additive, named for what they record, and live in
`database/migrations/`. Prefer correcting an existing document in `docs/` over
adding one that contradicts it. Keep a change to what the task asked for: do
not add dependencies, run installers, or mutate `composer.json`,
`composer.lock`, `package.json` or `package-lock.json` as a side effect of
unrelated work — if a task genuinely needs a new dependency, that is the
task, and it is proposed rather than assumed.
