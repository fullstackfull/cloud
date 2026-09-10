# Customer Portal Wave 0 — Make the Existing Portal True

Status: **CLOSED** · Starting HEAD `08568f5` · Ending HEAD `a9c656185f1456a8fc7942997d5125c192ef3b3b` · Dates: 2026-09-10

The implementation pass approved on `docs/customer-portal-product-ux-audit.md`.
Scope was the nine findings named in the brief and nothing else; the audit's
findings are not rewritten here, only resolved.

## A. Starting HEAD

| Item | Value |
| --- | --- |
| Branch | `claude/hv-t6hq1p` |
| HEAD when the wave began | `08568f56f6c2fa52d105dff3e41595b1b43d64f3` (the audit commit) — verified locally and on origin, tree clean, no newer commits |
| Frozen software baseline beneath it | `9050d69226b15f544da9d2cdcab35e02bf37a49c` |

## B. Ending HEAD

| Item | Value |
| --- | --- |
| Implementation commit | `8eb9b47 (Customer portal Wave 0: make the existing portal true)` |
| Browser-spec fix after CI run 131 | `a9c6561 (Match the seeded rack by its exact name in the sites browser spec)` — one spec file, no product code (see R) |
| Report commit | the commit that adds this file, on top of the two above |
| Ending HEAD (last code commit) | `a9c656185f1456a8fc7942997d5125c192ef3b3b` |
| Diff against the starting HEAD | 64 files changed, 3414 insertions(+), 222 deletions(-) |

## C. Scope implemented

| Finding | What was done | Status |
| --- | --- | --- |
| AR-1 Idempotency-Key transport | One option on the API client sends the key as the `Idempotency-Key` header; all six guarded callers use it; the checkout request shares the trait every other guarded request uses; a missing or malformed key is its own error code naming the header | FIXED |
| AR-2 2FA enable | The "Turn on" control asks for the current password first and sends it; a wrong password is reported against the field | FIXED |
| AR-3 VPS resources | The row reads `resources.vcpu/memory_mib/disk_gib`; the TypeScript type matches the resource | FIXED |
| AR-8 Confirmations | Eight actions confirm first under the graded policy (BD-6): VPS force off, dedicated force off, DNS record removal, order cancellation, API token revocation, session sign-out (one device and all others), invitation withdrawal, country/currency request withdrawal | FIXED |
| AR-14 Design tokens | `--surface-base`, `--border`, `--danger-text`, `--warning-text`, `--accent` defined in the token layer for light, system-dark and forced-dark | FIXED |
| AR-15 Catalogue readiness | One `ProductSellability` decision read by the order guard and the catalogue listing, product lookup and plan lookup; the invariant "listed iff sellable" is structural and tested rung by rung | FIXED |
| AS-9 Reinstall during needs_review | Both machine resources publish `actions.{power, reinstall, blocked_reason}` from the guards' own facts; the rows disable on that and say why | FIXED |
| AS-15 LoadFailure | Team (members and invitations), Support, API tokens and Sessions distinguish loading, failed and empty | FIXED |
| AT-10 Stale docs | The backups route comment and the capability matrix's "still cannot do" list now describe the code | FIXED |

## D. Scope explicitly not implemented

Nothing from Wave 1 onward was started. Specifically untouched: navigation and
the sidebar, per-resource pages, the dashboard, invoice detail, payment UI,
registration country/currency, verification resend, the activity feed,
polling, domain renew/contacts/transfer controls, DNS edit, hosting usage,
WordPress UX, Arabic error-catalogue expansion beyond the strings this wave
introduced (each of which has both languages), dark mode, real providers.

Two defects found in passing and recorded rather than fixed (out of scope):

- The dedicated **power** endpoint takes no idempotency key at all (only the
  dedicated reinstall does). The portal now sends the header harmlessly; the
  API ignores it. Recorded for Wave 2 as a backend contract gap (AW-15).
- The seeded `e2e-web-01` cannot be operated at all — correctly, since its
  last rebuild is unresolved — which left the browser suite nothing to press.
  A second seeded machine (`e2e-app-02`) now exists for that; a fixture
  change, not a product one.

