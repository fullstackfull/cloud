# Customer Portal Closure — Wave 2 — Money Is Legible

This report records what Wave 2 changed, how each claim was verified, and what
it deliberately did not do. It does not restate or revise the Wave 0 and Wave 1
reports or the original audit; they stand as written.

---

## A. Starting HEAD

```text
5c69f1827c49804aa3a90549b58823588bbcd2e8
```

The Wave 1 report commit. Its CI run — number 135, id 34474350338, attempt 1 —
was still in progress when the Wave 2 brief was prepared, and was re-checked
before any Wave 2 code was written: it completed with conclusion `success` on
all jobs. The working tree was clean and level with
`origin/claude/hv-t6hq1p` at that commit.

## B. Ending code HEAD

```text
0b0ecf1ad584f5b5fa9384a324d630f0e0d59617
```

Six commits, this report's own excluded:

| Commit | What it carries |
| --- | --- |
| `7aa2a49` | Backend: registration country and currency, verification and the unverified capability, subscription identity, invoice documents, the order quote, the typed payment contract, the controlled gateway, the test mail outbox |
| `e70077b` | Frontend: invoice detail and print, payments history, wallet ledger, subscription identity, the order chain, the catalogue breakdown, registration selects, the verification page |
| `3e7f79c` | Browser journeys A–H across the three projects, and their own seeded fixtures |
| `69b3a46` | The wallet fixture credited through its ledger, three more frontend tests, and the browser suite's run artifacts untracked |
| `b2e46ca` | The country/currency change request kept able to explain a currency nothing is priced in, rather than refusing it as invalid input |
| `0b0ecf1` | A separate seeded account for the journeys that spend money, and the locator and arrangement corrections that followed |

## C. Scope

Closed in this wave:

- **AR-9** — a subscription now says what it is for.
- **AR-10** — an invoice is a document, and paying one has a visible outcome.
- **AR-11** — registration collects a billing country and establishes the
  currency out loud.

Supporting findings closed as part of the above:

- **AS-2** — order, invoice, payment, subscription and service are linked by
  the server rather than inferred by the reader.
- **AS-3** — the wallet publishes its ledger.
- **AS-4** — payment history exists, including failures.
- **AS-1 (money subset only)** — the price breakdown before purchase: unit
  price, setup fee, billing period, discount, tax, total, the renewal figure,
  and what happens after payment.

## D. Explicit out-of-scope

Untouched, on purpose, and named here so their absence is not read as an
oversight:

- the future sidebar / information architecture rework (AR-6, AR-7);
- resource detail pages (AR-12);
- the dashboard rework and the activity feed (AR-13);
- polling and live status;
- customer-initiated wallet top-up;
- a PDF generation service;
- real payment providers and real provider credentials;
- dedicated power idempotency, which remains an architecture item;
- anything belonging to Wave 3.

---

## E. Money invariants

| Invariant | How it holds | Evidence |
| --- | --- | --- |
| No floats for money | Amounts are BIGINT minor units with an ISO-4217 code, wrapped in the `Money` value object over brick/money | `LayeringTest::money_is_never_cast_to_a_float` |
| The API publishes minor units, currency and a formatted string | `SerialisesMoney::moneyOfMinor()` on every money field of every resource in this wave | `AnInvoiceIsADocumentTest::every_money_field_is_published_as_minor_units_and_a_currency` |
| The portal formats money and never computes it | New source gate walks every file under `apps/web/src` and rejects `parseFloat`, `Number(<amount>)` and arithmetic on amount-shaped names; it also asserts it can still recognise those patterns, so a gate that stopped working would fail | `apps/web/src/lib/__tests__/the-portal-never-computes-money.test.ts` |
| Rounding is the server's | `PricingEngine` allocates discount proportionally and applies tax per line with an explicit `RoundingMode`; the quote and the order both go through it | `QuoteAndOrderAgreeTest` |
| Three-decimal currencies are respected | KWD renders as `9.000`, and the invoice document asserts the shape | `AnInvoiceIsADocumentTest` |

## F. AR-9 Subscription identity

`SubscriptionResource` now publishes, alongside the price the agreement
records:

- `plan` — the plan the subscription is on, named in the language of the
  request, from the plan the subscription points at rather than whatever the
  catalogue is selling today;
- `product` — the product that plan belongs to, with its kind;
- `services` — every service the agreement pays for, each with the name the
  customer uses for it: the VPS hostname, the hosting account's primary
  domain, the dedicated server's serial, or the WordPress site's domain.

Identities are resolved by `ServiceIdentities`, which fetches them in one
query per resource kind for a whole page rather than one per row, and hangs
each answer on its own service model. A service that has not been created yet
has no identity and the API says `null`; the screen says "being created"
rather than inventing a name.

The subscriptions list leads with that identity, and the cancellation dialogue
repeats it, so the confirmation is about a specific machine.

