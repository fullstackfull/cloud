# Gap 8 · E-11 — production catalogue control closure

**A production deployment can now be told what it sells, by a person, through
the Control Center. Before this it could not, by any supported means.**

That sentence is the whole change. Nothing here touches infrastructure, no real
provider was contacted, and every `REAL_*` status is still NONE.

---

## 1. The finding

E-11 was found while doing 30B.0-E's hosting-package work — mapping real panel
packages onto Lynomia plans — and the mapping had nowhere to go.

No product, plan, price or hosting package had a writer anywhere in `src/` or
`app/`. Checked exhaustively at the time and recorded in
`docs/phase-30b-0e-trusted-runner-bootstrap.md` §22a:

| Model | Writers in `src/` or `app/` | What did write it |
| --- | --- | --- |
| `Product` | **none** | `CatalogueSeeder` |
| `Plan` | **none** | `CatalogueSeeder` |
| `PlanPrice` | **none** | `CatalogueSeeder` |
| `HostingPackage` | **none** | `CatalogueSeeder`, `E2ESeeder`, the reference topology loader |

`Catalog`'s controllers were `index` and `show`. There was no `store`, no
request object for one, and no route: every non-GET route mentioning plan,
product or catalogue turned out to be a deployment plan or a readiness
assessment.

And the one seeder that wrote them refuses to run where it would matter:

```php
if (app()->isProduction()) {
    throw new RuntimeException(
        'CatalogueSeeder must never run in production: prices are an operator
         decision, not a fixture.'
    );
}
```

That refusal is correct and stays. Nothing was built to replace it.

**What it cost.** `CreateHostingAccountHandler` requires a `hosting_package_id`
and refuses when the package does not exist. The infrastructure preflight has
reported `mapping.hosting_package` as **FAIL** since it was written — "No
hosting package is mapped, so an account has no plan to be created under". So a
real panel onboarded through the Control Center would have had zero packages
and every Shared Hosting order would have been refused. One level up, an order
is placed against a `Plan`, and a production deployment could have none of
those either.

This is the same defect class Gap 8 found in `vm_templates`, by Gap 8's own
test — "onboarding infrastructure this platform already models must not require
a code change; a product that cannot be delivered without one is not
code-complete" — and it is wider, because `vm_templates` blocked VPS builds and
this blocked every sellable product's existence.

## 2. The architecture before

Everything below the write path was already there and is unchanged.

| Piece | State before | Changed here |
| --- | --- | --- |
| `products`, `plans`, `plan_prices`, `hosting_packages` tables | complete, with the constraints below | **no migration** |
| `unique(plan_id, currency, billing_period)` on prices | already in the schema | no |
| `order_items` snapshot of unit amounts, name, period, resources | already in the schema | no |
| `order_items.plan_id` `nullOnDelete` | already in the schema | no |
| `ProductKind` enum, three cases | already | no |
| `ProductSellability` reading software state and readiness | already | no |
| `catalog.view`, `catalog.manage`, `pricing.manage` permissions | already in the vocabulary, **`catalog.view` unused by any route** | no new permission |
| `BillingCurrencies` as the one currency list | already | no |

No migration was needed, which is the clearest statement of what the gap was:
the model was right and nobody could write to it.

## 3. The design

Four actions, four request objects, two controllers, eleven routes. Every write
is an **upsert on a stable key**, and every delete is a **deactivation**.

### 3.1 Recording something twice is a correction

Keyed on the product or plan slug, and on `(plan, currency, period)` for a
price. A second call edits the row and answers `200`; the first answers `201`.

An endpoint that answered `409` would leave "fix the typo" with no route
through the API at all — which is the shape of hole this whole gap was, one
level down. The same reasoning `RecordVmTemplate` records.

Each upsert locks the row rather than reading it, because two operators
correcting the same thing in the same minute would otherwise both find it
absent and one would get a constraint violation for doing the thing the
endpoint is for.

### 3.2 Withdrawal, never deletion

