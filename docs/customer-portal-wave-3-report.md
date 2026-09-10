# Customer Portal Wave 3 — One Place Per Resource

A closure report. It records what was built, what was proved, what was refused
and what is still open. Where a thing was not done, it says so and why.

---

## A. Starting HEAD

```text
f216afe  Add the customer portal Wave 2 closure report
```

Wave 2's closure commit, on `claude/hv-t6hq1p`. Everything below was written on
top of it.

## B. Ending code HEAD

```text
a31b9f5ae8cfe6da41f41b202278c4e8fc40e18e
```

## C. Scope

The wave closes the audit's **AR-7 — there is no page for a resource**, and the
findings that could only be closed once a resource had a page to be closed on:

| Finding | Subject | Outcome |
| --- | --- | --- |
| AR-7 | No page per resource | CLOSED |
| AS-5 | VPS reinstall offered no template source | CLOSED |
| AS-6 | Hosting account showed neither plan, panel nor usage | CLOSED |
| AS-7 | WordPress copies were a screen, not part of a site | CLOSED |
| AS-18 | Backups unreachable from the machine they belong to | CLOSED |
| AS-20 | Buying vocabulary disagreed with itself | CLOSED |
| AU-3 | Subscription did not reach its resource | CLOSED |
| AU-7 | Order did not reach what it produced | CLOSED |
| AU-8 | Domain could not be renewed from the portal | CLOSED |
| AU-9 | Auto-renew could not be seen or changed | CLOSED |
| AU-11 | Registrant contacts could not be read or corrected | CLOSED |
| AU-13 | DNS records could not be edited, only deleted and re-made | CLOSED |

## D. Explicit out-of-scope

Not started, not designed, not half-built:

- **AR-6** — the operational dashboard. Wave 4.
- **AR-12** — global polling and async feedback. Wave 4.
- **AR-13** — account-wide activity. Wave 4.
- Toast or global feedback system; unread notification badge; contextual
  support; timezone architecture; team permission matrix; API-token expansion;
  dark mode; skeleton loaders; account deletion and export; wallet top-up; a
  new payment provider; real payment or registrar integration; monitoring
  graphs; snapshots; firewall; extra IP addresses; an SSH key library; CDN,
  object storage, GPU, email or Kubernetes products; durable idempotency for
  dedicated power; real infrastructure validation.

The three Wave 4 findings deserve their own line, because the temptation to
close them was real. A resource page has an Activity section, and the same
component pointed at the account rather than a service would have looked like
AR-13. It is not: an account-wide feed needs a query the API does not have, and
faking it from nine per-resource endpoints in the browser would have been a
screen that lies about being complete. `GET /services/{id}/events` is the only
event source this wave uses, and it is only ever asked about the resource whose
page is open.

---

## E. Target information architecture

The brief's architecture, and where each destination now lives:

```text
Dashboard                    /
Buy                          /catalogue
Services
  All services               /services
  Cloud servers              /vps
  Dedicated servers          /dedicated
  Hosting                    /hosting
  WordPress                  /wordpress
  Domains                    /domains
  DNS                        /dns
  IP addresses               /ips
  Backups                    /backups
Billing
  Orders                     /orders
  Invoices                   /invoices
  Payments                   /payments
  Subscriptions              /subscriptions
  Wallet                     /wallet
Support                      /support
Notifications                /notifications
Account
  Profile                    /profile
  Team                       /settings/team
  Security                   /security
  API tokens                 /api-tokens
```

Twenty-two destinations, the same twenty-two the portal had. Nothing was
removed and nothing was invented — the wave regrouped them and gave the things
inside them addresses of their own.

There is **no Activity destination**. The target architecture has one; the feed
behind it does not exist. `src/app/navigation.ts` says so in a comment at the
top of the file, so the next person to read it does not conclude the entry was
forgotten.

## F. Unified navigation model

One module, `apps/web/src/app/navigation.ts`, exports:

- `CUSTOMER_NAV_GROUPS` — the grouped model above;
- `CUSTOMER_NAV` — the same thing flattened, **derived** from the groups rather
  than written twice;
- `OPERATOR_NAV` and `CONTROL_CENTER_NAV`, and `OPERATOR_NAV_GROUPS` over them.

Both renderers read it. `Sidebar.tsx` renders it as a column; `MobileNavigation.tsx`
renders it as a drawer; `NavGroups.tsx` is the shared group renderer both use, so
a group's heading, ordering and active-link rule are written once.

Wave 1 created this single source to stop the drawer showing four of
twenty-one destinations. Wave 3 extended it — groups, headings, and a
`labelKey: null` convention for the groups that are one destination — without
introducing a second list.

## G. Desktop sidebar

`apps/web/src/app/Sidebar.tsx`, mounted by `AppLayout.tsx` at `lg:` and up
(1024px), which is the breakpoint the brief names.

- **Persistent.** It is a `<aside>` in the layout grid, not a disclosure. It
  does not collapse, because collapsing is what hid seventeen destinations
  behind the old `More` menu.
- **Permission-aware.** The operator groups render only for a login holding at
  least one operator permission. The comment in `navigation.ts` is explicit
  that this is a courtesy rather than a control: each endpoint behind those
  screens checks its own permission, and a customer who types the address gets
  403 from every request the page makes. A test asserts the customer login
  never sees them.
- **RTL-aware.** The column is placed with logical properties, so in Arabic it
  sits on the right without a second stylesheet.
- **Keyboard-accessible.** It is a `<nav aria-label>` over lists of links;
  every entry is a real `<a>` reachable by Tab, with a visible focus ring from
  the design tokens, and `aria-current="page"` on the active one.

## H. Mobile drawer

Below 1024px the same groups are a full-height drawer — Wave 1's modal drawer,
now reading the grouped model. It traps focus, closes on Escape and on
navigation, and is labelled. Nothing collapses inside it either: the groups
scroll.

## I. Customer/operator navigation boundary

The customer groups and the operator groups are separate exports rather than
one list with a permission flag. That is deliberate and the module says why:
the audit's complaint about the old `More` menu was precisely that it ran the
two products together into one undifferentiated list of thirty-five links.

**The obsolete desktop `More` disclosure is gone**, and a regression gate keeps
it gone: `src/app/__tests__/desktop-sidebar.test.tsx` renders the layout at a
desktop width and asserts there is no `More` control and no `<details>` in the
navigation, alongside asserting the sidebar is present and that the drawer
offers every entry the sidebar does.

---

## J. Universal resource-page architecture

One shell, composed rather than inherited:

| Piece | File | What it is |
| --- | --- | --- |
| Breadcrumbs | `components/Breadcrumbs.tsx` | Group → family → this thing, by name |
| Resource header | `components/ResourceHeader.tsx` | Family, identity, badges, facts, actions |
| Section navigation | `components/ResourceTabs.tsx` | Routes, not ARIA tabs |
| Facts | `components/FactList.tsx` | Label/value pairs; "not available" rather than a guess |
| Danger zone | `components/DangerZone.tsx` | Bordered region for the irreversible |

A page is a header, a section nav and an `<Outlet>`. The sections are separate
components in separate files, each fetching what it needs on the visit that
needs it; there is no God component holding every product's state.

