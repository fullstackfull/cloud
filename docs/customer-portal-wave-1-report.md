# Customer Portal Wave 1 — Reach, Language and Customer-Facing State Clarity

Status: **CLOSED** · Starting HEAD `4bee5c2` · Ending code HEAD `369a698d4c6e9825ed204b163c3467ec44c0c771` · Report HEAD: the commit that adds this file · Date: 2026-09-10

The implementation pass approved after Wave 0 closed. Scope was the six
findings named in the brief (AR-4, AR-5, AS-8, AS-10, AS-16, AT-1) and
nothing else; the audit's findings are not rewritten here, only resolved.
The desktop navigation architecture is unchanged, no resource page exists,
no Wave 2 item was touched, and the dedicated power path was left exactly as
it was.

## A. Starting branch and HEAD

| Item | Value |
| --- | --- |
| Branch | `claude/hv-t6hq1p` |
| HEAD when the wave began | `4bee5c2f6ed12432288cb9861530f8bd522f127d` (the Wave 0 report commit) — verified with `git status`, `git rev-parse HEAD`, `git log --oneline -25` and `git diff`: tree clean, branch level with origin, no newer work to preserve |
| Wave 0's last code commit beneath it | `a9c656185f1456a8fc7942997d5125c192ef3b3b` |
| Known CI at the start | run 132 green (Wave 0); run 133 green per the brief |

## B. Ending code HEAD

| Item | Value |
| --- | --- |
| Implementation commit | `369a698 (Customer portal Wave 1: reach, language and customer-facing state clarity)` |
| Fix commits after CI | none — run 134 was green on the implementation commit |
| Report commit | the commit that adds this file, on top of the above |
| Diff against the starting HEAD | 116 files changed, 4,090 insertions(+), 401 deletions(-) |

## C. Scope

| Finding | What was built | State |
| --- | --- | --- |
| AR-4 Mobile navigation / reachability | One navigation definition (`apps/web/src/app/navigation.ts`) read by the desktop bar and by a new phone drawer (`MobileNavigation.tsx`, a native `<dialog>`): every customer destination, the operator sections for operators only, the language switch and sign-out, scrolling, Escape, backdrop and route-close | FIXED |
| AR-5 Language propagation and translated errors | `Accept-Language` on every request from the shared API client; a `SetRequestLocale` middleware on every API route; a canonical customer error catalogue of 275 codes in English and Arabic (`lang/{en,ar}/errors.php`) that the exception renderer answers from; Arabic validation (every framework rule, 109 customer field names, 25 request sentences); country/currency blockers and warnings stored as codes and put into words per request; stored provider prose no longer published to customers | FIXED |
| AS-8 Canonical status labels and tones | `lib/statusVocabulary.ts`: 119 statuses, each with an explicit tone; three untranslated statuses translated; a parity test that fails when a status lacks a translation or a tone | FIXED |
| AS-10 Arabic date rendering | Dates are a named month inside bidi isolates in both languages; the three dates that sat in left-to-right containers were taken out of them | FIXED |
| AS-16 Accessible localized loading state | One `Loading` component (`role="status"`, translated) at 55 sites in 45 files; the hard-coded English "Loading" in the route guards removed; three tables that showed "Loading…" as their empty state now show the loading state instead | FIXED |
| AT-1 Hamburger accessible name | `nav.menu` ("Menu" / "القائمة"), `aria-haspopup="dialog"`, `aria-expanded` following the drawer | FIXED |

## D. Explicit out-of-scope

Not implemented, deliberately, because the brief excludes them or assigns
them to later waves: the target desktop sidebar (BD-10 stays decided, not
built; the desktop "More" menu is still there), any new information
architecture, resource detail pages, dashboard, invoice detail, payment
gateway UI, wallet ledger, payments area, registration country/currency,
verification recovery, activity feed, polling framework, deep notification
links, contextual support, domain renew/contact/transfer UI, DNS record
edit, hosting usage, WordPress redesign, monitoring graphs, dark mode,
account deletion/export, real providers, real infrastructure, and the
dedicated power idempotency architecture (AW-15 — see AC and Y). List pages
were not turned into cards; no table was redesigned; no Wave 1 browser
journey found a primary action literally unreachable in the current shell,
so the minimum-fix clause was not exercised.

## E. AR-4 — Mobile reachability

**Source.** `AppLayout.tsx` held two arrays: four items for the bar, seventeen
for "More", and the phone drawer rendered only the first array. Both arrays
and the two operator arrays now live in `src/app/navigation.ts` (`PRIMARY_NAV`,
`SECONDARY_NAV`, `CUSTOMER_NAV`, `OPERATOR_NAV`, `CONTROL_CENTER_NAV`). The
desktop bar still renders `PRIMARY_NAV` in the open and `SECONDARY_NAV`
behind "More", exactly as before; the drawer renders `CUSTOMER_NAV` (all 21)
and, for an operator, the two operator groups with their headings.

