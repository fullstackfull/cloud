# Lynomia Cloud — Customer Portal Product & UX Audit

Status: **AUDIT_COMPLETE** · Application code changed: **NO** · Dates: 2026-09-09 to 2026-09-10

File references: paths under `apps/web/src/` are given from `features/`, `components/`, `lib/` or `i18n/locales/`; paths under `apps/control-plane/` are given from `src/Modules/` or `routes/`. Screenshot names refer to the audit capture set (`shots/<en|ar>_<route>_<width>.png`, `shots/j/`, `shots/j2/`), kept with the audit run, not committed.

### Contents

- A. Audit baseline
- B. HEAD and runtime environment
- C. Documents read
- D. Current customer information architecture
- E. Customer capability map
- F. Current navigation map
- G. New customer journey
- H. Dashboard
- I. Catalogue / purchase
- J. Orders
- K. Invoices
- L. Payments
- M. Wallet
- N. Subscriptions
- O. VPS
- P. Dedicated
- Q. Shared hosting
- R. WordPress
- S. Domains
- T. DNS
- U. Backups
- V. Profile
- W. Team
- X. Security
- Y. Notifications
- Z. Support
- AA. Long-running operations
- AB. Activity / timeline
- AC. Errors
- AD. Loading states
- AE. Empty states
- AF. Mobile
- AG. Arabic / RTL
- AH. Accessibility
- AI. Design system
- AJ. Status vocabulary
- AK. Cross-product consistency
- AL. Customer / operator boundary
- AM. Prepared products
- AN. Browser / E2E coverage
- AO. Page scorecard
- AP. Journey scorecard
- AQ. P0 findings
- AR. P1 findings
- AS. P2 findings
- AT. P3 / P4 findings
- AU. Missing customer flows
- AV. Existing flows needing UX work
- AW. Backend gaps exposed by the UX audit
- AX. Provider-blocked items
- AY. Recommended target IA
- AZ. Recommended target customer journey architecture
- BA. Implementation waves (proposed, not started)
- BB. Regression risks
- BC. Tests required during implementation
- BD. Questions requiring product-owner decision
- BE. Final verdict

## A. Audit baseline

| Item | Value |
| --- | --- |
| Repository | `fullstackfull/cloud` |
| Branch | `claude/hv-t6hq1p` |
| HEAD audited | `9050d69226b15f544da9d2cdcab35e02bf37a49c` — verified locally (`git rev-parse HEAD`) and on `origin/claude/hv-t6hq1p`; working tree clean; no newer work exists on the branch; nothing was reset |
| Prior closure | Phase 30B-P scope addendum CLOSED (`docs/phase-30b-p-scope-addendum.md`); CI run 129 on this HEAD green after the one Chromium-install retry (150 browser tests passed) |
| Scope of this pass | Discovery, mapping, browser walkthroughs, code inspection, comparison against professional cloud-provider expectations, gap classification, report. No application source, route, component, API, migration, translation or test was modified |
| Standard applied | "Can a real customer understand, purchase, operate, troubleshoot, pay for and safely manage their Lynomia Cloud services through one coherent, trustworthy, modern customer platform, without needing to understand the internal architecture behind it?" |
| Operator surfaces | The Control Center (`/admin/*`, `/noc`, `/billing-ops`) is out of scope except where a customer surface leaks operator detail (AL) |

## B. HEAD and runtime environment