Evidence: `TwoSubscriptionsAreTellableApartTest` (five cases, including the
Arabic plan name and the price coming from the subscription record rather than
from a plan whose price has since risen);
`two-subscriptions-are-tellable-apart.test.tsx`; browser journey G.

## G. AR-10 Invoice detail

`GET /invoices/{invoice}` is a document. It carries:

- the **billing snapshot** taken when the invoice was issued — display name,
  legal name, tax number, billing email and address — with the platform's
  internal customer id stripped;
- its **lines**, each with quantity, unit amount, discount, tax, the tax rate
  as the string the invoice carries, and the period it covers;
- the **payments** recorded against it, including the ones that failed, with
  the provider's failure code (never the provider's prose);
- the **wallet credit** applied to it, signed, with the balance after each
  entry.

The list endpoint deliberately carries none of that: twelve invoices have no
use for twelve addresses.

`/invoices/:id` renders it, and the invoices list gained a link to it.

Evidence: `AnInvoiceIsADocumentTest` (six cases);
`an-invoice-is-a-document.test.tsx` (five cases); browser journeys C, E, F, H.

## H. Invoice print

`/invoices/:id/print` is the printable copy: HTML, authenticated, and outside
the application layout so a printed page carries the document rather than the
navigation. The customer's own browser turns it into paper or a PDF.

No PDF service was added — approved decision BD-8. A headless browser in
production would mean a font pipeline, a queue and a new way for an invoice to
be unavailable, for a document the browser already renders in both languages.

Evidence: browser journey H asserts the number, the address, the line table and
the absence of navigation.

## I. Payment initiation / next-action contract

`POST /invoices/{invoice}/payments` answers with a typed next action. The
vocabulary was `none | redirect | client_secret`, which collapsed three unlike
situations into "none". It is now five:

| `next_action.type` | Meaning | What the portal does |
| --- | --- | --- |
| `redirect` | The provider wants the customer on its page | Leaves for `redirect_url` |
| `client_confirmation` | The provider wants the intent confirmed from here | Shows a confirmation panel with the client credential |
| `completed` | The provider says it already has the money | Says so, and does not say "paid" |
| `failed` | The provider refused it | Names the reason from the portal's own catalogue |
| `pending` | Nobody has decided yet | Asks the customer to wait, and offers no retry |

`is_awaiting_provider` is true for `pending` alone, which is the one state in
which a client must not open a second payment.

Evidence: `StartInvoicePaymentEndpointTest`,
`PayingThroughTheControlledGatewayTest`, `the-five-answers-to-paying.test.tsx`
(five cases, one per answer).

## J. Payment confirmation security

Unchanged and re-asserted: no route lets a client tell the platform that a
payment succeeded. Settlement arrives either through the signed webhook at
`POST /webhooks/{provider}` or through `ConfirmPaymentFromReturn`, which is the
platform asking the provider and has no HTTP caller.

The controlled gateway added in this wave is held to a stricter rule rather
than exempted from the gate:

- it is refused outright in production (`FakeProviderGuard`);
- it is refused whenever a real provider is configured, asserted by a test that
  starts a payment, switches the configured provider to Stripe, and watches
  approve, decline and confirm all answer 404 with the invoice untouched;
- a client credential that does not belong to the intent confirms nothing;
- approving does not mark anything paid: it records the decision on the
  provider's side and emits a genuinely signed webhook, which settles the
  invoice through the same ingestion path a real provider's callback uses.

Webhook idempotency is unchanged: the provider event id is claimed in
`webhook_events` before handling, and a repeat is reported as already
processed. A double approval settles once.

Evidence: `PaymentConfirmationIsServerSideOnlyTest` (five cases, two new),
`PayingThroughTheControlledGatewayTest::authorising_twice_settles_once`, browser
journey C and the "browser-claimed success" journey.

## K. Payment failure and recovery

A refusal is visible in three places: in the answer to the payment request
(`next_action.type: failed` with a code), on the invoice document's payment
table, and in the payments history. The portal turns the provider's code into
its own sentence in both languages; the provider's English prose is never shown
to an Arabic-speaking customer.

An invoice with a failed payment stays payable and offers another attempt. A
payment in an indeterminate state does not: the screen says "We are checking
the payment. Do not pay again yet." and offers no button.

Evidence: `PayingThroughTheControlledGatewayTest::a_decline_is_visible_and_leaves_the_invoice_unpaid`,
`the-five-answers-to-paying.test.tsx`, browser journey F, Arabic money spec.

## L. AS-4 Payments history

`/payments` lists every payment on the account, newest first: date, type,
method, amount, status, the reason when there is one, and a link to the
invoice. Refunds are shown and cannot be started from the portal — a refund is
a conversation, not a button.

Evidence: the payments-history browser test; the endpoint's own
`ListPaymentsRequest` coverage from earlier waves is unchanged.

## M. AS-3 Wallet ledger