**Drawer.** `MobileNavigation.tsx` is a native `<dialog>` opened with
`showModal()` — the browser owns the focus trap, the inert page behind,
Escape and the accessibility tree, as it already does for every confirmation
dialog in the portal. It is anchored to the start edge by logical margins
(left in English, right in Arabic), `h-dvh` with `overflow-y-auto` so a
short viewport scrolls it rather than truncating it, and it closes on Escape
(`cancel` event), on a press on the backdrop, on the close button, and on
choosing a destination. It carries the `LocaleSwitcher` and a sign-out
button, so nothing a customer needs is only on the desktop. The active route
is marked through `NavLink` (`aria-current="page"`).

**Permission boundary.** The drawer decides what to draw from the same
`useIsOperator()` the desktop uses; the API decides what is allowed. A
customer's drawer holds exactly the 21 customer links and no operator link
(unit test and phone browser spec both assert the count and the absence).

**Breakpoints.** The header hides the desktop bar below `sm` (640 px) and
shows the hamburger; above it the bar returns and the hamburger is hidden.
Inspected at 1440, 1280, 1024, 768, 430, 390 and 360 by the phone project
(393 × 851) and the desktop project (1280 × 720), plus the captures in W.
The shell is intact at every width; tables scroll inside their own box as
before.

## F. AR-5 — Locale propagation

**Client.** `lib/api.ts` sets `Accept-Language` on every request from the
live i18n instance (`acceptLanguage()`), narrowed to `en` or `ar`; the
per-request `locale` option remains as an override. No hook adds the header.
A language switch changes the header on the very next request (unit test
`accept-language.test.ts`).

**Server.** `Lynomia\Http\Middleware\SetRequestLocale` is registered
globally beside `AssignRequestId`, so a 401 from authentication and a 404
for a missing route are localised too. Negotiation lives in
`Lynomia\Http\Support\RequestLocale` (the catalogue's own helper now
delegates to it): Symfony's matcher over `app.supported_locales` with the
fallback first, so `ar-KW`, `ar-SA,ar;q=0.9,en;q=0.5` and `ar_EG` resolve to
`ar`; `en-US`, `en-GB,en;q=0.8` to `en`; `fr`, `ja-JP`, `*`, `xx-YY`, a
300-character header, a header carrying a CRLF, an empty and a blank header
all resolve to `en`, deterministically. Nothing about the account is
consulted: the stored preference governs what the platform sends unprompted
(notifications keep `localeFor($notification)`), the header governs what it
answers with.

**Proof.** `tests/Feature/Api/RequestLocaleTest.php` (6 tests): the same
code with a different sentence per language on a customer route; a
validation refusal in Arabic with the field named in Arabic; regional
variants; unsupported and malformed values; the 401 and the 404 in Arabic;
no leak of the locale from one request into the next.

## G. Customer error catalogue

**Inventory.** Codes were derived from the source rather than typed: every
dotted literal in `Domain/Exceptions/*.php` (and `*Refusal*` enums) of the
twenty modules that serve or can escape through `/api/v1` — ApiKeys, Backups,
Billing, Catalog, Compute, Dedicated, Dns, Domains, Identity, Ipam,
Notifications, Orders, Payments, ProductReadiness, Shared, SharedHosting,
Subscriptions, Support, Vps, Wallet — plus every `ApiError::make('…')` in
the HTTP layer and customer controllers, plus the renderer's own codes. The
derivation found 258 codes; with the renderer's `http.*` family, the
generic `request_failed` and six sentences for stored failures (H) the
catalogue holds **275 codes**, each in English and Arabic.

**Architecture.** `lang/en/errors.php` and `lang/ar/errors.php` are the
canonical customer error catalogue, generated from one list so the two
cannot drift. `Lynomia\Http\Responses\ErrorCatalogue::message($code,
$context, $fallback)` answers with the catalogue's sentence in the request
locale when the code has one and with the fallback otherwise; every arm of
the exception renderer in `bootstrap/app.php` goes through it, as do the
three hand-built customer error responses (verification link, reset link,
session not found). Context values are offered as `:placeholders` and used
only where a sentence names one, so an identifier carried for the log never
surfaces unless asked for. The exception's own message — allowed to name a
node, a driver, a provider — is now sent only for codes outside the customer
catalogue, i.e. the operator modules, whose readers are staff.

**Why the sentences live on the server and not in a second frontend
catalogue.** The brief asked for one canonical source and warned against
repeating a giant list in four files. The API is also the public customer
API; integrations must receive localised, safe sentences with no portal in
front of them. So the server composes the sentence, and the portal shows it.
The portal keeps its own `errors.*` keys (15) only for conditions the server
cannot see (network, rate limiting) and for the few codes whose portal
wording differs. This is documented in `useApiErrorMessage.ts` and tested in
`api-error-message.test.tsx`.