## E. AR-1 — Idempotency transport

**Root cause.** `apps/web/src/lib/queries.ts` posted `idempotency_key` in the
body for orders, VPS power, VPS reinstall, dedicated power, dedicated
reinstall and plan change; only the wallet-credit hook used the header. Every
guarded backend request merges the header over the body and validates the
result, so five of six calls were answered 422 with a field error naming no
visible field.

**Fix, at the transport level.**

- `apps/web/src/lib/api.ts`: a new `idempotencyKey` request option sets the
  `Idempotency-Key` header; `newIdempotencyKey()` is the one helper that mints
  a key. Nothing sends the key in a body any more (asserted by unit tests on
  the product and VPS pages, and by the browser spec, which inspects the
  request).
- `queries.ts`: `usePlaceOrder`, `useVpsPower`, `useVpsReinstall`,
  `useChangePlan`, `useDedicatedPower`, `useDedicatedReinstall`,
  `usePayFromWalletCredit` all pass `{ idempotencyKey }`. The public hook
  shapes renamed the field from `idempotency_key` to `idempotencyKey` so a
  body key cannot be reintroduced by habit.
- Backend: `PlaceOrderRequest` now uses the shared `ReadsIdempotencyKey` trait
  instead of its own copy, so the checkout cannot drift from the other five
  contracts. The trait's `failedValidation` raises
  `IdempotencyKeyRejectedException` (`request.idempotency_key_rejected`, 422,
  `details.header = "Idempotency-Key"`, `details.field = "idempotency_key"`)
  when the header is the thing that failed, so the error names the header
  instead of a form field. Body semantics are unchanged: the header is
  canonical, a body key alone is still refused, no fallback was added.
- The portal translates the new code in English and Arabic
  (`errors.request.idempotency_key_rejected`); after the fix no customer
  should ever see it.

**Contract rule, as tested** (`tests/Feature/Api/IdempotencyKeyContractTest.php`,
one file across all families, plus the existing per-endpoint suites updated to
the new code): missing header → 422 with the code and header named and no side
effect; same key twice → one order / one job / one resize / one debit; a
different key → a second order where the action allows one, and a 409 (not a
silent replay, not a second job) where the machine is already busy; a key in
the body alone → refused.

## F. AR-2 — Two-factor enablement

`useBeginTwoFactorEnrolment` now takes the current password and posts
`{ current_password }`; `TwoFactorSection` asks for it in a form (same pattern
the disable flow already used) before anything is sent, and shows a wrong
password against the field. The backend password requirement is untouched.

Safety: the password is held in component state until the request is sent,
then cleared; it is never logged, never persisted, never echoed, and the
audit metadata the backend writes for a failed confirmation is the existing
login-activity record, which stores the outcome and not the value.

Unit test: password step blocks the call, a correct one begins enrolment and
shows the secret, a wrong one is reported against the field. Browser spec:
wrong password refused → correct password → secret → TOTP computed in the
spec → confirmed → recovery codes shown → reload shows "On" → turned off again
with the password so the rest of the suite can sign in.

## G. AR-3 — VPS resources

`VpsPage` reads `vm.resources.*`; `VirtualMachine` in `types.ts` nests
`resources` and drops the three top-level fields that never existed on the
API. Unit test renders two machines and asserts the exact strings
"2 vCPU · 4 GiB · 40 GiB" and "4 vCPU · 8 GiB · 80 GiB" and the absence of
"NaN" and "undefined". The OpenAPI schema was already nested; the client type
was the one that was wrong.

## H. AR-8 — Confirmations

Graded policy as approved (BD-6). Every dialog names the resource, says what
happens, whether it can be undone, and what to expect next; none says only
"are you sure".