`/wallet` now shows the ledger behind the balance: date, type, description, the
signed amount with its direction in words, the balance the server recorded
after that entry, and a link to the invoice an entry paid.

The portal does not add the rows up and compare them with the balance: the
balance is the server's and the ledger is its history. The E2E fixture was
changed to credit the wallet through `WalletLedger` rather than writing a
balance onto the wallet row, because a balance with no history is a fixture
that cannot exercise this screen.

Evidence: `the-wallet-ledger.test.tsx` (four cases); the phone wallet journey.

## N. Mixed-payment lifecycle

Wallet credit and a card are different payment records and stay that way. A
40.000 KWD invoice against a 15.000 KWD balance applies the smaller of the two
server-side, leaves the remainder payable, and the card pays exactly the
remainder — never the original total. The invoice document then shows two
payment rows, one against the provider `wallet` and one against the gateway,
plus the ledger entry from the wallet's own side.

Evidence: `PayingThroughTheControlledGatewayTest::credit_and_a_card_settle_one_invoice_and_the_document_shows_both`,
`AnInvoiceIsADocumentTest::wallet_credit_and_a_card_appear_as_two_different_rows`,
browser journeys D and E.

## O. AS-2 Commercial relationship graph

Relationships are published by the server, never inferred from amounts or
dates:

- `Order` carries `invoice_id`, `invoice_number` and the `services` it produced,
  each with its identity and state;
- `Invoice` carries `order_id` and `subscription_id`, its payments and its
  wallet credits;
- `Subscription` carries its plan, its product and its services;
- a wallet ledger entry carries the invoice it paid;
- a payment carries the invoice it is for.

The order screen shows the chain as a customer reads it: this order produced
that invoice, the payment is received or is not, and these services exist
because of it.

## P. Pre-order quote / pricing clarity

`POST /orders/quote` prices a basket without buying it. It runs the same path as
placing the order: the same catalogue lookups, the same readiness and stock
checks, the same coupon validation, the same `PricingEngine`. That is enforced
structurally — the pricing pipeline was extracted from `PlaceOrder` into
`OrderPricing`, which both callers use — and asserted by a contract test that
prices one basket both ways and compares subtotal, discount, tax, total,
currency and every line figure.

A quote writes nothing: no order, no held stock, no coupon use. It says so in
its own payload (`meta.creates_nothing`), and it takes no idempotency key
because there is nothing that could happen twice.

Evidence: `QuoteAndOrderAgreeTest` (six cases);
`the-price-before-you-buy.test.tsx` (five cases); browser journey C compares the
quoted figure with the invoice the order produced.

## Q. Tax and setup fee

Both are named separately, before purchase and on the invoice:

- the setup fee is published as its own figure on the quote and as
  `unit_setup` per line, because a one-off fee folded into a total reads as the
  platform having got the price wrong;
- tax is published with its rate as a string and its name from the tax rule, so
  the screen can say "Tax (VAT)" rather than an unexplained increase;
- the renewal figure drops both the setup fee and the coupon, and the screen
  says that it does.

Evidence: `QuoteAndOrderAgreeTest::the_renewal_figure_drops_the_setup_fee_and_the_coupon`.

## R. Registration country

`country` is now required and validated against the ISO-3166-1 alpha-2 list in
`config/geography.php` — 249 officially assigned codes. `GET /registration/options`
publishes that list with the currency recommended for each code, and the same
list validates the submission, so a browser cannot submit a country it was
never offered.

Country names are deliberately absent from the server's answer: the portal
labels each code with the browser's own CLDR data through `Intl.DisplayNames`
and sorts by the name the reader sees, which is neither code order nor an
English order.

Evidence: `RegistrationDecidesTheCurrencyOutLoudTest` (nine cases);
`registration-decides-the-currency.test.tsx` (six cases); browser journeys A
and B.

## S. Registration currency

The currency comes from two explicit inputs: the country the customer chose and
the mapping a human wrote in `config/billing.php`. There is no algorithm and no
locale guess. The screen shows the recommendation before anything is submitted,
and says which of the two cases it is:

- the country has a row of its own — "We bill customers in that country in
  SAR";
- the country has no row — "We do not price in that country's own currency, so
  we bill in USD".

The customer may override the recommendation with any currency the platform
bills in. A currency the platform does not bill in is refused however it is
submitted, which is the DevTools case: `Rule::in` against
`billing.currencies`, and `BillingCurrencies::assertEnabled()` again inside
`RegisterCustomer`, because that action is also reachable from a seeder and a
console command.

A test asserts that every currency the configured country map recommends is one
the platform actually bills in, so a half-finished edit to the config fails a
test rather than offering a currency the provider cannot take.

## T. Verification/recovery

Four changes:

1. The verification endpoint answers a browser with a redirect to
   `FRONTEND_URL/verify-email?status=…` and an API client with JSON, chosen by
   `Accept`. A customer clicking the most important link the platform sends no
   longer lands on a JSON document.