**Frontend safe path** (`useApiErrorMessage`): (1) the portal's translation
by code if it has one; (2) the API's sentence when the response is a
customer refusal (4xx) with a non-empty message; (3) the generic sentence
with the request reference for everything else — every 5xx, an empty body, a
code the catalogue does not know. The server's words for a 5xx are never
shown (test plants "PDOException at pve-node-03" and asserts it does not
appear).

**Validation.** `lang/en/validation.php` is now published and extended;
`lang/ar/validation.php` carries every framework rule (137 lines), 109
customer field names in the wildcard forms the validator reports
(`items.*.plan_id`), and 25 request-specific sentences under `requests.*`.
The 13 FormRequests and 4 controllers/traits that handed the validator
English sentences (`messages()`, `withMessages()`, the idempotency trait,
`ConfirmsCurrentPassword`) now ask the catalogue by key. An Arabic customer
who types the wrong password enabling two-factor reads
"كلمة المرور هذه غير صحيحة." from the API (browser spec).

**Gate.** `tests/Feature/Api/CustomerErrorCatalogueTest.php` (4 tests) fails
the build when: a code any customer module can raise lacks an English or an
Arabic sentence; the two catalogues carry different codes or different
placeholders, or an Arabic sentence has no Arabic letters; any customer
sentence names an internal thing (BMC, PXE, Proxmox, Redfish, IPMI, driver,
adapter, node, cluster, hypervisor, stack trace, exception, SQL, credential
reference, Cloudflare, cPanel, DirectAdmin); a framework validation rule, a
customer field or a `validation.requests.*` key the code references is
missing from either language. The derivation is documented in the test; the
operator modules are excluded by name and reason.

## H. Raw server-message elimination

| Where | Before | Now |
| --- | --- | --- |
| DNS zone / record `failure_reason` | The provider's redacted words ("Cloudflare API answered 403 for POST …") | `CustomerFailureReason::describe()` — a catalogue sentence in the request language saying the last change did not go through; the provider's words stay in the row for support |
| WordPress site / operation `failure_reason` | The panel's redacted words | Same, `wordpress.site_operation_failed` / `wordpress.operation_failed` |
| Backup / file-restore `failure_reason` | Provider words, "the cluster this backup was taken on no longer exists in the platform" | Same, `backup.operation_failed` / `backup.file_restore_failed` |
| Backup `files.reason` | `BackupFileRefusedException::getMessage()` | `ErrorCatalogue::message($e->errorCode(), …)` — localised |
| WordPress `copies.reason` | `WordPressRefusedException::getMessage()` and a literal English sentence | Catalogue sentences (`wordpress.operation_in_flight` etc.) |
| Country/currency `impact.blockers` / `impact.warnings` | English `sprintf` sentences stored at analysis time | Stored as `{code, params}`; composed per request from `lang/{en,ar}/account.php` with `trans_choice` pluralisation (Arabic has singular, dual and plural forms); tax described structurally and worded at answer time |
| Country/currency `decision_note` | The operator's own note | Unchanged and published: it is a person's message to the customer, not the platform's; recorded in Y |
| VPS/dedicated `actions.blocked_reason` | Already a code, translated by the portal (Wave 0) | Unchanged |

The API field types are unchanged (`string|null`), so no client breaks; the
OpenAPI descriptions of the two fields that promised "the provider's own
words" now say what is published, and `docs/openapi.yaml` was regenerated.
Operator observability is untouched: the redacted text is still stored, still
logged, and still on the operator resources.

## I. AS-8 — Status vocabulary

119 `status.*` keys (116 + `processing`, `quarantined`, `refused`, which the
badge toned but nobody had translated). Every key has an explicit tone in
`STATUS_TONES`; 53 that were silently grey are now classified: `needs_review`,
`manual_review`, `under_review` warning; `payment_failed`,
`provisioning_failed`, `provisioning_timeout`, `expired`, `rejected`,
`withdrawn`, `transferred_away`, `offline`, `unavailable`, `uncollectible`,
`unsupported`, `unknown` danger; `queued_for_provisioning`, `redemption`
(warning — the customer must act), `transfer_pending`, `scheduled`,
`requested`, `awaiting_registry`, `registration_pending`, `installing`,
`configuring`, `pxe_booting`, `bmc_configuring`, `preparing`, `reinstalling`,
`restoring`, `validating`, `powering_on/off` info; `applied`, `restored`,
`verified`, `available` success; `closed`, `refunded`, `registered`,
`retired`, `not_*` neutral, explicitly. The existing 66 keep their tones.
`StatusBadge` reads the vocabulary; text remains authoritative and every
value has a sentence in both languages. Parity test:
`status-vocabulary.test.ts` (5 tests) — translations in both languages,
tone for every key, no tone without a key, and "does not lie with colour"
assertions for the states the brief names.