| Action | Grade | Dialog names | Reversibility stated | Cancel/confirm labels |
| --- | --- | --- | --- | --- |
| VPS Force off | disruptive, plain dialog | hostname | can be started again from this page; unsaved data lost | Cancel / Force off |
| Dedicated Force off | plain dialog | serial | can be powered on again; disks may need checking | Cancel / Force off |
| Remove DNS record | plain dialog | type, name, value | can be added back; things relying on it stop meanwhile | Cancel / Remove record |
| Cancel unpaid order | plain dialog | order number | nothing charged; place a new order | **Keep order** / Cancel this order |
| Revoke API token | plain dialog | token name | cannot be undone; create a new token | Cancel / Revoke token |
| Sign out one device | plain dialog | IP address | they sign in again; your session unaffected | Cancel / Sign out that device |
| Sign out other devices | plain dialog | — (all others) | everyone signs in again | Cancel / Sign out other devices |
| Withdraw invitation | plain dialog | invited email | invite again later | Cancel / Withdraw invitation |
| Withdraw country/currency request | plain dialog | target currency | nothing changes; request again any time | Cancel / Withdraw request |

`ConfirmDialog` gained an optional `cancelLabel` so the order dialog does not
carry two buttons reading "Cancel". No ULID is asked for anywhere new; the
existing typed confirmations (reinstall hostname/serial, zone name, production
domain) are unchanged. Every new dialog: Escape and the cancel button change
nothing; confirming fires the mutation once; a second click lands on a
disabled (loading) button — asserted in unit tests for force off and token
revocation, and in the browser for each of the eight.

## I. AR-14 — Design tokens

Audited first: `--surface-base` (34 uses), `--border` (12), `--danger-text`
(5), `--warning-text` (2), `--accent` (2) were referenced and undefined in all
three theme blocks. Defined in `apps/web/src/styles/index.css` only — no
component was touched for this — as: `--surface-base` (input/card ground,
white in light, a shade above the page in dark), `--border` (between subtle
and strong), `--danger-text` and `--warning-text` (oklch reds/ambers with
legible contrast on both grounds), `--accent` (brand-600 light / brand-400
dark). Verified in a real browser at 1440, 1024 and 390 in English and Arabic
by reading the computed styles (script `tokens-check.mjs`, 30 captures): the
reinstall warning line resolves to `oklch(0.5 0.19 25)` (red), the blocked
reason and resize caveat to `oklch(0.55 0.15 70)` (amber), the notifications
"Open" link and unread dot to brand-600, and form inputs on cards to a white
ground with a visible border — identical in both languages at all three
widths. No redesign.

## J. AR-15 — Catalogue readiness

**Single source of truth.** `ProductReadiness\Application\Services\ProductSellability`
answers `maySell(Product)` and `sellableCatalogueKinds()`; `AssertProductMaySell`
(the order guard) and `ListPurchasableProducts`, `FindPurchasableProduct`,
`FindPurchasablePlan` (the catalogue) all read it. The policy is unchanged:
in production a product is sellable only while its readiness row is
`ready_to_sell`; outside production nothing is restricted, exactly as the
guard has always behaved (a fake provider cannot reach `ready_to_sell`, so a
non-production catalogue that hid on readiness would hide the checkout from
every test and staging walk). Readiness is read from the row the engine
wrote, never inferred from a provider, plan, price or software state. Prepared
products have no catalogue row and no customer card.

**Tests** (`tests/Feature/Catalog/CatalogueRespectsProductReadinessTest.php`),
each asserting the listing, the product-by-slug link, the plan-by-id link and
the guard together, in production: not_ready → absent; ready_for_test (all
fakes) → absent and a declaration over it refused 409; ready_for_real_validation
(real drivers, staging) → absent; ready_for_production undeclared → absent;
ready_to_sell → listed and an order is accepted; provider disabled → absent in
the same transaction and the order refused `product.not_sellable`; outside
production → listed; a priced public row alone → absent.

Browser: the catalogue offers exactly the three sellable families and no
prepared product by name; `/catalogue/cdn` is a not-found with no purchase
control.

## K. AS-9 — Reinstall blocking