`is_active = false`. Orders, subscriptions and hosting accounts point at these
rows; the order line keeps its own snapshot so the money survives regardless,
but the link is how an operator gets from an invoice to the thing that was
sold. `scopePurchasable()` already filters on that column, so one call removes
it from the customer surface.

Recording the same slug again re-lists it. An operator who withdrew the wrong
plan needs a route back that is not a support ticket.

Withdrawing a plan stops future sales and does nothing else. It does not
suspend, terminate or reprice a service somebody is already using — those are
lifecycle decisions with their own actions, their own audit entries and their
own notifications, and coupling them to a catalogue edit would mean an operator
tidying a price list could end a customer's server.

### 3.3 Product rows are operator-created, within a fixed universe

`ProductKind` has three cases — `vps`, `dedicated`, `shared_hosting` — and an
operator may not add a fourth. Several products may share a kind, because the
kind says how a thing is delivered and the product says what is being sold; the
slug is what is unique.

This is also the whole of the prepared-product safety, and it is structural
rather than a check somebody remembered to write. See §9.

## 4. The production write paths

| Route | Permission | Answers |
| --- | --- | --- |
| `GET /api/admin/catalogue/products` | `catalog.manage` | operator view: withdrawn and unlisted included |
| `POST /api/admin/catalogue/products` | `catalog.manage` | 201 new, 200 correction |
| `DELETE /api/admin/catalogue/products/{product}` | `catalog.manage` | deactivates |
| `GET /api/admin/catalogue/plans` | `catalog.manage` | with prices; `meta.unpriced` |
| `POST /api/admin/catalogue/plans` | `catalog.manage` | 201 / 200 |
| `DELETE /api/admin/catalogue/plans/{plan}` | `catalog.manage` | deactivates |
| `POST /api/admin/catalogue/plans/{plan}/prices` | `pricing.manage` | 201 / 200 |
| `DELETE /api/admin/catalogue/plans/{plan}/prices/{price}` | `pricing.manage` | deactivates |
| `GET /api/admin/catalogue/hosting-packages` | `catalog.manage` | `meta.orderable` |
| `POST /api/admin/catalogue/hosting-packages` | `catalog.manage` | 201 / 200 |
| `DELETE /api/admin/catalogue/hosting-packages/{package}` | `catalog.manage` | deactivates |

No new permission was added: the vocabulary already had all three.

## 5. RBAC — and the permission that looked right and was not

**`catalog.view` guards none of this, deliberately.**

It is the permission a plain customer holds. `Role::Customer` carries it and
nothing else — the baseline every customer login gets — and
`tests/Feature/Rbac/AuthorizationTest` treats every *other* permission as
staff-only by explicitly excluding this one. `ReportedPermissionsMatchEffectiveAuthorityTest`
asserts a customer reports exactly `['catalog.view']`.

It is also the obvious-looking guard for a listing, and before this change no
route used it at all. Guarding the operator reads with it would have handed
every customer the operator catalogue: withdrawn products, unlisted plans, both
languages, and every price whether active or not. A read of the operator
surface is an operator act, so the reads are `catalog.manage`.

Pricing is separate because describing a thing and deciding what it costs are
different acts, and a role may legitimately do one and not the other.

## 6. Audit

Eight new `AuditAction` cases, on the list that is otherwise about money moving,
because a price is the money before it moves:

```
catalogue.product.recorded          catalogue.product.withdrawn
catalogue.plan.recorded             catalogue.plan.withdrawn
catalogue.price.set                 catalogue.price.withdrawn
catalogue.hosting_package.mapped    catalogue.hosting_package.withdrawn
```

Every mutation goes through `RecordActAtomically`, so the write and its audit
entry are one transaction. A price entry carries both amounts in minor units
with the currency beside them: a price change is the catalogue event an
operator is most often asked to account for afterwards, and an entry saying
only "a price changed" would answer none of the questions that get asked. A
price is not a secret. No credential, token or secret value is reachable from
any of these paths.

## 7. Money