## J. AS-10 — Arabic dates

Cause, reproduced: Intl's medium Arabic date is `09‏/03‏/2027` — digits,
slashes and three right-to-left marks — and inside a left-to-right container
(`.technical`, `dir="ltr"`) the marks reorder the slashes into the audit's
"092027/03/". Fix in the formatting layer (`lib/format.ts`): `formatDate`
and `formatDateTime` render a two-digit day, a **named month** and a
four-digit year (plus hour and minute), Western numerals in both languages,
and wrap the value in first-strong isolates (U+2068 … U+2069) so a date
interpolated into a sentence lays out as one unit. The three places that
forced a date left-to-right (`DomainsPage` expiry `<dd class="technical">`,
`SupportPage` and `AdminSupportPage` message timestamps `dir="ltr"`) were
taken out of those containers; no other of the 85 date renderings sits in
one. The user-timezone feature (AS-17) was not started; the established
timezone behaviour is unchanged. Tests: `dates.test.ts` (3) and the Arabic
browser project asserts a named-month date on the domains and backups
screens and the absence of any `dddddd/` shape.

## K. AS-16 — Loading state

Inventory: 51 inline `t('common.loading')` paragraphs/spans plus one
hard-coded English "Loading" in `guards.tsx`, plus three `DataTable`
`empty={isPending ? loading : none}` conflations (Team, Support, admin
Support). Now: one `Loading` component (`role="status"`, the translated
sentence, `size="region"|"screen"`) at 55 sites in 45 files; the guards use
`<Loading size="screen" />`; the three tables render `<Loading />` while
pending and the real empty state only when a successful read returned
nothing, so `loading ≠ error ≠ empty ≠ loaded` holds (Wave 0's `LoadFailure`
stays above each). Buttons keep their own `loading`/`aria-busy` and do not
announce. Zero `common.loading` calls remain outside the component; zero
hard-coded "Loading"/"Please wait". Test: `loading.test.tsx`.

## L. AT-1 — Accessible mobile menu

The hamburger's `sr-only` text is `t('nav.menu')` ("Menu" / "القائمة"), with
`aria-haspopup="dialog"` and `aria-expanded` bound to the drawer state. The
drawer is `aria-label={t('nav.menu')}`, its `<nav>` is labelled with the
existing `nav.primary`. Verified in jsdom (`mobile-navigation.test.tsx`: the
button is found by the name "Menu" and no button is named "Dashboard") and in
Chromium on the phone descriptor (`navigation.e2e.ts`).

## M. Mobile browser project

`playwright.config.ts` now declares three projects. `customer-mobile` runs
`e2e/mobile/*.e2e.ts` on Playwright's `Pixel 5` descriptor (393 × 851,
touch, mobile user agent — the closest shipped descriptor to the 390 × 844
the audit measured; a real device profile, not a narrowed desktop window).
Specs:

| Spec | Proves |
| --- | --- |
| `navigation.e2e.ts` · hamburger | The button is announced "Menu"; the desktop bar is hidden at this width |
| · reaches every customer destination | Opens the drawer 20 times and lands on Cloud VPS, Dedicated, Shared Hosting, WordPress, Domains, DNS, Backups, Invoices, Subscriptions, Wallet, Support, Profile, Security, Team, API Keys, Notifications, Orders, IP Addresses, Services, Catalogue — URL and heading asserted, drawer closed behind each |
| · current route, Escape, backdrop, boundary | `aria-current="page"` on the current link; no operator links for the customer; Escape closes and `aria-expanded` returns to false; a press on the backdrop closes |
| · language switch | Arabic from inside the drawer on `/invoices`: URL unchanged, `lang=ar dir=rtl`, drawer still open in Arabic with the same link current, then back to English |
| · sign out | From the drawer; a later `/wallet` lands on sign-in |
| `actions.e2e.ts` | One VPS action (shut down → Stopped → start → Running, both 202), one DNS interaction (claim zone, publish record, remove after dialog, give zone up), one billing interaction (credit dialogue on the invoice credit does not cover), one security interaction (create API token, revoke after dialog), one support interaction (reply on the seeded ticket) — every one reached through the drawer |

Result: **10 passed, 0 failed**.

## N. Arabic browser project