Both machine resources publish `actions: { power, reinstall, blocked_reason }`
computed from the same facts the operation guards refuse on, batched per page
(`UnresolvedServiceWork` for VPS: queued/running/needs_review jobs on the
service; `LiveServerWork` for dedicated: queued/running jobs by payload or
service). Reasons: VPS `service_not_active`, `not_provisioned`,
`operation_in_flight`, `operation_needs_review`; dedicated
`server_not_in_service`, `reinstall_in_flight`, `operation_in_flight` (power
stays available under the last, as the dedicated guard allows). The rows
disable on `actions.*` — not on a client-side guess — and show the reason
under the power badge in the customer's language. Backend guards are
untouched and remain authoritative; the tests assert both that the resource
says "refused" and that the endpoint refuses.

## L. AS-15 — Load failure

Team (members and invitations cards), Support (requests card), API tokens
(token list) and Security → Signed-in devices now render `LoadFailure` and no
table when the read fails, the loading text while pending, and the empty text
only when a successful read returned nothing. Unit test on the tokens page:
a 403 renders the "not permitted" alert and never "No tokens".

## M. Documentation corrections

- `routes/v1/backups.php`: the "No delete" comment above the DELETE route now
  says deletion exists and why the old comment was wrong.
- `docs/customer-capability-matrix.md`, "What a customer still cannot do":
  four entries that described built features (redemption, zone import/export,
  file restore, country/currency change) were removed or reworded to what is
  actually still missing; historical closure documents were not touched.
- `docs/openapi.yaml` regenerated for the `actions` block on both machine
  schemas; the generator's own "committed equals generated" test passes.

## N. Security review

Adversarial checks performed against the running portal and API:

- **Duplicate submits**: every new dialog disables its confirm button while
  the mutation is pending; the checkout holds one key per basket; the API
  replays the same key to the same result (contract test) and the browser
  spec replays a captured order request and gets the same order back.
- **Stale dialogs**: each dialog holds the whole row it was opened for, not an
  id looked up at confirm time, so a list refetch under an open dialog cannot
  retarget it.
- **Wrong-account resource / forged id**: unchanged and re-run — every guarded
  route resolves the resource through the acting customer's relation before
  the guard runs (existing cross-account tests in the VPS, dedicated, orders,
  wallet and plan-change suites still pass).
- **Replayed idempotency key**: same key + same body → same result, no second
  side effect; a key is scoped per machine/operation and hashed
  (`VpsIdempotencyKey`), so one customer's key cannot claim another's job
  (existing test).
- **Confirmation bypass / direct API call**: confirmation is UX only. A direct
  POST without the dialog is accepted or refused on exactly the same server
  rules as before; nothing in this wave made the backend trust a client flag,
  a client readiness value, a client price or a client state.
- **No backend weakening**: the password requirement on 2FA is unchanged; the
  order guard is unchanged in policy; no validation was removed; the header
  is canonical with no body fallback.

## O. Contract tests

| Test | Covers |
| --- | --- |
| `tests/Feature/Api/IdempotencyKeyContractTest.php` (5) | missing / same / different / body-only key across orders, VPS power, VPS reinstall, plan change, wallet credit |
| `PlaceOrderEndpointTest`, `VpsPowerEndpointTest`, `VpsReinstallEndpointTest`, `DedicatedServerReinstallEndpointTest`, `PlanChangeEndpointTest` (updated) | the refusal now carries `request.idempotency_key_rejected` and names the header |
| `tests/Feature/Catalog/CatalogueRespectsProductReadinessTest.php` (8) | the readiness/catalogue invariant, every rung, both directions, both environments |
| `tests/Feature/Vps/PublishedActionAvailabilityTest.php` (7) | `actions` for clean, stranded, live, precedence, suspended, unprovisioned, neighbour isolation; endpoint agreement |
| `tests/Feature/Dedicated/PublishedActionAvailabilityTest.php` (5) | `actions` for clean, live reinstall, other live work, maintenance, stranded-not-blocking |
| `tests/Feature/Api/OpenApiSpecificationTest.php` (existing) | schemas document the new field; committed spec equals generated |

## P. Frontend tests