| Rule | Where it is enforced |
| --- | --- |
| Whole minor units, integers | `integer` validation refuses `9.5` and `"9.000"` rather than casting them; the column is `bigInteger` |
| No floats anywhere | no arithmetic on money exists in this path; the portal's own gate forbids it in the browser |
| Currency must be one the platform bills in | `BillingCurrencies::assertEnabled()`, the same list registration uses |
| No negative amounts | refused in the action, with the reason |
| An availability window that never opens | refused |
| One price per plan, currency, period | the database's own unique index |

The Admin form asks for minor units and sends minor units. A decimal box would
need a conversion, and a conversion in a browser is a rounding decision taken in
the one place the money cannot be tested.

## 8. History

**Changing a price does nothing to anything already sold, and cannot.**

`order_items` snapshots `unit_recurring_minor`, `unit_setup_minor`, the name,
the billing period and the resources at the moment of sale, and `plan_id` is
`nullOnDelete` so the line survives the catalogue outright. The schema comment
already said why — "re-deriving this from the catalogue later would silently
rewrite history the first time a price changes" — and this phase is the first
thing that makes it testable rather than theoretical, because before it nothing
could change a price at all.

`ChangingAPriceDoesNotRewriteWhatWasAlreadySoldTest` places a real order at
9000, raises the price to 12000 through the API, and asserts the order total
and the line's unit amount are unchanged.

## 9. Prepared-product safety

**WordPress and Domains cannot be given a catalogue product, and the reason is
the type rather than a check.**

`ProductKind` has no case for them. There is no request body that creates a
WordPress product; the validation refuses the kind and the action refuses it
again with the reason. `ConfiguringACatalogueDoesNotMakeAnythingSellableTest`
asserts this for all seven non-Complete products, and separately asserts that
each of the three kinds that *do* exist names a readiness product whose
software state is `Complete` — so a kind added later for something Prepared
fails a test rather than shipping.

And for the three that exist, configuration still sells nothing. The same test
builds a complete VPS offering — product, plan, price — switches the
application to production, and asserts `ProductSellability::maySell(Vps)` is
**false**. Readiness decides that. It reads the software state and the
readiness row, and it has never read a catalogue row.

```
WordPress sellable: NO
Domains sellable:   NO
```

## 10. The hosting package mapping

Recorded, never verified. `panel_package_name` is what this platform will ask
cPanel or DirectAdmin for; nothing here contacts a panel and nothing should,
because 30B.0-E is blocked and `REAL_HOSTING_VERIFIED` is NONE. A mapping is
`CONFIGURED`. The resource deliberately has no `verified` field — a
`verified: false` invites a screen to render "not verified yet", as though
verification were pending. It is not pending; it is a phase that has not
started.

Only a shared hosting plan may be mapped. A package behind a VPS plan would sit
there looking configured while the build went to a hypervisor the panel never
sees.

## 11. The Admin screen

`/admin/control-center/catalogue`, in English and Arabic. It shows the two
states a list cannot otherwise show: a listed plan with no active price
(purchasable by nobody, and identical to a working plan in a list that shows
only the two switches), and a hosting package mapped to no plan.

The plan form's fields follow the product's kind rather than offering a generic
JSON box — a VPS plan is asked for its compute triple, a dedicated plan for its
hardware profile — because a generic box is how a plan gets saved without the
values a build silently defaults.

## 12. What this does not close

- **E-1 through E-10 are untouched.** There is still no trusted runner, no
  management route, no private inventory and no real credential. 30B.0-E is
  still NOT READY.
- **Addons have no production writer either.** `Addon` and `AddonPrice` are
  written by nothing at all — not even a seeder. Backups is charged as an addon
  kind. This is the same defect class and it is *not* fixed here, because E-11
  named four entities and widening the patch to a fifth mid-flight is how a
  closure stops being reviewable. It is recorded here so it is not rediscovered
  as a surprise.
- **Nothing real was validated.** Every `REAL_*` status remains NONE.