`customer-arabic` runs `e2e/arabic/*.e2e.ts` on Desktop Chrome with
`locale: 'ar'`, so the portal picks Arabic the way a customer's browser would.
`e2e/support/language.ts` distinguishes Arabic prose from Latin identifiers:
a text passes when it has Arabic letters and no run of three Latin words
after domains, hostnames, `INV-E2E-0001`-style identifiers, currency codes
and numbers are stripped; `e2e-web-01`, `KWD 12.500`, `example.com` pass,
"That password is incorrect." fails.

| Spec | Proves |
| --- | --- |
| navigation | The bar and "More" in Arabic; Catalogue and Security reached |
| catalogue | Arabic headings, three-decimal KWD prices, Western numerals only |
| VPS | Shutdown accepted (202) and the row reads متوقف then قيد التشغيل; force-off dialog is Arabic prose and cancel leaves the machine alone; the stranded machine's reinstall is disabled with an Arabic reason; the hostname stays Latin and left-to-right |
| DNS | Zone claimed; a private-range record is **refused by the API and the alert is Arabic prose**; a public record publishes with an Arabic badge; the give-up dialog is Arabic prose |
| domains | Search in Arabic; a taken name reads محجوز and offers no purchase; the held name's expiry is a named-month Arabic date; no `dddddd/` shape anywhere on the page |
| backups | Both seeded states as Arabic badges (نجحت, بحاجة إلى مراجعة), every badge Arabic prose, taken-date a named month |
| invoices | Arabic headings, the invoice number as issued, Arabic status, the credit dialogue in Arabic with Western-numeral amounts |
| support | Reply posted from the Arabic screen |
| security | Wrong password on enabling two-factor: the API's Arabic sentence appears; the English one does not |

Result: **9 passed, 0 failed**.

## O. RTL runtime review

Checked at runtime in the Arabic project and the captures in W: the drawer
opens from the right and its close button sits on the left (logical
margins); "More" and its menu align to the reading direction; status badges
read in Arabic; dates read as named months; forms and dialogs mirror with
their labels; technical values (hostnames, addresses, invoice numbers, domain
names, prices) stay left-to-right inside `.technical`/`dir="ltr"` spans and
read correctly. Broader layout issues seen and left for later waves are in Y.

## P. Accessibility review

No axe package is installed in the repository and none was added (a
dependency is not a Wave 1 item). Evidence is therefore role- and
name-based, automated where the DOM decides and manual where layout does:

| Check | Evidence |
| --- | --- |
| Hamburger accessible name | "Menu"/"القائمة" (unit + browser) |
| Drawer keyboard operation | Native modal `<dialog>`: browser focus trap and inert background; Escape closes (browser spec); links are ordinary anchors, Tab order follows reading order |
| Focus visible | Unchanged portal focus styles on links and buttons |
| Escape closes | Browser spec and unit test (`cancel` event) |
| Loading regions announced | `role="status"` on the one component, polite by default; buttons do not announce |
| Language switch labelled | `role="group"` with `aria-label` "Language", each button `lang` and `aria-pressed` (existing, asserted in the drawer test) |
| Status meaning not colour-only | Every status has text in both languages; tone is the second channel (parity test) |

No WCAG conformance is claimed.

## Q. Security / information-leak review

Adversarial checks run: a planted `RuntimeException` naming an SQLSTATE, an
internal address and a driver on a customer route → 500 with the generic
sentence and a request id, none of the planted words in the body, in both
languages (`CustomerSafeErrorsTest`); a planted `DomainException` → the
catalogue sentence, not the exception's; a stored Cloudflare 403 sentence →
the catalogue sentence; catalogue placeholders take only what a sentence
names; no customer sentence contains any of the sixteen internal words
(gate). Field names never appear by wire name in Arabic validation. Nothing
was weakened: the dedicated power endpoint, the idempotency contract, the
readiness guard and every authorisation check are untouched. No secret,
credential or PII was added to the repository or to any log; the middleware
logs nothing.

## R. Wave 0 regression proof

The full desktop project (161 specs) ran with the phone and Arabic projects
in one invocation: place order, VPS power, VPS reinstall, dedicated
power/reinstall controlled flow, plan change, 2FA, VPS specs, confirmations,
catalogue readiness, needs-review blocking and LoadFailure are all in it.
Result: **161 passed, 0 failed**. Wave 0's backend tests (contract, readiness,
availability) are in the backend total in S.

## S. Backend tests

`php artisan test` on the whole control plane: **2840 passed, 0 failed, 136,364 assertions (431.5 s)** — 16 more tests than Wave 0's 2824. Pint clean on the whole tree.

New tests this wave: `RequestLocaleTest` (6), `CustomerErrorCatalogueTest`
(4), `CustomerSafeErrorsTest` (6). Tests changed: none by hand; the whole
suite passed against the new sentences, because no test asserted the
English of a catalogue sentence and the country/currency test asserts on the
English rendering, which was preserved word for word.

## T. Frontend tests