| Test | Covers |
| --- | --- |
| `features/infrastructure/__tests__/vps-page.test.tsx` (5) | resources render, never NaN; force off asks and cancels cleanly; confirms once with the key in the header; other actions send the header; blocked machine has every control off with the reason |
| `features/security/__tests__/two-factor-enrolment.test.tsx` (2) | password step, correct password begins enrolment, wrong one reported on the field |
| `features/tokens/__tests__/api-tokens-page.test.tsx` (3) | revoke names the token and cancels cleanly; revokes once; 403 reads as failure not emptiness |
| `features/catalog/__tests__/product-page.test.tsx` (2) | an unsold product offers nothing to buy; an order carries the key in the header and no price in the body |
| existing 91 tests | unchanged, green (translation parity now covers the new keys in both languages) |

## Q. Browser E2E

`apps/web/e2e/wave-0.e2e.ts` (new) plus the updated `portal`, `dns` and
`team` specs, all against the fake providers, one Chromium project.

| # | Flow the brief required | Spec | Result |
| --- | --- | --- | --- |
| 1 | Place a valid order | wave-0 · placing and cancelling an order | PASS |
| 2–5 | VPS start, shut down, force off, reboot | wave-0 · every power control completes | PASS |
| 6 | VPS reinstall confirmed | wave-0 · a reinstall is confirmed and carried out | PASS |
| 7 | Dedicated power confirmed | wave-0 · operating a dedicated server | PASS |
| 8 | Dedicated reinstall confirmed | same spec | PASS |
| 9 | Plan change | wave-0 · changing plan | PASS |
| 10 | Enable 2FA | wave-0 · two-factor authentication | PASS |
| 11 | DNS remove with confirmation | dns.e2e (updated) | PASS |
| 12 | Cancel unpaid order with confirmation | wave-0 · placing and cancelling an order | PASS |
| 13 | Revoke API token with confirmation | wave-0 · revoking access | PASS |
| 14 | Revoke session with confirmation | wave-0 · signing out another device | PASS |
| 15 | Withdraw invitation with confirmation | team.e2e (updated) | PASS |
| 16 | Withdraw country/currency request with confirmation | wave-0 · withdrawing a request | PASS |
| 17 | Unsellable product not shown | wave-0 · what is for sale | PASS |
| — | Duplicate protection from the browser | the order spec replays its own captured request with the same key and asserts the same order id and one row | PASS |
| — | Stranded machine has every control off, neighbour untouched | portal.e2e (new spec) | PASS |

Suite totals: full suite on the implementation commit, one Chromium project: **161 passed, 0 failed (7.8 min)** locally; **161 passed** again on CI run 132 and in the clean room (R and the verdict block). The wave-0 file alone: 10 passed. Earlier runs of the same day found the six regressions listed in S; each was fixed before this run.

## R. CI

Two runs of `.github/workflows/ci.yml` on this branch, both observed to
completion; nothing here is inferred.

| Run | Run id | HEAD | Result | Jobs |
| --- | --- | --- | --- | --- |
| 131 | `34428007046` | `8eb9b47c423c87bb28ab8747b59d65e410568426` (implementation commit) | **failure** | Backend (PG16, PG18), Static analysis, Frontend, API description, Security checks, Infrastructure validation, Production guards: all green. Browser end-to-end (job `102717257937`): **red**, 160 passed, 1 failed |
| 132 | `34428925082` | `a9c656185f1456a8fc7942997d5125c192ef3b3b` (fix commit) | **success** | all nine green: Backend (PHP 8.4, PostgreSQL 16) `102720030601`, Backend (PHP 8.4, PostgreSQL 18) `102720030726`, Static analysis `102720030441`, Frontend `102720030644`, API description `102720030589`, Security checks `102720030686`, Infrastructure validation `102720030700`, Production guards `102720030663`, Browser end-to-end `102720030639` (161 passed, 7.6 min); run attempt 1, no manual re-run |