2. An expired or tampered signature — which fails at the `signed` middleware,
   before any controller runs — also redirects a browser, to
   `?status=expired`, and still answers 403 to an API client.
3. `/verify-email` is a real page: it says what the server did with the link,
   shows the address the link went to so a typo is visible, and offers a resend
   button that holds for sixty seconds after use.
4. The layout's verification banner links to that page instead of merely
   stating the problem.

The page never treats the query parameter as proof: the account's own
`email_verified` flag, from the API, decides what it offers.

Evidence: `AnUnverifiedCustomerCanLookButNotBuyTest` (seven cases);
`verify-email-page.test.tsx` (six cases).

## U. Unverified catalogue policy

Exactly one capability moved out of the `verified` group: reading the
catalogue. Orders, quotes, invoices, subscriptions, payments, wallet, services
and every other business route still require a verified address.

The refusal now has its own code. Laravel's own middleware aborts with a bare
403, which the renderer necessarily reported as `auth.forbidden` — the same
answer a customer gets for asking to do something their role forbids. The
platform's own `EnsureEmailIsVerified` raises `auth.email_unverified` instead,
with the address in the context, so the portal can show the verification step
rather than a permissions dead end. A test asserts the two refusals differ.

## V. Real signed email-verification E2E

Browser journey A registers a new customer, reads the mail the platform
actually sent, follows the signed link out of it, and lands verified.

The mail is delivered by `OutboxTransport`, a Symfony transport that writes each
message to a file as JSON. It exists because the alternatives prove less:
`UPDATE users SET email_verified_at` proves a column can be written and would
pass with a broken signed URL, and parsing the `log` mailer's output passes
while the mail body is unreadable. The transport refuses to be constructed when
`APP_ENV=production`, is reachable only when `MAIL_MAILER` names it, and no
endpoint in the application reads the file — the harness does.

## W. Cross-currency review

- Wallets are per customer and per currency and are never mixed or converted. A
  wallet holding 100.000 USD applies nothing to a KWD invoice; the quote reports
  zero applicable and the payment is refused with
  `wallet.no_credit_in_currency`.
- An order is priced in the customer's own currency, and a plan with no price in
  it is refused with `checkout.terms_unavailable` rather than converted.
- `PricingEngine` refuses to mix currencies in one basket.
- Registration cannot select a currency outside the enabled list.

Evidence: `PayingThroughTheControlledGatewayTest::a_wallet_in_another_currency_pays_nothing_towards_this_invoice`,
`QuoteAndOrderAgreeTest::a_quote_prices_in_the_customers_own_currency_and_refuses_when_there_is_no_price_in_it`,
and the wallet suite's own `credit_is_never_converted_between_currencies`.

## X. Immutability review

An issued invoice does not change when the world does. The document renders the
billing snapshot captured at issue, and a test changes the customer's display
name, address and tax number afterwards and asserts that none of the new values
appears anywhere in the document body. Order and invoice lines are snapshots of
what was bought, and a subscription's price is its own record rather than the
plan's current price.

Evidence: `AnInvoiceIsADocumentTest::the_document_carries_the_billing_details_as_they_stood_when_it_was_issued`,
`TwoSubscriptionsAreTellableApartTest::the_price_is_the_subscriptions_own_and_not_what_the_plan_costs_today`.

## Y. Tenant isolation

Every new read is scoped through a relation hanging off the acting customer, so
another tenant's id matches no row and the request answers 404 rather than 403 —
a 403 would confirm the row exists.

Asserted for another account's invoice, another account's subscription, and
another customer's payment reference on the controlled gateway (which appears in
a redirect URL, and therefore in a browser's history and any shared
screenshot).

## Z. Security review

- No provider secret is in the repository. `config/payments.php` holds
  behaviour — currencies, tolerances, which confirmation shape the fake asks
  for — and reads any real credential from the environment at the point of use.
- The client secret appears in one response, for the request that created the
  intent, and is never persisted or logged. The controlled gateway's confirm
  endpoint compares what the browser presents against the credential derived
  for that intent.
- Raw webhook payloads are redacted through `SecretRedactor` before being
  stored, unchanged from earlier waves.
- No card number or CVV is accepted, stored or logged anywhere in the platform;
  card data never reaches this application.
- The new error sentences carry no internal identifiers; the customer error
  catalogue gate asserts that for every code.
- `current_password` is still never logged, echoed or persisted.

## AA. Desktop

Ten desktop journeys, all passing: journeys A–H, a browser-claimed success that
settles nothing, and the payments history.

## AB. Mobile

Three phone journeys on the Pixel 5 descriptor: the invoice document reads
without the page scrolling sideways (asserted against
`document.documentElement.scrollWidth`), paying from a phone reaches the
provider page and settles by webhook, and the catalogue breakdown and the wallet
ledger both fit.

## AC. Arabic / RTL