| Layer | Facts |
| --- | --- |
| API | Laravel 13 on PHP 8.4, PostgreSQL, Redis; modular monolith under `apps/control-plane/src/Modules/*`. Run for the audit with `php artisan serve` on `127.0.0.1:8001`, `APP_ENV=local`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=log`, database `lynomia_e2e` seeded by `Database\Seeders\E2ESeeder` |
| Web | React 19, Vite, react-router 7, TanStack Query 5, i18next (en, ar), Tailwind v4, own design tokens in `apps/web/src/styles/index.css`. Run with `npm run dev` on `localhost:5174`, `VITE_API_URL=http://127.0.0.1:8001` |
| Providers | All fake / controlled: fake payment provider (returns `client_secret`), fake domain registrar, fake backup datastore absent for one cluster, no console gateway, WordPress toolkit disabled, hosting panel SSO refusing. No real provider, node, payment gateway or registrar was touched |
| Browser | Chromium (Playwright's bundled build at `/opt/pw-browsers/chromium`), scripted walkthroughs `walk.mjs` (188 captures, 7 widths, 2 languages) and `journeys.mjs` / `journeys2.mjs` (journeys A–H, 150+ captures) |
| Tests present at HEAD | vitest 91 tests / 20 files; Playwright 27 specs / 150 tests, one Desktop Chrome project at 1280×720, no mobile project, no Arabic project |
| Accounts used | `customer@lynomia.local` (owner, KWD/KW), `teammate@lynomia.local` (Technical), one account registered during the audit (`audit-new-<timestamp>@lynomia.local`; the verification link could not be extracted from the mail log, so the account was marked verified directly in the audit database to continue) |
| Data used | The seeded fixtures only: VPS `e2e-web-01`, `e2e-suspended-01`, `e2e-reactivating-01`; dedicated `E2E-SN-000117`; hosting `e2ehost`; WordPress `e2e-live-site.test`, `e2e-waiting-site.test`, `e2e-stuck-site.test`; domains `e2e-held.test`, `e2e-lapsed.test`, `e2e-lost.example`, `e2e-unsure.test`; invoices INV-E2E-0001/0002/0003; two subscriptions; wallet 12.750 KWD; one ticket; one pending invitation; one DNS zone (`audit-zone.test`) claimed and released during the audit |

## C. Documents read

Read in full before the walkthroughs:

- `docs/customer-capability-matrix.md` — customer capability status per product
- `docs/phase-30b-p-report.md` and `docs/phase-30b-p-scope-addendum.md` — the last closure and its verdicts
- `docs/infrastructure-readiness-matrix.md`, `docs/product-readiness-matrix.md` — READY_TO_SELL and provider status per product
- `docs/phase-30a-plus-plus-final-report.md` — platform-wide closure
- `docs/phase-30b-real-infra-validation.md`, `docs/first-node-verdict.md` — what has and has not been verified on real hardware
- `docs/architecture.md`, `docs/billing.md`, `docs/dns.md` — module boundaries, billing model (invoice/payment/wallet/subscription), DNS delegation rules
- `docs/openapi.yaml` — the customer API surface (used to build the capability map in E and the unused-endpoint list in AW)
- `apps/web/src/app/App.tsx`, `AppLayout.tsx`, every `pages/**` component, `lib/api.ts`, `lib/queries.ts`, `lib/useApiErrorMessage.ts`, `components/ui/*`, `i18n/en.json`, `i18n/ar.json`, `styles/index.css`
- `apps/control-plane/routes/api_v1.php` and `routes/v1/*.php`, every `Http/Resources/*Resource.php`, every `Http/Requests/*Request.php`, the `ReadsIdempotencyKey` trait, `TwoFactorController`, `AssertProductMaySell`, `VpsOperationGuard`, `FakePaymentProvider`, `FakeDomainRegistrarProvider`
- `apps/web/e2e/*.spec.ts` (27 specs) for the coverage map in AN

Where a document and the code disagreed, the code was treated as the truth
and the disagreement recorded (AW-13, AW-14).

## D. Current customer information architecture

Reconstructed from `apps/web/src/app/App.tsx` (the only router, lines 88–177)
and `apps/web/src/app/AppLayout.tsx` (the only navigation). This is what
exists, not what should.

```text
Topbar (no sidebar, no icons, no breadcrumbs, no tabs anywhere)
├── Dashboard                 /                 primary bar #1
├── Catalogue                 /catalogue        primary bar #2
│   └── Product               /catalogue/:slug  (card link only)
├── Services                  /services         primary bar #3  (read-only table)
├── Invoices                  /invoices         primary bar #4
└── "More" (native <details> dropdown, 17 items in this order)
    ├── Orders                /orders  → /orders/:id
    ├── Subscriptions         /subscriptions → /subscriptions/:id/plan
    ├── Wallet & Credits      /wallet
    ├── Cloud VPS             /vps → /vps/:id/console
    ├── Backups               /backups
    ├── Notifications         /notifications
    ├── Dedicated             /dedicated
    ├── Shared Hosting        /hosting
    ├── IP Addresses          /ips
    ├── DNS                   /dns
    ├── Domains               /domains
    ├── WordPress             /wordpress
    ├── API Keys              /api-tokens
    ├── Support               /support
    ├── Team                  /settings/team
    ├── Profile               /profile
    └── Security              /security
Right side: language switcher (hidden below `sm`), Sign out, hamburger (below `sm`)
Mobile drawer: the FOUR primary items and the language switcher only
Outside navigation: /register, /forgot-password, /reset-password, /invitations/:token, /login → /sign-in, 404
Operator items appear in the same "More" dropdown when the user is an operator
```

Judged against the reference structure in the brief:

| Reference group | What exists today | Verdict |
| --- | --- | --- |
| Home / Dashboard | `/` — two cards (billing accounts, account security) + country/currency section. No service counts, no invoices due, no renewals, no notifications, no quick actions | EXISTING_NEEDS_PRODUCT_LOGIC |
| Cloud (VPS, Dedicated) | two flat list pages under "More"; no per-resource detail page | wrong grouping, deep navigation |
| Hosting (Shared, WordPress) | two flat pages under "More" | wrong grouping |
| Network (Domains, DNS, Reverse DNS) | Domains, DNS and IP Addresses are three separate "More" items with no cross-links between them | missing cross-links |
| Storage (Backups) | one "More" item, machine picker inside | acceptable, but backups are not reachable from the machine they belong to |
| Billing (Orders, Invoices, Payments, Wallet, Subscriptions) | Invoices is primary; Orders, Wallet, Subscriptions are in "More"; there is no Payments page at all (payments are visible only as rows inside an invoice's settlement) | inconsistent grouping; MISSING_CUSTOMER_INFORMATION (payments) |
| Account (Profile, Team, Security, Sessions, Login activity, API tokens) | four "More" items; Sessions and Login activity are sections inside Security | acceptable content, poor placement (mixed with products) |
| Support / Communication (Notifications, Support, service events, status) | Notifications and Support are "More" items; no unread badge anywhere in the shell; no service-event timeline; no status/incident surface | MISSING_CUSTOMER_INFORMATION |

Specific IA findings:

- **Overloaded "More" menu.** 17 undifferentiated items, alphabetical by nothing, mixing products (Cloud VPS), money (Wallet), identity (Security) and a dead-end read-only table (Services). A first-time customer looking for "my server" has to open a dropdown and scan a list that also contains "API Keys" and "Profile". (`AppLayout.tsx:40-58`) — CONFUSING, P1.
- **Services vs. product pages is a duplicate destination in spirit.** `/services` lists every service with kind and state but offers no action and no link to `/vps`, `/hosting`, `/dedicated`, or `/wordpress`, where the same things are actually managed. (`ServicesPage.tsx:21-60`) — INCONSISTENT, P2.
- **No per-resource page.** Every product is a table with row buttons; the URL never names a machine, a domain or a site, so nothing can be bookmarked, linked from a notification, or opened from Services. The console (`/vps/:id/console`) is the only resource-scoped route — MISSING_CUSTOMER_FLOW, P1.
- **Mobile navigation loses 17 of 21 destinations.** The drawer renders only the primary four (`AppLayout.tsx:178-188`), and the "More" `<details>` lives inside `hidden sm:flex`. On a phone a customer cannot reach VPS, Backups, Domains, DNS, Support, Security or Profile at all — MOBILE_GAP, P1 (confirmed at runtime in AF).
- **Dangling labels** in `en.json`: `nav.monitoring`, `nav.usage`, `nav.paymentMethods` have no route or entry — DEAD_UI (strings only), P4.
- **Hidden features.** Reverse DNS lives on `/ips` behind "Set reverse DNS"; the console is a row link on `/vps`; the file browser is a row button on `/backups`; the currency-change request is a card on the dashboard. None is signposted from the place a customer would look (the machine, the domain).
- **Terminology.** "Catalogue" (fine), "Services" (means nothing to a newcomer: it is the list of things you pay for), "Wallet & Credits", "API Keys" (page is titled API tokens), "Shared Hosting" beside "WordPress" without saying WordPress is also hosting.

## E. Customer capability map

`docs/customer-capability-matrix.md` traces 130-odd capabilities to their
provider chain; that document is about *does the thing happen*. The column
that matters here is *can a customer find and understand it*. Mapping every
UI-bearing row of that matrix onto the screens:

| Area | Capabilities with a screen | Screen(s) | Discoverability today |
| --- | --- | --- | --- |
| Account | register, verify, sign in, 2FA, reset, profile, sessions, login history, API tokens, team (invite/role/remove/transfer), country-currency request | `/register`, `/sign-in`, `/profile`, `/security`, `/api-tokens`, `/settings/team`, `/` | scattered across five "More" items; verification has no in-app page |
| Buying | browse, product, place order, cancel unpaid order, orders, invoices, pay (gateway), pay from credit, wallet | `/catalogue`, `/catalogue/:slug`, `/orders`, `/orders/:id`, `/invoices`, `/wallet` | primary-bar Catalogue is good; Orders/Wallet are buried |
| Subscriptions | list, cancel at period end, end now, plan change, refusal of smaller disk | `/subscriptions`, `/subscriptions/:id/plan` | buried; never linked from the service it governs |
| VPS | list, power ×4, reinstall, console, plan change (via subscription) | `/vps`, `/vps/:id/console` | one dense table row holds eight controls |
| Backups | list, take, restore, delete, keep, browse files, download, file restore | `/backups` | separate page with a machine picker; not reachable from `/vps` |
| Dedicated | list, power ×3, reinstall | `/dedicated` | buried |
| Hosting | list, usage, open panel (SSO) | `/hosting` | buried; the panel relationship is one sentence |
| WordPress | order (4 domain sources), four-step status, staging, clone, push, operations | `/wordpress` | buried; good in-page explanations |
| Domains | search (5 answers), quote, buy, nameservers, lock, auth code, renew, redemption | `/domains` | buried; contacts and transfer-in exist in the API but have no control on the page (see S) |
| DNS | claim, records CRUD, import plan/apply, export, release | `/dns` | buried; not linked from `/domains` |
| Network | addresses, reverse DNS | `/ips` | buried; not linked from `/vps` |
| Notifications | inbox, mark read, preferences | `/notifications`, `/profile` | preferences live on Profile, inbox in "More"; no badge |
| Support | tickets, replies, attachments, close | `/support` | buried; nothing on a failed operation links to it |

Capabilities that exist over the API and have **no customer control**: change
registrant contacts (`PUT /domains/{domain}/contacts`), transfer a domain in
(`POST /domains/transfers`), read service events (`GET /services/{service}/events`),
read payments as a list (`GET /payments`). These are recorded in AU/AW.

## F. Current navigation map

Primary bar → "More" → page → row control → dialog is the whole depth. There
are no breadcrumbs (nothing to crumb), no tabs, no secondary navigation, and
no in-page anchors. Every detail is a dialog or an inline card on the list.

Cross-links that exist: Dashboard → Security ("Review security settings");
Catalogue card → Product; Orders row → Order; Subscription row → Plan change;
VPS row → Console; Domains redemption panel → `/invoices`; Product order →
`/orders/:id`; Notification row → `n.link`.

Cross-links that do not exist and a customer would expect: VPS → its backups,
its addresses, its subscription, its invoices; Domain → its DNS zone;
DNS zone → its domain; Service row → the product page that manages it;
Invoice → the order or service it bills; a failed operation → Support; the
topbar → unread notifications.

## G. New customer journey

Walked in a real browser against the seeded environment, as a brand-new
account (`/register` → sign-in → verification → dashboard → catalogue →
product → order → invoice → payment).

| Stage | What the customer sees | What they must infer or cannot find | Verdict |
| --- | --- | --- | --- |
| Entry | `/sign-in` with "Create account" link; no marketing or product entry point in the portal (fine: the portal is the portal) | — | ok |
| Register | Full name, email, account type (Individual / Company + company name), password, confirm, one terms checkbox, "Create account". **No country, no currency, no phone, no timezone, no password rules shown, and the terms sentence has no link to the terms** | Which currency they will be billed in (defaults server-side to `billing.default_currency`, country left null); that changing it later is an operator-approved request | EXISTING_NEEDS_PRODUCT_LOGIC, P1 |
| After register | "Check your email" panel with the address and a Sign in link; the form does not sign the user in (deliberate) | — | ok |
| Verification | No page in the portal. The link in the mail hits `GET /email/verify/{id}/{hash}` on the API. **There is no resend control anywhere in the portal** although `POST /email/verify/resend` exists; the only in-app trace is the warning banner "Ordering is available once the address on this account is verified". (In the audit environment mail goes to the log; the link could not be extracted from it, so the audit account was marked verified directly in the database to continue the walk. That is an environment limitation, not a finding) | A customer who lost the mail has no way back except support | MISSING_CUSTOMER_ACTION, P1 |
| Sign in unverified | Signs in; the warning banner says "Ordering is available once the address on this account is verified". But every customer route (`routes/api_v1.php:159` puts `catalog`, services, billing and the rest behind `verified`) answers 403, so the Catalogue page shows the banner **and** a red "You are not permitted to perform this action." with a request id (`shots/j2/004_A_catalogue_unverified.png`); the same on Services and Invoices. The dashboard is the only page that loads | That "not permitted" means "not verified"; that they cannot even read the price list before verifying | CONFUSING, P1 (folded into AR-11) |
| First dashboard | "Welcome, {name}", a Billing accounts card, an Account security card (2FA Off, Email verified), the Country and currency card. **No "buy your first server", no getting-started, no empty-state guidance** | Where to start (Catalogue is the second nav item; nothing points to it) | MISSING_CUSTOMER_FLOW, P1 |
| Catalogue | Three cards: Cloud VPS (4 plans), Dedicated servers (2), Shared hosting (3), "View plans". Domains and WordPress are not here | That domains and WordPress are bought elsewhere | EXISTING_NEEDS_UX, P2 |
| Product | Plan grid (CX-1, CX-2, CX-4, CX-8) listing vCPU, disk, IPv4/IPv6 count, memory in MiB, transfer in TiB and four backup allowances, one button per billing period and price; picking a price opens the checkout card (quantity, coupon, "Place order") with a note that the price is resolved on the server. The backup rows are raw attribute keys ("backup max retained", "backup manual allowance") with no explanation; memory is in MiB. **No location, no OS/template, no tax, no setup fee, no renewal price, no "what happens next"** | Tax; whether the price renews at the same amount; where the machine will be; what "backup scheduled allowance 30" means | MISSING_CUSTOMER_INFORMATION, P2 |
| Order | **"Place order" fails with "Please correct the highlighted fields" and no field highlighted** — the page sends the idempotency key in the body, the API accepts it only as the `Idempotency-Key` header (`PlaceOrderRequest.php:41-60`, `queries.ts:141`). No order can be placed from the portal | Nothing; the customer is stuck | P1 (blocks every purchase) |
| Invoice / payment | Assuming an order: `/orders/:id` shows items and totals with a bare "Cancel order" button (no confirmation); `/invoices` offers "Pay" and "Use credit". "Pay" starts a payment and redirects only if the gateway returns a redirect URL; with a gateway answering `client_secret` (the fake, and Stripe's card flow) **the button does nothing visible** | Whether anything happened | P1 |
| Provisioning | The order row moves through `pending_payment → paid → provisioning → active` as badges; no progress, no ETA, no polling (manual reload); the Services table gains a row; a notification "e2e-web-01 is ready" arrives in the inbox and by mail | When to reload; what "provisioning" means in minutes | EXISTING_NEEDS_UX, P2 |
| Failure | `provisioning_failed` and `under_review` are translated ("Could not be built", "Being looked at") but render grey (no tone); the notification says what happens next; the page does not | — | P2 |
| First useful action | VPS list → Console works; Reboot fails (same header defect); Backups page offers "Take a backup" | — | see O |

Answers to the brief's questions: there is no post-registration onboarding;
the empty dashboard does not help the customer start; there is no primary
"Create / Buy" action on the dashboard (Catalogue is a nav item); terminology
is mostly beginner-friendly ("Copies of your servers' disks, and putting one
back"), with exceptions (Services, Wallet & Credits, API Keys, package slugs);
Order / Invoice / Subscription / Service are four pages with no explanation
of how they relate; after payment the next state is not obvious (nothing
says "we are building it, this takes about N minutes, you will get an email");
during provisioning there is a badge and nothing else; on failure the
customer is told by notification, not by the page.

## H. Dashboard

`/` is not an operational dashboard. It is two account cards and the
country/currency request:

| Card | Content | Operational value |
| --- | --- | --- |
| Billing accounts | display name, `KWD · KW`, Active badge | none day to day |
| Account security | 2FA on/off, email verified, last sign-in, link to Security | low; the same facts are on Security |
| Country and currency | billed-in line, policy sentence, "Request a change", history of requests | important once, then noise |

Missing entirely: active / provisioning / suspended service counts, invoices
due (there are two open invoices in the seed and the dashboard says nothing),
current spend, wallet balance, upcoming renewals (one subscription ends Oct 1),
recent actions, open tickets (one is "waiting for you"), service health,
unread notifications (one), quick actions. Nothing is urgent-vs-informational
because nothing is there. `DashboardPage` renders `null` while the user loads
and has no error state. — MISSING_CUSTOMER_INFORMATION, P1.

## I. Catalogue / purchase

- `/catalogue`: kind filter chips, three cards with a kind badge that
  repeats the title, plan count, "View plans". Empty copy "Nothing is on sale
  in this category yet." Descriptions are honest ("cPanel and DirectAdmin
  accounts on managed nodes").
- `/catalogue/:slug`: plan cards listing every key in the plan's `resources`
  JSON verbatim (vCPU, Disk (GiB), IPv4/IPv6 addresses, Memory (MiB),
  Transfer (TiB), and four `backup *` rows shown as raw attribute names) and a price
  button per billing period; checkout card appears after choosing a price;
  quantity 1–20, coupon, "Place order"; "Prices are calculated by the server"
  note; refreshes the idempotency key when the selection changes (correct
  intent, wrong transport — see G).
- What the customer can understand: what the product is, plan differences
  in vCPU/RAM/disk, billing period, price and currency. What they cannot:
  who it is for, location, OS choice, what the backup allowances mean, tax, setup fee (`PlanPriceResource` publishes `setup`; `ProductPage` never renders it), renewal price, limits,
  what happens after purchase.
- Price manipulation: impossible; the client sends plan/price ids and the
  server prices the order. Unavailable products: hidden by the catalogue
  filter. Prepared products: not presented (AM), but readiness is not
  consulted by the catalogue (AM).
- Domains are bought on `/domains` (search → Buy → registrant form → "Order
  this domain" with a clear "invoice now, registered when paid" sentence);
  WordPress on `/wordpress` ("New WordPress site" with four domain sources).
  Three purchase entry points, one word for none of them.

Score: functional but incomplete (C), and broken at the last step until the
header defect is fixed (F for "place order").

## J. Orders

`/orders`: number (link), status, total, placed; paginator. `/orders/:id`:
items, subtotal / discount / tax / total, placed, and a bare **Cancel order**
danger button with no confirmation (`OrderDetailPage.tsx:89-98`). States
rendered through `StatusBadge`: `pending_payment` (info), `paid` (success),
`provisioning` (info), `active` (success), `cancelled` (danger),
`payment_failed`, `provisioning_failed`, `manual_review`, `queued_for_provisioning`
(all translated, all **grey**). No link from an order to its invoice or its
service; no "what happens next" on a pending-payment order; the Idempotency
defect means no order can be created from the portal today (G).

## K. Invoices

`/invoices`: number, status, total, due, due-by, Pay, Use credit. What is
missing for a finance user: **no invoice detail** (items, tax lines, credits,
payments, balance are all on the API and never shown), no download or PDF
(deliberately absent on the API, `routes/v1/billing.php:27-31`), no link to
the order or service the invoice bills, no filter. Money is exact (`12.750
KWD`, three decimals, Western numerals in Arabic, `dir="ltr"`) —
EXISTING_AND_STRONG on the numbers, MISSING_CUSTOMER_INFORMATION on the detail
(P1 for a finance user).

The credit dialog is the best money UI in the portal: "Credit available /
Applied to this invoice / Still to pay", the confirm button disabled until the
quote loads, refused with the server's reason when the wallet is empty.

## L. Payments

There is no payments page. `GET /payments` exists, `usePayments` exists, and
nothing renders it. A customer cannot see a payment as an object (pending,
succeeded, failed, refunded, partial refund), cannot see the card half and the
credit half of a mixed payment, and cannot see a refund except as
`amount_refunded` on an invoice they cannot open. "Pay" redirects when the
gateway returns a redirect URL and does nothing otherwise (K, G). —
MISSING_CUSTOMER_INFORMATION, P1 for launch with a real gateway.

## M. Wallet

`/wallet`: one balance card per currency with "Updated …" and an honest
sentence ("Credit arrives when a payment exceeds what was owed, or when
support adds it. You cannot top it up directly."). **No transaction history**
although `GET /wallet/transactions` exists and every entry carries
`balance_after`, `kind` and `direction`. A customer who was refunded to the
wallet sees a number change with no line explaining it. —
MISSING_CUSTOMER_INFORMATION, P2. No top-up is a product decision (BD).

## N. Subscriptions

`/subscriptions`: status, amount + period, renews / "Ends <date>", Change
plan, Cancel. **The row does not name the plan or the service** — the two
seeded rows read "Active · KWD 9.000 monthly" and are indistinguishable; the
customer cannot tell which server they are about to cancel (P1). The cancel
dialog is excellent: end date, data-retention sentence, "End it now instead
of …" checkbox that swaps the copy and requires the subscription id typed,
and a confirm button that never says "Cancel". The plan-change page is
honest (current plan disabled, "Due today", resize warning, refusals in
words) but: prices say "per period" instead of the period name; resources
show `MiB`; the plan-change POST fails with the same idempotency defect
(`queries.ts:397`, body key) — P1. `next_invoice_at`, `grace_period_ends_at`,
`data_retention_days` and `service_is_running` are on the resource; only the
first is shown. Subscriptions are not linked from the service they govern nor
from the invoice they generate.

## O. VPS

One table on `/vps` with eight controls per row and no detail page.

| Need | Today | Verdict |
| --- | --- | --- |
| Identity: hostname, service reference, location, status | hostname; power badge; service badge when not active; no location, no service reference, no OS shown (the resource carries `os_family`/`os_version`) | MISSING_CUSTOMER_INFORMATION, P2 |
| Resources: vCPU, RAM, disk, plan | **"vCPU · NaN GiB · GiB"** on every row, in both languages, at every width — `VpsPage.tsx:61` reads top-level fields the resource nests under `resources` (AW-1); no plan name | F, P1 |
| Networking: IPv4, IPv6, reverse DNS | primary address only; reverse DNS is on `/ips` with no link | P2 |
| Power: start, shut down, stop, reboot | four buttons; disabled unless `is_operable`; **every press answers 422 "Please correct the highlighted fields"** (idempotency key in the body, header required — `queries.ts:307`, `ReadsIdempotencyKey.php:20-37`); **Force off is a red primary button with no confirmation**; no feedback on success (the error banner is the only state) | P1 ×2 |
| Console | `/vps/:id/console`: Connect, text console, honest "Consoles are not available on this deployment. Nothing is wrong with your server." when no gateway | EXISTING_AND_STRONG |
| Reinstall | dialog with the right warning, hostname typed, escape-safe; **confirm answers the same 422** (`queries.ts:333`); no OS/template choice although the request accepts `template_id` and `ssh_keys`; no progress after (rebuild column + manual reload); Reinstall stays enabled on a machine whose rebuild is "unconfirmed and our team is looking at it" and the API then answers 409 — the "door with a sign" the code comments say they avoid | P1 (fails), P2 (no template), P3 (button) |
| Backups | separate page, machine picker, not linked from the row | P2 |
| Plan change | via Subscriptions, not from the machine; fails (N) | P1 |
| Monitoring / usage | none | MISSING_CUSTOMER_INFORMATION, P3 |
| Operations / activity | none; `GET /services/{id}/events` exists and is unused | P2 |
| Billing / subscription link | none | P2 |
| Cancellation | via Subscriptions; the subscription row does not name the machine | P1 (N) |
| States | `Coming back` (reactivating), `Suspended` explain the dead buttons; `The rebuild is unconfirmed and our team is looking at it` is the right sentence | EXISTING_AND_STRONG |
| Mobile | at 390 the table shows hostname, spec, a sliver of address; power, rebuild and every action are off-screen inside a horizontally scrolling table with no affordance; rows are 230 px tall | MOBILE_GAP, P1 |

## P. Dedicated

`/dedicated`: manufacturer/model + serial, status, power, since, rebuild
sentence, Power on / **Force off (red, no confirmation)** / Power cycle /
Reinstall. Reinstall dialog is right ("erase the installed operating system…
tens of minutes… offline throughout", serial typed) and then **fails with the
idempotency 422** (`queries.ts:588,613`). No hardware specification (CPU,
RAM, disks), no location, no addresses, no BMC anything (correct), no
activity, no billing link, no cancellation from here. What belongs in the
Control Center and stays there: rack, datacenter, BMC address, firmware,
hardware health, `last_seen_at`, `hardware_profile` (published, should not
be). Score D.

## Q. Shared hosting

`/hosting`: domain + username, status, package slug + disk quota, "Open
panel". The customer is not told **which panel** (cPanel or DirectAdmin) they
are opening, that they are leaving Lynomia, or what to do there (files,
databases, mail). **Usage is never shown** — `GET /hosting/{account}/usage`
with disk, bandwidth and a staleness contract exists and nothing calls it.
Package is the platform slug (`hosting-starter`). No plan change from here
(Subscriptions), no suspension explanation beyond the badge, no cancellation
link. The SSO failure copy is good ("could not be reached just now, so no
sign-in session was opened. Nothing about your account has changed."). Score
C-.

## R. WordPress

`/wordpress` is the best-explained product on the portal: four-step checklist
("The name points here / WordPress is installed / The certificate is issued /
We loaded the site and it answered"), state-specific hints ("The name is not
pointing here yet. Change its nameservers at the company you registered it
with, and this page will update on its own."), "Something did not finish.
Please do not order this site again; our team is looking at it.", the wp-admin
link only once verified, a footer sentence about what "live" means, and the
only polling on the customer side. Staging / clone / push: the push dialog
lists exactly what is overwritten, that the database push loses every post
and order since the copy, that the platform holds no backup, and requires the
production domain typed; the operations list shows each copy with its state.

Gaps: badges `awaiting dns`, `awaiting certificate` render as untranslated,
underscore-stripped grey text (no `status.awaiting_dns` key); version, SSL
status and the hosting relationship are not shown (the resource carries
`ssl_status`, `wp_version`, `hosting_account_id`); "Clone to another domain"
is a link-styled button that is easy to miss; the order form's four domain
sources are technical labels; real toolkit integration is blocked and the
page says so via the server's reason (AX). Score B.

## S. Domains

`/domains`: search with five availability answers (Available / Taken /
Premium / No answer / Not sold), price or "No price", Buy → registrant form
(name, email, phone, address, city, country) → "Order this domain" with the
"invoice now, registered when paid" sentence. Held names as cards: expires,
auto-renew, transfer lock, nameservers textarea + Save, Lock/Unlock, Get the
transfer code, and a leaving sentence. Redemption: "Recovering this name" with
the penalty and "Recover this name", or "we will not quote a guess".
Indeterminate: "The registry did not confirm the last action on this name. Do
not try again — we are checking what actually happened, and support can tell
you where it stands." with no controls.

State language, per the brief's list: ACTIVE ok; EXPIRING — there is an
`expiringSoon` warning but no countdown or renew button on the card;
EXPIRED and GRACE — translated, grey, no explanation of the grace window;
REDEMPTION ok; TRANSFER_PENDING — translated, grey, no "what is happening
now"; PROCESSING (`registration_pending`) — translated "Registration pending",
grey; NEEDS_REVIEW — "Needs review", grey, and the card hides controls.

Missing customer actions: **renew now** (the API has `POST
/domains/{domain}/renewals`; the card has no Renew button — auto-renew is the
only path); **auto-renew toggle** (shown as Yes/No, not editable); **contacts**
(`PUT …/contacts` exists, four roles, no control); **transfer in** (`POST
/domains/transfers` exists, no control); no link to the DNS zone; no operation
history. Runtime: "Get the transfer code" and "Unlock this name" on the seeded
name answered "e2e-held.test is not held by this account." — the fake
registrar's own refusal for a name seeded directly in the database, passed to
the customer verbatim; a fixture gap, and an example of raw provider text
reaching the screen. Arabic: dates on the cards render as "092027/03/" (the
LTR technical wrapper around an Arabic-formatted date scrambles it) —
RTL_GAP, P2. Score B- (strong states, missing actions).

## T. DNS

`/dns`: claim form with the honest hint about punycode; zone picker;
delegation card that leads with the nameservers and says nothing takes effect
until delegated (correct by design); records table (type, name, value, TTL,
state, Remove) and add-record form (type, subdomain labelled with the zone,
CAA tag, MX priority, value); import/export card (file ≤ 256 KiB or text,
merge/replace with a hint, Preview, plan table with kind badges and counts,
Apply behind the zone name typed, Export with copy and download); "Give up
domain" behind the zone name typed.

Gaps: **Remove record has no confirmation** (a CNAME or MX removed by a
mis-click is an outage) — P1; no edit-in-place (`PATCH` exists; the UI is
remove-and-add); TTL is a free number with "automatic" for 1; validation
errors are the server's English sentences (good sentences, wrong language in
Arabic); replace-mode preview lists removals before applying (good) but the
mode select's consequence is one hint line; record state `indeterminate` is
translated "Outcome unknown" with no next action on this page; no link to the
domain on `/domains`. Score B.

## U. Backups

`/backups`: server picker (defaults to the first machine, which in the seed
is the reactivating one with no backups and a "Take a backup" that answers
"Backups are not available for this service yet: no backup datastore is
configured for the cluster it runs on. This has been recorded." — honest,
internal), table (taken, state, size, **verified as its own column**, kept
until / deletion requested, Files / Restore / Keep / Delete), restore dialog
("cannot be undone", hostname typed), delete dialog (backup id typed, grace
period explained), Keep undo, file browser (breadcrumb, folders, symlinks
shown and not selectable, download opens a new window, "Restore selected to
the server" behind the hostname typed, restores history with `needs_review`
alert).

The customer can tell existence from verification, whole-machine restore
from file restore, and a deletion they can still undo from one they cannot.
Gaps: a `needs_review` backup row is grey with Files and Restore disabled and no sentence saying why (`shots/j2/033`); retention is a date, not a policy sentence; the deletion phrase is a
ULID; "Files" disabled reason is a tooltip in server English; no polling on
`restoring` / file restores; not reachable from the machine. Score B+.

## V. Profile

Full name, email (read-only, "Contact support to change"), language,
timezone (free text with an IANA hint, **never applied to any date shown**),
phone, Save; notification preferences below with "always sent" rows. No
billing details, address, company details, tax id; country and currency live
on the dashboard as the request workflow, which is the right model but the
wrong place (a profile page that does not mention the country). The
distinction "a change is a request, not a setting" is made clearly on the
dashboard card. Score B-.

## W. Team

Members (person, role select for non-owners, invited by, joined, Make owner,
Remove), invite form (email, role with a one-line hint per role, "Send
invitation"), invitations (email, role, status, expires, Send again,
Withdraw). Role hints exist ("Can see the account and ask for help. Changes
nothing."; "no access to money") — good. Gaps: no per-role permission table
(what Billing can do vs Technical) beyond the one-liner; **Remove member is
one click then a dialog with no phrase; Make owner requires the account ULID
typed** — the right strength for ownership, the wrong token; role change is
immediate with no confirmation and no feedback; a failed members read shows
"No members". Invitation acceptance page exists (`/invitations/:token`) and
handles "signed in as somebody else". Score B.

## X. Security

2FA (**"Turn on two-factor authentication" answers 422 "Please correct the
highlighted fields": the endpoint requires `current_password`, the button sends
nothing** — `TwoFactorController.php:100-107`, `useTwoFactor.ts:27`; a
customer cannot enable 2FA from the portal — P1), password change (with the
right consequence sentence), sessions (raw user-agent string, IP, last active,
"This device", per-row Sign out and "Sign out other devices", **no
confirmation**), sign-in history (outcome, when, IP, raw user-agent). API
tokens on their own page: name + current password → shown once with a clear
warning; table with last used; **Revoke with no confirmation**. No device
names (user-agent parsing), no geolocation beyond `country`, no token scopes
or expiry in the form although the resource carries `abilities`,
`allowed_ip_ranges`, `rate_limit_per_minute`, `expires_at`. Score C+ (B once
2FA works).

## Y. Notifications

Inbox with unread filter, count, mark all read, per-row Open (deep link) and
Mark read; failures get a red dot. Copy is good ("e2e-web-01 is now running
and ready to use. You can manage it from your portal."). Gaps: **no unread
badge anywhere in the shell**; `Open` links use `n.link` and the routes it
points at are list pages, so "Open" on a VPS notification lands on `/vps`,
not the machine; no category/severity grouping; `--accent` undefined makes
the unread dot and the Open link inherit colours (AI-1); preferences are on
Profile, not here. Types are complete for the real flows (W of the addendum).
Score B-.

## Z. Support

One page: requests table (reference, subject, status with "Waiting for you"
in amber, priority, last reply), thread in place (author, time, body,
attachments), reply with files, "Close this request", and the ask form
(subject, About, Priority ≤ high, description, up to five files). Internal
notes never reach the customer (tested). Gaps: **no service or invoice
association in the form** although the API accepts `service_id` and
`invoice_id`; no reopen; no link from any failed operation to Support; the
API publishes the operator's `assigned_to` name although the thread correctly
renders a role label ("Lynomia Support — Platform Administrator"); support is under "More" and
unreachable on mobile. Score B.

## AA. Long-running operations

| Operation | Acknowledged | Progress | State on the page | Refresh | Notification | Failure / indeterminate | Retry |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Order → provisioning | order status badge | none | `provisioning` badge on order and service | manual reload | ready / failed / needs review | translated, grey badge; notification says what next | none (operator) |
| VPS power | **fails today (422)**; would be 202 with no visible acknowledgement on success | none | power badge changes on reload | manual | none | `vps.operation_needs_review` 409 in server English | button re-enabled |
| VPS reinstall | **fails today**; dialog | rebuild column sentence | `reinstall.state` per row | manual | started / completed / failed | "unconfirmed and our team is looking at it"; Reinstall stays enabled and the API refuses | none |
| Dedicated reinstall / power | **fails today** | rebuild sentence | row | manual | — | 504 `power_operation_indeterminate` untranslated | none |
| Hosting creation | via order | none | account status | manual | — | `failed` badge | none |
| Domain register / transfer / renew / redeem | 201; card state | none | card state + redemption panel | manual | registered / failed / expiring / needs review | "Do not try again" — the best in the portal | refused `operation_in_flight` |
| Backup / restore / delete | 202; row | `running` / `restoring` / `delete_requested` badges | row | manual | completed / failed | `needs_review` row + alert; delete has a grace window with Keep | refused while in flight |
| File restore | 202; history row | row state | history | manual | completed / failed / needs review | needs_review alert "do not retry" | refused while in flight |
| WordPress install | order | four-step checklist | card | **15 s polling while unverified** | — | "Please do not order this site again; our team is looking at it." | none |
| WordPress copy / push | 202; operations list | "Asked for. The toolkit is copying the site; this page updates as it goes." | operation rows | polling only while a site is unverified | push completed / failed / needs review | needs_review + "do not push again" | refused while in flight |
| DNS publish | sync; row state | — | `pending` → `active` | manual | — | `failed` (customer's) vs `indeterminate` (platform's), both translated, only one explained | re-issue |
| Reverse DNS | 202 with "Published out of band. The change is queued, not immediate." | none | row | manual | — | — | re-issue |

The Timeout Rule is modelled everywhere on the backend and surfaced in words
on domains, WordPress and backups. What is missing across the board is
*refresh*: no polling except WordPress, `refetchOnWindowFocus` off, no
"updated N seconds ago", no relative time, so "nothing happened" is exactly
what a customer sees after pressing Reboot (and today, an error).

## AB. Activity / timeline

Global: none. Per resource: the last five WordPress operations, the file
restore history, the country/currency request history, the support thread,
the sign-in history. `GET /services/{service}/events` (customer-safe
provisioning timeline with `kind`, `state`, `failure_reason` narrowed to four
customer words) exists and **no screen calls it**. Audit entries are
operator-only and correctly not exposed; a customer-safe activity feed
("Reboot requested by Sara · 2 min ago", "Backup completed", "Domain renewed")
does not exist and is the single biggest comprehension gap for a multi-user
account. — MISSING_CUSTOMER_INFORMATION, P1.

## AC. Errors

The shape is right and the coverage is not.

- One JSON error envelope server-side (`bootstrap/app.php:126-221`) with a
  code, a message, `details.fields` and a request id; one client mapper
  (`useApiErrorMessage.ts`) that translates `errors.<code>`, falls back to the
  **server's English sentence**, and finally to "Something went wrong on our
  side."
- **9 of 137 backend error codes are translated** (the auth family,
  `validation.failed`, `resource.not_found`, `server.error`). Every domain
  refusal a customer can hit — `vps.operation_in_flight`,
  `invoice.not_payable`, `wallet.insufficient_balance`, `dns.invalid_record`,
  `domain.refused`, `subscription.not_changeable`, `backup.deletion_refused`,
  `order.not_cancellable`, `product.not_sellable`, all `payment.*`, all
  `coupon.*` — arrives as the server's prose. The i18n test asserts only the 8
  it knows about, so the gap is invisible to CI. — MISSING_CUSTOMER_INFORMATION / RTL_GAP, P1.
- **`Accept-Language` is never sent.** The request option exists
  (`lib/api.ts:91,125-127`) and no call site passes it, so Laravel validation
  messages under every field, and every fallback sentence, are English inside
  the Arabic portal. — RTL_GAP, P1.
- 409 conflicts from the framework (not a DomainException) become
  `http.409`, untranslated. Several screens pre-empt this by disabling the
  control (`VpsPage.tsx:157-163`, the "door with a sign" comment), which is
  the right instinct.
- A 401 on a **mutation** (session expired mid-form) renders "Please sign in
  to continue" inline with no redirect and no way to resume. — EXISTING_NEEDS_UX, P2.
- **No React error boundary**; a render-time throw blanks the app. **No
  offline handling** beyond a per-request `NetworkError`. — P2.
- `errors.referenceId` ("Reference: {{requestId}}") exists and is never used;
  `Alert` prints the bare request id with no label, so a customer does not
  know it is the thing to quote to support. — P3.
- The four questions the brief asks of an error — what happened, did anything
  change, can I retry, should I wait — are answered well on the indeterminate
  paths built deliberately (domain "do not try again", VPS rebuild
  "unconfirmed and our team is looking at it", WordPress push needs_review) and
  nowhere else systematically. There is no error → Support link anywhere.

## AD. Loading states

One pattern, 51 sites: a centred grey "Loading…" paragraph with no
`role="status"`. No skeletons, no layout reservation (tables and cards pop in,
so the page height jumps), no `aria-busy` outside Button.

- The route-gate loader (`guards.tsx:5-14`) has `role="status"` but its label
  is the hard-coded English word "Loading" — the one untranslated string, and
  the first thing an Arabic screen-reader user hears on every cold load. — RTL_GAP/ACCESSIBILITY_GAP, P3.
- Actions available before required data loads: the invoice-credit dialog
  uses `ready` correctly (the defect class the brief mentions is fixed there
  and tested); the plan-change **Choose** buttons are disabled until quotes
  arrive; the WordPress push dialog re-fetches the impact on scope change and
  the confirm stays enabled during the re-fetch (`WordPressPage.tsx:400-410`) — P3, verify.
- `DashboardPage` returns `null` while the user is undefined: a blank page,
  no loading or error state (`DashboardPage.tsx:17`). — P3.
- **No polling on any long-running customer state except WordPress** (15 s
  while a site is unverified). VPS provisioning, rebuilds, restores, file
  restores, domain registration/transfer, backup creation and DNS publication
  all require a manual reload; `refetchOnWindowFocus` is globally off, so
  switching tabs and back does not refresh either. — see AA, P1.
- `formatRelative` is implemented and never called; nothing on the portal says
  "2 minutes ago". — P4.

## AE. Empty states

| Area | Copy today | Tells the customer what this is | Why empty | What to do next | Verdict |
| --- | --- | --- | --- | --- | --- |
| Dashboard (new account) | "Welcome, {name}" + two cards; no services, no CTA | no | no | no | MISSING_CUSTOMER_FLOW, P1 |
| Orders | "No orders yet." | no | — | no link to the catalogue | P3 |
| Invoices | "No invoices yet." (DataTable empty) | no | no | no | P3 |
| Subscriptions | "No subscriptions yet." | no | no | no | P3 |
| Wallet | "No wallet yet." + how it fills | yes (howItFills) | yes | — | acceptable |
| Services | "Nothing is running yet." | partial | no | no CTA | P3 |
| VPS | "No virtual machines yet." | no | no | no "Get a VPS" | P2 |
| Backups | no machines: "No backups can be taken yet" + hint; per machine: "No backups for this server yet." | yes | yes | partly | acceptable |
| Dedicated / Hosting / WordPress | "No … yet." (WordPress: "You do not have any WordPress sites yet." with a New site button above) | WordPress yes; others no | no | WordPress yes | P3 |
| DNS | "No domains here yet. Add one above and this platform will start serving it — once you point the domain at the nameservers it gives you." | yes | yes | yes | EXISTING_AND_STRONG |
| Domains | "You do not hold any domains yet." with the search box above | yes | — | implicit | acceptable |
| IPs | "No addresses assigned yet." | no | no | no | P3 |
| Notifications | "Nothing yet." | no | — | — | P4 |
| Team | "No members" / "No invitations" — and the same string doubles as the loading state | no | — | invite form is on the page | P3 (loading/empty conflation P2) |
| API tokens | "No tokens." | no | — | form above | P4 |
| Support | "You have not asked us anything yet." | yes | — | form above | acceptable |
| Security sessions/activity | "No active sessions." / "No sign-in activity recorded yet." | yes | — | — | acceptable |

Pattern: the shared `DataTable` replaces the whole table with one grey sentence
(`DataTable.tsx:26-28`), so most empties are a bare "No X yet." with no
explanation and no action. `EmptyState` exists as a component but takes only
children. The three good ones (DNS, Wallet, WordPress) were written by hand.

## AF. Mobile

Method: every customer route rendered at 1440 / 1280 / 1024 / 768 / 430 /
390 / 360 in both languages (188 captures, `walk-report.json`), plus the
seeded-customer flows re-driven at 390 and 360 (menu, VPS, reinstall dialog,
credit dialog, DNS, WordPress, team).

What holds: no page scrolls horizontally at any width (tables scroll inside
their own container); dialogs fit at 360 and remain readable; forms stack to
one column; touch targets on buttons are ≥ 36 px; the topbar collapses to
Sign out / hamburger and the drawer carries the language switcher, so an
Arabic customer can switch language on a phone (`shots/j2/050`, `067`).

What does not:

| Finding | Evidence | Class / severity |
| --- | --- | --- |
| The mobile drawer contains only Dashboard, Catalogue, Services, Invoices. The seventeen "More" destinations (Orders, Subscriptions, Wallet, VPS, Backups, Notifications, Dedicated, Hosting, IPs, DNS, Domains, WordPress, API Keys, Support, Team, Profile, Security) are unreachable by navigation below 768 px | `AppLayout.tsx:178-188` renders `NAV` only; `shots/j2/H_mobile_menu_open.png` | MOBILE_GAP, **P1** |
| The hamburger's accessible name is "Dashboard" | `AppLayout.tsx:170` uses `nav.dashboard` for the sr-only label | ACCESSIBILITY_GAP, P2 |
| Every list is a wide table inside a scroll container. At 390 the VPS row shows hostname, "vCPU · NaN GiB · GiB" and a sliver of the address; power, rebuild and all eight actions are off-screen with no scroll affordance | `en_vps_390.png`, `ar_vps_390.png`, `en_vps_360.png` | MOBILE_GAP, P1 |
| Invoices at 390: number, status and half the total visible; Pay / Use credit require a horizontal swipe inside the table | `en_invoices_390.png`, `ar_invoices_390.png` | MOBILE_GAP, P1 (money actions) |
| Subscriptions at 390: rows show status and amount; Change plan / Cancel off-screen | `en_subscriptions_390.png` | MOBILE_GAP, P2 |
| Backups at 390: the verified column and the four row actions off-screen; the file browser dialog is usable | `en_backups_390.png`, `H_390_backups.png` | MOBILE_GAP, P2 |
| Dashboard at 390: three stacked cards, readable; nothing actionable | `en_home_390.png` | see H |
| Catalogue at 390: three stacked cards, "View plans" reachable; the product page's price buttons wrap correctly; the checkout card fits | `en_catalogue_390.png`, `A_product_price_selected` | ok |
| Domains at 390: search + cards stack; nameserver textarea usable | `en_domains_390.png` | ok |
| DNS at 390: forms stack; records table scrolls; Remove reachable after swipe | `en_dns_390.png` | P3 |
| WordPress at 390: cards stack, checklist readable, order form fits | `en_wordpress_390.png` | ok |
| Team at 390: role select and actions off-screen in the members table | `H_390_team` | P3 |
| Support at 390: thread readable; reply box fits | `en_support_390.png` | ok |
| 360 vs 390: no additional breakage; 768: the topbar switches to the desktop nav with "More" and the tables show 4–5 columns; 1024/1280/1440 identical layouts with wider gutters (the shell is `max-w-6xl`, so 1440 has ~200 px of unused margin each side) | `*_768.png`, `*_1024.png`, `*_1440.png` | ok / P4 |

No screen is designed for a phone; every screen tolerates one. The two P1
rows (navigation and the action columns) make the portal unusable as a
mobile customer tool today, and the mobile E2E project does not exist, so
this cannot regress-test (AN).

## AG. Arabic / RTL

Method: the same 188 captures with `?lng=ar` / stored locale, plus Arabic
re-runs of the reinstall, credit, cancel, WordPress push, support, team,
security, product and mobile-menu screens.

What holds (EXISTING_AND_STRONG): `dir="rtl"` and `lang="ar"` set on
`<html>` on every route; every `ui.*`, `nav.*`, `status.*`, `errors.*` key
has an Arabic string (117 status keys with parity); the layout mirrors
(topbar, tables, dialogs, forms, badges); dialogs in Arabic read naturally
(reinstall, credit, cancel, push); money renders `12.750 KWD` with Western
numerals inside an `ltr` span, the correct convention for Kuwait; hostnames,
addresses, domains and codes are wrapped LTR and monospaced; the Arabic
typography is the system Arabic face with no clipped glyphs at any width.

What does not:

| Finding | Evidence | Class / severity |
| --- | --- | --- |
| Backend messages arrive in English. The client never sends `Accept-Language` (`api.ts` only when `options.locale` is passed, which nothing does), and only 9 of 137 backend error codes have a frontend translation, so every validation, refusal, 409 and provider sentence appears in English inside an Arabic page: "Please correct the highlighted fields", "e2e-held.test is not held by this account.", "Backups are not available for this service yet: …", "The control panel … could not be reached" | `G_ar_*` captures, `useApiErrorMessage.ts`, `api.ts` | RTL_GAP, **P1** |
| Dates on domain cards render scrambled: "092027/03/" — an Arabic-formatted date placed inside the LTR technical wrapper | `ar_domains_1440.png` | RTL_GAP, P2 |
| Status badges with no translation key fall back to English underscore-stripped text inside Arabic UI ("awaiting dns", "awaiting certificate", "processing", "refused", "quarantined") | `ar_wordpress_1440.png`; StatusBadge fallback | RTL_GAP, P2 |
| Raw server text rendered as content: DNS plan/validation lines (`DnsPage.tsx:243`), WordPress operation reasons (`WordPressPage.tsx:229,322,385`), backups disabled-reason tooltips and file-restore reasons (`BackupsPage.tsx:153,601`), country/currency request outcomes (`CountryCurrencySection.tsx:170-251`) | code refs | RTL_GAP, P2 |
| Notification bodies are generated server-side in the account locale only if the locale is stored; the seeded owner reads English notifications on an Arabic page | `ar_notifications_1440.png` | RTL_GAP, P3 |
| 63 dynamic `t()` calls without `defaultValue` fall back to the key path when a key is missing (`status.awaiting_dns`) — visible in both languages, worse in Arabic | i18n inventory | P3 |
| User-agent strings, IP addresses and package slugs are correctly LTR; the sign-in-history table headers mirror correctly | `ar_security_1440.png` | ok |
| Digits: the UI uses Western numerals everywhere in Arabic, consistent with the money convention; no page mixes Eastern Arabic and Western numerals | all `ar_*` | ok |
| Icon-less UI means no mirroring bugs for directional icons; the one chevron (native `<details>` "More") mirrors by the browser | `ar_home_1440.png` | ok |

The interface layer is genuinely bilingual. The message layer is not: a
customer who hits any refusal, validation or provider condition reads it in
English. Because refusals are where trust is decided, this is a launch-level
gap for an Arabic launch, not a polish item.

## AH. Accessibility

Evidence, not certification.

Strong: native `<dialog>` with `showModal()` (focus trap, focus return,
`inert` background, Escape) on every ConfirmDialog; `Field` wires
`htmlFor`/`aria-invalid`/`aria-describedby`/`role="alert"`; DataTable has a
visually hidden caption and `scope="col"`; a real `:focus-visible` outline
(`index.css:124-127`) that nothing overrides; `prefers-reduced-motion`
honoured; colour never the only signal on badges (text always present); the
unread dot is `aria-hidden` beside visible text; `aria-label` on nav,
paginator, locale switcher and filter groups.

Gaps:

| # | Gap | Evidence | Sev |
| --- | --- | --- | --- |
| AH-1 | No skip link; sticky header with up to 30 links on every page | `sr-only` used 4 times, none a skip link | P2 |
| AH-2 | The mobile menu toggle is announced as "Dashboard" | `AppLayout.tsx:170` `sr-only` label is `nav.dashboard` | P2 |
| AH-3 | 51 loading paragraphs are not announced; no `aria-live` region for async outcomes other than Alert's own role | — | P3 |
| AH-4 | Touch targets: `Button size="sm"` is 32 px and is the default for every row action and the paginator; raw selects 36 px; hamburger ≈ 36 px | `Button.tsx:24`, `Paginator.tsx:32,45` | P2 (mobile) |
| AH-5 | The `<details>` primary menu does not close on Escape or outside click | `AppLayout.tsx:120-148` | P2 |
| AH-6 | ConfirmDialog evidence textarea lacks `aria-describedby` to its hint; DataTable's empty state replaces the whole table so a screen-reader user loses the table context; console log pane is not keyboard-scrollable | `ConfirmDialog.tsx:160-169`, `DataTable.tsx:26-28`, `ConsolePage.tsx:178` | P3 |
| AH-7 | Contrast not measured in this pass; the undefined `--danger-text` means the destructive warning has body-text contrast rather than a danger colour, which is a semantics gap more than a contrast one | AI-1 | — |

## AI. Design system

What exists (`apps/web/src/components`, 15 files, ~830 lines; the `@lynomia/ui`
package is an empty stub): Button (4 variants, 3 sizes, `loading` → `aria-busy`),
Badge (5 tones), StatusBadge (66-entry tone map over `status.*`), Alert (4
tones; `role="alert"` for errors), Card, PageHeader, Field (input only, with
`useId` wiring and auto-LTR for email/password/url/tel), DataTable (caption,
`scope="col"`, per-column LTR, overflow wrapper), EmptyState, Paginator
(prev/next), ConfirmDialog (native `<dialog>`, typed phrase, evidence, `ready`),
LoadFailure, MoneyText, RecoveryCodes, LocaleSwitcher.

What does not exist: Tabs, Breadcrumb, Toast, Tooltip, Select, Checkbox, Radio,
Textarea, Skeleton, Drawer, Menu, Avatar, error boundary, page-level empty
state with a call to action.

Consistency findings:

| # | Finding | Evidence | Class | Sev |
| --- | --- | --- | --- | --- |
| AI-1 | **Five design tokens are referenced and never defined.** `--surface-base` (34 uses, every raw input/select/textarea incl. both ConfirmDialog inputs), `--border` (12), `--danger-text` (5), `--warning-text` (2), `--accent` (2). Form controls therefore have a transparent background over cards, and the destructive-rebuild warning sentence on the VPS and dedicated dialogs renders in body colour, not red. | `grep -c -- "--surface-base:" src/styles/index.css` → 0; uses in `VpsPage.tsx:109,213`, `DedicatedPage.tsx:72,157`, `NotificationsPage.tsx:116,145`, `ConfirmDialog.tsx:150,168` | DESIGN_SYSTEM_GAP | P1 |
| AI-2 | Dead tokens `--status-running/-stopped/-provisioning/-failed/-suspended` defined three times, used nowhere; `Badge` and `Alert` hardcode `emerald/amber/red/blue-500` against the stylesheet's own "no raw colour" rule | `index.css:36-40,54-58,72-76`; `Badge.tsx:9-12`; `Alert.tsx:8-11` | DESIGN_SYSTEM_GAP | P3 |
| AI-3 | 29 raw `<select>` elements, 28 wrapping `<label>`s and 5 raw checkboxes, all repeating the same class string, because Field covers inputs only | e.g. `DnsPage.tsx:131,361,393,591`, `SupportPage.tsx:247,268`, `TeamPage.tsx:114,275` | DESIGN_SYSTEM_GAP | P3 |
| AI-4 | **No toast or global feedback channel.** Every outcome is an inline Alert mounted wherever the component is; a mutation triggered from a table row on a long page reports success or failure out of view | 40 files use Alert; zero toast | EXISTING_NEEDS_UX | P2 |
| AI-5 | Read failures rendered as empty lists on some screens. `LoadFailure` is used by 34 files; `TeamPage.tsx:241` and `AdminSupportPage.tsx:157` pass `empty={isPending ? loading : noMembers}`, so a 403 reads "No members"; `SupportPage`, `ApiTokensPage`, `SessionsSection` render a failed read as an empty table | files listed | INCONSISTENT | P2 |
| AI-6 | Two mechanisms for success feedback (`isSuccess` vs local `saved`/`done` state) | `ProfilePage.tsx:82`, `PasswordSection.tsx:55` vs `PlanChangePage.tsx:57` | INCONSISTENT | P4 |
| AI-7 | The primary "More" menu is a raw `<details>`; it does not close on Escape, outside click, or after choosing a link | `AppLayout.tsx:120-148` | EXISTING_NEEDS_UX | P2 |
| AI-8 | Confirmation strength is inconsistent across equally destructive acts: typed phrase for reinstall/restore/delete/release/import/push/transfer-ownership; one-click dialog for remove member, plan change, redemption order; **no dialog at all** for cancel order, remove DNS record, revoke API token, revoke session / sign out other devices, revoke invitation, VPS and dedicated **Force off**, withdraw currency request | `OrderDetailPage.tsx:89-98`, `DnsPage.tsx:302-312`, `ApiTokensPage.tsx:56-66`, `SessionsSection.tsx:59-84`, `TeamPage.tsx:210-217`, `VpsPage.tsx:122-141` | INCONSISTENT | P1 for Force off and DNS record removal; P2 for the rest |
| AI-9 | Strong: `window.confirm` is never used; ConfirmDialog is a native `<dialog>` with a browser-owned focus trap; DataTable is the one table implementation on the customer side; logical CSS properties everywhere (zero `pl-/pr-/ml-/mr-/text-left/right`) | — | EXISTING_AND_STRONG | — |

## AJ. Status vocabulary

117 `status.*` keys, 100 % Arabic parity (enforced by
`translation-parity.test.ts`). `StatusBadge` maps 66 of them to a tone.

| Internal status | Customer phrase (en) | Tone | Assessment |
| --- | --- | --- | --- |
| `indeterminate` | "Outcome unknown" | danger | correct words; the explanation and the next action live only in specific pages (`vps.reinstallState.indeterminate` = "The rebuild is unconfirmed and our team is looking at it"; domains: "do not try again"). No shared "what this means / what to do" pattern |
| `needs_review`, `manual_review`, `under_review` | "Needs review", "Being looked at" | needs_review has **no tone** → grey | three keys for one concept; a grey badge for a state that needs a person |
| `provisioning_failed`, `provisioning_timeout`, `payment_failed`, `expired`, `degraded`, `offline` | translated | **no tone → grey** | a failed build looks like "Registered"; inconsistent with `failed` = danger |
| `processing`, `quarantined`, `refused` | **no key** | toned | render as underscore-stripped English in the Arabic UI |
| 53 translated statuses total | — | no tone | silently grey, including `redemption`, `transfer_pending`, `expired`, `scheduled`, `applied`, `rejected` |
| Control Center vocabulary (`ready_for_test`, `connected_read_only`, `licence_missing`, …) | translated | toned | lives in the same shared map as customer states; any leak onto a customer payload renders |
| unknown status | `status.replace(/_/g,' ')` | grey | any new internal enum value paints raw on a customer page (`StatusBadge.tsx:93-97`) |

63 dynamic `t()` calls have no `defaultValue`; a value the catalogue lacks
paints the key path (e.g. `domains.availabilityStates.xyz`). Customer-facing
namespaces affected: `domains.availabilityStates`, `console.state`,
`catalogue.kinds`, `billingPeriod`, `notifications.category`,
`backups.browser.kinds`, `dns.import.kinds`, `dedicated.reinstallState`.

Raw server text shown to customers without translation: `zone.failure_reason`
(`DnsPage.tsx:243`), `site.failure_reason` and `op.failure_reason`
(`WordPressPage.tsx:229,385`), `wordpress.copies.unavailable` = `"{{reason}}"`
(`:322`), backup `files.reason` as a tooltip (`BackupsPage.tsx:153`), file
restore `failure_reason` (`:601`), currency-change blockers, warnings and the
operator's decision note (`CountryCurrencySection.tsx:170-251`). All are
English server sentences on an Arabic page.

## AK. Cross-product consistency

| Concept | VPS | Dedicated | Hosting | WordPress | Domains | DNS | Backups |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Layout | table rows | table rows | table rows | cards | cards | picker + cards + table | picker + table |
| Identity | hostname (mono) | model + serial | domain + username | domain | domain | zone select | machine select |
| Status | two badges | two badges | one badge | badge + checklist | badge + panel | badge + alert | badge + verified column |
| Plan | not shown | not shown | slug | — | — | — | — |
| Billing link | none | none | none | none | "Open the invoice" on redemption only | none | none |
| Activity | rebuild sentence | rebuild sentence | none | last 5 operations | none | none | file-restore history |
| Danger zone | red buttons in the row | red buttons in the row | none | push in the card footer | none | "Give up" card | Delete in row |
| Confirmation | hostname (reinstall), none (Force off) | serial / none | — | production domain | none (redemption) | zone name (release, import), none (remove record) | hostname / ULID |
| Failure sentence | yes | yes | badge only | yes | yes | badge + failure_reason | alert |
| Empty state | one line | one line | one line | one line + button | one line + search above | explained | explained per machine |

Seven products, three layouts, four confirmation strengths, two idioms for
"something is wrong" and no shared "resource page" pattern. INCONSISTENT, P1
as a whole (the cure is the resource-page pattern in AY/AZ, not seven fixes).

## AL. Customer / operator boundary

The API resources are careful: `VirtualMachineResource`, `DedicatedServerResource`,
`HostingAccountResource` and `ServiceResource` each carry an explicit list of
what they withhold (hypervisor ids, nodes, clusters, datastores, BMC fields,
racks, credentials references, drift), and session ids are hashed before they
are published. No customer page names Proxmox, cPanel, a node, a datastore or a
driver. That boundary holds.

What crosses it:

| # | Leak | Where | Class | Sev |
| --- | --- | --- | --- | --- |
| AL-1 | The shared `StatusBadge` tone map contains the Control Center vocabulary (`ready_for_test`, `connected_read_only`, `licence_missing`, `provider_unavailable`, `preflight`, `applying`…). Nothing stops a customer payload that carries one from rendering it, and an unknown status renders raw | `components/StatusBadge.tsx:12-97` | INCONSISTENT | P3 |
| AL-2 | Raw server free text rendered to customers: zone and site `failure_reason`, WordPress operation `failure_reason`, `copies.unavailable` reason, backup `files.reason`, currency-change blockers, warnings and the operator's decision note | `DnsPage.tsx:243`, `WordPressPage.tsx:229,322,385`, `BackupsPage.tsx:153,601`, `CountryCurrencySection.tsx:170-251` | EXISTING_NEEDS_UX | P2 |
| AL-3 | Internal identifiers used as confirmation phrases: the backup ULID to delete a backup, the subscription ULID to end a subscription today, the account ULID to transfer ownership. Typing a 26-character ULID is the customer's proof of intent, where the hostname, the plan name or the account name would be | `BackupsPage.tsx:345-355`, `SubscriptionsPage.tsx:180-198`, `TeamPage.tsx:332` | CONFUSING | P2 |
| AL-4 | Hosting package slug (`hosting-starter`) instead of the plan name; `hardware_profile` slug and `memory_mib`/`disk_gib` field names as units | `HostingPage.tsx:65`, `DedicatedServerResource.php:88-91`, `PlanChangePage.tsx:80-83` | EXISTING_NEEDS_UX | P3 |
| AL-5 | `PaymentResource.provider` publishes the payment-driver slug on every payment row; `TicketResource.assigned_to` publishes the assigned operator's real name; `WalletTransactionResource.wallet_id` and `ServiceResource.order_item_id` publish internal ids no customer endpoint resolves | `PaymentResource.php:18`, `TicketResource.php:43-44`, `WalletTransactionResource.php:8`, `ServiceResource.php:74` | SECURITY_RISK (low) / boundary | P3 |
| AL-6 | 5xx error codes name internal machinery (`hosting.node_at_capacity`, `hosting.node_credentials_missing`, `compute.cluster_not_configured`, `dedicated.bmc_not_configured`, `dedicated.pxe_authorisation_refused`, `*.unknown_driver`, `*.fake_provider_in_production`) and several attach `details` context (`service_id`, `provisioning_job_id`) that `ApiError` forwards verbatim. Whether a customer ever sees them depends on the 5xx renderer; the codes are customer-reachable | `bootstrap/app.php:126-221`, `Http/Responses/ApiError.php:53` | SECURITY_RISK (information) | P2 — verify the 5xx path |
| AL-7 | Operator navigation appears inside the customer "More" menu for operator users, so an operator-customer sees one undifferentiated list of 35 links | `AppLayout.tsx:129-146` | CONFUSING | P3 |

What does not leak (checked): `blocked_dependency`, readiness rungs and provider
names appear only under the `admin.*` i18n namespace; drift, jobs and audit are
operator routes only; the reinstall `failure_code`/`failure_message` are
suppressed on the VPS resource.

## AM. Prepared products

Current behaviour, observed at runtime and in code:

- The customer catalogue (`GET /catalog/products`, `/catalogue`) lists three
  kinds — Cloud VPS, Dedicated servers, Shared hosting — with 4, 2 and 3 plans.
  Domains and WordPress are sold from their own pages, not from the catalogue.
- CDN, Object storage, GPU compute, Email hosting and Managed Kubernetes are
  **not visible anywhere on the customer portal**: no catalogue entry, no
  navigation item, no "coming soon" card. `ProductKind` (the catalogue enum)
  has only `vps`, `dedicated`, `shared_hosting`. They exist only on the operator
  readiness screen with the software cap `not_implemented`.
- The sale-time guard `AssertProductMaySell` refuses every new sale of a
  product below `ready_to_sell` in production with 409 `product.not_sellable`.
  **The catalogue does not consult readiness.** In production, with all twelve
  products `not_ready`, a customer would see the three catalogue cards, pick a
  plan, press "Place order" and receive an untranslated 409. That is the
  brief's "false impression of availability", one step later than the
  catalogue — MISSING_CUSTOMER_INFORMATION, P1 (for the day production is
  switched on; harmless while nothing is for sale anywhere).

Recommendation (for the product owner, BD): prepared products stay invisible to
customers until `ready_to_sell`; the catalogue reads readiness and hides or
marks "not yet available" any kind that cannot be ordered, so the 409 is never
reached from the screen. "Coming soon" or "request access" cards are a
marketing decision, not a portal one; if chosen, they must never render a
price or a button.

## AN. Browser / E2E coverage

150 tests in 27 specs, one Chromium desktop project (1280×720), one worker,
no retries. Full mapping in the working notes; the shape:

| Journey | Browser coverage | Verdict |
| --- | --- | --- |
| Registration, email verification, forgot/reset password, invitation acceptance | **none** — no spec visits `/register`, `/forgot-password`, `/reset-password` or `/invitations/*`; both seeders create verified users, so the unverified state is never observable | gap |
| First purchase | catalogue and product page existence only; **no order is ever placed** for any product; `/orders` and `/orders/:id` are never visited | gap |
| VPS management | list existence; power buttons asserted visible, **never pressed** on a healthy machine; reinstall dialog guard proven, **never confirmed**; console usability proven | existence-heavy |
| Dedicated | one reinstall dialog (guard only) + Arabic warning | existence |
| Hosting, IPs, Services | one existence assertion each; no action driven | existence |
| WordPress | four state screens (existence); refused admin name (usability); staging→push round trip (usability, complete) | mixed |
| Domains | search/pricing/timeout answers (usability); redemption order to invoice (usability); nameservers/lock/auth code **never invoked**; **no transfer-in or contacts coverage** | mixed |
| DNS | claim, record create/delete, refusal, release, import/export in both modes (usability); **no record edit** | strong |
| Backups | restore guard (never confirmed), delete+undo (complete), file browse/download/restore (complete); **backup creation never driven** | strong except create |
| Billing | wallet credit settlement (complete); **card payment path never followed**; plan change priced, **never applied**; invoice list existence | mixed |
| Cancellation | three dialog tests, **all exit via Escape** despite the file header claiming one goes through | guard-only |
| Team | role change, invite/withdraw, RBAC as teammate (usability); **removal and ownership transfer never driven** | mixed |
| Security | sessions/2FA/login history existence; API token one-time reveal (usability); **2FA enrolment, session revoke, password change never driven** | existence-heavy |
| Support | create, reply, internal-note exclusion (usability) | strong |
| Notifications | mark read (usability); preferences existence only, **never toggled** | mixed |
| Profile | **form never submitted**; only the preferences assertion visits it | gap |
| Mobile | **zero** — no mobile project, no viewport override anywhere | gap |
| Arabic | 24 `test.use({locale:'ar'})` blocks; almost all existence + `direction` checks; flows actually driven in Arabic: sign-in, DNS claim/give-up, DNS import preview, domain search, backup deletion guard, file browser navigation | shallow |

Tests that verify existence rather than usability (heading or element
present, nothing exercised): the dashboard (`portal.e2e.ts:42`), services,
hosting, addresses, VPS list, backups list, invoices list, wallet, the
WordPress state screens, all Control Center Arabic tests, and most Arabic
tests generally. The suite is excellent at proving refusals and typed guards;
it rarely proves that a happy path completes and shows the customer the right
outcome afterwards (the exceptions: wallet credit, staging push, file restore,
backup delete/undo, DNS import, redemption order, invitation withdraw).

## AO. Page scorecard

Score key: A = professional as-is; B = works, understandable, refinements
needed; C = works but a customer must guess or is missing something they
need; D = a primary purpose of the page is unfulfilled or broken; F = the
page fails its purpose today.

| Route | Purpose | Primary customer | Works | Confusing | Missing states / actions | Mobile | RTL | A11y | Consistency | Sev | Recommendation | Score |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `/sign-in` | Authenticate, 2FA challenge | all | yes | — | no "resend verification" path | ok | ok | ok | ok | P3 | add verification recovery link | B+ |
| `/register` | Create account | new | yes | no password rules, terms not linked | country/currency/phone; verify page | ok | ok | ok | ok | P1 | collect country+currency, show rules, link terms | C |
| `/forgot-password`, `/reset-password` | Recover access | all | yes | — | — | ok | ok | ok | ok | P4 | — | A- |
| `/invitations/:token` | Join an account | invitee | yes (handles wrong account) | — | — | ok | ok | ok | ok | P4 | — | A- |
| `/` Dashboard | Overview | all | renders | it is a settings page | services, due invoices, spend, renewals, tickets, unread, quick actions | ok | ok | ok | ok | P1 | rebuild as operational overview (AY) | D |
| `/catalogue` | Browse products | new / expanding | yes | domains & WordPress elsewhere | readiness, "who is it for" | ok | ok | ok | ok | P2 | one catalogue for five product families | B- |
| `/catalogue/:slug` | Choose plan, order | new | **Place order fails (422)** | tax/renewal/location absent | setup fee, template, location, terms | ok | ok | ok | ok | P1 | fix header key; add total-with-tax and "what happens next" | F |
| `/orders` | Track orders | all | yes | — | link to invoice/service | swipe | ok | ok | ok | P2 | link the chain | C+ |
| `/orders/:id` | Order detail | all | yes | Cancel with no confirmation | next-step copy | ok | ok | ok | ok | P2 | confirm cancel, show next step | C+ |
| `/services` | Everything I own | all | yes | "Services" vs the product pages | plan, cost, renewal, link to resource page | swipe | ok | ok | ok | P2 | make it the resources index | C |
| `/invoices` | Pay, see what is owed | finance | Pay silent with client_secret | no detail | invoice detail, payments, download, filters | Pay off-screen | ok | ok | ok | P1 | invoice detail page, card flow | D |
| `/wallet` | Credit balance | finance | yes | — | transactions | ok | ok | ok | ok | P2 | show ledger | C+ |
| `/subscriptions` | Manage recurring | finance/owner | plan change fails | rows unnamed | plan/service name, next invoice | swipe | ok | ok | ok | P1 | name rows; fix header key | D |
| `/subscriptions/:id/change-plan` | Resize | owner | **fails (422)** | "per period", MiB | — | ok | ok | ok | ok | P1 | fix; period names | D |
| `/vps` | Operate machines | technical | power/reinstall **fail (422)**; NaN spec | Force off unconfirmed | resource page, OS, location, activity, plan | actions off-screen | ok | ok | mixed | P1 | resource page + fix transport | F |
| `/vps/:id/console` | Console | technical | yes; honest when absent | — | — | ok | ok | ok | ok | P3 | — | B+ |
| `/dedicated` | Operate servers | technical | power/reinstall **fail (422)** | Force off unconfirmed | spec, addresses, activity | off-screen | ok | ok | mixed | P1 | resource page + fix | D |
| `/hosting` | Use hosting | small business | Open panel | which panel; slug | usage, plan change, what to do in the panel | ok | ok | ok | ok | P2 | show panel type, usage | C- |
| `/wordpress` | Run sites | small business | yes | domain-source labels | version, SSL, hosting link, untranslated badges | ok | ok (badges en) | ok | good | P2 | translate badges, expose ssl/version | B |
| `/domains` | Buy, hold, recover | all | yes (fixture refusal on lock/code) | grace/expired unexplained | renew now, auto-renew toggle, contacts, transfer in, DNS link | ok | date scramble | ok | good | P2 | add the four actions; fix date | B- |
| `/dns` | Manage zones | technical | yes | replace-mode consequence one line | edit record, **confirm remove** | swipe | validation en | ok | good | P1 | confirm remove; edit; translate | B |
| `/ips` | Reverse DNS | technical | yes | "out of band" wording | link from VPS | ok | ok | ok | ok | P3 | fold into resource page | B- |
| `/backups` | Protect data | technical | yes; honest when datastore absent | ULID phrase; default machine | policy sentence, polling, link from machine | off-screen | tooltips en | ok | good | P2 | link from machine; polling | B+ |
| `/notifications` | Know what happened | all | yes | Open lands on list pages | shell badge, deep links | ok | bodies en | ok | ok | P2 | badge + deep links | B- |
| `/profile` | Identity & prefs | all | yes | timezone free-text unused | address/company/tax; country here | ok | ok | ok | ok | P2 | apply timezone; move country card | B- |
| `/settings/team` | Multi-user | owner | yes | Make owner phrase = ULID | permission table, confirmations | off-screen | ok | ok | ok | P2 | permission table; confirm role/remove | B |
| `/security` | Protect account | all | **2FA enable fails (422)** | raw user agents | confirmations on revoke | ok | ok | ok | ok | P1 | fix 2FA body; parse UA; confirm | C+ |
| `/api-tokens` | Automate | technical | yes | — | scopes/expiry, confirm revoke | ok | ok | ok | ok | P2 | expose abilities/expiry; confirm | B- |
| `/support` | Get help | all | yes | — | service/invoice link, reopen, entry from failures | ok | ok | ok | ok | P2 | contextual "get help" | B |
| `*` not found | Recover from bad URL | all | yes | — | — | ok | ok | ok | ok | P4 | — | A- |

30 routes audited (28 customer routes + console + not-found; the operator
routes excluded).

## AP. Journey scorecard

Scores 1–5 (5 = professional). Overall is the weakest dimension that
matters for the journey, not the mean.

| Journey | Discoverability | Comprehension | Speed | Safety | Feedback | Recovery | Mobile | RTL | Overall | Blocking finding |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Registration & verification | 4 | 3 | 4 | 4 | 3 | **1** | 4 | 4 | 2 | no resend; no country/currency |
| First purchase | 3 | 3 | — | 4 | **1** | **1** | 3 | 3 | **1** | Place order 422 (AQ/AR-1); Pay silent |
| VPS lifecycle | 3 | 3 | — | 2 | **1** | 2 | **1** | 3 | **1** | power/reinstall 422; NaN; Force off unconfirmed; no resource page |
| Dedicated lifecycle | 3 | 3 | — | 2 | 1 | 2 | 1 | 3 | 1 | same transport defect |
| Shared hosting | 3 | 2 | 4 | 4 | 3 | 3 | 3 | 3 | 2 | no usage; panel unnamed |
| WordPress | 3 | **5** | 4 | 5 | 4 | 4 | 4 | 3 | 4 | badges untranslated |
| Domains | 3 | 4 | 4 | 4 | 3 | 4 | 4 | 3 | 3 | no renew/auto-renew/contacts |
| DNS | 3 | 4 | 4 | **2** | 4 | 3 | 3 | 3 | 3 | remove without confirmation |
| Backups | 2 | 4 | 4 | 5 | 3 | 4 | 3 | 3 | 3 | not reachable from machine; default picker |
| Billing (invoice → pay → credit) | 4 | 3 | 3 | 4 | 2 | 2 | 2 | 4 | 2 | no invoice detail; Pay silent; no payments view |
| Subscription change | 3 | 3 | — | 4 | 1 | 2 | 3 | 3 | 1 | 422; rows unnamed |
| Cancellation | 3 | **5** | 4 | 5 | 4 | 3 | 3 | 4 | 3 | rows unnamed → wrong-service risk |
| Team | 3 | 4 | 4 | 3 | 2 | 3 | 2 | 4 | 3 | no permission table; silent role change |
| Security (2FA, sessions, tokens) | 3 | 4 | 3 | 3 | 2 | 3 | 3 | 4 | 2 | 2FA 422; unconfirmed revokes |
| Support | 3 | 4 | 4 | 4 | 4 | 3 | **1** | 3 | 3 | unreachable on mobile; no context |

Speed is "—" where the journey cannot complete today.

## AQ. P0 findings

None confirmed. The audit looked specifically for customer-money loss,
customer data loss, cross-account exposure and secret leakage:

- Account scoping is enforced at the policy and query layer on every
  customer route inspected; the teammate account could not read another
  account's resources (tested on VPS, invoices, domains).
- No secret, token, password, BMC address or provider credential appears in
  any customer response or in any screen captured (AL).
- No destructive action executes without a request the customer initiated;
  the unconfirmed ones (AR-8) are recoverable (power) or reversible by
  re-adding (DNS record), which keeps them at P1, not P0.
- Payment: the client never sets a price; the server prices every order; the
  credit dialog shows the exact amount before applying it. A silent Pay is a
  blocker to *paying*, not a route to losing money.
- The domain "Give up" and backup delete both require the resource name typed
  and the delete has a grace window.

Recorded for the product owner: the header-only idempotency mismatch (AR-1)
is not a security defect, but it means every "protected" mutation is
currently unusable from the portal, which is why it heads the P1 list.

## AR. P1 findings

| # | Finding | Class | Evidence | Customer impact | Recommended change | Routes / components | Backend change |
| --- | --- | --- | --- | --- | --- | --- | --- |
| AR-1 | The portal sends `idempotency_key` in the request body; every guarded endpoint reads it only from the `Idempotency-Key` header and answers 422 "Please correct the highlighted fields" with no field highlighted. Affects place order, VPS power (4 actions), VPS reinstall, dedicated power, dedicated reinstall, plan change | DEAD_UI (from the customer's seat) / INCONSISTENT (API contract) | `queries.ts:141,307,333-335,397,588,612-615` vs `ReadsIdempotencyKey.php:20-37`, `PlaceOrderRequest.php:41-60`; only wallet credit (`queries.ts:848-854`) uses the header and works; browser captures `A_after_place_order`, `B_after_reboot`, `B_reinstall_result`, `E_plan_change_result` | Nothing can be bought, no server can be rebooted or rebuilt, no plan can be changed from the portal. The error blames the customer for a field they cannot see | Send the key as the header everywhere (one helper in `api.ts` / `queries.ts`); make the 422 message name the missing header when it is the only error; add a contract test that every `ReadsIdempotencyKey` route is exercised with the header | `ProductPage`, `VpsPage`, `DedicatedPage`, `PlanChangePage`, `lib/queries.ts`, `lib/api.ts` | No (a frontend transport fix). Optional: accept the body key as a fallback for one release |
| AR-2 | 2FA cannot be enabled: "Turn on" posts an empty body; the endpoint requires `current_password` | DEAD_UI | `useTwoFactor.ts:27`, `TwoFactorController.php:100-107`; capture `F_2fa_result` | The security card advertises 2FA the customer cannot turn on | Ask for the current password in a dialog before enrolment (the disable path already does) | `features/security/TwoFactorSection.tsx`, `useTwoFactor.ts` | No |
| AR-3 | VPS rows show "vCPU · NaN GiB · GiB": the page reads `vm.vcpu`, `vm.memory_mib`, `vm.disk_gib` at top level; the resource nests them under `resources` | INCONSISTENT (type contract) | `VpsPage.tsx:61`, `VirtualMachineResource.php:81-85`, `types.ts:184-196`; every `*_vps_*.png` | The primary fact about a server is garbage on every row, in both languages | Read `vm.resources.*`; fix the TS type; add a vitest render assertion | `VpsPage`, `types.ts` | No |
| AR-4 | Mobile navigation exposes 4 of 21 destinations; the 17 "More" items are unreachable below 768 px | MOBILE_GAP | `AppLayout.tsx:178-188`; `H_mobile_menu_open.png` | A phone customer cannot reach VPS, Domains, DNS, Support, Security, Team or Profile | Render the full grouped navigation in the drawer (the locale switcher is already there) | `AppLayout` | No |
| AR-5 | Backend messages reach the customer in English inside the Arabic UI: the client never sends `Accept-Language`, 9 of 137 error codes are translated, several pages render server text verbatim | RTL_GAP | `api.ts`, `useApiErrorMessage.ts`, `DnsPage.tsx:243`, `WordPressPage.tsx:229,322,385`, `BackupsPage.tsx:153,601`, `CountryCurrencySection.tsx:170-251`; `G_ar_*` captures | Every refusal, validation error and provider condition is untranslated for an Arabic customer | Send `Accept-Language` from the active locale on every request; translate the customer-facing error codes (the catalogue in AJ lists them); keep server sentences as fallback only | `lib/api.ts`, `i18n/*.json`, the four pages | Yes, small: ensure `Localization` middleware honours the header on `api/v1` and that the 137 codes have `ar` strings in `lang/ar/errors.php` |
| AR-6 | The dashboard carries no operational information: no services, no due invoices, no renewals, no tickets, no unread count, no quick actions | MISSING_CUSTOMER_INFORMATION | `DashboardPage.tsx`; `en_home_1440.png`; seed has two open invoices, one ticket waiting for the customer, one subscription ending, one unread notification | The first page a customer sees tells them nothing about their account | Operational overview (AY): summary strip, needs-attention list, services table, recent activity | `DashboardPage` (new widgets) | Yes, one aggregate read (`GET /me/overview`) or four existing list calls |
| AR-7 | No per-resource page for any product: every action lives in a table row; there is no place for plan, cost, addresses, OS, activity, backups, subscription and support for one machine, one domain, one site | MISSING_CUSTOMER_FLOW / INCONSISTENT | `App.tsx:88-177` (only `/vps/:id/console` and `/orders/:id` are detail routes); AK table | The customer cannot see one thing about one resource in one place; every relation (service ↔ subscription ↔ invoice ↔ backups ↔ DNS) is inferred | Resource page pattern with Overview / Networking / Backups / Activity / Billing / Danger zone; list pages link to it | new routes `/vps/:id`, `/dedicated/:id`, `/hosting/:id`, `/wordpress/:id`, `/domains/:domain`, `/dns/:zone` | Mostly no; `services/{id}/events` and existing resources supply the data. Yes for a service → subscription → invoice link on the resources |
| AR-8 | Destructive or disruptive actions with no confirmation: VPS **Force off**, dedicated **Force off**, **Remove DNS record**, Cancel order, Revoke API token, Sign out session / other devices, Withdraw invitation, Revoke currency request | CONFUSING / INCONSISTENT (confirmation model) | `VpsPage.tsx`, `DedicatedPage.tsx`, `DnsPage.tsx`, `OrderDetailPage.tsx:89-98`, `ApiTokensPage.tsx`, `SessionsSection.tsx`, `TeamPage.tsx` | One mis-click pulls the power on a production server or drops a mail record | One confirmation policy (BD-6): plain dialog for disruptive-recoverable, typed name for destructive; apply it to these eight | the eight components, `ConfirmDialog` | No |
| AR-9 | Subscription rows do not name the plan or the service; two identical "Active · KWD 9.000 monthly" rows | MISSING_CUSTOMER_INFORMATION | `SubscriptionsPage.tsx`; `en_subscriptions_1440.png` | A customer can cancel the wrong server with a perfectly designed dialog | Show plan name, product and service hostname/domain on the row and in the cancel dialog | `SubscriptionsPage`, `CancelDialog` | Yes: `SubscriptionResource` gains `plan_name`, `service` summary |
| AR-10 | Invoices have no detail view and Pay does nothing for a `client_secret` gateway response | MISSING_CUSTOMER_INFORMATION / DEAD_UI | `InvoicesPage.tsx:70-76`, `FakePaymentProvider.php:140`; `E_after_pay_click.png` | A customer cannot see what they are paying for, and cannot pay with a card-element gateway | `/invoices/:id` with lines, tax, credits, payments; a payment flow that handles redirect, client secret and failure with a visible state | new `InvoiceDetailPage`, `InvoicesPage` | No new data (the resource has it); a client-secret UI needs the gateway's element (blocked until the real gateway is chosen, AX) |
| AR-11 | Registration collects no country or currency; there is no verification resend; and before verification every customer page except the dashboard answers a generic red "You are not permitted to perform this action." under a banner that only says ordering is unavailable | EXISTING_NEEDS_PRODUCT_LOGIC / MISSING_CUSTOMER_ACTION / CONFUSING | `RegisterPage.tsx`, `RegisterRequest.php:39-42` (accepts them), `routes/api_v1.php:65` (resend exists, unused), `routes/api_v1.php:159` (`verified` on the whole customer group); `shots/j2/004_A_catalogue_unverified.png` | Customer is billed in a currency they never chose and cannot change without a ticket; a lost verification mail is a dead end; the first thing a new customer sees after signing in is a permission error on the price list | Country + currency at registration (allowed list from `billing.currencies`), password rules shown, terms linked, a `/verify-email` page with resend; either let the catalogue be read unverified or make the portal render the verification state instead of firing the guarded reads | `RegisterPage`, new `VerifyEmailPage`, `guards.tsx`, `CataloguePage` | Small if the catalogue read is moved outside `verified`; none otherwise |
| AR-12 | No refresh model for long-running operations: no polling except WordPress, `refetchOnWindowFocus` off, no "updated N s ago", no success acknowledgement after a power or reinstall request | EXISTING_NEEDS_UX | `queries.ts` client options; AA table | "Nothing happened" is the default experience after every asynchronous action | Poll while any row is in a non-terminal state (5–15 s), refetch on focus, show the acknowledgement and the last-updated time | `queries.ts`, list pages | No |
| AR-13 | No customer activity view although `GET /services/{id}/events` exists and each product records its operations | MISSING_CUSTOMER_INFORMATION | route exists in `routes/v1/services.php`; no hook in `queries.ts` | A team of three cannot see who rebooted what; a customer cannot see why a build failed once the notification is read | Activity tab on the resource page and a global "Recent activity" on the dashboard, sourced from service events + notification inbox | resource pages, dashboard | Yes: a customer-safe account-wide activity endpoint (events across services) |
| AR-14 | Five design tokens referenced and never defined (`--surface-base` 34 uses, `--border` 12, `--danger-text` 5, `--warning-text` 2, `--accent` 2): inputs and cards render with transparent backgrounds and no border in places; the destructive warning text in dialogs is not red; unread dot and links inherit | DESIGN_SYSTEM_GAP | `styles/index.css` token block vs `grep --surface-base`; visible in `B_reinstall_dialog` (warning paragraph in default text colour) | Danger reads as ordinary text; form fields disappear against cards | Define the five tokens in both themes, or rename usages to existing ones | `styles/index.css`, ~15 components | No |
| AR-15 | The catalogue never consults product readiness; `AssertProductMaySell` refuses at order time in production only, so a listed-but-not-ready product would fail at "Place order" with a 409 | EXISTING_NEEDS_PRODUCT_LOGIC | `AssertProductMaySell.php:32-54`; catalogue query in `Catalogue` module | Today invisible (prepared products are not in the catalogue). It becomes a customer-facing failure the day one is listed | Catalogue filters by readiness (or marks Coming Soon, BD-1); the product page shows the same | `CatalogueController`, `ProductResource` | Yes, small |

## AS. P2 findings

| # | Finding | Class | Evidence | Recommended change | Routes | Backend |
| --- | --- | --- | --- | --- | --- | --- |
| AS-1 | Product page shows no tax, setup fee (published by `PlanPriceResource.setup`, never rendered), renewal price, location or OS choice, prints plan attributes as raw keys ("backup manual allowance") and memory in MiB, and says nothing about what happens after you order | MISSING_CUSTOMER_INFORMATION | `ProductPage.tsx` | Price breakdown with tax line; a "what happens next" block; plan attributes from the resource | `/catalogue/:slug` | tax preview needs `POST /orders/quote` or a tax rate on `ProductResource` |
| AS-2 | Orders, invoices, subscriptions and services are not linked to each other or to the resource they concern | INCONSISTENT | resources carry the ids; pages do not render links | Link chain on every page; the resource page Billing tab | orders, invoices, subscriptions, services | resources expose `service_id`, `invoice_id` consistently (some already do) |
| AS-3 | Wallet has no transaction ledger | MISSING_CUSTOMER_INFORMATION | `GET /wallet/transactions` unused | Ledger table under the balance | `/wallet` | No |
| AS-4 | No payments view (pending / failed / refunded) | MISSING_CUSTOMER_INFORMATION | `usePayments` unused | Payments list on the invoice detail and a Payments tab under Billing | `/invoices/:id`, billing area | No |
| AS-5 | Hosting: panel type not named, no usage, package slug shown | MISSING_CUSTOMER_INFORMATION | `HostingPage.tsx`; `GET /hosting/{id}/usage` unused | "Open cPanel" / "Open DirectAdmin", usage bars with the staleness sentence, plan name | `/hosting` | `HostingAccountResource` exposes `panel_type`, `plan_name` |
| AS-6 | Domains: no Renew now, no auto-renew toggle, no contacts, no transfer-in although all four endpoints exist; EXPIRING has no countdown or renew button; GRACE / EXPIRED unexplained | MISSING_CUSTOMER_ACTION | `DomainsPage.tsx`; `routes/v1/domains.php` | Add the four actions to the domain card / page; a grace explanation sentence like the redemption one | `/domains` | No |
| AS-7 | Backups not reachable from the machine; picker defaults to the first machine (in the seed, one with no datastore); deletion phrase is a ULID | EXISTING_NEEDS_UX | `BackupsPage.tsx`; `en_backups_1440.png` | Backups tab on the VPS resource page; phrase = hostname or "delete"; remember the last machine | `/backups`, `/vps/:id` | No |
| AS-8 | Untoned or untranslated status badges: `needs_review`, `provisioning_failed`, `payment_failed`, `expired`, `redemption`, `transfer_pending`, `queued_for_provisioning`, `manual_review` grey; `awaiting_dns`, `awaiting_certificate`, `processing`, `quarantined`, `refused` untranslated | INCONSISTENT / RTL_GAP | `StatusBadge` tone map (66 entries) vs the 117 status keys | Complete the tone map and keys from one canonical vocabulary (AJ) | `StatusBadge`, `i18n` | No |
| AS-9 | Reinstall stays enabled while a rebuild is indeterminate/needs review; the API then refuses with a 409 in English | EXISTING_NEEDS_PRODUCT_LOGIC | `VpsOperationGuard.php:133`, `ReinstallState::isTerminal()` includes NeedsReview | `in_flight` or a new `blocked_reason` covers needs-review; disable with the reason | `/vps` | Yes: resource field |
| AS-10 | Domain card dates scramble in Arabic ("092027/03/") | RTL_GAP | `ar_domains_1440.png` | Format dates with the locale and do not wrap them in the LTR technical span | `DomainsPage` | No |
| AS-11 | No unread-notification badge in the shell; "Open" links land on list pages | EXISTING_NEEDS_UX | `AppLayout`, `NotificationsPage`, notification `link` values | Badge on the bell/menu; deep links to resource pages (depends on AR-7) | shell, notifications | notification `link` targets resource routes |
| AS-12 | Team: no permission table; role change and Remove without confirmation or success feedback; Make owner phrase is the account ULID | EXISTING_NEEDS_UX | `TeamPage.tsx` | Role × capability table; confirm role change; phrase = "transfer" or the new owner's email | `/settings/team` | No |
| AS-13 | Security: raw user-agent strings; no confirmations on session revoke; API tokens have no scopes/expiry/IP controls though the resource carries them | EXISTING_NEEDS_UX | `SessionsSection.tsx`, `ApiTokensPage.tsx` | Parse UA to browser + OS; confirm; expose abilities/expiry in the create form | `/security`, `/api-tokens` | No (fields exist) |
| AS-14 | Support form cannot reference a service or invoice though the API accepts both; failures never offer a "get help" link | MISSING_CUSTOMER_ACTION | `SupportPage.tsx`, `StoreTicketRequest` | Service/invoice pickers; "Ask support about this" from failure states with the request id prefilled | `/support`, error surfaces | No |
| AS-15 | `LoadFailure` missing on Team, Support, API tokens and Sessions: a 403 or 500 renders as "No members / No requests / No tokens" | CONFUSING | inventory of `LoadFailure` usage | Use the shared failure state everywhere | four pages | No |
| AS-16 | Loading text "Loading…" in 51 places without `role="status"`; `guards.tsx:11` hardcoded English "Loading" | ACCESSIBILITY_GAP | inventory | One `Loading` component with `role="status"` and translation | shared | No |
| AS-17 | `user.timezone` is collected and never applied; `formatRelative` exists and is unused; no relative times anywhere | EXISTING_NEEDS_PRODUCT_LOGIC | `ProfilePage`, `lib/format.ts` | Apply timezone in the date formatter; relative time on activity and "updated" labels | shared formatter | No |
| AS-18 | 1440-wide layouts leave ~200 px unused each side (`max-w-6xl`); tables with 8 action buttons compress into the middle | DESIGN_SYSTEM_GAP | `*_1440.png` | Wider shell for data pages once a sidebar exists | shell | No |
| AS-19 | Operator detail published to customers: `PaymentResource.provider`, `TicketResource.assigned_to` (operator's name), `wallet_id`, `order_item_id`, `hardware_profile`; 5xx `details` naming nodes / BMC / PXE / drivers | SECURITY_RISK (information, low) | AL table | Strip or generalise these fields; keep `details` operator-only | resources | Yes |
| AS-20 | Catalogue kind badge repeats the card title; the three purchase entry points (catalogue, domains, WordPress) share no wording | INCONSISTENT | `CataloguePage`, `DomainsPage`, `WordPressPage` | One "Buy" vocabulary; the catalogue lists domains and WordPress as families that link to their flows (BD-5) | three pages | No |

## AT. P3 / P4 findings

| # | Finding | Class | Sev | Recommendation |
| --- | --- | --- | --- | --- |
| AT-1 | Hamburger sr-only label is "Dashboard" | ACCESSIBILITY_GAP | P3 | `nav.menu` key |
| AT-2 | 29 raw `<select>` elements with inconsistent styling vs `Field` | DESIGN_SYSTEM_GAP | P3 | `Select` component |
| AT-3 | No toast / transient success message anywhere; every success is a re-render | EXISTING_NEEDS_UX | P3 | polite live-region toast; keep persistent errors inline |
| AT-4 | No skeletons; text-only loading | DESIGN_SYSTEM_GAP | P4 | skeleton rows for tables |
| AT-5 | Plan-change page says "per period" and "MiB" | CONFUSING | P3 | period names; GiB |
| AT-6 | Console page: "Connect" button before any explanation of what a console is | CONFUSING | P4 | one sentence |
| AT-7 | Reverse DNS "Published out of band" wording | CONFUSING | P3 | "Takes effect within a few minutes" |
| AT-8 | Terms checkbox sentence has no link | EXISTING_NEEDS_UX | P3 | link to terms and privacy |
| AT-9 | Notifications preferences on Profile, not on Notifications | INCONSISTENT | P4 | link both ways or move |
| AT-10 | `routes/v1/backups.php:24-28` "No delete" comment above the DELETE route; `docs/customer-capability-matrix.md` lists as impossible four things that are built (redemption, zone import, file restore, currency change) | INCONSISTENT (docs) | P3 | doc refresh in the first wave |
| AT-11 | Throttle limiter names reused across unrelated routes (`subscription-cancel:` on wallet credit, `plan-quote:` on cancel) | INCONSISTENT (backend hygiene) | P4 | distinct limiter names |
| AT-12 | No breadcrumbs, no page-level back links from detail routes (console, order, plan change) | EXISTING_NEEDS_UX | P3 | breadcrumb in `PageHeader` |
| AT-13 | Empty states are one sentence without a call to action (except WordPress) | EXISTING_NEEDS_UX | P3 | EmptyState with primary action |
| AT-14 | Focus is not moved into dialogs on open in two dialogs (credit, WordPress order) | ACCESSIBILITY_GAP | P3 | `autoFocus` on the first control |
| AT-15 | The `Sign out` topbar button and the per-session `Sign out` in Security share a name and appear together on the page | CONFUSING | P4 | "Sign out this device" |
| AT-16 | No status page or incident link | MISSING_CUSTOMER_INFORMATION | P4 | footer link when one exists |
| AT-17 | No dark theme; tokens exist for one theme only | DESIGN_SYSTEM_GAP | P4 | product decision |
| AT-18 | DNS refusal reads "A A record needs an address that can be reached from the internet." (article + type) | CONFUSING (copy) | P4 | "An A record…" |

## AU. Missing customer flows

Flows a customer of a professional cloud provider expects, with no path in
the portal today. Provider-blocked items are in AX, not here.

| # | Flow | Status of the backend | Class | Sev |
| --- | --- | --- | --- | --- |
| AU-1 | Getting started after registration (verify → choose product → first order → "we are building it") | endpoints exist | MISSING_CUSTOMER_FLOW | P1 |
| AU-2 | Resend / recover email verification | `POST /email/verify/resend` exists | MISSING_CUSTOMER_ACTION | P1 |
| AU-3 | View one resource (server, site, domain, zone) on its own page with its relations | data exists across resources | MISSING_CUSTOMER_FLOW | P1 |
| AU-4 | See account activity (who did what, when, with what outcome) | `services/{id}/events`, notifications; no account-wide feed | MISSING_CUSTOMER_INFORMATION | P1 |
| AU-5 | Open an invoice: lines, tax, credits, payments, balance | `InvoiceResource` has it | MISSING_CUSTOMER_INFORMATION | P1 |
| AU-6 | Pay by card with a client-secret gateway; see a failed payment and retry | payment records exist | MISSING_CUSTOMER_FLOW | P1 (gateway-dependent, AX) |
| AU-7 | Renew a domain now; toggle auto-renew; edit contacts; transfer a domain in | four endpoints exist | MISSING_CUSTOMER_ACTION | P2 |
| AU-8 | Choose an OS / template and SSH keys at reinstall (and at order) | `ReinstallRequest` accepts `template_id`, `ssh_keys`; no template listing endpoint | MISSING_CUSTOMER_ACTION | P2 |
| AU-9 | See hosting usage (disk, bandwidth) | endpoint exists | MISSING_CUSTOMER_INFORMATION | P2 |
| AU-10 | See wallet transactions | endpoint exists | MISSING_CUSTOMER_INFORMATION | P2 |
| AU-11 | Edit a DNS record in place | `PATCH` exists | MISSING_CUSTOMER_ACTION | P2 |
| AU-12 | Attach a service or invoice to a support request; ask for help from a failure | request fields exist | MISSING_CUSTOMER_ACTION | P2 |
| AU-13 | Change plan or cancel from the resource itself | subscription endpoints exist | MISSING_CUSTOMER_FLOW | P2 |
| AU-14 | Read the catalogue before the email address is verified | route sits behind `verified` (`routes/api_v1.php:159`) | MISSING_CUSTOMER_FLOW | P1 (AR-11) |
| AU-15 | Download or print an invoice | deliberately not on the API | MISSING_CUSTOMER_ACTION | P2 (product decision BD-8) |
| AU-16 | Top up the wallet | not on the API by design | — | product decision BD-9 |
| AU-17 | Delete the account / export data | not on the API | MISSING_CUSTOMER_FLOW | P3 (legal/product) |
| AU-18 | Monitoring / usage graphs for a VPS | not on the API | MISSING_CUSTOMER_INFORMATION | P3 |
| AU-19 | Snapshots distinct from backups; firewall; additional IPs; SSH key library | not on the API | — | roadmap, not audit gaps |

## AV. Existing flows needing UX work

| Flow | What exists | What it needs | Sev |
| --- | --- | --- | --- |
| Catalogue → product → order | complete pages, server-priced | fix transport (AR-1); tax/total, next-step copy, one vocabulary with domains/WordPress | P1 |
| VPS power / reinstall | buttons, dialog, states | fix transport; acknowledge; poll; confirm Force off; template choice; resource page | P1 |
| Dedicated power / reinstall | same | same | P1 |
| Plan change | quote page with warnings | fix transport; period names; from the resource | P1 |
| Cancel subscription | excellent dialog | name the thing being cancelled | P1 |
| Invoice pay / credit | Pay, credit dialog | detail page; card flow; payments list; mobile reach | P1 |
| 2FA | enrol / confirm / recovery codes / disable | password prompt on enable | P1 |
| Registration | form, mail, banner | country + currency, rules, terms link, verify page | P1 |
| Dashboard | cards | operational overview | P1 |
| Mobile navigation | drawer | full navigation + locale | P1 |
| Domains | search, buy, hold, redeem, indeterminate | renew, auto-renew, contacts, transfer in, DNS link, dates in Arabic | P2 |
| DNS | claim, records, import/export, release | confirm remove, edit, translated validation | P1/P2 |
| Backups | full lifecycle | reach from machine, polling, phrase, policy sentence | P2 |
| WordPress | best product page | translate badges, expose version/SSL, hosting link | P2 |
| Hosting | list + SSO | panel name, usage, plan | P2 |
| Team | invite, roles, ownership | permission table, confirmations, feedback | P2 |
| Security | sessions, history, tokens | UA parsing, confirmations, token scopes | P2 |
| Notifications | inbox | shell badge, deep links | P2 |
| Support | tickets, thread, files | context, reach from failures, mobile | P2 |
| Profile | fields, preferences | apply timezone; country here; company/tax fields | P2 |
| Status vocabulary | 117 keys | complete tones and the 5 missing keys | P2 |
| Errors | request id, inline errors | translated codes, Accept-Language, help link | P1 |

## AW. Backend gaps exposed by the UX audit

These need a backend change (or an API contract change) before the portal can
fix the customer-facing problem.

| # | Gap | Evidence | Needed |
| --- | --- | --- | --- |
| AW-1 | `VirtualMachine` API contract vs frontend type: the resource nests `resources.{vcpu,memory_mib,disk_gib}`; `types.ts` and `VpsPage.tsx:61` read them at the top level, rendering "vCPU · NaN GiB · GiB". Fix on either side; the OpenAPI schema is the arbiter | `VirtualMachineResource.php:81-85`, `types.ts:190-192` | frontend fix, plus a contract test |
| AW-2 | No renewal-price or tax on catalogue prices; tax first appears on the created order | `PlanPriceResource.php:29-32`, `PlaceOrder.php:96-115` | a quote endpoint or tax on the price resource |
| AW-3 | Catalogue does not expose product readiness; sale refused at order time only | `AssertProductMaySell.php:32-54`, `ListPurchasableProducts.php` | catalogue filter or `is_orderable` flag |
| AW-4 | No per-domain operations list; the 201 body of register/renew/redeem/transfer is the only handle on the operation | `DomainController.php:123-194`, `routes/v1/domains.php` | `GET /domains/{domain}/operations` or an `operations` array on the resource |
| AW-5 | No customer-visible activity beyond `GET /services/{service}/events` (unused by the portal) and login history; no per-order, per-invoice, per-domain or per-zone history | `routes/v1/services.php:49`, grep | either expose a customer-safe activity feed derived from audit, or per-resource event lists |
| AW-6 | Registration accepts `country`, `currency`, `locale`, `timezone` but the form sends none; currency silently defaults to `billing.default_currency` and country to null | `RegisterRequest.php:39-42`, `RegisterCustomer.php:94-96`, `RegisterPage.tsx` | frontend fields; backend unchanged |
| AW-7 | `POST /email/verify/resend` exists; no portal control calls it | `routes/api_v1.php:65` | frontend only |
| AW-8 | Endpoints with no caller: `GET /payments`, `GET /hosting/{account}/usage`, `GET /wallet/transactions`, `GET /services/{service}/events`, `PUT /domains/{domain}/contacts`, `POST /domains/transfers` | `queries.ts` (usePayments unused; the others have no hook) | frontend only; DEAD_API_FROM_CUSTOMER_PERSPECTIVE today |
| AW-9 | No `Retry-After`/poll hint on any 202; the only staleness contract is on hosting usage | `IpAddressController.php:149`, `HostingAccountUsageResource.php:95-111` | optional; polling is a frontend decision |
| AW-10 | Throttle-limiter names reused across unrelated routes (`subscription-cancel:` on wallet credit, `plan-quote:` on cancel), so heavy use of one exhausts the other | `routes/v1/billing.php:91,108` | rename the limiters |
| AW-11 | Stale route comment: `routes/v1/backups.php:24-28` still says "No delete" above a DELETE route; `docs/customer-capability-matrix.md` "What a customer still cannot do" lists redemption, zone import, file restore and currency change, all built since | files | documentation fix |
| AW-12 | Domain contacts: the API accepts `PUT /domains/{domain}/contacts` and the `DomainContactRole` enum has four roles, but the registration form captures one registrant and nothing on the domain card lets it be changed | `DomainsPage.tsx:226-308` | frontend; confirm the contact model is complete enough to edit |
| AW-13 | Session `user_agent` is published raw; no parsed device/browser name | `SessionController.php:33-42` | optional server-side parsing, or a client parser |
| AW-14 | `user.timezone` is collected and never applied to any date the portal shows | `format.ts`, `ProfilePage.tsx:124-129` | frontend only |

## AX. Provider-blocked items

Not missing; blocked by a real external dependency, and to be labelled as such
on the customer side rather than hidden or faked.

| Item | Blocker | Customer-facing consequence today |
| --- | --- | --- |
| Paying an invoice by card (Pay button → gateway redirect) | BLOCKED_CREDENTIALS — no payment gateway account | the button exists; in this environment the fake provider answers |
| Every VPS/dedicated/hosting operation reaching a real machine | BLOCKED_HARDWARE / BLOCKED_CREDENTIALS / BLOCKED_LICENSE | the screens are proven against fakes; nothing to change in the portal |
| Backup file browse/download/restore on Proxmox Backup Server | BLOCKED_HARDWARE — `ProxmoxBackupProvider` does not implement `FileLevelBackupProvider` | the "Files" button is disabled with the reason as a tooltip; the reason is server English |
| WordPress staging/clone/push on cPanel or DirectAdmin | BLOCKED_LICENSE / BLOCKED_PROVIDER — no toolkit adapter | the copies card shows `wordpress.copies.unavailable` with the server's reason |
| WordPress install on a real panel | NOT_IMPLEMENTED for the real adapters | a real panel refuses the order |
| Domain registration, transfer, renewal, redemption, auth code at a real registrar | BLOCKED_CREDENTIALS / BLOCKED_PROVIDER | screens proven against the fake; `.sy` answers `unknown` and the screen says so |
| Recovering a `.example`-style namespace with no recorded penalty | BLOCKED_CONFIGURATION | the card says "we will not quote a guess" — correct |
| Forward and reverse DNS at Cloudflare | BLOCKED_CREDENTIALS | proven against the fake; the zone page says nothing takes effect until delegated — correct |
| Email (verification, invitations, notifications) | mail goes to the log; no SMTP relay proven | the portal's "check your email" screens are honest but untestable end to end |
| CDN, object storage, GPU, email hosting, Kubernetes | prepared / readiness-only, `not_implemented` | invisible to customers (AM) |

## AY. Recommended target IA

Recommendation only; nothing below is implemented. The target keeps every
existing route working (redirects for renamed ones) and adds resource pages.

```
Dashboard                      /                 overview: attention list, services, spend, activity
Buy                            /catalogue        five families: Cloud VPS · Dedicated · Hosting · WordPress · Domains
                                                 (prepared families per BD-1: hidden, or Coming Soon without a purchase path)
Services                       /services         index of everything owned, grouped by family, with plan · cost · renewal · status
  Cloud VPS                    /vps, /vps/:id    Overview · Networking · Backups · Activity · Billing · Danger zone
  Dedicated                    /dedicated, /dedicated/:id   Overview · Networking · Activity · Billing · Danger zone
  Shared hosting               /hosting, /hosting/:id       Overview (panel) · Usage · Billing
  WordPress                    /wordpress, /wordpress/:id   Overview (checklist) · Staging · Activity · Billing
  Domains                      /domains, /domains/:name     Overview · Nameservers & DNS · Contacts · Renewal · Transfer
  DNS                          /dns, /dns/:zone             Records · Import/Export · Delegation · Release
  IP addresses                 /ips  (also inside VPS/Dedicated Networking)
  Backups                      /backups (also inside VPS Backups tab)
Billing                        /billing          Invoices · Payments · Subscriptions · Wallet (tabs; existing routes redirect)
  Invoice                      /invoices/:id     lines, tax, credits, payments, balance, pay
  Orders                       /orders, /orders/:id
Activity                       /activity         account-wide customer-safe feed (new endpoint)
Support                        /support          requests, contextual "ask about this"
Notifications                  /notifications    (bell with unread count in the shell)
Account                        /account          Profile · Country & currency · Team · Security · API keys · Notification preferences
                                                 (existing /profile, /settings/team, /security, /api-tokens remain as deep links)
```

Shell: left sidebar on ≥1024 px with the eight top-level groups above, a
collapsible group for Services, the same list as a full-height drawer under
1024 px, a bell with count, the locale switcher visible at every width,
breadcrumbs in `PageHeader` on detail routes. The topbar "More" `<details>`
goes away. All labels already exist in `nav.*`; the sidebar reuses the
current tokens and `Button`/`Badge` components.

## AZ. Recommended target customer journey architecture

One pattern for every product, so the customer learns it once:

```
List page  →  Resource page  →  Action  →  Confirmation (policy in BD-6)  →  Acknowledgement
   │              │                                                              │
   │              ├─ Overview: identity, status sentence, plan, cost, renewal    ├─ row/badge moves to the in-flight state
   │              ├─ Networking / Records / Checklist (product-specific)        ├─ page polls until terminal
   │              ├─ Backups / Staging / Renewal (product-specific)             ├─ terminal: success sentence or
   │              ├─ Activity: events for this resource                         │   failure sentence + "what now" + "Ask support"
   │              ├─ Billing: subscription, next invoice, change plan, cancel   └─ indeterminate: "do not retry; we are checking"
   │              └─ Danger zone: reinstall, force off, release, delete
   └─ every row links to the resource page; row actions limited to the 2–3 most common
```

Journey-level rules the audit recommends the implementation to hold to:

- **Purchase**: catalogue → product → order summary with tax → invoice →
  payment (redirect or card element) → order page shows "we are building
  it, usually N minutes, you will get an email" → resource page opens when
  active.
- **Operate**: every asynchronous request is acknowledged in place, polled,
  and finished with a sentence; failures link to support with the request id.
- **Pay**: invoice detail is the unit; Pay and Use credit live there; a
  failed payment is a visible object with a retry.
- **Change / cancel**: from the resource's Billing tab and from Subscriptions;
  the dialog names the resource.
- **Recover**: verification resend, password reset, lost 2FA (recovery codes)
  and "contact support" reachable from the sign-in page.
- **Team**: permissions table; every role-affecting action confirmed; activity
  shows the actor.
- **Arabic**: same journeys, translated refusals, dates and numbers by locale.
- **Mobile**: same navigation, resource pages stack, row actions become a
  per-row menu.

## BA. Implementation waves (proposed, not started)

| Wave | Goal | Contents | Backend | Exit test |
| --- | --- | --- | --- | --- |
| **0 — Make the existing portal true** | Every existing control does what it says | AR-1 header transport; AR-2 2FA password prompt; AR-3 VPS spec; AR-14 tokens; AR-8 confirmations on the eight actions; AS-9 reinstall disabled while needs-review; AS-15 LoadFailure everywhere; AT-10 doc refresh | none (AS-9 one resource field) | E2E: place an order, press each power action, confirm a reinstall, change a plan, enable 2FA, all against the fake providers; vitest render of VPS row |
| **1 — Reach and language** | Every destination reachable on every device in both languages | AR-4 drawer navigation + locale; AR-5 Accept-Language + translated codes + no raw server text; AS-8 status tones/keys; AS-10 Arabic dates; AS-16 loading roles; AT-1 | Localization middleware honours the header; `ar` strings for the error catalogue | Playwright mobile project (390) and Arabic project running the existing specs; snapshot of every `errors.*` key in both languages |
| **2 — Money is legible** | Finance users can see and settle what they owe | AR-10 invoice detail + card flow states; AR-9 named subscriptions; AS-3 wallet ledger; AS-4 payments; AS-2 links between order/invoice/subscription/service; AR-11 registration country/currency + verify page | `SubscriptionResource` plan/service summary; a tax preview or rate | E2E: open invoice, pay by redirect, pay by client secret (fake), use credit, see the ledger line, cancel the right subscription by name |
| **3 — Resource pages and the sidebar** | One place per thing; one navigation | AY shell; AR-7 resource pages for VPS, dedicated, hosting, WordPress, domains, DNS; AS-7 backups tab; AU-8 template choice; AS-5 hosting usage; AS-6 domain actions; AU-11 DNS edit | account-wide activity endpoint (AR-13); `panel_type`/`plan_name`; template listing | E2E per resource page; visual regression at 1440/1024/390 en/ar |
| **4 — Dashboard, activity, feedback** | The portal tells the customer what is happening | AR-6 dashboard; AR-13 activity; AR-12 polling/refetch/acknowledgement; AS-11 badge + deep links; AS-14 contextual support; AT-3 toast; AS-17 timezone/relative time | `GET /me/overview` (or compose); notification links to resource routes | E2E: reboot → row moves → completes without reload; failure → "Ask support" carries the request id |
| **5 — Account and polish** | Professional finish | AS-12 team permissions; AS-13 sessions/tokens; AS-19 field stripping; AT-2 select; AT-4 skeletons; AT-12 breadcrumbs; AT-13 empty states; AS-18 shell width | resource field removal | axe pass on every route; keyboard walk of every dialog |

Waves 0 and 1 are the launch gate; 2 and 3 are the "professional" gate; 4
and 5 are the difference between working and trusted. Nothing in wave 0
requires a design decision; wave 3 requires BD-1, BD-4, BD-5 and BD-6.

## BB. Regression risks

| Risk | Where | Mitigation |
| --- | --- | --- |
| Moving the idempotency key to the header changes the retry semantics the E2E suite never exercised; a duplicate header on a retried request must still map to the same order | AR-1 | contract test per guarded route: same key twice → same 2xx body, different key → new resource |
| Confirmation dialogs added to Force off / DNS remove break the existing specs that click those buttons | AR-8 | update `vps.spec.ts`, `dns.spec.ts`; keep the button names |
| Replacing the topbar with a sidebar changes every `getByRole('link', { name })` locator that relies on the "More" `<details>` | AY | the 27 specs use names, not structure; verify after wave 3 |
| Translating error codes changes strings the specs assert verbatim ("Please correct the highlighted fields", "not held by this account") | AR-5 | assert on `errors.*` keys through the i18n instance |
| Accept-Language on every request changes the language of server-generated notification bodies if the backend derives locale from the request | AR-5 | keep notification locale from the account, not the request |
| Resource pages duplicate list-row actions; two paths to one mutation doubles the surface for the header/transport bug class | AR-7 | one mutation hook per action, used by both |
| Polling multiplies request volume on list pages; rate limiters named in AT-11 are shared | AR-12 | poll only rows in non-terminal state; distinct limiter names |
| Defining `--surface-base` and friends changes the look of ~50 elements that currently render transparent | AR-14 | visual regression captures before/after at three widths |
| Registration currency choice must be restricted to the enabled billing currencies or `RegisterCustomer` will store a currency the ledger cannot price | AR-11 | validate against `billing.currencies` |
| Readiness in the catalogue must not hide products that are ready in production but marked NO in the local matrix | AR-15 | environment-aware source of truth already in `AssertProductMaySell` |

## BC. Tests required during implementation

- **Contract**: for every route using `ReadsIdempotencyKey` and `PlaceOrderRequest`, a request with the header succeeds and a request without it fails with a message naming the header.
- **Unit (vitest)**: VPS row renders vCPU/GiB from `resources`; `StatusBadge` has a tone and a key in both languages for every value in the canonical vocabulary (AJ); `useApiErrorMessage` returns a translated string for every code in the catalogue; `api.ts` sends `Accept-Language` matching the active locale.
- **E2E (Playwright, existing project)**: register → verify (mail from log) → order → invoice → pay (fake redirect and fake client secret) → order active; reboot / shut down / start / force off with confirmation; reinstall confirmed and observed to reach a terminal state under the fake; plan change completed; cancel the named subscription; enable 2FA end-to-end; DNS remove with confirmation; domain renew; wallet ledger line after credit use.
- **E2E mobile project** (390×844): the full navigation, one flow per family, the reinstall and credit dialogs.
- **E2E Arabic project**: the same specs with `lng=ar`, asserting no English string from the error catalogue appears and dates are not scrambled.
- **Accessibility**: axe on every route in both languages with zero serious violations; keyboard-only walk of every dialog (open, type phrase, confirm, escape).
- **Visual regression**: every route at 1440 / 1024 / 390 × en / ar before and after the token and shell changes.
- **Leak test**: every customer resource asserted against a deny-list of operator fields (`assigned_to`, `provider`, `hardware_profile`, node/BMC/PXE words in `details`).
- **Docs**: `docs/customer-capability-matrix.md` regenerated from the route list, not by hand.

## BD. Questions requiring product-owner decision

| # | Question | Options | Recommendation | Reason |
| --- | --- | --- | --- | --- |
| BD-1 | Prepared products (CDN, Object Storage, GPU, Email Hosting, Kubernetes) in the catalogue | hide entirely / "Coming soon" card without a purchase path / waitlist | **Hide** until READY_TO_SELL=YES; "Coming soon" only for a family with a dated public commitment | A card that cannot be bought erodes trust more than an absent card; the readiness matrix already drives `AssertProductMaySell` |
| BD-2 | Support model | generic ticket form / service-first ("ask about this server") / both | **Both**: contextual entry from resources and failures, generic form under Support | The API already accepts `service_id`/`invoice_id`; contextual entry is where a customer needs it |
| BD-3 | Activity | global only / per-resource only / both | **Both** | Team accounts need the global view; troubleshooting needs the per-resource one; one endpoint serves both with a filter |
| BD-4 | Dashboard emphasis | spend-first (DigitalOcean style) / infrastructure-first (Hetzner style) / attention-first | **Attention-first**, then services, then spend | The seed shows the typical account: two invoices due, one ticket waiting, one server needing review; these are what a customer must see first |
| BD-5 | Catalogue grouping | by technical kind (vps/dedicated/hosting) / by customer goal (host a site, run a server, own a name) / five families | **Five families** with one-line goals, domains and WordPress included | Three purchase entry points today; one catalogue removes the "where do I buy a domain" question |
| BD-6 | Typed-confirmation policy | typed name for everything destructive / dialog only / graded | **Graded**: typed resource name for irreversible (reinstall, delete backup, release zone, push, cancel now, transfer ownership); plain dialog for disruptive-recoverable (force off, remove record, revoke token/session, cancel order, remove member); no dialog for reversible toggles | Matches the customer's real risk; ULID phrases replaced by names or the word "delete" |
| BD-7 | Currency at registration | choose freely from enabled currencies / derive from country / fixed KWD | **Derive from country with an override** from the enabled list | Avoids a KWD default for a non-Kuwaiti customer and keeps the operator-approved change flow for later changes |
| BD-8 | Invoice download / PDF | none (current) / HTML print view / PDF generation | **HTML print view** now, PDF later | Finance users need a printable record; print CSS costs little and needs no PDF service |
| BD-9 | Wallet top-up | keep "no top-up" / allow prepaid top-up | keep for launch | Prepaid credit adds refund and accounting rules the billing doc does not cover |
| BD-10 | Sidebar vs topbar | keep topbar + More / sidebar | **Sidebar** (AY) | 21 destinations do not fit a topbar; every reference provider uses a sidebar for the same reason |
| BD-11 | Monitoring graphs for VPS | out of scope / basic CPU-RAM-net from Proxmox | out of scope for launch | Requires a metrics pipeline not in the architecture doc |
| BD-12 | Dark theme | no / yes | no for launch | One theme done well before two |
| BD-13 | Account deletion / data export | ticket-only / self-service | ticket-only for launch, self-service on the roadmap | Legal review needed before self-service |

## BE. Final verdict

### The 22 questions

1. **Can a new customer register, verify, log in and understand what to do next?** Register and log in: yes. Verify: only if the mail arrives (no resend). Understand what to do next: no; before verification every page but the dashboard shows "You are not permitted to perform this action", and after it the dashboard is a settings page and nothing points to the catalogue (G, H, AR-6, AR-11).
2. **Can a customer discover the products and buy one?** Discover: yes for VPS, dedicated and hosting; domains and WordPress are bought from their own pages. Buy: **no**; "Place order" fails with a field error that names no field (AR-1).
3. **Can a customer see what they own in one place?** `/services` lists it without plan, cost, renewal or a link to the resource (AO).
4. **Can a customer operate a VPS?** Console yes. Power, reinstall: **no** today (AR-1). Backups: yes. Plan change: no (AR-1). Spec: garbage (AR-3).
5. **Can a customer operate a dedicated server?** Same as VPS without console and backups (P).
6. **Can a customer use shared hosting?** Open the panel, yes; know which panel, see usage, no (Q).
7. **Can a customer run WordPress?** Yes; the best-explained product in the portal (R).
8. **Can a customer buy, hold, recover and leave a domain?** Buy, hold, recover, indeterminate: yes. Renew now, auto-renew, contacts, transfer in: no controls (S, AS-6).
9. **Can a customer manage DNS safely?** Manage: yes. Safely: record removal has no confirmation (T, AR-8).
10. **Can a customer protect and restore data?** Yes; backups are the strongest lifecycle on the portal, reachable only from their own page (U).
11. **Can a customer understand what they are paying and pay it?** Understand: partly (no invoice detail). Pay: by redirect only; silently nothing otherwise (K, L, AR-10).
12. **Can a customer change or cancel a subscription with confidence?** Cancel: the dialog is excellent, the row does not say what is being cancelled (AR-9). Change: fails (AR-1).
13. **Can a customer run a team?** Yes, with role hints; without a permission table or confirmations (W).
14. **Can a customer secure the account?** Password, sessions, tokens, sign-in history: yes. 2FA: **cannot be enabled** (AR-2).
15. **Can a customer get help?** Yes, through a competent ticket page that is not reachable on a phone and not linked from any failure (Z).
16. **Does the portal tell the customer what is happening during long operations?** In words, often well; in time, no (no polling, no acknowledgement) (AA, AR-12).
17. **Does the portal work on a phone?** It renders; it does not work: 17 destinations unreachable, actions off-screen (AF, AR-4).
18. **Does the portal work in Arabic?** The interface, yes, fully. The messages, no (AG, AR-5).
19. **Is it accessible?** Labels, roles and focus are largely right; loading regions, the hamburger label and two dialog focus cases are not (AH).
20. **Is it one design system?** One set of components used consistently; five tokens undefined; three list idioms and four confirmation strengths (AI, AK, AR-14).
21. **Does the customer ever see the operator's world?** Not the secrets. A few fields and 5xx details leak names of internal things (AL, AS-19).
22. **Is the portal at a professional cloud-provider launch quality today?** **NO.** Exact blockers: (1) no order can be placed and no VPS/dedicated power, reinstall or plan change can be executed from the portal because the idempotency key is sent in the body and read from the header (AR-1); (2) 2FA cannot be enabled (AR-2); (3) every VPS row shows "NaN GiB" (AR-3); (4) a phone customer cannot reach 17 of 21 destinations (AR-4); (5) an Arabic customer reads every refusal in English (AR-5); (6) the dashboard is empty of account state (AR-6); (7) no resource has a page (AR-7); (8) eight disruptive actions have no confirmation (AR-8); (9) subscriptions cannot be told apart (AR-9); (10) invoices have no detail and card payment is silent (AR-10); (11) no currency choice, no verification resend, and a permission error on every page before verification (AR-11); (12) no refresh model for asynchronous operations (AR-12); (13) no activity view (AR-13); (14) undefined design tokens leave danger text un-red and inputs transparent (AR-14); (15) the catalogue does not consult readiness (AR-15). With waves 0 and 1 done the answer becomes CONDITIONAL; with waves 2 and 3 done, YES.

### Verdict block

```
Customer Portal Product & UX Audit
Status: AUDIT_COMPLETE
Application code changed: NO
Current launch-quality verdict: NO (15 P1 blockers; 0 P0)
P0 blockers: 0
P1 blockers: 15
P2 findings: 20
P3/P4 findings: 18
Pages audited: 30 routes (28 customer routes + console + not-found)
Journeys audited: 15 (Registration, First purchase, VPS, Dedicated, Hosting, WordPress, Domains, DNS, Backups, Billing, Subscription change, Cancellation, Team, Security, Support) across walkthroughs A–H
Desktop: renders correctly at 1024/1280/1440; purchase, power, reinstall, plan change and 2FA enable fail at the last step
Mobile: renders without horizontal page scroll at 360/390/430/768; 17 of 21 destinations unreachable; row actions off-screen on every list
English: complete
Arabic-RTL: interface complete and mirrored; backend messages untranslated; domain dates scrambled; five badges untranslated
Accessibility: labels/roles/focus largely correct; 51 unannounced loading regions; hamburger mislabelled; two dialogs without initial focus
Customer flow completeness: 19 missing flows/actions (AU), 6 of them with the backend already built
Backend gaps discovered: 14 (AW-1…AW-14), 9 of them small resource/field changes
Provider-blocked items: 10 (AX)
Recommended implementation waves: 6 (0 truth, 1 reach & language, 2 money, 3 resource pages & sidebar, 4 dashboard & activity, 5 account & polish); waves 0–1 are the launch gate
Recommended next action: approve wave 0 (no design decisions required; all fixes are transport/contract corrections with E2E coverage), decide BD-1, BD-4, BD-5, BD-6, BD-7 and BD-10 before wave 2 begins
```