**Red cause (run 131).** `e2e/control-center-sites.e2e.ts:65 › in Arabic ›
the overview and sites screens read in Arabic` failed with a strict-mode
violation: `getByRole('listitem', { name: 'E2E-R1' })` resolved to two rows,
`E2E-R1` (the seeded rack) and `E2E-R1PEL`. The neighbouring spec registers a
rack whose name is derived from the clock (`E2E-R` + the last four base-36
digits of `Date.now()`); at 02:08 UTC that suffix began with `1`, so the
substring match in the Arabic spec saw two racks. The PostgreSQL line in the
same job log (`duplicate key value violates unique constraint
"racks_datacenter_id_name_unique" … E2E-R1`) is the *expected* refusal that
the "refused a second with the same name" spec asserts, not a defect. The
failure is a pre-existing test-hygiene flake in a Control Center spec this
wave did not touch; no product code was involved, and no Wave 0 spec failed.

**Fix commit.** `a9c6561` — the datacenter and rack fixtures are matched
with `exact: true` in both places the sites spec looks them up. Verified
locally (3 passed) before pushing. No test was skipped, retried or weakened.

**Rerun reason.** None requested by hand; run 132 is the push of the fix
commit. No job in either run was re-run manually.


## S. Regressions found

Defects found by this wave that the audit had not seen, because the
idempotency defect masked them or because no test had ever pressed the
control:

| # | Found where | Defect |
| --- | --- | --- |
| RF-1 | browser spec, place order | The checkout hook sent the basket as `lines`; the API requires `items`. Every order was refused "the items field is required" — hidden until AR-1 was fixed, because the key error was reported first. A second contract mismatch on the same button |
| RF-2 | browser spec, dedicated power | `config('dedicated.provider')` was never declared, so `DEDICATED_PROVIDER=fake` (in `.env.example` and `phpunit.xml`) did nothing outside PHPUnit, which sets the config key by hand. Every non-test environment built a real Redfish adapter and tried to reach 192.0.2.x; the browser suite's first Power cycle answered "no working management path" |
| RF-3 | browser spec, 2FA | Confirming enrolment refetched the user, the account flipped to "enabled", and the panel showing the one-time recovery codes unmounted in the same render. The codes were on screen for one frame. A real product defect on the flow AR-2 opened up |
| RF-4 | browser spec, VPS power | The fake hypervisor is built per request and, without a shared state file, has never heard of a machine seeded straight into the database, so the first power action went to review as "no such machine". A harness gap, not a product one: the fake was modelling drift correctly |
| RF-5 | browser spec, VPS reinstall | The seeded machine had no `storage_name` and no live address, both of which the rebuild handler correctly refuses to guess. Fixture gap |
| RF-6 | audit AW-15 confirmed | The dedicated power endpoint takes no idempotency key at all (only the reinstall does); recorded, not fixed (out of scope) |

## T. Regressions fixed

| # | Fix | Proof |
| --- | --- | --- |
| RF-1 | `CheckoutPayload.items`; the product page sends `items` | unit test asserts the exact body; browser spec places the order |
| RF-2 | `config/dedicated.php` declares `provider => env('DEDICATED_PROVIDER')`; `.env.example` says what the value does. The fake still refuses to be constructed in production, so a production `.env` carrying `fake` fails loudly rather than silently | browser spec power-cycles and force-offs the seeded server against the fake controller |
| RF-3 | `TwoFactorSection` holds the issued codes itself and keeps them on screen, above an "I have saved these codes" button, until dismissed (en/ar) | browser spec sees the codes after confirming, then turns the factor off again |
| RF-4 | `COMPUTE_FAKE_STATE_PATH` set for the browser suite's API and seeder processes (`playwright.config.ts`, `global-setup.ts`); `E2ESeeder` registers the operable machine with the fake through its own create call | browser spec shuts down, starts, forces off and reboots it and the row reflects each |
| RF-5 | seeder gives the operable machine a storage name and a primary address in the seeded subnet | browser spec rebuilds it to "Rebuilt" |
| RF-6 | not fixed; recorded in D and W | — |

## U. Remaining blockers

None for Wave 0's own scope. Two items stay open by design and are named
so they are not mistaken for omissions:

- A dedicated server rebuild completes only as far as the controlled
  environment can take it (the request is accepted and tracked; the install
  itself is BLOCKED_HARDWARE, as the audit and the 30B first-node verdict
  record). Nothing in this wave claims otherwise.