Four Arabic journeys in a browser whose language is Arabic: the invoice document
is Arabic prose with amounts kept left-to-right and Latin-numeral, a refused
payment explains itself in Arabic rather than in the provider's English, the
catalogue breakdown names the setup fee and the renewal in Arabic, and the
verification page explains what to do.

Every new string was added to both catalogues; the parity test asserts the two
files carry the same keys with the same placeholders and that every Arabic
sentence contains Arabic script.

## AD. Accessibility

- The new select control (`SelectField`) binds its label with `htmlFor`,
  references its error and hint through `aria-describedby`, and carries
  `aria-invalid` — two screens had been hand-rolling a bare `<select>` beside a
  `<span>`, which a screen reader reads as unlabelled.
- Validation errors are associated with their fields, so registration's country
  and currency errors are announced rather than coloured.
- No new meaning is carried by colour alone: a wallet debit says "spent" as well
  as showing a negative amount; a payment says its status in words.
- Every table has a caption; every action is a real button or link and is
  reachable by keyboard.

## AE. Wave 0 regression

`e2e/wave-0.e2e.ts` and the Wave 0 backend suites are unchanged and green. The
idempotency-key transport, the graded confirmations and the catalogue readiness
behaviour are all still asserted by their own tests.

## AF. Wave 1 regression

The Wave 1 mobile and Arabic projects are green. Wave 1 and Wave 0 specs did
fail during Wave 2 development, and each was fixed at its cause rather than
adjusted away.

The cause in every case was the same: paying an invoice is not a read. The money
journeys settled invoices on the shared seeded account, and settling one spends
credit, writes a payment row, posts notifications and — where the journey buys a
plan — adds a subscription. Five later specs read exactly those facts, so they
failed against an account the journeys had changed. The fix is fixture
isolation, not looser assertions: `E2ESeeder::moneyJourneys` now seeds a second
customer, `money@lynomia.local`, with its own wallet credit and the four Wave 2
invoices, and every journey that moves money signs in as that account. The
journeys that only read — cancelling nothing, printing a paid invoice, refusing
a browser-claimed success — stay on the shared account.

Three specs also needed their locators corrected, because the wallet screen
legitimately gained a table: the credit history carries the balance after each
movement, so `12.750` now appears both on the balance card and in a table cell.
The English and Arabic wallet specs now read the figure off the balance card,
and the phone spec reads the ledger's column heading by its role rather than
matching the card's description sentence, which ends in the same words.

Two Wave 0 specs were arranged rather than corrected, and both had been passing
by luck.

The first counts session rows. The security screen lists a hundred sessions at
most, and one browser running the whole suite signs in more often than that, so
the count could not move: revoking a row only let an older session into view. It
now drops the account's stored sessions and signs in again before it counts, so
the numbers it asserts are the platform's rather than the harness's.

The second changes a subscription's plan, and took whichever row came first.
Since the Wave 2 fixture began naming what each subscription pays for, that row
is the agreement running `e2e-web-01` — the machine whose rebuild nobody can
settle — and the platform correctly refuses to resize a server with work
outstanding, so every option on the screen was disabled. Until this wave the
spec had been rescued by an extra subscription that an earlier journey happened
to create on the shared account. It now names the operable machine's agreement,
which is the subscription the spec was always about.

Nothing in any Wave 0 or Wave 1 assertion was weakened. The three rounds it
took are in section AI.

## AG. Backend tests

```text
{"tool":"phpunit","result":"passed","tests":2885,"passed":2885,"assertions":137163,"duration_ms":508881}
```

Run locally against PostgreSQL 18 and Redis at `0b0ecf1`, the whole suite, not
a filtered selection. Pint reports `passed`; PHPStan reports `0` errors at the
level the repository pins.

New Wave 2 suites:

| Suite | Cases |
| --- | --- |
| `QuoteAndOrderAgreeTest` | 6 |
| `RegistrationDecidesTheCurrencyOutLoudTest` | 9 |
| `AnUnverifiedCustomerCanLookButNotBuyTest` | 7 |
| `AnInvoiceIsADocumentTest` | 6 |
| `TwoSubscriptionsAreTellableApartTest` | 5 |
| `PayingThroughTheControlledGatewayTest` | 10 |

Existing suites amended where Wave 2 changed the answer, never to make a
failure go away:

- `PaymentConfirmationIsServerSideOnlyTest` gained two stricter cases about the
  controlled gateway rather than an exemption;
- `InvoiceEndpointsTest` now asserts the snapshot is on the document and absent
  from the list, and that the internal customer id is not in it;
- `CatalogueBrowsingTest` asserts an unverified customer may read prices and may
  not reach money, with the two refusals distinguished;
- `StartInvoicePaymentEndpointTest` asserts `failed` where it asserted `none`;
- `PurchaseToActiveServiceTest` follows the verification link as a browser and
  asserts the redirect.

## AH. Frontend tests