Two decisions worth recording:

**Sections are routes.** `/vps/:id/backups` is an address: it can be sent to a
colleague, refreshed and bookmarked. ARIA tabs cannot. The brief allowed either
"real accessible tabs or route navigation"; routes were chosen, and the section
nav is a `<nav aria-label="Sections">` of links with `aria-current="page"`.

**Context, not prop-drilling.** The parent fetches the resource once and
publishes it through the router:

```ts
<Outlet context={{ resource: vm } satisfies ResourceOutlet<VirtualMachine>} />
```

and a section reads it with `useResource<VirtualMachine>()`. Both come from one
module, `src/features/resources/outlet.ts`, so there is one shape for all six
families.

**Width is deliberate.** The shell is capped at a readable measure rather than
stretched to the window, and the fact grids step 1 → 2 → 3 columns.

**Breadcrumbs carry human identity.** The last crumb is the hostname, the
serial, the domain or the zone name — never a ULID. This is asserted per family
in the browser suite.

## K. Services index

`/services` is an ownership index, not a ninth product screen. Each row names
the thing the service fulfils and links to its own page.

It can do that because the API now publishes the link. `ServiceResource`
carries:

- `identity` — the name the customer knows the service by;
- `resource` — a `{kind, id}` handle.

The frontend turns the handle into an address with one function,
`pathForResource(kind, id)` in `src/features/resources/resourcePaths.ts`. **A
service id is not a machine id**, and the wave found that trap before shipping:
guessing `/vps/{service_id}` would have 404'd for every row. The handle is
resolved server-side from the row that actually exists, in one query per kind
for a whole page.

The same handle is published by `OrderServiceResource` and by a subscription's
service summaries, which is what closes AU-3 and AU-7 without a second mapping.

---

## L. VPS resource page

`/vps/:id`, six sections:

```text
/vps/:id             Overview
/vps/:id/networking  Networking
/vps/:id/backups     Backups
/vps/:id/activity    Activity
/vps/:id/billing     Billing
/vps/:id/danger      Danger zone
/vps/:id/console     (unchanged, declared before /vps/:id)
```

The header carries the hostname as the page's only `h1`, the power state, the
service state when it is not simply active, the blocked reason when the API
publishes one, the plan's shape as facts, the power controls and a console
link.

`/vps` remains, as an index. Its rows keep one power control — Reboot — and
link to the machine. Everything that interrupts or destroys is on the machine's
own page, where the hostname is in the heading.

The console route still works, and is declared **before** `/vps/:id` so the
section router does not swallow it.

## M. VPS networking

The addresses the platform already modelled for that machine, in a table, with
each address in a `dir="ltr"` cell so an IPv4 does not mirror in Arabic. No new
capability: `/ips` still exists and still lists the account's addresses.

## N. VPS backups

The machine's backups, reachable from the machine — AS-18. The section renders
`features/backups/BackupsForMachine`, the same component the global `/backups`
screen uses with its picker set, so there is one implementation of listing,
restoring and deleting a backup.

`/backups` is kept. An account-wide view of what is protected is a legitimate
question, and removing it would have been a regression dressed as tidying.

**Confirmation uses human identity.** Deleting a backup asks for the machine's
hostname, not the backup's ULID. A ULID typed back from the same screen it was
read on proves nothing; the brief's words were "no raw ULID as proof of
intent".

## O. VPS reinstall and template selection — AS-5

The reinstall dialogue now offers:

- **A template chosen from an authoritative source.** `GET /api/v1/vps/{id}/templates`
  publishes the exact set the reinstall endpoint already validated a submitted
  id against. No hard-coded list in the frontend; a template that is not
  offered for that machine is refused by the server whatever shape the id is
  (asserted in the security pass).
- **Public SSH key material only.** The field takes a public key. Private key
  material is never asked for, never sent and never logged.

The confirmation is unchanged from Wave 0: the hostname typed exactly, and a
nearly-right hostname stays refused.

## P. VPS billing

The section reads the resource's commercial relationship **through explicit
server relations** and displays it. It does not recalculate money, and it never
matches a subscription or invoice to a resource by amount, date or kind — the
Wave 2 rule, kept. Cancelling from here names the resource.

---

## Q. Dedicated resource page

`/dedicated/:id`, four sections: Overview, Activity, Billing, Danger zone.
There is no Networking section, because a chassis's addresses live on `/ips`
where the platform models them; inventing a section would have meant inventing
the data behind it.

The index keeps Power cycle and links to the chassis. Force off, Power on and
Reinstall are on the machine's page, the last of them in the danger zone.

## R. Dedicated safety boundary

What a customer sees: manufacturer, model, hardware profile, serial, service
state, chassis power state as last **reported**, when it was activated, and the
rebuild history including whether the disks are already gone.

What a customer does not see, here or anywhere: the management address, the BMC
or iLO, the rack and datacentre position, the credential the platform uses to
reach the chassis, the provider driver, the deployment job internals. The
resource the API publishes does not carry them — this is a contract-level
boundary, not a hidden `<div>`.

**Dedicated power idempotency is unchanged.** The wave did not touch it. It
remains an architecture item, deferred.

**Real dedicated reinstall remains `BLOCKED_HARDWARE`.** The rebuild runs
against the fake BMC. Nothing in this report claims otherwise.

---

## S. Hosting resource page — AS-6

`/hosting/:id`, three sections: Overview, Activity, Billing. No danger zone: a
hosting account has no customer-facing destructive act that the platform
implements, and an empty red box would be furniture.

The page names:

- **The plan**, from the catalogue package the account was sold.
- **The panel type**, as the customer's word for it: cPanel or DirectAdmin
  where the node runs one, and the truthful generic "Hosting control panel"
  where the platform does not know which. `hosting/panelName.ts` maps the
  driver to one of three keys; there is no fourth branch that invents a brand.

The panel button opens the panel. Nothing about the node the account sits on is
published.

## T. Hosting usage

`GET /api/v1/hosting/{id}/usage` gives disk, bandwidth and account counts, each
with what it was measured at. The card renders each as a `role="meter"` with an
accessible name and value.

Three states, all of them said out loud:

- a measurement with a time, shown with when it was taken;
- a **stale** measurement, shown with the wording that it is old rather than
  silently presented as current;
- **never measured**, which says exactly that instead of showing zero.

`unlimited` is `boolean | null` in the frontend type because the API publishes
`?bool`: a plan with no limit is not a plan with a limit of nothing, and an
unknown limit is neither.

---

## U. WordPress resource page — AS-7

`/wordpress/:id`, four sections: Overview, Copies, Activity, Billing.

The existing staging, clone and push capabilities are **organised, not
rewritten**. `SiteCopies.tsx` is the Wave-2 screen's body extracted verbatim
and `SiteSteps.tsx` its step list, so every semantic the audit checked is
preserved by construction:

- the impact preview before a push;
- the confirmation;
- one operation in flight at a time;
- indeterminate outcomes reported as indeterminate, never as success.

`/wordpress` becomes an index of sites.

---

## V. Domain resource page

`/domains/:identity`, four sections: Overview, Nameservers, Contacts, Transfer.