- The dedicated power endpoint takes no idempotency key (RF-6 / AW-15). The
  portal sends the header; the API does not read it on that one route. To be
  closed in Wave 1 or 2 so the contract is uniform.

## V. P1 status after Wave 0

| Audit P1 | Status after Wave 0 |
| --- | --- |
| AR-1 idempotency transport | CLOSED |
| AR-2 2FA enable | CLOSED |
| AR-3 VPS NaN | CLOSED |
| AR-4 mobile navigation | OPEN (Wave 1) |
| AR-5 Arabic backend messages | OPEN (Wave 1); this wave added its new strings in both languages |
| AR-6 dashboard | OPEN (Wave 4) |
| AR-7 resource pages | OPEN (Wave 3) |
| AR-8 confirmations | CLOSED |
| AR-9 subscription rows unnamed | OPEN (Wave 2) |
| AR-10 invoice detail / silent Pay | OPEN (Wave 2) |
| AR-11 registration / verification | OPEN (Wave 2) |
| AR-12 refresh model | OPEN (Wave 4) |
| AR-13 activity | OPEN (Wave 4) |
| AR-14 design tokens | CLOSED |
| AR-15 catalogue readiness | CLOSED |

Six of fifteen P1 findings closed; the remaining nine are the ones the audit
assigned to later waves. AS-9, AS-15 and AT-10 (P2/P3) also closed.

## W. Wave 1 prerequisites

- Product-owner decisions BD-4, BD-5, BD-7, BD-10 are not needed for Wave 1
  (reach and language) but are needed before Wave 2 and Wave 3; BD-1 and
  BD-6 are now decided and implemented.
- Wave 1 needs a Playwright mobile project (390×844) and an Arabic project;
  neither exists yet and both are cheap to add to `playwright.config.ts`.
- The Arabic error catalogue (AR-5) needs the backend `Localization`
  middleware to honour `Accept-Language` on `api/v1`; confirm that before the
  client starts sending it.
- The dedicated power endpoint's missing idempotency key (AW-15, recorded
  above) should be closed in Wave 1 or 2 so the contract is uniform.

## X. Final verdict

### Answers with evidence

- **Can a customer place an order now?** YES — the browser spec places an order from the catalogue, lands on the order page, and replays the same request with the same key to get the same order back; two contract mismatches (the header, then `lines` vs `items`) stood in the way and both are fixed and tested.
- **Can every existing VPS power control actually complete?** YES — shut down, start, force off (after its dialog) and reboot each answered 202 against the fake hypervisor and the row reflected each state.
- **Can a VPS be reinstalled from the portal?** YES — the typed-hostname dialog, a 202, and the row reading "Rebuilt" once the synchronous fake rebuild finished.
- **Can a dedicated server power/reinstall action complete against the controlled provider?** YES for power — power cycle, force off (after its dialog) and power on each answered 202 against the fake controller; the row shows what the controller last reported, by design. Reinstall: the request is accepted (202) with the serial typed; the fake install then stops at the state the controlled environment can reach (no PXE install profile), which the row reports as a rebuild state rather than an error. Completing a real dedicated rebuild remains BLOCKED_HARDWARE, as the audit recorded.
- **Can a customer change plan successfully?** YES — an upgrade quoted, confirmed in the dialog, answered 2xx, and the page reads "Your plan has changed"; the seeded subscription now governs a real machine so the resize has something to act on.
- **Does using the same idempotency key avoid duplicate logical work?** YES — contract test across five families, and the browser replay of the captured order request returns the same order.
- **Can a customer enable 2FA?** YES — wrong password refused on the field, correct password begins enrolment, the secret is shown, a TOTP computed in the spec confirms it, the recovery codes stay on screen, a reload shows "On", and the factor is turned off again with the password.
- **Does every VPS show valid CPU/RAM/Disk values?** YES — unit test asserts the exact strings and the absence of NaN/undefined; the browser suite renders the seeded rows.
- **Can any of the eight audited disruptive actions occur without a confirmation now?** NO — each has a dialog; unit and browser tests press cancel and confirm on each.
- **Does the UI distinguish a failed load from an empty result?** YES on the four screens the audit named (unit test on tokens; the shared `LoadFailure` on the other three).
- **Can a not-ready product still appear purchasable?** NO in production — eight backend tests walk every rung; outside production the shelf and the guard agree the other way, which is the documented rehearsal behaviour.
- **Can a controlled provider accidentally make a product appear sellable?** NO — all-fake providers cap at `ready_for_test`, the shelf stays empty in production and a declaration over it is refused 409.
- **Can a customer press reinstall while the current operation needs human review?** NO — the button is disabled on the API's own `actions.reinstall`, the reason is shown, and the endpoint still refuses 409 if called directly.
- **Did this wave change navigation architecture?** NO.
- **Did this wave start Wave 1 work?** NO.