```text
 Test Files  41 passed (41)
      Tests  177 passed (177)
   Duration  33.71s
```

New Wave 2 specs: the invoice document, the five answers to paying, registration
currency, the verification page, subscription identity, the price before you
buy, the wallet ledger, and the source gate that fails on money arithmetic
anywhere in `apps/web/src`.

## AI. Browser tests

```text
Running 198 tests using 1 worker
  198 passed (11.9m)
```

| Project | Surface | Tests |
| --- | --- | --- |
| `chromium` | Desktop, English, with the Arabic describe blocks | 171 |
| `customer-mobile` | Pixel 5, 393 x 851, touch | 14 |
| `customer-arabic` | Desktop with the browser's language set to Arabic | 13 |

Journeys A to H run in the desktop project; the phone project drives the
invoice document, a card payment, the catalogue breakdown and the wallet
ledger on 393px; the Arabic project drives the invoice document, a refused
payment, the price breakdown and the verification page in Arabic.

Getting there took two rounds, both recorded in section AF: the first full
three-project run was 9 failed / 189 passed, every failure a Wave 0 or Wave 1
spec reading an account the money journeys had changed, and the second was
1 failed / 170 passed on the desktop project alone. The run above is the
third, with no failures and nothing retried — the suite is configured with
`retries: 0` precisely so a flake cannot be hidden.

## AJ. Clean room

A fresh clone of `claude/hv-t6hq1p` at `0b0ecf1`, its own PostgreSQL databases
(`lynomia_cleanroom_w2` and `lynomia_e2e_cleanroom_w2`) and its own ports
(8011 and 5184), then the whole toolchain in the order CI runs it. Nothing was
copied from the working tree except the analysis toolchain, for the reason
recorded below.