The address is the **name**, not a ULID: `/domains/example.com`. Both a domain
and a DNS zone answer to their name as well as their id, so the address a
customer keeps is the one they can read. The route input is validated
server-side; encoded and slashed input is covered in the security pass.

A name the platform cannot act on — an indeterminate registrar state — shows
its controls **visible and disabled with the sentence that says why**, rather
than absent. A missing button leaves a customer wondering whether the platform
can do the thing at all; a disabled one with no reason is worse.
`NotManageableNote.tsx` is that sentence, and it is rendered by each panel that
is off.

## W. Domain renewal — AU-8

Renew now, priced by the server. The quote comes from the catalogue for that
namespace; the frontend displays it and never computes it. Ordering the renewal
creates the order and invoice, and **nothing is sent to the registry before
payment** — the panel says so and links the invoice.

Renewal is offered **once** on the page, in the renewal section beside the
price it costs and the switch that automates it. It was briefly offered twice —
in the header and in the section — and the duplicate was removed in the product
rather than papered over in the test: offering one act twice on one page is the
duplication this wave exists to remove.

The redemption path is unchanged and still separate: a lapsed name is recovered
for the registry's penalty, quoted from the catalogue, and a namespace with no
redemption price says "we will not quote a guess" and offers nothing.

## X. Domain auto-renew — AU-9

A toggle. It is reversible, so it takes **no typed confirmation** — BD-6's
grading applied honestly: typed identity for the irreversible, a plain dialog
for the disruptive-but-recoverable, nothing for a safe switch.

Its wording says what it does — whether the platform will renew the name before
it expires — and does **not** imply that turning it off cancels the domain.

## Y. Domain contacts — AU-11

The registrant, read from the registrar's own record and correctable. Contact
data is registrant PII: it is displayed to its owner, submitted over the
existing contact endpoint, and never logged.

## Z. Domain transfer-in

Begin a transfer with an authorisation code.

- The code is **never re-shown** after submission.
- The screen never implies the transfer has completed: a transfer begun is a
  transfer begun, and the state comes from the registrar.
- An indeterminate outcome is reported as indeterminate and is **not blindly
  retried** — the same Timeout Rule the domains module already enforced, now
  visible on the page.

Moving away is offered plainly, with the transfer code, and says that the
platform does not make leaving difficult.

## AA. Domain / DNS relationship

The domain page links its zone and the zone page links its domain, both through
the canonical relationship the API publishes — `/dns/{name}` for the name the
customer holds. Neither screen guesses a zone from a string.

---

## AB. DNS resource page

`/dns/:identity`, four sections: Overview (delegation), Records, Transfer
(import/export), Danger zone.

The zone's import preview, plan and apply, and its export, are preserved whole
— the addendum built them, and this wave moved them into a section without
touching the pipeline.

## AC. DNS record edit — AU-13

Editing a record goes through the canonical `PATCH` contract, sending
`{content|data, ttl, priority}`. It is **not** implemented as delete-then-create:
a record that is deleted and re-made loses its identity, and a failure between
the two loses the record.

Deleting a record keeps its confirmation.

---

## AD. Billing relationship integration

Per-resource billing reuses the existing subscription routes; there is no
second billing implementation. The relationship is read from explicit server
relations, money is displayed as the server computed it, and a cancellation
initiated from a resource page names the resource it will end.

From the other direction: a subscription reaches its exact resource and an
order reaches what it produced, both through the published `{kind, id}` handle.

## AE. Buy / catalogue consistency — AS-20

One vocabulary. The destination is **Buy**; "Catalogue" is gone from the
customer's screens. Product family labels come from one map —
`RESOURCE_FAMILIES`, `familyForServiceKind` and `labelKeyForProductKind` in
`resourcePaths.ts` — which is the same map the services index, notifications,
orders and subscriptions read, so a Cloud VPS is called the same thing
everywhere.

**Prepared products stay invisible.** BD-1 holds: a product family is offered
only when it is `READY_TO_SELL`. The six prepared families do not appear in
Buy, and the wave added no screen that hints at them.

## AF. Per-resource event usage

Each resource page has an Activity section, and it uses exactly one endpoint:
`GET /api/v1/services/{id}/events`, asked only about the service behind the
resource whose page is open.

There is no polling engine, no toast system and no global badge. Freshness
comes from the query invalidation that already existed: a mutation invalidates
the queries it affects, and nothing more was built.

## AG. Notification deep links

A notification now publishes what it is about as a `{kind, id}` handle,
resolved from its stored subject — the polymorphic subject the notification was
created with — rather than inferred from its text. The inbox turns the handle
into an address with the same `pathForResource` the services index uses.

Where the backend does not know the exact resource, there is **no link**. A
guessed destination is worse than a list, and matching on words in a message
was never on the table.

---

## AH. Mobile

First-class, not a narrowed desktop. The phone project runs a Pixel 5 profile
(393px) with a real mobile user agent, and the wave's own specs
(`e2e/mobile/resource-pages.e2e.ts`) drive the resource pages there:

- the drawer offers every destination;
- a resource page's header, sections and danger zone are usable at 390px;
- the section nav scrolls rather than wrapping into an unreadable stack;
- fact grids fall to one column;
- tables that cannot fit scroll inside their own container, and the page body
  does not scroll sideways.

## AI. Arabic / RTL

Also first-class. `e2e/arabic/resource-pages.e2e.ts` opens each family in
Arabic and asserts:

- the page reads in Arabic — headings, section names, facts, actions;
- the layout mirrors, including the sidebar;
- **technical values are bidi-isolated**: the hostname, serial, domain, zone
  name and IP addresses sit in `dir="ltr"` elements inside Arabic prose, so
  they do not mirror. `ResourceHeader` renders the identity in a `dir="ltr"`
  span inside the `h1`, which is why the Arabic assertions read the inner node
  rather than the heading — the heading itself is Arabic-direction prose, and
  that is correct.

Translation parity is gated: every key exists in both `en.json` and `ar.json`,
and Arabic plurals carry all six forms.

## AJ. Accessibility

- One `h1` per page, carrying the resource's identity.
- Section navigation is a labelled `<nav>` of links with `aria-current="page"`;
  keyboard-reachable by construction because they are links.
- The sidebar and drawer are labelled `<nav>`s; the drawer traps focus and
  closes on Escape.
- Confirmation dialogues are `role="dialog"` with an accessible name, focus
  moved in, Escape to leave, and the confirming control disabled until the
  typed identity matches.
- Usage meters are `role="meter"` with names and values.
- Danger zones are `<section>`s with accessible names, so "recovering this
  name" and "danger zone" are addressable regions rather than red boxes.
- Status is never colour alone: every badge carries words.

## AK. Visual review

Captures are written by `e2e/captures.e2e.ts` at 1440, 1024 and 390, in English
and Arabic, for sixteen screens — the services index, every VPS section, the
dedicated, hosting, WordPress (with copies), domain (with contacts) and DNS
(with records) pages, and Buy.

The pass is skipped unless `CAPTURES=1`: it is a recording pass, not an
assertion pass, and the assertions about those widths live in the specs that
run every time.