### Verdict block

```
Customer Portal Wave 0

Status:
CLOSED

Starting HEAD:
08568f56f6c2fa52d105dff3e41595b1b43d64f3

Ending HEAD:
a9c656185f1456a8fc7942997d5125c192ef3b3b

Application areas changed:
api client transport; orders/vps/dedicated/plan-change/wallet mutation hooks;
VPS and dedicated pages; DNS, order detail, API tokens, sessions, team,
country/currency, two-factor sections; ConfirmDialog (cancelLabel); design
tokens; en/ar strings; VirtualMachine/DedicatedServer resources and
controllers (+actions); ReadsIdempotencyKey trait and PlaceOrderRequest;
IdempotencyKeyRejectedException; ProductSellability and the three catalogue
actions; E2E seeder (operable machine, BMC endpoint, subscription link);
backups route comment; capability matrix; OpenAPI schemas and document

P1 findings closed:
AR-1, AR-2, AR-3, AR-8, AR-14, AR-15 (plus AS-9, AS-15, AT-10)

P1 findings remaining:
AR-4, AR-5, AR-6, AR-7, AR-9, AR-10, AR-11, AR-12, AR-13 (Waves 1–4 as planned)

Backend:
php artisan test: 2824 passed, 0 failed (125,780 assertions), including 25 new tests across the contract, readiness, VPS and dedicated availability suites

Frontend:
vitest 103 passed (24 files, 12 new tests); eslint clean; tsc clean; production build succeeds

Browser:
Playwright 161 passed, 0 failed, one Chromium project, every provider a fake

PHPStan:
level 6, 0 errors on the full tree

Clean room:
fresh clone of the branch at a9c6561 into a scratch directory, its own database
(lynomia_cleanroom_w0) and its own E2E database and ports: composer install,
key:generate, migrate:fresh --seed, Pint, php artisan test (2824 passed,
125,771 assertions), PHPStan (0 errors), npm ci, typecheck, lint, vitest (103
passed), production build, OpenAPI lint (valid, 4 pre-existing description
warnings), Playwright (161 passed, 7.4 min) — all green. One exact external
exception: composer cannot authenticate against github.com through this
sandbox's egress proxy, so the PHPStan toolchain under tools/phpstan was
copied from the working copy's lockfile-installed vendor directory instead of
being installed by composer; the analysed code was the fresh clone. CI's own
"Install analysis toolchain" step ran that composer install unassisted and
passed

CI:
run 131 (id 34428007046, HEAD 8eb9b47) red on one pre-existing clock-dependent
browser spec in the Control Center sites suite (E2E-R1 vs E2E-R1PEL substring
match), all other jobs green; fix a9c6561 (exact-name locators, one spec file);
run 132 (id 34428925082, HEAD a9c6561) green on all nine jobs, attempt 1

Real infrastructure touched:
NO

Wave 1 started:
NO

Recommended next action:
Review this report; then approve Wave 1 (reach and language: full mobile
navigation with the locale switcher, Accept-Language on every request and the
translated error catalogue, status tones and keys, Arabic dates, loading
roles), which needs no product decision. Decide BD-4, BD-5, BD-7 and BD-10
before Wave 2 and Wave 3 are planned.
```