```text
HEAD 0b0ecf1ad584f5b5fa9384a324d630f0e0d59617
OK   git clone
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

What each of those reported:

| Step | Result |
| --- | --- |
| Backend tests | `{"tool":"phpunit","result":"passed","tests":2885,"passed":2885,"assertions":137169,"duration_ms":508889}` |
| Pint | `{"tool":"pint","result":"passed"}` |
| PHPStan | `{"tool":"phpstan","result":"passed","errors":0}` |
| Frontend unit tests | 41 files, 177 tests, all passed |
| Browser suite | 198 passed in 12.2m — 171 desktop, 14 phone, 13 Arabic |

**The one exception, recorded exactly.** `composer install` for the application
itself succeeds in this sandbox, but the PHPStan toolchain in
`tools/phpstan/` cannot be installed here: its `composer install` authenticates
against `github.com`, and this environment's egress proxy refuses that host, so
the command fails with a GitHub authentication error rather than with anything
about the repository. The clean-room script therefore copies
`tools/phpstan/vendor` from the working copy — installed from the same
`composer.lock` — and runs the analysis with it. The step is labelled as a copy
in the transcript above so it cannot be read as a clean install. CI installs
that toolchain from the network and its Static analysis job passed on the same
commit, which is where that claim is proven rather than here.

## AK. CI

| Field | Value |
| --- | --- |
| Run number | 137 |
| Run id | 34490579683 |
| Attempt | 1 |
| Head SHA | `0b0ecf1ad584f5b5fa9384a324d630f0e0d59617` |
| Event | push |
| Conclusion | `success` |
| Started / finished | 2026-09-10 14:40:16Z / 14:51:02Z |

All nine jobs concluded `success`:

| Job | Conclusion | The step that matters |
| --- | --- | --- |
| Backend (PHP 8.4, PostgreSQL 18) | success | Run tests, 14:41:44 → 14:46:42 |
| Backend (PHP 8.4, PostgreSQL 16) | success | Run tests, 14:41:50 → 14:47:10 |
| Static analysis | success | PHPStan, 19s |
| Frontend | success | Typecheck, Lint, Unit tests, Production build |
| Browser end-to-end | success | Run the browser suite, 14:41:14 → 14:50:58 |
| API description | success | Validate `docs/openapi.yaml` |
| Security checks | success | Dependency audits; fail if a secret or environment file was committed |
| Production guards | success | Fail if a fake provider is configured outside the development template |
| Infrastructure validation | success | Safety gate, alert-rule and runbook checks, Ansible and OpenTofu |

The two runs before it are recorded rather than hidden. Run 136 (id
34485710073, SHA `69b3a46`) was `failure` in four jobs: both backend jobs on
the country/currency change test that Wave 2's stricter validation had broken,
the frontend job on a lint rule about an inline `import()` type in a new spec,
and the browser job on the nine fixture failures described in section AF. Run
137 is the same pipeline with those three causes fixed. Run 135 (id
34474350338), the Wave 1 baseline, was `success`.

The Actions budget was available: run 137 executed all nine jobs. There is no
`CI_BLOCKED_BUDGET` condition to report.

Per-suite counts inside the CI jobs are not restated here from the run's own
output: this environment's egress cannot reach the Actions log archive host,
and the per-job log the API returns for the browser job contains only its
service-container section. What is recorded above is what was read directly
from the API — the run, its attempt, every job and every step with its
conclusion and timing. The suite counts in sections AG to AI are from the
local and clean-room runs of the same commit.

## AL. New defects found

| Reference | What | Classification | Disposition |
| --- | --- | --- | --- |
| W2-RELATED-1 | The E2E wallet fixture wrote a balance onto the wallet row, so the account held 12.750 KWD that its own ledger had never received | W2-RELATED | Fixed: the fixture credits through `WalletLedger` |
| W2-RELATED-2 | Both seeded subscriptions were on the same plan at the same price and neither named a service, which made the AR-9 screen untestable | W2-RELATED | Fixed: different plans, different prices, different machines |
| W2-RELATED-3 | Seeded invoices carried totals and no lines or billing snapshot, so an invoice document could look finished while empty | W2-RELATED | Fixed: every seeded invoice carries a line and a snapshot |
| W2-RELATED-4 | The country/currency change request validated the country's shape only, so a change request could name a country that does not exist | W2-RELATED | Fixed: validated against the same authoritative list |
| W2-RELATED-5 | The Wave 2 money journeys spent the shared account's credit, notifications and subscriptions, so five Wave 0 and Wave 1 specs read an account the journeys had changed | W2-RELATED (harness) | Fixed: a second seeded account, `money@lynomia.local`, owns the wallet and the invoices those journeys spend |
| W2-RELATED-6 | The security screen's hundred-session cap made the Wave 0 sign-out spec's row count unmovable once a suite-long run had filled the list | W2-RELATED (harness) | Fixed: the spec arranges the session count it starts from; the cap itself is correct and unchanged |
| OUT-OF-SCOPE-1 | `Subscription::invoices()` had lost its generic return annotation | OUT-OF-SCOPE (housekeeping) | Fixed while PHPStan was green-gating this wave |

No P0 was found: no wrong charge, no duplicate charge, no cross-tenant leak, no
silent conversion, and no mutation of historical money.

## AM. Deferred items

- AS-1 in full — the non-money half of the purchase-clarity finding.
- Everything in section D.

## AN. Real-provider blockers

| Step | Status |
| --- | --- |
| Real gateway credentials | `BLOCKED_CREDENTIALS` — no merchant account exists for this platform |
| Real gateway SDK / hosted page | `BLOCKED_PROVIDER` — nothing in this wave was tested against a real provider |

Everything up to the provider boundary is implemented and tested against the
controlled fake: intent creation, the typed next action, the hosted-page
redirect, client confirmation, signature verification, webhook idempotency,
settlement, failure, and reconciliation by server-side retrieve.

## AO. P1 status

```text
Closed: 11 / 15
Remaining: AR-6, AR-7, AR-12, AR-13
```

## AP. Wave 3 prerequisites

Wave 3 was not started. What it will need from here:

- the invoice document and the payments history exist, so a future dashboard has
  real figures to summarise rather than placeholders;
- `ServiceIdentities` gives resource pages a service's own name without a query
  per row;
- the order chain gives navigation a real graph to build on.

## AQ. Final questions

> Can the customer tell which service every subscription belongs to?

Yes. `SubscriptionResource.services[]` names each one, and the list leads with
it. A service still being created says so instead of showing a name.

> Can two same-priced subscriptions be distinguished before cancelling one?

Yes. Plan, product and machine appear on the row and again inside the
cancellation dialogue. `TwoSubscriptionsAreTellableApartTest::two_identical_looking_subscriptions_carry_different_identities`
and browser journey G.

> Can a customer open a full invoice?

Yes. `/invoices/:id` with lines, periods, snapshot, payments and credits.

> Does an issued invoice show the immutable billing snapshot rather than current
> profile data?

Yes, asserted by changing the profile afterwards and searching the document body
for the new values.

> Can the customer see subtotal, tax, credits, payments and balance due?

Yes, all five, each from the server as minor units plus a currency.

> Can the invoice be printed cleanly?

Yes. `/invoices/:id/print`, HTML, no application chrome, asserted by journey H.

> Does clicking Pay always produce a visible next state?

Yes. Five typed outcomes, each with its own screen; the "nothing happened" case
no longer exists.

> Can the controlled client-secret-style flow complete without pretending a real
> gateway was verified?

Yes. It completes against the fake, and this report classifies the real provider
as `BLOCKED_CREDENTIALS` / `BLOCKED_PROVIDER`.

> Can a browser redirect/success URL mark an invoice paid by itself?

No. There is no such route, the gate that asserts it now also asserts the
controlled gateway is refused in production and whenever a real provider is
configured, and journey C shows the return screen saying so out loud.

> Can a webhook be replayed without double settlement?

No. The provider event id is claimed in `webhook_events` before handling, and a
second approval settles once —
`PayingThroughTheControlledGatewayTest::authorising_twice_settles_once`.

> Can a failed payment be seen later?

Yes: on the invoice document and in the payments history, with the reason in the
portal's own words in both languages.

> Can the customer see wallet ledger history?

Yes, with the balance the server recorded after each entry.

> Can an invoice be paid partly by wallet and partly externally without merging
> the two payment records?

Yes. Two payment rows, one per method, plus the ledger entry.

> Can two currencies ever be mixed silently?

No. Wallets are per currency, an unpriced currency is refused, and the pricing
engine refuses a mixed basket.

> Can the customer follow Order → Invoice → Payment → Subscription → Service?

Yes, through server-published ids rather than by matching amounts and dates.

> Is the order quote calculated by the same authoritative pricing logic as order
> creation?

Yes — one `OrderPricing`, two callers, and a contract test comparing every
figure.

> Can the frontend manipulate the tax or total?

No. Only plan ids, quantities, the period and a coupon code cross the wire,
and a source gate fails the build on arithmetic over an amount in the portal.

> Does registration require/collect a billing country?

Yes, required and validated against the ISO-3166-1 list.

> Does registration visibly establish billing currency?

Yes, before submit, with the reason for the recommendation stated.

> Can the customer choose a disabled currency by tampering with the request?

No. Refused by the FormRequest and again by `BillingCurrencies::assertEnabled()`
inside the action.

> Does a previously-issued invoice change when account country/currency later
> changes?

No. The document renders its own snapshot; the change workflow is unchanged.

> Can an unverified signed-in customer browse the catalogue?

Yes, and only the catalogue.

> Can that unverified customer place an order?

No. Nine money-moving endpoints answer 403 `auth.email_unverified`.

> Can the customer resend verification mail?

Yes, from `/verify-email`, with a cooldown and the address shown.

> Does the E2E suite follow a real signed verification link rather than modifying
> the database?

Yes. Journey A reads the message the platform sent from the test outbox and
follows the signed link in it.

> Can customer A fetch customer B's invoice/payment/wallet/subscription?

No. Scoped queries, 404 rather than 403, asserted for invoice, subscription and
payment reference.

> Does the Wave 2 flow work in Arabic?

Yes — four Arabic money journeys, including a refusal explained in Arabic, in a
browser whose own language is Arabic.

> Does it work on phone?

Yes — four phone money journeys, including one that asserts the page does not
scroll sideways.

> Did Wave 0 remain green?

Yes, and two of its specs were made deterministic rather than adjusted: the
sign-out spec now arranges the session count it starts from, and the plan-change
spec now names the machine whose plan it changes. Section AF has both.

> Did Wave 1 remain green?

Yes. The shared-fixture collisions the money journeys caused were fixed at their
cause — a separate seeded account for the journeys that spend money — and no
Wave 1 assertion was weakened.

> Did this wave create resource detail pages?

NO

> Did this wave implement the future sidebar?

NO

> Did this wave change Dedicated power idempotency?

NO

> Did this wave begin Wave 3?

NO

> Did this wave claim a real payment provider was verified?

NO

## AR. Final verdict

```text
WAVE 2 COMPLETE — MONEY IS LEGIBLE