vitest: **127 passed, 0 failed (30 files)** — 24 new tests in 6 new files. ESLint clean (`eslint .`), `tsc -b --noEmit` clean, `tsc -p tsconfig.e2e.json` clean, `vite build` succeeds.

New: `mobile-navigation.test.tsx` (6), `accept-language.test.ts` (3),
`api-error-message.test.tsx` (6), `status-vocabulary.test.ts` (5),
`dates.test.ts` (3), `loading.test.tsx` (1). ESLint clean, `tsc -b` clean,
production build succeeds. The list in the brief's §19 is covered: shared
navigation renders all permitted destinations; customer/operator
separation; language switch preserves route; Accept-Language follows the
locale; unknown locale fallback; error code → EN, → AR, unknown → safe
fallback; status parity and tones; Arabic dates; bidi isolation; Loading
accessibility/localisation; menu accessible label.

## U. Browser tests

| Project | Specs | Result |
| --- | --- | --- |
| chromium (desktop, English + the existing Arabic describes) | 161 | 161 passed, 0 failed |
| customer-mobile (Pixel 5) | 10 | 10 passed, 0 failed |
| customer-arabic (Desktop Chrome, `locale: ar`) | 9 | 9 passed, 0 failed |
| Total | 180 | 180 passed, 0 failed (10.0 min, one worker) |

Matrix: Desktop English ✓, Desktop Arabic ✓ (the existing Arabic describes
plus the Arabic project, which runs at desktop width), Mobile English ✓,
Mobile Arabic ✓ (the language-switch spec drives the drawer and the invoices
screen in Arabic on the phone). CI runtime grows by the two customer
projects only; the Control Center is not rerun.

## V. Clean room

Fresh clone of the branch at `369a698` into a scratch directory, with its
own database (`lynomia_cleanroom_w1`), its own E2E database
(`lynomia_e2e_cleanroom`) and its own ports (8011 / 5184), run start to end
by one script:

| Step | Result |
| --- | --- |
| `composer install` | OK |
| `key:generate`, `migrate:fresh --seed` (testing) | OK |
| Pint | passed |
| `php artisan test` | 2840 passed, 0 failed, 136,370 assertions (433.5 s) |
| PHPStan | 0 errors |
| `npm ci`, typecheck, lint | OK |
| vitest | 127 passed (30 files) |
| production build | OK |
| OpenAPI lint (redocly) | valid, 4 pre-existing description warnings |
| Playwright, all three projects | 180 passed (10.1 min) |

One exact external exception, the same one Wave 0 recorded: composer
cannot authenticate against github.com through this sandbox's egress
proxy, so the PHPStan toolchain under `tools/phpstan` was **copied from the
working copy's lockfile-installed vendor directory** rather than installed by
composer in the clone. The analysed code was the fresh clone. CI's own
"Install analysis toolchain" step ran that composer install unassisted and
passed (job `102853557594`). Nothing else was reused.

## W. CI

One run of `.github/workflows/ci.yml` on this branch for this wave, observed
to completion; the GitHub Actions allowance the brief warned about did not
block it (the run was created, started within seconds and ran to the end).

| Run | Run id | HEAD | Attempt | Result |
| --- | --- | --- | --- | --- |
| 134 | `34471917565` | `369a698d4c6e9825ed204b163c3467ec44c0c771` | 1 | **success** (created 11:33:28 UTC, finished 11:41:31 UTC) |

Jobs, all green: Backend (PHP 8.4, PostgreSQL 16) `102853557388`;
Backend (PHP 8.4, PostgreSQL 18) `102853557441`; Static analysis
`102853557594`; Frontend `102853557327` (typecheck, lint, unit tests,
production build); API description `102853557183`; Security checks
`102853557415`; Infrastructure validation `102853557478`; Production guards
`102853557536`; Browser end-to-end `102853557323`.

Browser counts on CI (from the job log): **180 passed (6.9 min)** — the
desktop project 161, `customer-mobile` 10, `customer-arabic` 9; 0 failed,
0 flaky, no retries (the suite has `retries: 0`). Red cause: none. Fix
commit: none. Rerun: none; no job was re-run manually. Nothing here is a
"should pass": every figure is from the run's own record.

For reference, the runs that preceded this wave on the branch: 132 (Wave 0
code) and 133 (Wave 0 report) were both green.


## X. New defects discovered