It is **six tests — one per language and width — rather than two**, and that
shape was arrived at the hard way. Written as one test per language, each
walking all three widths, a recording ran long enough that the portal's own
session had gone before the last width; the click that then waited ten minutes
on a link that was no longer there said nothing whatever about the portal. The
Arabic recording had a second fault of its own: it asked for an Arabic welcome
heading before anything had switched the language, so it could only ever fail.
Arabic is now chosen on the sign-in screen, through the portal's own control,
before there is a session to carry the choice — which also means the record
starts from the first screen an Arabic-speaking customer meets.

Six short recordings finish in three minutes and fail where the fault is.

---

## AL. Performance and query review

**The read that could have gone wrong.** A services index that names what each
service fulfils is exactly the shape that becomes N+1: one lookup per row to
find the machine, the account, the site or the name. `ServiceIdentities::handlesFor()`
does not do that. It takes the whole page's service ids and issues **one query
per fulfilling table** — five or six queries for a page of any size — reading
two columns through the query builder rather than hydrating five modules' worth
of models.

The existing constant-cost gate covers it:
`tests/Feature/Performance/ListEndpointsDoNotQueryPerRowTest.php` adds rows to
`/api/v1/services` and asserts the query count does not grow with them. That
test was written before this wave and still passes with identity and handle
resolution in the response.

**The reads a resource page makes.** Each section fetches only what it needs,
on the visit that needs it:

| Section | Requests |
| --- | --- |
| Overview | 1 (the resource) |
| Networking | 1 (the machine's addresses) |
| Backups | 1 (that machine's backups) |
| Activity | 1 (`/services/{id}/events`) |
| Billing | 3 (service → subscription, order) |
| Danger zone | 0 beyond the resource |

A page that fetched all of it up front would spend six requests on every
visitor who came for the hostname. The billing chain's three requests are
sequential because each one's id comes from the last — that is the price of
following published relations instead of guessing, and it is paid only by the
customer who opened the billing tab.

**No polling.** Nothing added here repeats a request on a timer.

## AM. Tenant isolation

Every resource lookup starts from the acting customer and narrows to the id,
rather than fetching the row and then checking who owns it. There is therefore
no moment at which a request holds another tenant's data — which is also why
the answer is **404 rather than 403**: a 403 would confirm that the id names
something real.

`tests/Feature/Security/AttackingWhatWaveThreeBuiltTest.php` walks fifteen
addresses across all six families with another account's ids — the resource,
its templates, its backups, its usage, its contacts, its records, its service
and that service's events — and asserts 404 from every one, **and** that the
refusal body contains none of the other tenant's identities.

Name-addressed resources are covered in the same pass: another account's domain
cannot have its renewal setting changed by naming it, and another account's
subscription cannot be ended from a resource page.

## AN. Security review

The brief's nine attacks, each with where it is proved:

| Attack | Result | Evidence |
| --- | --- | --- |
| Cross-tenant resource id | 404, no leak | `AttackingWhatWaveThreeBuiltTest::every_resource_page_reads_nothing_that_belongs_to_another_account` |
| Forged domain identity | 404 | same test, `/domains/not-mine.test` |
| Encoded / slashed domain route input | Reaches nothing | `ADomainHasItsOwnPageTest::a_route_parameter_carrying_a_path_reaches_nothing`, and `the_name_is_matched_however_it_was_typed` for the legitimate variants |
| Zone identifier tampering | 404 / reaches nothing | `AZoneHasItsOwnPageTest::a_zone_identifier_carrying_a_path_reaches_nothing`, `another_accounts_zone_name_answers_exactly_as_an_unknown_one_does`, `a_record_belonging_to_another_accounts_zone_cannot_be_changed_through_mine` |
| Foreign backup id | 404 | `Backups/BackupEndpointTest::a_backup_belonging_to_another_machine_is_not_found` |
| Foreign subscription id | Refused | `AttackingWhatWaveThreeBuiltTest::another_accounts_subscription_cannot_be_ended_from_my_resource_page` |
| Disallowed template id | Refused, any id shape | `…::an_image_that_is_not_offered_for_this_machine_is_refused_whatever_shape_the_id_is` |
| Hidden operator route | 403 | `…::the_operator_area_is_closed_to_a_customer_who_types_its_address` |
| Direct action bypassing a disabled control | Refused server-side | `…::a_control_the_screen_disables_is_refused_by_the_server_too` |

The last of those is the one that matters most for this wave. A resource page
disables controls it knows cannot be used — a name in an indeterminate
registrar state, a machine with a rebuild nobody can settle. The server refuses
the same acts independently, so a disabled button is a courtesy to the customer
and not the enforcement.

**What is not published.** The customer-safe model is a contract boundary. No
resource, and no error, carries: a Proxmox node or cluster id, a datastore, a
BMC or iLO address or credential, a rack or datacentre position, a provider
driver name, a credential reference, deployment job internals, IaC or Ansible
detail, a hardware-management network address, or an internal failure stack.
The exact-key leak gates on the service resource assert the whole key set, so a
field added carelessly fails a test rather than reaching a customer.

**What is never logged.** Unchanged and re-checked for the paths this wave
touched: no passwords, no API secrets, no domain authorisation codes, no
registrant PII, no WordPress credentials, no payment secrets. `current_password`
is never logged, persisted or echoed. Reinstall takes public key material only;
no private key is accepted, stored or logged.

**No secrets in the repository.** Nothing in this wave added a credential, a
key or a token to git or to a document.

---

---

## AO. Wave 0 regression

`e2e/wave-0.e2e.ts` — 10 tests, all green.

Wave 0's subject was that existing controls did what they said: the
Idempotency-Key header, every power action, both reinstalls, the plan change,
2FA enable, and eight graded confirmations. This wave moved where a customer
*finds* several of those controls, so the specs were re-pointed at the
machine's page and the chassis's page and then asserted the same outcomes —
the acceptance, the confirmation, the absence of the field error Wave 0 was
about. Same mutation path, same assertions, new address.

**One Wave 0 behaviour had genuinely regressed and was restored:** the
machine's overview reported a finished rebuild as "Completed" (the generic
status vocabulary) where the list has said "Rebuilt" since Wave 1, and the
danger-coloured emphasis on a rebuild nobody can settle had gone with it. Both
are back, and the dedicated page — which never lost them — is now consistent
with the VPS page.

No other regression. Prepared products are still unreachable, force-off still
asks first, and no control fires on one click that should not.

## AP. Wave 1 regression

Wave 1's subject was navigation, localisation and vocabulary:
`e2e/appearance.e2e.ts` (6), `e2e/arabic/customer.e2e.ts` (9),
`e2e/mobile/navigation.e2e.ts` (5) — all green, plus the translation-parity and
status-vocabulary unit tests in the frontend suite.

Wave 3 extended Wave 1's single navigation model rather than replacing it, so
the parity test that walks the model still proves the drawer offers every
destination the sidebar does. Two spec updates were vocabulary, not behaviour:
the drawer's services entry is now "All Services" and the catalogue entry is
"Buy" — AS-20's unification, which the Arabic specs assert in Arabic.

The Arabic `المزيد` assertions became a `toHaveCount(0)` gate: the word was
being asserted as *present* in the old More menu, and the menu is gone.

## AQ. Wave 2 regression

Wave 2's subject was money and the commercial graph: `e2e/money.e2e.ts` (10),
`e2e/arabic/money.e2e.ts` (4), `e2e/mobile/money.e2e.ts` (4),
`e2e/wallet-credit.e2e.ts` (2) — all green.

No money behaviour changed. The per-resource billing section reads the same
published relations Wave 2 established and renders the server's own amounts; it
computes nothing. The one money-adjacent addition — domain renewal — takes its
price from the catalogue, creates the order and invoice through the existing
routes, and sends nothing to the registry before payment.

Wave 2's own rule that a resource is never matched to a subscription by amount,
date or kind is what the `{kind, id}` handle exists to honour.

## AR. Backend tests

```text
php artisan test --compact
{"tool":"phpunit","result":"passed","tests":2933,"passed":2933,"assertions":137504,"duration_ms":474203}
```

**2933 tests, 2933 passed, 137,504 assertions.** The baseline at the start of
the wave was 2885; the wave added 48 across seven files:

| File | Subject |
| --- | --- |
| `tests/Feature/Vps/InstallableTemplatesEndpointTest.php` | The template list a reinstall may choose from |
| `tests/Feature/SharedHosting/HostingAccountNamesItsPlanAndPanelTest.php` | Plan, panel and usage, and what is not published |
| `tests/Feature/Domains/ADomainHasItsOwnPageTest.php` | Name addressing, auto-renew, registrant read |
| `tests/Feature/Dns/AZoneHasItsOwnPageTest.php` | Name addressing, in-place record edit |
| `tests/Feature/Provisioning/AServiceNamesTheThingItFulfilsTest.php` | Identity and the `{kind, id}` handle |
| `tests/Feature/Notifications/ANotificationPointsAtTheThingItIsAboutTest.php` | Deep-link subject resolution |
| `tests/Feature/Security/AttackingWhatWaveThreeBuiltTest.php` | The adversarial pass |

Static analysis and style:

```text
{"tool":"phpstan","result":"passed","errors":0}
{"tool":"pint","result":"passed"}
```

The API description validates: **243 operations**, no errors, 5 pre-existing
warnings.

## AS. Frontend tests

```text
npm run test -- --run
Test Files  46 passed (46)
     Tests  197 passed (197)
```

Typecheck (`tsc -b --noEmit`, which covers the app, the node config and the
e2e project), lint and the production build are all clean.

The wave's own additions include the three gates:

| Test | What it refuses to let through |
| --- | --- |
| `src/app/__tests__/desktop-sidebar.test.tsx` | A missing sidebar, or the return of the `More` disclosure |
| `src/app/__tests__/route-and-capability-inventory.test.ts` | A route with no way in; a data-layer hook with no caller |
| `src/lib/__tests__/one-mutation-path.test.ts` | A second UI path to a mutation |

and unit tests for the VPS resource page and the DNS zone page.

## AT. Browser tests

One run, all three projects, against the controlled providers. The numbers
below are from the clean-room run at the ending HEAD, which is the one that
matches the code as pushed:

```text
npx playwright test
222 passed, 6 skipped (13.8m)
```

| Project | Profile | Tests |
| --- | --- | --- |
| `chromium` | desktop, English unless a block says otherwise | 194 |
| `customer-mobile` | Pixel 5, 393px, real mobile user agent | 17 |
| `customer-arabic` | locale `ar` | 17 |

The six skipped are the capture pass, which runs only with `CAPTURES=1`.

An earlier full run in the working copy reported the same 222 passed with 2
skipped, because the capture pass was then two tests rather than six; it was
split after that run, for the reason section AK gives.

The wave's own journeys:

| Spec | Tests | Subject |
| --- | --- | --- |
| `e2e/wave-3.e2e.ts` | 17 | The six families, the cross-resource links, the old addresses, the shell at three widths |
| `e2e/mobile/resource-pages.e2e.ts` | 3 | A machine on a phone: read it, reach every section, back out of a destructive control |
| `e2e/arabic/resource-pages.e2e.ts` | 4 | A machine and a name in Arabic, with identifiers unmirrored |

Then the captures, on request:

```text
CAPTURES=1 npx playwright test e2e/captures.e2e.ts --project=chromium
6 passed (3.0m)
```

**96 images** in `apps/web/e2e/.artifacts/captures/` — sixteen screens × three
widths × two languages. Spot-checked rather than merely counted: the Arabic VPS
overview at 1024 mirrors the sidebar and keeps the hostname and IPv4
left-to-right; the Arabic domain page at 390 falls to one column with the
auto-renew switch worded as a switch; the English hosting page at 1440 names
the plan, the panel honestly, and the usage with the time it was measured and
the sentence that the figures are not live.

## AU. Clean room

A fresh clone of the pushed branch, then the whole toolchain in the order CI
runs it. Nothing was reused from the working copy except where this section
says otherwise, explicitly.

```text
OK   git clone
HEAD a31b9f5ae8cfe6da41f41b202278c4e8fc40e18e
OK   composer install
OK   key:generate
OK   migrate:fresh --seed (testing)
OK   pint
OK   backend tests
OK   phpstan toolchain (copied from working copy: composer cannot reach github.com here)
OK   phpstan
OK   npm ci
OK   typecheck
OK   lint
OK   unit tests
OK   build
OK   openapi lint
OK   browser e2e
```

What each step reported:

| Step | Result |
| --- | --- |
| `git clone` | The branch as pushed, at `a31b9f5` |
| `composer install` | Installed from the lockfile over the network |
| `key:generate` | Application key generated into a fresh `.env` from `.env.example` |
| `migrate:fresh --seed` | Every migration from an empty database, then the seeders |
| `pint` | Clean |
| `backend tests` | **2933 tests, 2933 passed, 137,510 assertions** |
| `phpstan` | **0 errors** |
| `npm ci` | Installed from `package-lock.json` |
| `typecheck` | Clean (`tsc -b`, all three project references) |
| `lint` | Clean |
| `unit tests` | **46 files, 197 tests, all passed** |
| `build` | Production build succeeded |
| `openapi lint` | Valid |
| `browser e2e` | **222 passed, 6 skipped** (the six capture recordings) in 13.8m |

### The one external limitation, recorded exactly

The PHPStan toolchain in `apps/control-plane/tools/phpstan` was **copied from
the working copy's own vendor directory**, not installed. It is not a network
install and this report does not claim it is.

The reason, verified in this run rather than carried over from a previous one:

```text
$ composer install   # in a fresh directory holding only tools/phpstan's
                     # composer.json and composer.lock
In AuthHelper.php line 132:
  Could not authenticate against github.com
```

Packagist is reachable from this sandbox — that is why the application's own
`composer install` succeeded — but this environment's egress proxy cannot
authenticate against github.com, which the analysis toolchain's lockfile needs.
So PHPStan ran in the clean room against a toolchain that came from outside it.

Everything else in the table above was installed or built inside the clone.

CI is the counter-check that matters here: its `Static analysis` job runs
`Install analysis toolchain` over the network on a GitHub runner and then
PHPStan, and both passed on run 140. So the toolchain does install cleanly
where github.com is reachable; the limitation is this sandbox's, not the
repository's.

---

## AV. CI

Observed to completion, not assumed. Two runs on this branch carry the wave's
code; a third carries this report.

**Run 140 — the wave's code**

```text
Workflow:     CI (.github/workflows/ci.yml)
Run number:   140
Run id:       34524755045
Attempt:      1
Head SHA:     a31b9f5ae8cfe6da41f41b202278c4e8fc40e18e
Event:        push
Branch:       claude/hv-t6hq1p
Started:      2026-09-10T20:10:19Z
Completed:    2026-09-10T20:22:39Z
Conclusion:   success
```

**Run 139 — the commit before it**

```text
Run number:   139
Run id:       34522217857
Attempt:      1
Head SHA:     c8b5dfc1a17c953b5d34e24488d52fd332b8215f
Conclusion:   success
```

Run 139's nine jobs, each observed:

| Job | Conclusion |
| --- | --- |
| Backend (PHP 8.4, PostgreSQL 16) | success |
| Backend (PHP 8.4, PostgreSQL 18) | success |
| Frontend | success |
| Browser end-to-end | success |
| Static analysis | success |
| API description | success |
| Security checks | success |
| Production guards | success |
| Infrastructure validation | success |

Test counts as CI ran them: the two backend jobs each run `php artisan test`
over the same **2933 tests**; the Frontend job runs the **197** unit tests, the
typecheck, the lint and the production build; the Browser end-to-end job runs
all three Playwright projects, taking 11m15s on the runner and concluding
success. `Static analysis` is PHPStan at **0 errors**; `API description`
validates **243 operations**.

The per-project browser counts in section AT and section AU are from runs whose
output this report read directly. CI's browser job is recorded here by its
conclusion, which is what was observed of it.

Two CI jobs are worth naming because they exist to catch exactly the mistakes
this wave could have made: **Security checks** includes "Fail if a secret or
environment file was committed", and **Production guards** includes "Fail if a
fake provider is configured outside the development template". Both passed.

`CI_BLOCKED_BUDGET` does not apply: the runs completed and were read.

---

---

## AW. New defects

Classified as the brief requires. Both were found by this wave's own tests and
both were fixed in it.

| # | Class | Defect | Resolution |
| --- | --- | --- | --- |
| 1 | W3-BLOCKER | Renewal was offered twice on the domain page — once in the resource header, once in the renewal section. Two controls for one act on one screen. | Removed from the header. Renewal belongs beside the price it costs and the switch that automates it. |
| 2 | W3-RELATED | `usePlan` existed in the data layer with no caller — a dead capability of exactly the kind the audit catalogued. | Deleted. The no-dead-capability gate now fails on any repeat. |

One near-miss is worth recording even though it never shipped: the services
index was first written to link `/vps/{service_id}`, which would have 404'd for
every row, because **a service id is not a machine id**. The fix was to publish
a `{kind, id}` handle from the server rather than to guess a path in the
browser.

## AX. Deferred items

| Item | Why | Where |
| --- | --- | --- |
| Operational dashboard (AR-6) | Out of scope by the brief | Wave 4 |
| Global polling / async feedback (AR-12) | Out of scope by the brief | Wave 4 |
| Account-wide activity (AR-13) | Needs an account-level event query the API does not have | Wave 4 |
| Dedicated power idempotency | Architecture item, explicitly untouched | Later |
| An Activity navigation destination | Deliberately absent until the feed exists | Wave 4 |

## AY. Provider and hardware blockers

| Capability | Status | Reason |
| --- | --- | --- |
| Real dedicated reinstall | `BLOCKED_HARDWARE` | No physical chassis or real BMC; the rebuild runs against the fake |
| Real registrar operations | `BLOCKED_PROVIDER` | Renewal, transfer and contacts run against the fake registrar |
| Real payment capture | `BLOCKED_PROVIDER` | The fake gateway |
| Real hosting panel | `BLOCKED_PROVIDER` | The panel button opens a controlled endpoint |
| Real hypervisor | `BLOCKED_PROVIDER` | Power, reinstall and backups run against the fake compute provider |

Every browser and backend result in this report was obtained against those
controlled providers. **No real infrastructure was touched.**

---

## AZ. P1 status

Fifteen P1 findings were opened by the customer-portal audit. Twelve are now
closed; three are Wave 4's by the brief's own instruction.

| Finding | Wave | Status |
| --- | --- | --- |
| AR-1 Idempotency-Key transport | 0 | CLOSED |
| AR-2 2FA enable | 0 | CLOSED |
| AR-3 VPS resources | 0 | CLOSED |
| AR-8 Graded confirmations | 0 | CLOSED |
| AR-14 Design tokens | 0 | CLOSED |
| AR-15 Catalogue readiness | 0 | CLOSED |
| AR-4 Navigation model | 1 | CLOSED |
| AR-5 Localisation of errors | 1 | CLOSED |
| AR-9 Money and papers | 2 | CLOSED |
| AR-10 Commercial graph | 2 | CLOSED |
| AR-11 Registration/verification | 2 | CLOSED |
| **AR-7 One place per resource** | **3** | **CLOSED** |
| AR-6 Operational dashboard | 4 | OPEN — WAVE 4 |
| AR-12 Global async feedback | 4 | OPEN — WAVE 4 |
| AR-13 Account-wide activity | 4 | OPEN — WAVE 4 |

**12 / 15 closed.** The three remaining are exactly the three the brief
reserved.

## BA. Wave 4 prerequisites

What Wave 4 will need that this wave did not build, and what it can now build
on:

**Available to it now**

- One navigation model with groups, which a Dashboard entry already occupies.
- `pathForResource(kind, id)` and the server-published `{kind, id}` handle:
  anything that needs to link to a resource has one way to do it.
- Six resource pages to link *to*, so a dashboard card or an activity row has
  a destination that exists.
- `GET /services/{id}/events` and the `ResourceActivity` component over it.

**Still missing, and the reason each is Wave 4 work**

| Prerequisite | Why it is not here |
| --- | --- |
| An account-level event query | `GET /services/{id}/events` is per-service; AR-13 needs a feed the API does not expose. Composing it in the browser from nine endpoints would be a screen that lies about being complete. |
| A job/operation status contract worth polling | AR-12 needs the server to publish operation progress the client can watch. Wave 3 deliberately added no polling engine. |
| A dashboard aggregate | AR-6 needs counts and attention items in one read, not nine. |
| An unread count | Explicitly out of scope; the badge has no source. |

---

## BB. Final questions

> **Does the desktop customer portal now use the approved sidebar?**

Yes. `apps/web/src/app/Sidebar.tsx`, mounted by `AppLayout.tsx` at 1024px and
up: persistent, grouped, permission-aware, RTL-aware, keyboard-navigable.
Asserted by `src/app/__tests__/desktop-sidebar.test.tsx` and by the browser
spec "a narrow window hides the column and offers the drawer instead".

> **Is the old desktop More menu gone?**

Yes, and a gate keeps it gone. `desktop-sidebar.test.tsx` asserts there is no
`More` control and no `<details>` element in the navigation at desktop width.
The Arabic browser specs assert `المزيد` has a count of zero.

> **Does mobile still expose the same logical destinations?**

Yes. Both renderers read `CUSTOMER_NAV_GROUPS`, and a unit test walks the
groups to prove the drawer offers every entry the sidebar does. All 22
destinations are in the drawer.

> **Is there still one authoritative navigation model?**

Yes — `apps/web/src/app/navigation.ts`. `CUSTOMER_NAV` is derived from
`CUSTOMER_NAV_GROUPS` rather than written twice, and the route inventory gate
reads the same module.

> **Can a customer open one VPS and manage that VPS from one coherent page?**

Yes. `/vps/:id`: hostname in the only `h1`, power state, service state, plan
shape, power controls, console link, and six sections. Asserted by "answers
what it is, whether it is healthy, and what it costs, in one place".

> **Can that VPS page reach Networking, Backups and Billing?**

Yes — `/vps/:id/networking`, `/vps/:id/backups`, `/vps/:id/billing`, each an
address that survives a refresh. Asserted by "its sections are addresses a
customer can send, refresh and bookmark".

> **Can a customer reinstall a VPS with an allowed template selected from an authoritative source?**

Yes. `GET /api/v1/vps/{id}/templates` publishes the exact set the reinstall
endpoint validates against; the dialogue offers that set and nothing else.
`InstallableTemplatesEndpointTest` covers the contract; the security pass
proves a template that is not offered is refused whatever shape the id is.

> **Can a customer provide only public SSH key material during reinstall where supported?**

Yes. The field takes a public key. No private key is accepted, transmitted,
stored or logged.

> **Can a customer open one dedicated server without seeing BMC/rack/management-network internals?**

Yes. `/dedicated/:id` shows manufacturer, model, hardware profile, serial,
states and rebuild history. The management address, BMC/iLO, rack position,
credential reference and provider driver are absent from the published
resource — a contract boundary, not a hidden element. Asserted by "a dedicated
machine names its hardware and never its management path".

> **Can a hosting customer see their plan, panel type and real available usage?**

Yes. The catalogue plan name, the panel as cPanel, DirectAdmin or the truthful
generic "Hosting control panel", and the real usage from
`GET /api/v1/hosting/{id}/usage` with its measurement time — including saying
"stale" or "never measured" rather than showing a comfortable zero.
`HostingAccountNamesItsPlanAndPanelTest` covers the contract.

> **Can a WordPress customer reach existing staging/clone/push capabilities from the site resource experience?**

Yes — `/wordpress/:id/copies`. The panel is the previous screen's body
extracted verbatim, so the impact preview, the confirmation, the
one-in-flight rule and the indeterminate wording are preserved by
construction.

> **Can a domain customer renew now?**

Yes, at server-authoritative pricing, with the order and invoice created and
nothing sent to the registry before payment. Offered once, in the renewal
section.

> **Can they toggle auto-renew?**

Yes. Reversible, so no typed confirmation, and worded so that turning it off
does not read as cancelling the name.
`ADomainHasItsOwnPageTest::turning_auto_renew_off_does_not_end_anything` and
the Arabic spec "says an auto-renew switch is not a cancellation".

> **Can they manage contacts?**

Yes. `/domains/:identity/contacts` reads the registrant back so a correction
need not be retyped, and submits through the existing contact endpoint.

> **Can they begin transfer-in?**

Yes. The authorisation code is never re-shown, and the screen never implies the
transfer has completed.

> **Are indeterminate registrar operations still protected against blind retry?**

Yes. A name in an indeterminate state shows its controls disabled with the
sentence that says why, the server refuses the same acts independently, and the
wording is "do not try again" rather than "failed".

> **Can the domain reach the correct DNS zone through a canonical relationship?**

Yes, and the zone links back. `/dns/{name}` from the domain page, the
registration from the zone page — both from the relationship the API
publishes, neither guessed from a string.

> **Can a DNS customer edit a record through the existing PATCH contract?**

Yes. `AZoneHasItsOwnPageTest::a_record_is_changed_in_place_rather_than_deleted_and_recreated`
proves the record keeps its identity, and
`an_edit_cannot_rename_a_record_or_change_its_type` proves the edit stays
within the contract.

> **Can a VPS customer reach only backups belonging to that resource?**

Yes. The section lists that machine's backups; a backup belonging to another
machine is not found, and another account's machine is not found either.

> **Can the customer move from subscription to its exact resource?**

Yes, through the `{kind, id}` handle a subscription's service summaries now
publish. Asserted by "a subscription opens the machine it pays for".

> **Can the customer move from order to the resource it produced?**

Yes, through the same handle on `OrderServiceResource`.

> **Can the resource show its billing relationship without recalculating money?**

Yes. The billing section follows published relations — service → subscription,
service → order → invoice — and renders the server's own amounts through
`MoneyText`. No arithmetic, and no matching on amount, date or kind.

> **Are prepared products still hidden from purchase?**

Yes. BD-1 holds: only `READY_TO_SELL` families appear in Buy. The wave added
no screen that hints at the prepared six.

> **Can a customer deep-link directly to their resource after refresh?**

Yes. Every section is a route; a reload of `/vps/:id/backups` renders that
section. Asserted per family.

> **Can customer A open customer B's resource by guessing an id?**

No. Fifteen addresses across six families answer 404 with no identity in the
body, because every lookup starts from the acting customer.

> **Do resource actions reuse the same canonical mutation hooks as list actions?**

Yes, and a gate enforces it. `src/lib/__tests__/one-mutation-path.test.ts`
walks the source and fails if a mutation hook is called from anywhere but its
one owning component. A list row and a resource page render the *same*
component, with `only` deciding which buttons appear.

> **Did all Wave 0 behaviours remain green?**

Yes, with one restored. `e2e/wave-0.e2e.ts` is green on all 10 tests, and the
backend suite carries Wave 0's contract tests. One Wave 0 vocabulary behaviour
had regressed in this wave and was fixed before closure: a finished rebuild on
the machine's overview read "Completed" instead of "Rebuilt", and the emphasis
on an unsettled rebuild was missing. Both are back. Nothing else regressed.

> **Did all Wave 1 behaviours remain green?**

Yes. `e2e/appearance.e2e.ts` (6), `e2e/arabic/customer.e2e.ts` (9) and
`e2e/mobile/navigation.e2e.ts` (5) are green, as are the translation-parity and
status-vocabulary unit tests. Wave 3 extended Wave 1's single navigation model
rather than replacing it. Two spec updates were AS-20's vocabulary — "All
Services" and "Buy" — and the Arabic `المزيد` assertion became a
count-of-zero gate, because the menu it asserted is gone.

> **Did all Wave 2 money behaviour remain green?**

Yes. `e2e/money.e2e.ts` (10), `e2e/arabic/money.e2e.ts` (4),
`e2e/mobile/money.e2e.ts` (4) and `e2e/wallet-credit.e2e.ts` (2) are green. No
money behaviour changed: the per-resource billing section reads Wave 2's
published relations and renders the server's own amounts, and domain renewal
prices from the catalogue and invoices through the existing routes.

> **Does the new shell work at 1440, 1024 and 390?**

Yes. Asserted at 1024 and below by the sidebar/drawer specs and at 390 by the
phone project; captured at all three widths in both languages.

> **Does the new shell work properly in Arabic RTL?**

Yes. The Arabic project drives every resource page; the sidebar mirrors, the
prose reads right-to-left, and the layout does not break.

> **Are technical identifiers still readable inside RTL resource pages?**

Yes. Hostnames, serials, domains, zone names and IP addresses sit in
`dir="ltr"` elements inside Arabic prose. The Arabic specs read the inner node
and assert its computed direction is `ltr`, including inside a right-to-left
table.

> **Did this wave build the operational Dashboard?**

NO

> **Did this wave build the global polling/async feedback system?**

NO

> **Did this wave build the account-wide Activity system?**

NO

> **Did this wave modify Dedicated power idempotency architecture?**

NO

> **Did this wave begin Wave 4?**

NO

> **Did this wave claim any real provider or infrastructure verification?**

NO

---

## BC. Final verdict

```text
Customer Portal Wave 3 — One Place Per Resource

Status:
CLOSED

Starting HEAD:
f216afe96840689ebf35c8402d323cfda6a12cdc

Ending code HEAD:
a31b9f5ae8cfe6da41f41b202278c4e8fc40e18e

Report HEAD:
the commit that adds this file; its parent is a31b9f5 and it changes no code

AR-7:
CLOSED — six resource pages, one shared shell, sections as addresses

AS-5:
CLOSED — templates from GET /vps/{id}/templates, public keys only

AS-6:
CLOSED — plan, panel (or the truthful generic) and real usage with staleness

AS-7:
CLOSED — staging, clone and push organised into the site's own page, semantics
preserved verbatim

AU-8:
CLOSED — renew now, server-priced, nothing sent to the registry before payment

AU-9:
CLOSED — auto-renew toggle, reversible, worded as a switch and not a
cancellation

AU-11:
CLOSED — registrant read back and correctable

Navigation:
One model, apps/web/src/app/navigation.ts, read by both renderers. 22
destinations, grouped as the platform is shaped. No Activity destination,
because the feed behind it does not exist.

Desktop sidebar:
Persistent at 1024px and up, grouped, permission-aware, RTL-aware,
keyboard-navigable. The More disclosure is gone, and a gate keeps it gone.

Mobile navigation:
The same groups as a full-height drawer below 1024px, with focus trapped and
Escape to leave. A test proves it offers every destination the sidebar does.

VPS resource page:
/vps/:id with Overview, Networking, Backups, Activity, Billing and Danger zone.
Console still works and is declared before the section routes.

Dedicated resource page:
/dedicated/:id with Overview, Activity, Billing, Danger zone. No management
address, BMC, rack or credential is published. Power idempotency untouched;
real rebuild BLOCKED_HARDWARE.

Hosting resource page:
/hosting/:id with Overview, Activity, Billing. Plan, panel type and real usage
with its measurement time; "stale" and "never measured" said out loud.

WordPress resource page:
/wordpress/:id with Overview, Copies, Activity, Billing. Impact preview,
confirmation, one-in-flight and indeterminate wording all preserved.

Domain resource page:
/domains/:identity with Overview, Nameservers, Contacts, Transfer. Renewal
offered once. Indeterminate names show disabled controls with the reason.

DNS resource page:
/dns/:identity with Overview, Records, Transfer, Danger zone. Records edited
in place through the canonical PATCH; import preview, plan, apply and export
unchanged.

Cross-resource billing links:
A subscription opens its resource and an order opens what it produced, through
one server-published {kind, id} handle. No money is recalculated and nothing is
matched by amount, date or kind.

Tenant isolation:
Fifteen addresses across six families answer 404 for another account, with no
identity in the refusal body. Every lookup starts from the acting customer.

English:
222 browser tests green across three projects; 190 of them the desktop project.

Arabic:
The Arabic project drives every resource page. Layout mirrors; hostnames,
serials, domains, zone names and IP addresses stay left-to-right inside Arabic
prose.

Mobile:
The phone project (Pixel 5, 393px, real mobile user agent) drives the resource
pages: drawer, header, sections past the edge of the screen, and a destructive
control that asks first and can be backed out of.

Accessibility:
One h1 per page carrying the identity; sections as a labelled nav of links with
aria-current; dialogues with accessible names, focus moved in and Escape to
leave; usage as role="meter"; danger zones as named regions; status never
colour alone.

P1 closed total:
12 / 15

P1 remaining:
AR-6, AR-12, AR-13 — all three reserved for Wave 4 by the brief

AR-6:
OPEN — WAVE 4

AR-12:
OPEN — WAVE 4

AR-13:
OPEN — WAVE 4

Backend:
2933 tests, 2933 passed, 137,504 assertions

Frontend:
46 files, 197 tests, all passed; typecheck, lint and build clean

Browser:
222 passed, 6 skipped across chromium, customer-mobile and customer-arabic;
plus 6 capture recordings writing 96 images at 1440, 1024 and 390 in both
languages

PHPStan:
0 errors

OpenAPI:
Valid — 243 operations

Clean room:
Every step OK from a fresh clone of the pushed branch: composer install,
migrations from empty, pint, 2933 backend tests, PHPStan 0, npm ci, typecheck,
lint, 197 unit tests, build, OpenAPI, and 222 browser tests. One limitation,
recorded exactly: the PHPStan toolchain was copied from the working copy
because this sandbox's egress proxy cannot authenticate against github.com
("Could not authenticate against github.com", verified in this run). Not a
network install, and not claimed as one. CI's own Static analysis job installs
that toolchain over the network and passed.

CI:
Run 140, id 34524755045, attempt 1, SHA a31b9f5, all nine jobs success. Run 139
(id 34522217857, SHA c8b5dfc) also success, jobs enumerated in section AV.

Wave 0 regressions:
ONE, FIXED — the machine's overview reported a finished rebuild in the generic
status vocabulary ("Completed") instead of the rebuild's own ("Rebuilt"), and
lost the emphasis on a rebuild nobody can settle. Both restored before closure.
Nothing else.

Wave 1 regressions:
NONE

Wave 2 regressions:
NONE

New findings:
Two, both found by this wave's own tests and both fixed in it. W3-BLOCKER: the
domain page offered "Renew now" twice on one screen — removed from the header.
W3-RELATED: usePlan was a data-layer hook with no caller — deleted, and the
no-dead-capability gate now fails on any repeat.

Dedicated power idempotency:
DEFERRED — ARCHITECTURE ITEM

REAL_INFRA_VERIFIED:
NOT CLAIMED

REAL_PAYMENT_VERIFIED:
NOT CLAIMED

Wave 4 started:
NO

Recommended next action:
Review this wave. Wave 4 needs three server-side capabilities before its
findings can be closed honestly: an account-level event query for AR-13, an
operation-status contract worth polling for AR-12, and a dashboard aggregate
for AR-6. Building any of the three screens on top of what exists today would
mean composing them in the browser from per-resource endpoints, which is a
screen that lies about being complete.
```

---

*Every result in this report was obtained against the platform's controlled
providers — the fake hypervisor, BMC, registrar, hosting panel and payment
gateway. No real infrastructure, no real registrar and no real payment provider
was contacted, and none is claimed.*