AR-9  CLOSED   — every subscription names its plan, its product and its service.
AR-10 CLOSED   — an invoice is a document, and paying one always has a visible,
                 server-decided outcome.
AR-11 CLOSED   — registration collects an ISO-3166-1 country and establishes the
                 billing currency out loud, before submit.
AS-1  PARTIAL  — the money subset only, as the brief scoped it.
AS-2  CLOSED   — order, invoice, payment, subscription and service are linked by
                 published ids.
AS-3  CLOSED   — the wallet publishes its ledger.
AS-4  CLOSED   — payments history exists, failures included.

CODE_COMPLETE        — yes, for everything up to the provider boundary.
TESTED               — yes: 2885 backend tests, 177 frontend tests, 198 browser
                       tests across desktop, phone and Arabic.
RUNTIME_VERIFIED     — yes, against the controlled fake provider: intent, typed
                       next action, hosted-page redirect, client confirmation,
                       signature verification, webhook idempotency, settlement,
                       refusal, and server-side reconciliation.
REAL_INFRA_VERIFIED  — NOT CLAIMED.
REAL_PAYMENT_VERIFIED— NOT CLAIMED. No real provider, no merchant account, no
                       real card was involved at any point.

Provider steps: BLOCKED_CREDENTIALS (no merchant account) and BLOCKED_PROVIDER
(no real gateway SDK or hosted page exercised).

P1 findings closed: 11 / 15. Remaining: AR-6, AR-7, AR-12, AR-13.

CI: run 137, id 34490579683, attempt 1, SHA 0b0ecf1, all nine jobs success.
Clean room: fresh clone at the same SHA, every suite green, with the PHPStan
toolchain's network install recorded as the single external limitation.

STOPPED FOR REVIEW.
```