| # | Class | Found where | Defect | Action |
| --- | --- | --- | --- | --- |
| W1-1 | W1-BLOCKER | reading `bootstrap/app.php` | No Localization middleware existed at all; the audit's remediation column assumed one. The catalogue's `RequestLocale` helper was the only reader of `Accept-Language` | Built `SetRequestLocale`; the helper delegates to the shared negotiation |
| W1-2 | W1-BLOCKER | reading the requests | 13 FormRequests and 4 controllers handed the validator literal English sentences, and the framework's Arabic validation lines were not present, so an Arabic customer would have read English on every field even with the header sent | Published/added `lang/{en,ar}/validation.php`; all sentences keyed |
| W1-3 | W1-BLOCKER | reading the resources | Six customer resources published redacted provider/panel prose; the JSON itself leaked topology to API consumers regardless of the portal | `CustomerFailureReason` (H) |
| W1-4 | W1-RELATED | reading the analyser | Country/currency blockers and warnings were English sentences persisted at analysis time; not translatable after the fact | Stored as codes; worded per request |
| W1-5 | W1-RELATED | `StatusBadge` | `processing`, `quarantined`, `refused` were toned but had no translation, so they painted underscore-stripped English on an Arabic page | Translated |
| W1-6 | W1-RELATED | `format.ts` | The Arabic date scramble was not a `DomainsPage` bug but a formatter output (RLM + slashes) that breaks in any LTR container | Fixed in the formatter for every date |
| W1-7 | OUT-OF-SCOPE | reading | `nav.support` and `admin.nav.support` are both "Support"; in the drawer an operator sees two "Support" links in two labelled sections. Correct for the customer; a naming nit for operators | Recorded; not changed (operator UI) |

No Wave 0 regression was found.

## Y. Deferred findings

- **AW-15 / dedicated power idempotency**: unchanged, as instructed. The
  endpoint sends the command to the controller directly and has no durable
  operation record that could promise replay semantics. DEFERRED —
  ARCHITECTURE ITEM, pre-real-infrastructure.
- `decision_note` on a country/currency change is the operator's own text
  and is shown to the customer as such; if operators write in English to
  Arabic customers that is a process matter, not a translation one.
- Operator modules (Infrastructure, Providers, Admin, Provisioning retries,
  Monitoring) answer in English with the engineer's message by design; the
  Control Center is an operator surface.
- Broader RTL layout items outside this wave's scope (table density on
  phones, card layouts) stay with the audit's AR-7 / later waves.
- W1-7 above.

## Z. P1 status after Wave 1

| P1 | State |
| --- | --- |
| AR-1, AR-2, AR-3, AR-8, AR-14, AR-15 | CLOSED (Wave 0) |
| AR-4, AR-5 | CLOSED (this wave) |
| AR-6, AR-7, AR-9, AR-10, AR-11, AR-12, AR-13 | OPEN (Waves 2–4 as planned) |

15 P1 findings; 8 closed; **7 remaining**. No new P1 was found at runtime
and none was downgraded.

## AA. Wave 2 prerequisites

Product decisions BD-4, BD-5 and BD-7 (invoice detail and payment feedback,
subscription identity, currency choice at registration) remain to be taken
before Wave 2 is planned; BD-10 (sidebar) is decided and belongs to the
information-architecture wave, whose navigation source is now the one file
`navigation.ts`. Wave 2 can assume: every request localised; a catalogue
gate that will demand sentences for every new code; a status vocabulary gate;
one loading component; one navigation definition.

## AB. Final questions

- **Can a signed-in mobile customer reach every customer destination available on desktop?** YES — the drawer renders `CUSTOMER_NAV` (21 links); the phone spec opens each of 20 and asserts URL and heading (Dashboard is the landing page); the unit test asserts the count equals the shared list.
- **Can a mobile customer reach Support?** YES — phone spec, and a reply is posted from there.
- **Can a mobile customer reach Security?** YES — phone spec.
- **Can a mobile customer reach VPS, DNS, Domains and Backups?** YES — phone spec, and VPS and DNS are operated from there.
- **Does mobile navigation preserve permission boundaries?** YES — customer drawer holds exactly 21 links and no `/admin` link; operator drawer adds the two operator groups (unit test both ways; phone spec asserts absence).
- **Can the customer switch English ↔ Arabic on mobile?** YES — from inside the drawer, both directions, in the phone spec.
- **Is the current route preserved when language changes?** YES — `/invoices` and `/support` stay put (phone spec, unit test).
- **Does every customer API call carry the active supported locale?** YES — set once in `request()`; `accept-language.test.ts`.
- **Does the backend actually honour `Accept-Language`?** YES — `RequestLocaleTest`: different sentence per language for the same code on a customer route, before authentication too.
- **Does an Arabic validation refusal appear in Arabic?** YES — the API-token request with no body answers `validation.failed` in Arabic with الاسم and the request's own Arabic sentence; the security browser spec reads كلمة المرور هذه غير صحيحة.
- **Does an Arabic provider/capability refusal appear in Arabic or safe localized customer wording?** YES — the DNS private-address refusal reaches the Arabic screen as Arabic prose; stored provider failures are published as catalogue sentences; blocked reasons were already codes.
- **Can any known raw internal error detail reach a customer screen?** NO — 5xx bodies carry only the generic sentence and a request id (test with planted internals); 4xx sentences come from the catalogue, whose gate forbids the internal vocabulary; the six free-text fields are no longer published raw.
- **Does every customer-visible status have an English label?** YES — 119, parity test.
- **Does every customer-visible status have an Arabic label?** YES — 119, parity test with an Arabic-letters check.
- **Does every semantically important status have the correct tone?** YES — explicit tone for all 119; the "does not lie with colour" test pins needs_review/payment_failed/processing/expired.
- **Do domain dates render correctly in Arabic?** YES — named month, isolates, out of the LTR cell; asserted in the Arabic project.
- **Are hostnames, IPs, domains, emails and invoice identifiers readable inside RTL pages?** YES — `.technical`/`dir="ltr"` spans unchanged and asserted (hostname, invoice number, domain names) in the Arabic project.
- **Does the loading state have correct accessibility semantics?** YES — `role="status"`, polite, translated; 55 sites, zero remaining inline strings.
- **Is the hamburger announced as a menu control rather than "Dashboard"?** YES — "Menu"/"القائمة", unit and browser.
- **Did Wave 0 remain green?** YES — 161 passed, 0 failed on the desktop project; backend suite green.
- **Did this wave implement the future desktop sidebar?** NO.
- **Did this wave create resource detail pages?** NO.
- **Did this wave change Dedicated power idempotency architecture?** NO.
- **Did this wave start Wave 2?** NO.

## AC. Final verdict

```
Customer Portal Wave 1

Status:
CLOSED

Starting HEAD:
4bee5c2f6ed12432288cb9861530f8bd522f127d

Ending code HEAD:
369a698d4c6e9825ed204b163c3467ec44c0c771

Report HEAD:
the commit that adds docs/customer-portal-wave-1-report.md

Scope closed:
AR-4 mobile navigation reaches all 21 customer destinations from one shared definition
AR-5 Accept-Language on every request, honoured server-side; 275-code customer error catalogue in en/ar; Arabic validation; no raw provider prose to customers
AS-8 119 statuses, each translated in en/ar with an explicit tone, gated
AS-10 dates are named-month, bidi-isolated, in both languages
AS-16 one accessible localised Loading component at every site
AT-1 hamburger announced as "Menu"/"القائمة"

P1 findings closed this wave:
AR-4, AR-5

P1 findings remaining:
AR-6, AR-7, AR-9, AR-10, AR-11, AR-12, AR-13 (7)

Mobile English:
10 passed, 0 failed on Pixel 5 (393×851, touch)

Mobile Arabic:
language switched inside the phone drawer and the invoices screen driven in Arabic, within the phone project

Desktop English:
161 passed, 0 failed (the existing suite, unchanged)

Desktop Arabic:
9 passed, 0 failed in the Arabic project, plus the existing Arabic describes in the desktop project

Customer error catalogue:
275 codes, English and Arabic, derived from source and gated; 109 field names and 25 request sentences in both languages

Status vocabulary:
119 statuses, en/ar, explicit tone each, gated

Accessibility:
role- and name-based evidence (unit + Chromium); native modal dialog; no axe package in the repository, none added; no WCAG claim

Backend:
php artisan test: 2840 passed, 0 failed (136,364 assertions); Pint clean

Frontend:
vitest 127 passed (30 files, 24 new); eslint clean; tsc clean; production build succeeds

Browser:
180 passed, 0 failed (10.0 min, one worker) across three projects

PHPStan:
tools/phpstan/phpstan.neon as CI runs it: 0 errors on the whole tree

OpenAPI:
regenerated (two descriptions changed); redocly valid; committed document equals the generator's output (test)

Clean room:
fresh clone at 369a698: every step green (backend 2840, PHPStan 0, vitest 127, build, OpenAPI, browser 180); one external exception recorded — PHPStan toolchain copied because composer cannot reach github.com from this sandbox

CI:
run 134 (id 34471917565, HEAD 369a698, attempt 1) green on all nine jobs; browser 180 passed (161 desktop, 10 phone, 9 Arabic); no red, no fix, no rerun

Wave 0 regressions:
NONE

New findings:
W1-1..W1-6 fixed in this wave (all in scope); W1-7 recorded (operator naming nit)

Dedicated power idempotency:
DEFERRED — ARCHITECTURE ITEM

Real infrastructure touched:
NO

Future sidebar started:
NO

Resource pages started:
NO

Wave 2 started:
NO

Recommended next action:
Review this report. Take decisions BD-4, BD-5 and BD-7 before Wave 2 is
planned. Wave 2 (billing clarity: invoice detail, payment feedback,
subscription identity) needs no further prerequisite from this wave.
```
