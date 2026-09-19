# Customer Portal — Wave 5 closure report

Account and final polish: team and role truth, sessions and credentials,
profile, the design system, read and recovery states, accessibility, routes and
translations, responsive geometry, and the final technical, security and
performance pass.

This report is the independent closure of Wave 5. It was written after the
code was frozen, from the repository's own history rather than from memory, and
every number in it was produced by a command run against a fresh clone of the
frozen commit. Where a claim could not be produced that way, it says so.

---

## A. Provenance

| | |
| --- | --- |
| Repository | `fullstackfull/cloud` |
| Branch | `claude/hv-t6hq1p` |
| Wave 5 starting HEAD | `c075400ff38a742477804d4f69d7b30defd4c509` |
| Wave 5 ending **code** HEAD | `d3499881ae01f17d0fad7b01ff9a2d213fee7470` |
| Commits in Wave 5 | 26 |
| Change | 204 files, +18,925 / −1,252 |
| Code-head CI | Run **162**, id **35087196101**, attempt **1**, **success**, 9 / 9 jobs |
| Report HEAD | the commit that adds this file — see AK and the verdict |

The baseline was frozen before anything else was done. `git status` was clean,
the branch was `claude/hv-t6hq1p`, `git rev-parse HEAD` was `d349988`,
`git diff` was empty, and `origin/claude/hv-t6hq1p` pointed at the same commit.
No newer legitimate work existed anywhere: `git branch -a --contains d349988`
returns the branch and its remote and nothing else. Nothing was reset,
rewritten, force-pushed or squashed. From that point application code was
frozen; the closure found no blocker that required changing it, and none was
changed.

## B. Starting HEAD

`c075400ff38a742477804d4f69d7b30defd4c509` — *Add the customer portal Wave 4
closure report*. This is the last commit before Wave 5 and the commit the Wave
4 closure was declared at. Repository history confirms it: the next commit,
`e005fbe`, is the first Wave 5 commit.

## C. Ending code HEAD

`d3499881ae01f17d0fad7b01ff9a2d213fee7470` — *Make the touch-target floor
measure the screen it claims to*.

## D. Scope

Wave 5 was the account-and-polish wave. Nine sub-waves were requested and
landed:

- **W5.1** Account and team clarity: roles, the permission matrix, role change,
  ownership transfer
- **W5.2** Sessions and API tokens: device naming, credential restrictions and
  expiry, profile time zone, dynamic labels
- **W5.3** Profile, the customer API field audit and overposting
- **W5.4** The design system: form components, tones, tokens
- **W5.5** State quality: loading, empty, read failure, error boundary,
  offline, session expiry, unsaved input
- **W5.6** The accessibility sweep
- **W5.7** State and translation parity, the error catalogue, dead UI, the
  route inventory
- **W5.8** Responsive and visual polish at 393 and 360, English and Arabic
- **W5.9** Final technical, security and performance closure

## E. Explicit out-of-scope

Wave 5 did not attempt, and this report does not claim:

- any contact with real infrastructure, a real payment gateway, a real
  registrar or a real hosting panel;
- self-service account deletion or data export;
- a new cloud product, or any change to the product catalogue's shape;
- dark mode as a product feature (see Y and AN);
- idempotency for direct Dedicated power (see U);
- a generated OpenAPI client to replace the portal's handwritten response
  types (see X);
- a Data Router migration to close the in-app dirty-form gap (see AN);
- upgrading a major dependency during the wave or during closure (see AN).

## F. Fresh Wave 5 gap inventory

Wave 5 opened with a fresh inventory taken *after* Wave 4 rather than from the
original audit, because four waves of work had changed what was true. The
inventory is recorded in the W5.0 pass and its findings are what the eight
implementation sub-waves worked through: the team screen explained nothing; the
security screen printed raw user-agent strings; twenty-three form controls were
hand-written beside three that were not; a failed read rendered as an empty
list; focus was not moved deliberately on navigation; about thirty
server-chosen values reached the translator with the value itself as the
fallback; and no gate measured geometry at 393 or 360.

## G. W5.1 — Team and role truth

Commit `e005fbe`, 63 files, +3,142 / −260.

The team screen let an owner pick "Billing" from a dropdown and learn what it
meant by watching a colleague be refused. The role changed on the change event,
with no confirmation and no feedback. Handing the account over took
twenty-six characters of the account's ULID typed back.

The fix refuses the obvious one. A hand-written role × capability table in
React is a promise maintained in a file nobody opens when a permission moves;
it would start lying on the first release that moved one. So
`GET /team/roles` computes the matrix from `CustomerRole::permissions()` — the
same list `AuthorisesWithinAccount` reads before every write — and publishes
each capability's permission string beside its customer-facing id, so the
claim can be checked against a real 403 rather than taken on trust.

An architecture test asserts the published set and the enforced set are equal
in both directions, that every enforced permission is held by some role, and
that the permissions nothing enforces are exactly the two written down with
their reasons: `customer.close`, because closing an account is a support
conversation at launch, and `billing.methods.manage`, because the platform
stores no payment instrument.

Choosing a role now opens a confirmation listing what the person gains and
loses, diffed from that same matrix. It is a plain dialogue with a plain
button, because a role change is reversible and painting it the red of "destroy
this machine" teaches customers that the red means nothing. The one promotion
that earns a warning is the one that hands over membership itself, because
somebody who can manage members can remove whoever promoted them. Ownership
transfer now takes the account's own name.

W5.1 also removed four operator fields from customer surfaces, with a gate so
they cannot come back: the support ticket's assignee, the payment gateway's
registered driver name, `hardware_profile`, and the inventory join key a
customer read as `ded-standard-1`.

## H. W5.2 — Sessions, credentials and labels

Commit `752cb5b`, 34 files, +1,785 / −121.

Four screens stopped showing customers values they cannot act on, and each
replacement is bounded rather than merely prettier.

**Sessions and sign-in history.** The device column printed the raw user-agent
header truncated to the column width, and every desktop session begins with the
same sixty characters: four identical rows, one destructive button each, no way
to aim it. An ordered-regex parser returns at most one browser id and one
platform id — Edge before Chrome before Safari, iPad before macOS, because each
of those strings contains the names below it — and a shared hook turns the two
ids into a sentence in the reader's own language. No device model, no version,
no city: a user agent is a claim the client makes, and a row reading "iPhone 14
Pro in Kuwait City" would invent two facts to dress up a third. The header
stays in `title` for whoever quotes it to support. No dependency added.

**API tokens.** The list gained restriction and expiry columns, so a customer
auditing their own credentials can tell the one pinned to a build server's
address from the one that works from anywhere. Abilities are deliberately
absent: the field holds the wildcard on every token and is checked by nothing,
so printing "full access" from it would read a field as a promise.

**Profile time zone.** Chosen from `Intl.supportedValuesOf` — the browser's own
IANA database, and the same one that formats every date the customer then reads
— rather than typed against a validator that could not explain why "GMT+3" was
refused. UTC is added explicitly, because the canonical list has 418 named
places and none of them is UTC.

**Dynamic labels.** About thirty server-chosen values reached `t()` with
`{ defaultValue: theValue }`, which renders the value itself when the catalogue
has no sentence — an inventory join key, in English, on an Arabic page, styled
as a labelled fact. Deleting the fallback is worse, since `t()` then returns the
key path. `safeLabel` returns a written, translated sentence either way, and a
source gate over the customer directories keeps the old pattern out.

## I. W5.3 — Profile and the customer API field classification

W5.3 has no commit of its own, and this report does not invent one for
symmetry. Its work landed inside `e005fbe` and `752cb5b`: the customer API
field audit and the four operator fields removed with their gate are in the
first; the profile screen and its time zone are in the second. The overposting
half was carried forward and finished as a full write matrix in W5.9 (see Q).

## J. W5.4 — The design system

Commit `81ef0ae`, 23 files, +866 / −305.

Twenty-three form controls on customer screens were written by hand beside the
three field components that already existed: nine selects, five textareas,
three checkboxes, two file pickers, two radios, one text input. Each had its
own padding, border colour and focus ring. Six had a `<span>` where the
`<label>` should have been, which is a control a screen reader announces as
unlabelled. Four named `--border` and `--surface` directly instead of the field
tokens, so they did not move with the form around them. None carried
`aria-invalid` or wired an error to `aria-describedby`.

All twenty-three now go through the design system, which grew a `FileField` and
a `RadioGroup` to absorb the last two families. A source gate holds it: no raw
`select`, `textarea` or `input` outside the components whose job is wrapping
one, and no raw palette class anywhere on a customer screen. Verified at
closure: zero raw `<select>` elements remain on any customer screen; the only
two textual matches in the customer directories are inside comments explaining
what was removed.

The file picker keeps a real `<input type="file">` and styles the browser's own
button through `::file-selector-button`, rather than the usual hidden input
behind a styled label, which is how file pickers stop working with a keyboard.

**Two real defects.** `--danger-surface` and `--danger-surface-strong` were
defined in `:root` and in the dark media query but not in the
`[data-theme='dark']` stamp, so a customer who had chosen dark explicitly read
every destructive button in the light theme's red. And the five `--status-*`
tokens, defined three times over, were referenced by nothing. A gate now
checks both: every `var(--…)` resolves, and every theme-dependent token is
defined in all three theme states — bare `:root`, the media query, and the
stamp — because the viewer has three, not two.

## K. W5.5 — Reads, offline and a session that ended mid-click

Commit `9636494`, 27 files, +2,019 / −82.

A failed read is not an empty one, and a lost session is not a reason to resend.
The wave separated the two states everywhere they had been conflated, added the
shared read-failure surface to the screens that lacked it, gave the portal an
error boundary, and made an expired session say so rather than say the action
failed.

The mandatory property is that a customer's write is never replayed for them.
It is asserted by counting requests rather than by reading the screen, because a
replay is silent: the customer sees a dialogue, signs in, and the machine
reboots — which looks like the thing they asked for, because it is the thing
they asked for, five minutes ago, under a session that no longer existed. The
proof is in AI.

## L. W5.6 — Accessibility

Commits `e497eeb` and `cdff3ac`, 36 files, +3,031 / −77.

The skip link, deliberate focus on route change, dialogue focus and focus
return, table semantics, live regions, reduced motion, the touch-target floor,
and the Arabic equivalents of all of it. Four of the wave's own answers about
where focus goes were wrong before they were measured, which is recorded in the
commit that corrected them.

One of this sub-wave's gates was later found to be measuring the wrong thing.
W5.9 found it, and the finding is in AO and AM rather than hidden here: the
touch-target sweep measured screens before they had finished loading, so its
most important result proved nothing. It is now correct, and the screens it
covers are at or above the floor.

## M. W5.7 — Routes, address-bar state and translations

Commits `272c3d5`, `f8420a6` and `50912f4`, 34 files, +2,372 / −237.

The address bar is state: a chosen filter and a page number belong in the URL,
Back returns to them, and a direct refresh lands on the same screen. A key is
not a label: the dynamic-translation gate from W5.2 was extended, the error
catalogue was brought to English/Arabic parity, and the route inventory was
asserted against one canonical navigation source rather than maintained twice.

`50912f4` is the commit that asserts the one route map *and proves the
assertion* — a gate that cannot fail is not a gate, so the assertion was
broken deliberately and the failure observed.

## N. W5.8 — Visual and responsive

Commits `9f02c33`, `d8b20d1`, `c354b40`, `87821d6`, `9777024`, `7e30a3d`,
`bdd84aa`, 29 files, +2,176 / −144.

Programmatic geometry gates at 1440, 1024 and 768 in both languages, beside the
phone and narrow projects that measure 393 and 360 on real device descriptors.
What is asserted is that the page never scrolls sideways, that a wide table
scrolls inside the table, that dialogues fit the viewport, that long technical
values fold rather than break the page, and that the right-to-left start edge
is safe.

Four of the seven commits here are corrections to the capture and measurement
machinery rather than to the product, and they are listed as such in AM. The
most instructive: the Arabic runs clicked *reboot* instead of *reinstall*
because the locator matched `إعادة` — which means "re-" and prefixes both — and
then waited for a dialogue that was never going to appear. Both Arabic captures
were lost that way while both English ones passed, which is the kind of
asymmetry only a second language exposes.

## O. W5.9 — Technical, security and performance

Commits `a96f4e4`, `d6d0205`, `77754f8`, `bfa6f4b`, `f59c9da`, `cfd554f`,
`2d50159`, `fe09936`, `25263ed`, `d349988`, 25 files, +3,622 / −114.

The final pass, run on two rules.

**Measure, don't read.** Every section that could be probed at runtime was
probed at runtime. Source reading tells you what a serialiser appears to
publish; a request tells you what it publishes.

**422 is not a pass.** A FormRequest validates before the controller consults
tenancy or role, so a body the validator rejects never reaches the check being
tested. Recording 422 as a *failure* — "untested: the validator refused the
body first" — exposed six masked cases across two matrices. Three were
mistakes about field names and enums in the matrices themselves; three were
real blind spots (token minting needs `current_password`; backup deletion
confirms by hostname through `hash_equals`).

A corollary: a negative-only gate is vacuous without a positive twin. Each
matrix gained one — the own-tenant walk beside the cross-tenant matrix, arrival
reads beside the idle-polling assertion, a minimum paired-schema count beside
the drift scan.

Six defects were found and fixed. They are in AL.

---

## P. Customer API boundary

`TheCustomerSurfaceCarriesNoOperatorDataTest` walks **51 customer GET
addresses** against an account built with one of every customer-reachable
object, collects every key at every depth — **317 of them** — and checks each
against **34 banned substrings**, with an **11-entry allow-list** where each
entry carries a written reason. The allow-list is checked for staleness,
because an exemption nobody can remember the reason for is how a boundary stops
being one. Result: **zero leaks**.

Genuinely customer-facing product labels are preserved, cPanel among them. That
is exactly why the allow-list exists rather than a blanket ban on
provider-sounding words.

A second test attaches an operator internal note naming a node and a watch list
to a ticket, reads the ticket as the customer, and asserts that neither appears,
that the customer's own message *is* still present, and that every rendered
message has `is_internal_note === false`. The protection that earns its place
is fail-closed composition: `TicketResource` reads only
`customerVisibleMessages`, so a miswired controller renders an empty list
rather than operator notes.

`NoCustomerResourceCarriesOperatorDetailTest` holds the same line from the
source side, on the four fields W5.1 removed.

## Q. Overposting

`TheFinalOverpostingMatrixTest`: **12 cases, 70 assertions, zero overposting.**
Sentinel values are posted into every named forbidden field — another account's
id, a foreign provider id, money, state — and swept for afterwards. Refusals
include the response body in the failure message, so a wrong expectation cannot
pass as a right one.

Frontend omission is nowhere treated as security: every case is a direct
request carrying fields the portal never offers.

The posture is recorded rather than assumed. Re-counted at closure: of the
**94** Eloquent models that extend `Model`, `Authenticatable` or `Pivot`,
**76 declare a non-empty `$guarded`**, **17 declare `$guarded = []`**, **none
declares `$fillable`**, and one relies on the framework default. The
ninety-fifth model is `ApiKeys\…\PersonalAccessToken`, which extends Sanctum's
own token class rather than `Model` — and it is the subtlety written into the
W5.9 fixtures because it cost time to find: **Sanctum's base class declares its
own `$fillable`, which beats a subclass's `$guarded`.**

Three of the matrix's own assertions were wrong about the product when first
written — a zone legitimately reaches `active`, a backup legitimately reaches
`succeeded` — and were re-expressed as comparisons against a poison-free
request, or documented as uncomparable with the reason stated. That is recorded
in AM as a test defect, not a product one.

## R. Tenant isolation

`TheFinalTenantIsolationMatrixTest`: **77 cases, zero cross-tenant findings.**
Each case records object, action, method, URI, expected and actual, as
executable assertions rather than a report table.

The attacker is the Owner of their own account, so no capability check can mask
a tenancy hole. Tenancy is by construction: every lookup starts from the acting
customer —
`CustomerVirtualMachines::of($acting)->whereKey($id)->firstOrFail()` — so a
foreign id is not in the result set, and **404 is indistinguishable from
nonexistent**. The matrix scores 2xx as `← CROSS-TENANT BREACH`, 403 as
`← discloses existence`, and 422 as `← untested`.

Indirect references are covered by nested-child cases:
`->where('virtual_machine_id', $machine->getKey())->whereKey($backup)`. **The
owned parent does not authorise a foreign child.**

The positive twin walks **19 of the same addresses as their owner** and requires
200, so the matrix cannot pass by addressing nothing. A guard asserts the case
count has not shrunk.

## S. Authorization parity

`TheFinalAuthorisationParityTest` reads the matrix from
`GET /api/v1/team/roles` and exercises one live endpoint per capability:
**45 checks — five roles times nine capabilities — zero disagreements.** The
count is asserted exactly, so a change in the matrix's shape is a test failure
rather than a silent reduction in coverage. The refusal spread is asserted to
fall between a fifth and a half, so a matrix that refuses everything or nothing
fails.

Nothing here consults the frontend. No role gains access because navigation
shows or hides a control.

The distinction that holds throughout: **404 for strangers**, **403 for members
whose role forbids it** — because `AuthorisesWithinAccount` runs before any
lookup.

## T. Money

Integer minor units with an ISO currency, throughout. `brick/money` carries the
real exponents, so KWD is three decimals, USD two and JPY zero. Signatures are
`int|string` so a float cannot coerce in. The default rounding mode is
`RoundingMode::Unnecessary`, which makes a rounding decision an explicit one.
`assertSameCurrency` guards plus and minus, so unlike currencies are never
aggregated.

The browser has no authority over an amount: the portal renders what the API
published and a payment is paid when the server says so, never when the browser
returns from a gateway. Wave 5 changed nothing on any money path; the closure
re-ran the money suites and the money browser journeys in English and Arabic.

## U. Idempotency

The `Idempotency-Key` header is the transport for every guarded write — the
closure of AR-1 — and the W5.9 matrices send a fresh key per case, so the
transport is exercised 77 more times than it was.

**Dedicated power idempotency: DEFERRED — ARCHITECTURE ITEM.** Dedicated power
talks to a controller directly and has no durable operation row. Reading its
reported state is not a claim of durable idempotency. Wave 5 did not change
that architecture and this closure did not touch it.

## V. Async and indeterminate truth

Accepted is not completed. Queued is not succeeded. Indeterminate is not
failed. Needs-review is not failed. This is what made the DNS defect in AL a
defect rather than a cosmetic gap: `Indeterminate` and `NeedsReview` are states
a record legitimately sits in, and a customer must be able to correct a record
in them.

## W. Activity safety and boundedness

No operator audit rows reach the customer feed. Notifications are not rendered
as history. Every entry names a genuine actor, and a NULL actor stays *system*
or *unknown* — it is never inferred to be the account owner.

Boundedness is asserted as scale invariance rather than as a magic number.
`TheDashboardAndFeedStayBoundedTest` proves:

- the activity feed costs the same at five machines as at forty-five;
- the dashboard costs the same at five machines as at forty-five;
- the unread count is one query whatever the inbox holds;
- a category filter reads fewer branches than the whole feed.

The Wave 4 structural baseline — Activity 7, Dashboard 23, Unread 2 — remains
recorded as information. It is not enforced as a constant, because a gate that
fails when 7 becomes 8 measures churn rather than boundedness.

## X. OpenAPI and client contract drift

`TheClientAndTheDescriptionAgreeTest` compares `docs/openapi.yaml` against the
portal's handwritten response types in `lib/types.ts` and
`features/auth/useAuth.ts`, following `extends` and one written alias
(`AuthenticatedUser` → `User`). It is a bounded drift gate, and it says what it
is: a scanner, not a YAML parser. A third test checks known properties by name,
so a silently-broken scanner cannot pass.

This gate found three of the six W5.9 defects. No frontend code-generation
migration was launched, and no generated client was introduced: the goal was
that critical drift is *detected*, not that the portal is rewritten.

The API description was corrected where it was wrong. It was not expanded to
make documentation symmetrical.

## Y. English

English is the portal's first language and every screen was walked in it. The
error catalogue, the status vocabulary and the state labels are asserted
complete from one source. Two English copy nits from the original audit remain
open and are recorded in AN rather than quietly closed.

## Z. Arabic and right-to-left

The Arabic project runs with the browser's own language set to Arabic, so the
portal picks it the way a customer's browser would, rather than by a test
flipping a flag. Thirty-two Arabic browser tests cover navigation, money,
resource pages, accessibility and "what is happening", and they assert the
things that only break in the second language: that amounts are not mirrored,
that a time zone reads as a place rather than as mirrored glyphs, that the role
matrix reads as a table of words, that a destructive action asks first *in
Arabic* and takes the cancellation, and that relative time is Arabic relative
time.

The error catalogue is at English/Arabic parity, asserted by a gate rather than
by inspection.

## AA. Mobile at 393

The `customer-mobile` project runs on the Pixel 5 descriptor — 393 × 851, touch,
mobile user agent — which is the closest descriptor Playwright ships to the
390 × 844 phone the original audit measured against. It runs `e2e/mobile/` and
also `e2e/narrow/`, so every narrow assertion is made at 393 as well as at 360
rather than at one width with the other inferred.

## AB. Narrow at 360

`customer-narrow` is a separate project on the Galaxy S8 descriptor — exactly
360 × 740, touch, mobile user agent — rather than a viewport option on the
project above. 360 is the narrowest width the portal claims to work at, and it
is not 393 minus a bit: a row that fits at 393 with four pixels to spare fails
at 360, and the failure has to name which width it was.

## AC. Accessibility

Re-run at closure: the skip link, deliberate focus on route change, dialogue
focus and focus return, table semantics, live regions, reduced motion, the
touch-target floor and the Arabic equivalents.

The touch-target gate now settles before it measures — two consecutive readings
agreeing on control count, not a fixed wait that would get tuned until it
passed — and fails with a stated reason if a screen never stops drawing. The
drawer test measures inside the drawer and asserts that more than ten
destinations were found, so an empty drawer is not a pass. Result: **zero
targets below the tested 24 × 24 CSS-pixel floor on all nine covered screens**,
and the exemption list is empty.

**This is not a WCAG conformance claim.** The floor is a measurement against a
published number (WCAG 2.2 SC 2.5.8). The spacing exception the same criterion
allows is not tested, so nothing here is a certification, and nothing in Wave 5
claims one.

## AD. Visual review

The 378-capture review from W5.8 already exists and was not regenerated. What
was re-run for closure is the programmatic geometry regression, which is the
part that can fail: the width gates at 1440, 1024 and 768 in both languages,
plus the 393 and 360 projects.

One application stylesheet change landed after W5.8 — the `.tap-link` utility
in W5.9 — and it was chosen specifically so that it cannot move anything W5.8
measured. Vertical padding grows the border box, which is what a finger hits
and what `getBoundingClientRect` reports, without touching the line box, so the
text does not move; the negative margin cancels the growth on the inline-block
links and is ignored on the inline ones, where vertical margins do nothing. A
`min-height` with `inline-flex` was tried first and rejected for exactly the
reason that would have required a full visual re-review: it needs a block-ish
display to apply, several of these links are `truncate` or sit inline in a flow
of text, and changing their display would have rearranged layouts W5.8 measured
at five widths.

The geometry gates then re-measured those layouts and found them unchanged.

## AE. Backend final regression

Run in the clean room, sequentially, one suite in one process.

| Step | Result |
| --- | --- |
| `./vendor/bin/pint --test` | **passed** |
| PHPStan | **0 errors** |
| `php artisan test --env=testing` | **3,027 tests, 3,027 passed, 138,182 assertions, 0 failures** (373.6 s) |

## AF. Frontend final regression

Run in the clean room from `npm ci` with no copied `node_modules`.

| Step | Result |
| --- | --- |
| `tsc -b` | clean |
| `eslint .` | clean, warning-free |
| `vitest run` | **81 files, 443 tests, all passed** |
| `npm run build` | **built** — 272 modules, `index.css` 36.38 kB (gzip 7.53), `index.js` 935.63 kB (gzip 244.84) |
| `npm run openapi:lint` | **valid**, 6 warnings |

The build's only advisory is Vite's chunk-size note at 500 kB; it is a
suggestion to code-split, not an error, and it is unchanged by Wave 5. The six
OpenAPI warnings are Redocly style warnings — one of them is the controlled
gateway's documented `302`, which has no 2xx response by design.

## AG. Browser final regression

Run in the clean room, all four projects, against a freshly created
`lynomia_e2e_cleanroom_w5` database and a flushed Redis.

| Project | Result | Duration |
| --- | --- | --- |
| `chromium` | **269 passed, 14 skipped, 0 failed** | 20.4 m |
| `customer-mobile` (Pixel 5, 393 × 851) | **48 passed, 0 failed** | 4.7 m |
| `customer-narrow` (Galaxy S8, 360 × 740) | **15 passed, 0 failed** | 2.6 m |
| `customer-arabic` (browser language `ar`) | **32 passed, 0 failed** | 1.7 m |
| **Total** | **364 passed, 14 skipped, 0 failed** | |

Run project by project rather than as one invocation, so that a container
restart could not erase the whole record — which is exactly what happened to
the first attempt at the working tree earlier in the day (AM). Each project's
verdict was appended as it landed.

The same 364 / 14 / 0 was produced three times independently at this commit: in
the working tree, in the clean room, and by CI's **Browser end-to-end** job in
run 162. The fourteen skips are the suite's own conditional specs, unchanged
since W5.8.

## AH. Security final regression

| Gate | Measure | Result |
| --- | --- | --- |
| Tenant isolation | 77 cross-tenant cases + 19-address own-tenant walk | 0 findings |
| Customer surface | 51 GET addresses, 317 keys, 34 banned substrings, 11 reasoned exemptions | 0 leaks |
| Operator notes | internal note + watch list attached, read as the customer | not present; own message still present |
| Overposting | 12 cases, 70 assertions, sentinel sweep | 0 overposting |
| Authorization parity | 45 checks from the published matrix | 0 disagreements |
| Sessions and tokens | `SessionCookiesCarrySecureUnderForcedHttpsTest`, `EveryAuthenticatedRouteIsThrottledTest`, `UnacceptedMembershipGrantsNoRoleTest`, token-shown-once browser test | all pass |
| Secret leakage | `DefaultLogStackIsRedactedTest`, `ExceptionChainSecretRedactionTest`, `RedactionFailsClosedWhenPcreAbortsTest`, CI's committed-secret gate | all pass |
| Error-response safety | `ForbiddenAlwaysAnswersWithTheDocumentedCodeTest`, `UnauthenticatedRequestsAnswerJsonTest` | all pass |
| `composer audit` | backend dependency tree | **no advisories** |
| `npm audit` | frontend dependency tree | **2 moderate**, both the same advisory (see AN) |

No secret, private key, provider credential, BMC or panel password, Cloudflare
or registrar token, payment or SMTP secret, database password or WordPress
administrator credential is committed to the repository, present in any
document, or written to any log. `current_password` is never logged, persisted
or echoed. Registrant PII is not logged.

## AI. Performance final regression

`TheDashboardAndFeedStayBoundedTest`, re-run in the clean room as part of the
full suite:

| Assertion | Result |
| --- | --- |
| Activity feed: 5 machines == 45 machines | equal |
| Dashboard: 5 machines == 45 machines | equal |
| Unread count: one query whatever the inbox holds | one |
| A category filter reads fewer branches than the whole feed | fewer |

The W5.5 no-replay proof, which is mandatory, is
`src/app/__tests__/a-session-that-ended-mid-click.test.tsx`. It counts requests
rather than reading the screen, and it chose `POST /vps/{id}/power`
deliberately over something harmless: a reboot is idempotent in shape and
emphatically not in effect.

```
one deliberate press                      → 1 power request
session expires, the dialogue appears     → still 1
the session returns, /me is refetched     → still 1
the window is focused                     → still 1
the network reconnects                    → still 1
client.resumePausedMutations() explicitly → still 1
the customer presses again, deliberately  → exactly 2
```

## AJ. Clean room

A genuine `git clone --no-local --no-hardlinks` of the frozen commit into an
empty directory, checked out detached at `d349988`. Verified before anything
was installed: no `vendor/`, no `node_modules/`, no `tools/phpstan/vendor`, no
`.env`, no build output.

| Step | Result |
| --- | --- |
| Fresh clone at `d349988` | OK, nothing carried over |
| `composer install --no-interaction` (control-plane) | OK |
| `npm ci` (workspace root) | OK — 323 packages |
| `php artisan key:generate` | OK |
| Fresh PostgreSQL database `lynomia_cleanroom_w5`, created empty | OK — 0 tables before |
| `php artisan migrate:fresh --seed --env=testing --force` | OK — **55 migrations, 109 tables**, seeded through the repository's own seeders |
| `migrate:rollback --step=100` then `migrate` | OK — 109 tables → 1 → 109 |
| `pint --test` | passed |
| PHPStan | 0 errors (toolchain note below) |
| `php artisan test --env=testing` | 3,027 / 3,027, 138,182 assertions |
| `tsc -b`, `eslint .`, `vitest run`, `npm run build` | all clean |
| `npm run openapi:lint` | valid |
| `composer audit`, `npm audit` | recorded in AH and AN |
| Browser suite, four projects, fresh E2E database | recorded in AG |

No database was copied from the working tree. Both databases were created empty
and migrated from zero.

### The one toolchain that was not clean-installed

**Stated plainly, because it is the one thing in this run that did not come from
the clone.** `apps/control-plane/tools/phpstan` has its own `composer.json`,
and installing it needs `github.com`. The failure was reproduced deliberately
for this report, twice — once with the default install and once with
`--prefer-source`:

```
In AuthHelper.php line 132:
  Could not authenticate against github.com
```

So the clean room's PHPStan ran against an analysis toolchain **copied from the
working copy's own lockfile-installed `vendor` directory**.

> **PHPStan toolchain copied — not clean-installed.**

That step is not clean room and is not described as such. CI remains the
independent proof that the toolchain installs from the network and runs: the
**Static analysis** job in run 162 installs it in its own "Install analysis
toolchain" step and passes.

The application's own `composer install` did succeed inside the clean room, but
by cloning 113 packages from source rather than fetching dist archives — the
same proxy restriction, taking the fallback path. It produced a working
install; it is noted because it explains a 3.3 GB `vendor` directory and a slow
step, not because it weakens the result.

## AK. CI

### Code head

| | |
| --- | --- |
| Run | **162** |
| Id | **35087196101** |
| Attempt | **1** |
| SHA | `d3499881ae01f17d0fad7b01ff9a2d213fee7470` |
| Conclusion | **success** |

All nine jobs, by name: **Backend (PHP 8.4, PostgreSQL 16)** success,
**Backend (PHP 8.4, PostgreSQL 18)** success, **Static analysis** success,
**Frontend** success, **API description** success, **Browser end-to-end**
success, **Security checks** success, **Infrastructure validation** success,
**Production guards** success.

The backend matrix runs on two PostgreSQL majors, which is why nine jobs come
from eight job definitions.

### Wave 5's CI history, in full

Not only the green ones. `concurrency.cancel-in-progress` is on, so a push
while a run is in flight cancels it; several "cancelled" rows below are that
and nothing more.

| Run | SHA | Conclusion | Note |
| --- | --- | --- | --- |
| 148 | `752cb5b` | failure | Browser end-to-end only; the other 8 jobs green |
| 149 | `81ef0ae` | failure | Browser end-to-end only; the other 8 jobs green |
| 150 | `9636494` | success | |
| 151 | `e497eeb` | failure | Browser end-to-end only; the other 8 jobs green |
| 152 | `cdff3ac` | success | |
| 153 | `272c3d5` | cancelled | Both backend jobs had already failed at the code-style step before the fixing push cancelled the run — so the recorded conclusion is *cancelled* and the truth is *a Pint failure*. The cause is in AM. |
| 154 | `f8420a6` | cancelled | superseded by the next push |
| 155 | `50912f4` | success | |
| 156 | `c354b40` | cancelled | superseded |
| 157 | `87821d6` | cancelled | superseded |
| 158 | `9777024` | cancelled | superseded |
| 159 | `7e30a3d` | cancelled | superseded |
| 160 | `bdd84aa` | success | W5.8 closed here |
| 161 | `25263ed` | success | |
| 162 | `d349988` | **success** | the frozen code head |

The lesson recorded rather than smoothed over: a run whose conclusion reads
`cancelled` is not evidence of anything, and one that reads `cancelled` may be
hiding a real failure. Only runs 160, 161 and 162 are evidence, and only 162 is
evidence about the frozen commit.

### Report head

This report is a documentation-only commit on top of `d349988`. It changes no
application code, no test, no migration and no workflow. That is a reason to
expect CI to pass, not a reason to assume it: the run is recorded in the
verdict, and Wave 5 is not closed until it is green.

## AL. Defects discovered during Wave 5

Six were found in W5.9, the final pass, and each carries a proving test that was
broken deliberately to confirm it fails.

**1 — A DNS record in `Pending`, `Indeterminate` or `NeedsReview` could not be
corrected.** `DnsState::isEditable()` admitted those states, so the portal
offered the edit; `allowed()` did not list `Pending` as a legal target from
them, so the transition threw and the customer got a 500 on a reachable action.
Two tables in one enum disagreeing. Fixed by adding `Pending` to the three
lists, each with a written reason.
*Proof:* `EditingARecordThatHasNotPublishedYetTest`, a data provider over every
state `isEditable()` admits, plus one test stating the invariant beside the
table. **PRODUCT DEFECT.**

**2 — Every order line rendered an empty name.** The portal read
`item.description`; the orders endpoint publishes `item.name`. The page's own
test encoded the same mistake, so the suite agreed with the page about a field
the API has never returned, and a customer opening an order saw quantities and
prices with nothing naming them. **PRODUCT DEFECT** (with a test defect beside
it — see AM).

**3 — `StartedPayment.failure_message` was a stale contract.** A prior wave
deliberately dropped the field from the API; the type and the hook still read
it. **PRODUCT DEFECT** (dead read).

**4 — The one-time token response was documented as carrying only the token.**
`IssuedApiToken` had one property and `additionalProperties: false` — not an
omission but an active claim that nothing else is there, on a response that in
fact carries the token's id, name, abilities, address restrictions, limits and
dates alongside the secret. **PRODUCT DEFECT** (in the published description),
and the gate that should have caught it was blind — see AM.

**5 — A `/favicon.ico` 404 on every page load.** Found by the console walk.
Fixed with an inline SVG data URI in the design system's own colours; no new
asset, no build step. **PRODUCT DEFECT** (minor).

**6 — The W5.6 touch-target sweep measured loading screens.** The largest
finding, and it indicts a gate rather than a screen. The sweep measured as soon
as the level-1 heading was visible, and on the dashboard the heading belongs to
the page shell while the cards and rows are still being fetched. So it measured
a shell, found only the shell's controls — all comfortably over the floor — and
its own "controls were found to measure" guard was satisfied by them. The screen
with the most inline links in rows and cards was the screen it proved the least
about.

```
immediately after the heading appears     0 under 24px
after the screen settles                 22 under 24px
```

Full extent: dashboard 22, invoices 3, DNS 2; zero on machines, a machine
detail, team, security, API tokens, support and profile. All were inline text
links inside rows and cards, where `text-sm` gives a 20px line box and
`text-xs` a 16px one, and a bare link is exactly as tall as its own text. Fixed
with `.tap-link` at 15 link sites; the gate now settles before measuring and
scopes the drawer test to the drawer.

Classified as both: **TEST DEFECT** (the gate proved nothing about the screen it
claimed to measure) and **PRODUCT DEFECT** (27 real targets under the floor,
which the gate had been hiding). Verified by deliberate breakage: with
`.tap-link`'s declarations emptied and nothing else changed, the gate fails on
exactly three screens and reports 22, 3 and 2.

## AM. Regressions, self-caught test defects, harness and process issues

Recorded because a closure report that only contains successes is not evidence
of anything.

### Test defects

| What | Detail |
| --- | --- |
| The order-line fixture agreed with the page | The fixture named the line `description`, the page read `item.description`, and nothing asserted the line was *identified* on screen. The suite therefore agreed with the page about a field the API has never returned. Fixed, and a test added that asserts the name is rendered. |
| `OpenApiSpecificationTest` could not see through `array_merge` | It reads a resource's published fields from literal `'key' =>` pairs. `IssuedApiTokenResource` has exactly one, because everything else arrives through `array_merge((new ApiTokenResource(...))->toArray($request), …)`. So the reader saw one field and the schema had been written to match the reader. The reader now follows a merge — and refuses to follow a resource used as a *value* (`'messages' => TicketMessageResource::collection(...)`), whose fields belong to a nested object, not to the parent. |
| The touch-target gate measured loading screens | See AL 6. |
| The drawer test measured the whole page | It passed only because the dashboard behind the open drawer had not finished loading. On a run where the dashboard arrived first it failed and named dashboard links as offenders "in the open drawer" — which is how the settling problem was found. |
| Three overposting assertions were wrong about the product | A zone legitimately reaches `active`; a backup legitimately reaches `succeeded`. Re-expressed as comparisons against a poison-free request, or documented as uncomparable with the reason. |
| `§21` dead-code scan produced 8 phantom findings | Keying controllers by file basename attributed Admin routes to same-named customer controllers. Keyed by fully-qualified class name, the true answer is zero unreachable methods. |
| The console spec asserted something must be read while idle | The design is to go quiet when nothing is unfinished. The assertion was inverted, then paired with arrival reads so that "quiet" cannot be satisfied by a walk that never happened. |
| An Arabic locator matched a prefix | `/reinstall|إعادة/i` — `إعادة` means "re-" and prefixes *reboot* as well as *reinstall*. The Arabic runs clicked reboot and waited for a dialogue that never came. Both Arabic captures were lost; both English ones passed. |
| Three narrow tests failed on a strict-mode violation, and only sometimes | The specs shared fixtures with each other. Each now names its own. |

### Harness defects

| What | Detail |
| --- | --- |
| Two backend suites run in parallel corrupted the shared test database | A deadlock on `services_region_id_foreign` and a missing `node_capacity_reservations`. **Both results were discarded** and the incident recorded in a commit message. One suite at a time since — including in this closure, where the backend suite ran alone in a single process. |
| The container restarted mid-run | The first full browser run of the frozen commit was lost 12 tests in. It was re-run project by project, appending each verdict as it landed, so a second restart could not erase the whole record. The number in AG is from a completed run, not from a reconstruction. |
| `pgrep -fa` matches its own shell | It produced a false "still running" that blocked a suite. Replaced with a match on `ps -eo cmd` for the real process. |
| The fake backup provider's fixed task id collides | `UPID:fake:1` violates the real unique index over `(provider, provider_task_id)` when two backups are taken in one test. Documented where it bites rather than worked around silently. |
| `dispatchAfterCommit` jobs never run under `RefreshDatabase` | A property of the harness, not of the product. Written into the tests that would otherwise look wrong. |

### Process issues

| What | Detail |
| --- | --- |
| Push cadence cancelled six CI runs | `cancel-in-progress` is on. Runs 153, 154, 156, 157, 158 and 159 are cancellations from the next push, and one of them (153) was hiding a real Pint failure. A `cancelled` conclusion is not evidence. |
| A Pint failure I caused myself | I ran `pint --test` against the one test file W5.3 touched and not against the architecture test W5.7 edited, where a script had re-sorted the import block. Pint sorts `Shared\` before `SharedHosting\`; a plain lexicographic sort does not. Both backend jobs failed at the code-style step, which skipped the tests behind them. |
| **The first clean-room browser run was aborted by me, for a mistake of mine** | I built the clean room's `.env` from `.env.testing.example`, which sets `SESSION_DRIVER=array` and `CACHE_STORE=array`. Those are right for PHPUnit, which runs in one process, and wrong for a browser suite, whose sessions must survive between requests. The run produced 56 failures across sign-in, sign-out, credential and Control Center specs before I stopped it. It was **not** a product result and is not reported as one: the numbers in AG come only from the completed re-run. The clean room was then rebuilt the way CI's own "Prepare environment" step does it — `cp .env.example .env`, which is `SESSION_DRIVER=database` and `CACHE_STORE=redis` — with Redis flushed and the E2E database dropped and recreated, and the suite re-run from the start. **PROCESS ISSUE.** |

The last one is worth stating plainly rather than burying: the closure's first
browser evidence was invalid because of how I had prepared the environment, and
the correct response was to throw it away and re-run rather than to explain it.

## AN. Deferred and carried items

None of these is a launch blocker, and none is being converted into one after
the fact. Several are recommendations from the original audit that were never
promised for Wave 5.

| Item | Classification | Detail |
| --- | --- | --- |
| Dedicated power idempotency | **DEFERRED — ARCHITECTURE ITEM** | No durable operation row exists. Unchanged by Wave 5 and by this closure. |
| 27 dynamically-composed translation keys | **CARRIED** | Not proven dead. The source gate prevents new unsafe dynamic labels; proving these 27 dead requires runtime evidence the portal does not currently produce. |
| 3 FUTURE_PREPARED operator hooks | **CARRIED** | Declared capabilities with no consumer yet. Deliberately not deleted: `§21` deletes only code proven dead, and a prepared operator capability is not dead code. |
| In-app dirty-form navigation gap | **DEFERRED_LAUNCH** | Closing it properly needs a Data Router migration, which Wave 5 was explicitly told not to start. A browser-level unload warning is in place; an in-app route change is not intercepted. |
| Loading region without a skeleton redesign | **FUTURE** | The loading region is announced (`role="status"`) and translated. Skeletons are a design change, not a defect (audit AT-4, P4). |
| Handwritten frontend response types | **CARRIED**, with bounded protection | Not mass-converted. The W5.9 drift gate now detects critical divergence between `docs/openapi.yaml` and the portal's types, which is what a full generated client would have bought at a fraction of the risk. |
| Dark-theme path | **PRE-EXISTING** | See the wording note below. |
| `@vitest/mocker` advisory GHSA-82fw-gwwq-j7x9 | **DEFERRED — ARCHITECTURE ITEM** | 2 moderate findings, both the same advisory: path traversal / arbitrary file read, reachable only through `vitest`. A development-only test runner; nothing it contains reaches a browser or a production install. The only remedy is `vitest@5`, a major upgrade, which closure is not the place for. Re-verified at closure: still exactly 2 moderate, still the same advisory, backend still 0 advisories. |
| A generated OpenAPI client | **FUTURE** | If the handwritten types ever become a maintenance problem, this is the answer. They are not one today. |
| AT-4 skeletons, AT-8 terms link, AT-9 notification-preference placement, AT-13 empty states without a call to action, AT-16 status-page link, AT-18 the "A A record" article | **OPEN — P3/P4 copy and UX recommendations** | Independently re-verified as still open at the frozen commit. AT-18 is exact: `sprintf('A %s record needs %s.', $type->value, $expected)` renders "A A record needs an IPv4 address." — `MX` is special-cased correctly and the vowel-initial types are not. None of these was claimed closed by any wave. |
| AT-7 "Published out of band", AT-15 the two "Sign out" controls | **MITIGATED** | The reverse-DNS hint now carries a second sentence explaining what it means; the session list now marks "This device" and confirms before revoking. The original wording complaints stand. |

### Dark-theme wording, stated exactly

**Dark-theme path: PRE-EXISTING.** Wave 5 did not introduce or expand dark mode
as a product feature. It repaired defects affecting the theme path that already
existed: two tokens that were defined in `:root` and in the
`prefers-color-scheme` media query but not in the `[data-theme='dark']` stamp,
so a customer who had chosen dark explicitly read every destructive button in
the light theme's red.

The path demonstrably exists — three theme states in `styles/index.css`, a gate
asserting every theme-dependent token is defined in all three, and a
`dark-theme.e2e.ts` browser spec. There is **no product toggle**: no component
writes `data-theme`, so a customer reaches the dark theme through their
operating system's setting. The original audit's AT-17 ("no dark theme; tokens
exist for one theme only") is therefore **NO_LONGER_ACCURATE** as written, and
the honest current statement is the one above.

### The rest of the audit's findings, reconciled

Not every P2, P3 and P4 recommendation was implemented, and this report does
not claim otherwise. Each was re-checked against the frozen commit rather than
against a previous report's claim. The classifications are: **CLOSED** (the
problem is gone and something holds it), **MITIGATED** (materially improved,
the original complaint partly stands), **DEFERRED_LAUNCH** (a decision was made
to carry it), **FUTURE** (a recommendation, never promised), **NO_LONGER
ACCURATE** (the finding's premise is no longer true).

#### P2 (AS-1 … AS-20)

| # | Subject | Class | Evidence at `d349988` |
| --- | --- | --- | --- |
| AS-1 | Product page: tax, setup fee, renewal, location, raw attribute keys | CLOSED | `features/catalog/ProductPage.tsx` renders the server quote; `__tests__/the-price-before-you-buy.test.tsx` |
| AS-2 | Orders, invoices, subscriptions, services unlinked | CLOSED | the order chain and invoice detail link by id, not by amount; `features/orders/__tests__/the-order-chain.test.tsx` |
| AS-3 | No wallet ledger | CLOSED | `wallet/transactions` is read and rendered |
| AS-4 | No payments view | CLOSED | `features/payments/` — `PaymentsPage`, `PaymentNextAction`, `ControlledGatewayPage` |
| AS-5 | Hosting: panel unnamed, no usage | CLOSED | `panel_type` and usage are read on the hosting surfaces |
| AS-6 | Domains: renew, auto-renew, contacts, transfer-in | CLOSED | all four are wired in `features/domains/`; `e2e/domains.e2e.ts` |
| AS-7 | Backups unreachable from the machine | CLOSED | backups live on the machine's resource page; `e2e/backup-files.e2e.ts`, `e2e/backup-deletion.e2e.ts` |
| AS-8 | Untoned / untranslated status badges | CLOSED | one canonical vocabulary with a parity gate — `i18n/__tests__` and `EveryStateAScreenShowsIsTranslatedTest` |
| AS-9 | Reinstall enabled while a rebuild is indeterminate | CLOSED | Wave 0; the blocked reason is published and the control is disabled with it |
| AS-10 | Arabic domain dates scrambled | CLOSED | dates are formatted by locale; `e2e/arabic/` asserts they are not mirrored |
| AS-11 | No unread badge; "Open" landed on list pages | CLOSED | `app/UnreadBadge.tsx` and `app/__tests__/the-unread-badge.test.tsx`; notification links resolve to resource pages |
| AS-12 | Team: no permission table, no confirmation | CLOSED | W5.1 — the computed matrix, the gain/lose confirmation, ownership by account name |
| AS-13 | Raw user agents; tokens without restrictions or expiry | CLOSED | W5.2 — `lib/useDeviceName.ts`, restriction and expiry columns |
| AS-14 | Support could not reference a service or invoice | CLOSED | the ticket form carries both; contextual "ask support about this" from failure states |
| AS-15 | `LoadFailure` missing on four screens | CLOSED | the shared failure surface is referenced from 65 files, 61 of them under `features/`; W5.5 separated failed from empty everywhere |
| AS-16 | "Loading…" in 51 places without `role="status"` | CLOSED | one `Loading` component with `role="status"`, translated; `components/__tests__/loading.test.tsx` |
| AS-17 | `user.timezone` collected and never applied | CLOSED | applied in the shared formatter; relative time in use |
| AS-18 | 1440 layouts leave ~200px unused each side | CLOSED | the shell was widened for data pages in Wave 3; the 1440 geometry gate measures the result |
| AS-19 | Operator detail published to customers | CLOSED | W5.1 removed four fields; two gates hold the line (`NoCustomerResourceCarriesOperatorDetailTest` from source, `TheCustomerSurfaceCarriesNoOperatorDataTest` from the wire) |
| AS-20 | Three purchase entry points shared no wording | CLOSED | one buying vocabulary, asserted in Wave 3 |

#### P3 / P4 (AT-1 … AT-18)

| # | Subject | Class | Evidence at `d349988` |
| --- | --- | --- | --- |
| AT-1 | Hamburger labelled "Dashboard" | CLOSED | `nav.menu` key; browser test *is opened by a button named as a menu, not as the dashboard* |
| AT-2 | 29 raw `<select>` elements | CLOSED | **zero** raw `<select>` on any customer screen; the two textual matches in the customer directories are comments. Operator screens are deliberately outside the gate's scope |
| AT-3 | No toast / transient success | CLOSED | the polite live-region toast channel from W4 |
| AT-4 | No skeletons | **FUTURE** | text-only loading, announced and translated. A design change, never promised |
| AT-5 | Plan change says "per period" and "MiB" | **MITIGATED** | "per period" is gone; `PlanChangePage.tsx:84` still prints `memory_mib` as MiB |
| AT-6 | Console offers "Connect" with no explanation | CLOSED | `console.subtitle` explains what the console is, plus a sentence on text-only consoles |
| AT-7 | "Published out of band" | **MITIGATED** | still the opening phrase, now followed by "The change is queued, not immediate." |
| AT-8 | Terms checkbox has no link | **OPEN (P3)** | `RegisterPage.tsx` renders the sentence and the error; no link to terms or privacy |
| AT-9 | Notification preferences on Profile, not Notifications | **OPEN (P4)** | `NotificationPreferencesSection` is owned by `features/notifications/` but rendered only from `ProfilePage.tsx:241` |
| AT-10 | Stale capability documentation | CLOSED | Wave 0; the "No delete" comment is gone and the matrix was refreshed |
| AT-11 | Throttle limiter names reused across unrelated routes | CLOSED | `OneThrottleBucketPerVerbTest` asserts one bucket per verb |
| AT-12 | No breadcrumbs or back links | CLOSED | `components/Breadcrumbs.tsx`, used by the page shell |
| AT-13 | Empty states are one sentence without a call to action | **OPEN (P3)** | `EmptyState.tsx` renders `children` in a muted paragraph and takes no action prop |
| AT-14 | Focus not moved into two dialogues | CLOSED | `ConfirmDialog` uses a native `<dialog>`, so the browser owns the focus trap and the return; `confirm-dialog-readiness.test.tsx` |
| AT-15 | Two controls both called "Sign out" | **MITIGATED** | the session list marks "This device" and confirms before revoking; both labels still read "Sign out" |
| AT-16 | No status page or incident link | **FUTURE** | there is no status page to link to |
| AT-17 | "No dark theme; tokens exist for one theme only" | **NO LONGER ACCURATE** | three theme states exist and are gated. There is no product toggle. See the wording note in AN |
| AT-18 | "A A record needs an address…" (article + type) | **OPEN (P4)** | `InvalidDnsRecordException::contentIsNot` is `sprintf('A %s record needs %s.', …)`; `MX` is special-cased, the vowel-initial types are not |

Six items are open or partly open — AT-4, AT-5, AT-8, AT-9, AT-13, AT-16, AT-18
in the mitigated or open columns. Every one is a P3 or P4 copy or UX
recommendation from the original audit, none was promised by any wave, and none
is being turned into a launch blocker here. They are written down so that the
next reader does not have to rediscover them.

## AO. Original P1 status

**15 / 15 CLOSED.** Independently verified at the frozen commit, against
current code rather than against the previous reports' claims.

| # | Original problem | Closed in | Current code evidence | Test / runtime evidence | Status |
| --- | --- | --- | --- | --- | --- |
| AR-1 | The portal sent `idempotency_key` in the body; every guarded endpoint reads only the `Idempotency-Key` header, so every purchase, power action, reinstall and plan change answered 422 | Wave 0 | `lib/api.ts:174` sets the header; no `idempotency_key` body key remains anywhere in `src/` (the only matches are two error-catalogue strings) | The W5.9 tenant matrix sends the header on 77 writes; backend contract tests cover every `ReadsIdempotencyKey` route | CLOSED |
| AR-2 | 2FA could not be enabled: "Turn on" posted an empty body, the endpoint requires `current_password` | Wave 0 | `features/security/useTwoFactor.ts:35` sends `current_password`; `TwoFactorSection.tsx:127` renders its field error | Browser: *two-factor authentication is offered and reports its real state*; Arabic: *is refused in Arabic by the server when turning on two-factor with the wrong password* | CLOSED |
| AR-3 | VPS rows read `vm.vcpu`, `vm.memory_mib`, `vm.disk_gib` at top level and printed "vCPU · NaN GiB · GiB" | Wave 0 | `VpsDetailPage.tsx:94,96,97,129–131` read `vm.resources.*` | Vitest render assertions; browser resource-page specs in English and Arabic | CLOSED |
| AR-4 | Mobile navigation exposed 4 of 21 destinations | Wave 1 | One source, `app/navigation.ts` — `CUSTOMER_NAV_GROUPS` holds 23 customer destinations, and both `Sidebar.tsx` and `MobileNavigation.tsx` read it; the 8 operator and 10 Control Center destinations are separate lists the drawer never shows a customer | `app/__tests__/mobile-navigation.test.tsx` — *offers every customer destination and none of the operator ones*; browser: *reaches every customer destination from the drawer* | CLOSED |
| AR-5 | Backend messages reached Arabic customers in English; the client never sent `Accept-Language` | Wave 1 | `lib/api.ts:171` sends `Accept-Language` on every request from the active locale | `EveryMessageCodeIsTranslatedTest`; the EN/AR error-catalogue parity gate; 32 Arabic browser tests | CLOSED |
| AR-6 | The dashboard carried no operational information | Wave 4 | `queries.ts:2035` reads `GET /me/overview`; `features/account/DashboardPage.tsx` renders attention-first | Browser: *reads the dashboard, attention first* (English and Arabic); `TheDashboardAndFeedStayBoundedTest` | CLOSED |
| AR-7 | No per-resource page for any product | Wave 3 | Six detail routes in `app/App.tsx`: `/vps/:id`, `/dedicated/:id`, `/hosting/:id`, `/dns/:identity`, `/domains/:identity`, `/wordpress/:id` | `e2e/wave-3.e2e.ts`, `e2e/mobile/resource-pages.e2e.ts`, `e2e/arabic/resource-pages.e2e.ts` | CLOSED |
| AR-8 | Eight destructive or disruptive actions with no confirmation | Wave 0 | `ConfirmDialog` used across infrastructure (4 files), DNS (3), orders, security, team (2), account | `components/__tests__/confirm-dialog-readiness.test.tsx`; browser: *a force off asks first*, *asks before a destructive action, in Arabic, and takes the cancellation* | CLOSED |
| AR-9 | Subscription rows named neither the plan nor the service | Wave 2 | `features/billing/SubscriptionsPage.tsx` names plan, product and service, and links the service | `features/billing/__tests__`; browser money journeys | CLOSED |
| AR-10 | Invoices had no detail view and Pay did nothing for a `client_secret` gateway | Wave 2 | `InvoiceDetailPage.tsx`, `InvoicePrintPage.tsx`, `PaymentsPage.tsx`, `PaymentNextAction.tsx`, `ControlledGatewayPage.tsx`, `usePaymentLaunch.ts` | `features/payments/__tests__/the-five-answers-to-paying.test.tsx`; browser: *a refused payment explains itself* (English and Arabic) | CLOSED |
| AR-11 | Registration collected no country or currency; no verification resend; pre-verification pages answered a generic red refusal | Wave 2 | `RegisterPage.tsx` collects `country` and `currency`; `VerifyEmailPage.tsx` exists with resend | Browser: *the verification page in Arabic explains what to do and offers the link again*; the registration journey follows the real signed link out of the real outbox | CLOSED |
| AR-12 | No refresh model for long-running operations | Wave 4 | `lib/watchOperation.ts` `nextListPollDelay`, used at three call sites in `queries.ts`; conditional — an idle screen goes quiet | `e2e/wave-5-console.e2e.ts` measures the network while idle and asserts no repeated read, paired with arrival reads | CLOSED |
| AR-13 | No customer activity view | Wave 4 | `routes/v1/activity.php` (`GET /activity`, deliberately no `?customer=`); `features/activity/ActivityPage.tsx`; `queries.ts` reads `GET /activity` with cursor paging | `e2e/wave-4.e2e.ts`, `e2e/arabic/what-is-happening.e2e.ts`; the activity-source safety tests | CLOSED |
| AR-14 | Five design tokens referenced and never defined | Wave 0 | `--surface-base`, `--border`, `--danger-text`, `--warning-text`, `--accent` each defined **3 times** in `styles/index.css` — one per theme state | `styles/__tests__/every-colour-has-a-name.test.ts` — *defines every custom property something reads* and *defines every theme-dependent token in all three theme states* | CLOSED |
| AR-15 | The catalogue never consulted product readiness | Wave 0 | `Catalog/Application/Actions/ListPurchasableProducts.php`, `FindPurchasableProduct.php`, `FindPurchasablePlan.php` all consult `ProductReadiness\…\ProductSellability` — the same decision the checkout makes | Catalogue readiness feature tests; `EveryPreparedCategoryHasAContractTest` | CLOSED |

Closure by wave: Wave 0 six (AR-1, 2, 3, 8, 14, 15), Wave 1 two (AR-4, 5),
Wave 2 three (AR-9, 10, 11), Wave 3 one (AR-7), Wave 4 three (AR-6, 12, 13).
None has regressed.

## AP. Final questions

**Are all original P1 findings still closed?** Yes — 15 / 15, re-verified
against current code and named tests in AO.

**Can another tenant read or mutate another tenant's customer resources?** No.
77 adversarial cases across every customer-reachable object, including nested
children, produce zero 2xx and zero 403; strangers get 404, which does not
disclose existence. The positive twin proves the same 19 addresses answer 200
for their owner.

**Can operator-only metadata leak to customer responses?** No. 317 keys
collected across 51 customer GET addresses, checked against 34 banned
substrings; 11 reasoned exemptions, all still justified. An operator's internal
note attached to a ticket is not present when the customer reads it.

**Can customer writes overpost ownership, provider, money or state fields?**
No. 12 cases, 70 assertions, sentinel sweep, zero landings.

**Do customer-visible Team permissions match server authorization?** Yes. The
matrix is computed from the enforced permission list, an architecture test
asserts the two sets are equal in both directions, and 45 live checks confirm
it against real endpoints.

**Can an expired, revoked or foreign token be used?** No. Expiry and revocation
are enforced server-side, and a foreign token cannot address another account's
objects — the tenant matrix covers the token surface as one of its objects.

**Is a newly issued token still shown only once?** Yes. `e2e/account.e2e.ts` —
*a new API token is shown exactly once*. W5.9's defect 4 *strengthened* the
published description of that response without weakening the property.

**Can a frontend critical response silently drift from OpenAPI without the new
gate detecting representative cases?** Not for the representative cases the
gate covers. It is bounded and says so: it compares the description against the
portal's handwritten types, follows `extends` and one written alias, and
asserts a minimum paired-schema count so it cannot pass by comparing nothing.
It found three real drifts in W5.9, which is the evidence that it detects
rather than the claim that it is exhaustive.

**Are money values still integer minor units plus currency?** Yes, everywhere.
`brick/money` carries real ISO exponents; `int|string` signatures; no float on
any money path.

**Can browser success mark a payment paid?** No. A payment is paid when the
server says so.

**Can a customer mutation replay automatically after reconnect or re-auth?**
No. The proof in AI drives session expiry, re-auth, focus, reconnect and an
explicit `resumePausedMutations()` and the count stays at one.

**Are indeterminate operations still distinct from failed operations?** Yes —
and the DNS defect in AL was found precisely because they are.

**Does Activity remain customer-safe?** Yes. No operator audit source, no
notifications-as-history, a genuine actor on every entry, and NULL stays
system/unknown.

**Do Activity, Dashboard and Unread remain bounded with larger data?** Yes,
proven as scale invariance: the same query count at five machines as at
forty-five, and one query for the unread count whatever the inbox holds.

**Can a query failure render as an empty business state?** No — W5.5 separated
the two and the read-failure surface is now on every screen that reads.

**Can a render crash white-screen the portal?** No. W5.5 added the error
boundary, and `app/__tests__/when-a-screen-breaks.test.tsx` covers it.

**Are English and Arabic customer states and errors complete?** Yes, asserted
by parity gates over the state vocabulary and the error catalogue rather than by
inspection.

**Does route navigation have deliberate focus behaviour?** Yes, and W5.6's
first four answers about where focus goes were wrong until they were measured.

**Can query-string filtering steal focus?** No — asserted in W5.7's
address-bar work and in the Arabic keyboard specs.

**Are covered touch targets at or above the tested floor after the screen
settles?** Yes — zero under 24 × 24 on all nine covered screens, measured after
settling. Not a WCAG conformance claim.

**Is there page-level overflow at 360 or 393?** No, at either width, in either
language.

**Are current customer routes reachable, refreshable and authorized as
intended?** Yes — one canonical route map, asserted, with the assertion itself
proved by deliberate breakage.

**Are there unresolved dead customer actions?** No dead customer action
remains. Three FUTURE_PREPARED *operator* hooks remain by decision, and are
recorded in AN.

**Did Wave 5 touch real infrastructure?** No.

**Did Wave 5 modify Dedicated power idempotency?** No.

**Did Wave 5 introduce dark mode as a feature?** No. See AN.

**Did Wave 5 implement self-service account deletion or export?** No.

**Did Wave 5 start a new cloud product?** No.

## AQ. Wave 5 verdict

### Real-infrastructure claims

```
REAL_INFRA_VERIFIED:      NONE
REAL_PAYMENT_VERIFIED:    NONE
REAL_REGISTRAR_VERIFIED:  NONE
REAL_HOSTING_VERIFIED:    NONE
```

Every provider exercised in Wave 5 and in this closure is a controlled fake.
No real hypervisor, registrar, payment gateway, BMC, DNS provider or hosting
panel was contacted. Nothing in mocks, CI, documentation or provider adapters
is treated as real verification.

### Closure gate

| Gate | Result |
| --- | --- |
| Original P1 | **15 / 15 CLOSED** |
| Backend | GREEN — 3,027 / 3,027 |
| Pint | GREEN |
| PHPStan | GREEN — 0 errors |
| Frontend | GREEN — 443 / 443 |
| TypeScript | GREEN |
| ESLint | GREEN |
| Production build | GREEN |
| OpenAPI | GREEN — valid |
| Tenant isolation | GREEN — 0 findings in 77 cases |
| Overposting | GREEN — 0 in 12 cases |
| Authorization parity | GREEN — 0 in 45 checks |
| Security | GREEN |
| Money | GREEN |
| Idempotency, existing paths | GREEN |
| Mutation no-replay | GREEN |
| Async truth | GREEN |
| Activity bounded | GREEN |
| Dashboard bounded | GREEN |
| Unread bounded | GREEN |
| Accessibility | GREEN |
| English | GREEN |
| Arabic | GREEN |
| Mobile 393 | GREEN |
| 360 narrow | GREEN |
| Visual geometry | GREEN |
| Clean room | GREEN — with the documented PHPStan-toolchain exception in AJ, and no other |
| Final exact-SHA CI | GREEN — run 162 at `d349988`, 9 / 9 |
| Real-infra claims | NONE |
| Dedicated power architecture | UNCHANGED |
| New product | NO |
| Self-service delete / export | NO |
| Dark mode introduced as a feature | NO |

---

```
Customer Portal Wave 5 — Account & Final Polish

Status:
CLOSED

Starting HEAD:
c075400ff38a742477804d4f69d7b30defd4c509

Ending code HEAD:
d3499881ae01f17d0fad7b01ff9a2d213fee7470

Report HEAD:
the commit that adds this file; its parent is
d3499881ae01f17d0fad7b01ff9a2d213fee7470 and it changes no code, no test,
no migration and no workflow

Original P1:
15 / 15 CLOSED

Backend:
GREEN — 3,027 tests, 3,027 passed, 138,182 assertions, 0 failures
Pint passed, PHPStan 0 errors

Frontend:
GREEN — 81 files, 443 tests, 0 failures
tsc clean, eslint clean, production build OK, OpenAPI valid

Browser:
GREEN — 364 passed, 14 skipped, 0 failed across four projects
chromium 269, customer-mobile 48, customer-narrow 15, customer-arabic 32

Security:
GREEN — 77 tenant-isolation cases / 0 findings
51 customer GET surfaces, 317 keys, 34 banned substrings / 0 leaks
12 overposting cases, 70 assertions / 0 landings
45 authorization-parity checks / 0 disagreements
composer audit 0 advisories; npm audit 2 moderate, development-only

Accessibility:
GREEN — 0 targets below the tested 24x24 CSS-pixel floor on all nine
covered screens, measured after the screen settles
Not a WCAG conformance claim

Mobile:
GREEN — 393 (Pixel 5) and 360 (Galaxy S8), no page-level overflow

Arabic:
GREEN — 32 Arabic browser tests; EN/AR error and state parity gated

Clean room:
GREEN — fresh clone at d349988, composer install, npm ci, empty database,
55 migrations from zero, 109 tables, rollback and re-apply proved
with the one documented exception:
PHPStan toolchain copied — not clean-installed
(composer cannot authenticate to github.com from this sandbox;
 CI's Static analysis job is the independent proof it installs and runs)

Code-head CI:
Run 162 / id 35087196101 / attempt 1 / SUCCESS / 9 of 9 jobs
SHA d3499881ae01f17d0fad7b01ff9a2d213fee7470

Report-head CI:
observed after the push and recorded in the amendment that follows this
commit. Wave 5 is CLOSED on the condition that it is green; if it is not,
this status does not stand and the amendment says so instead

Dedicated power idempotency:
DEFERRED — ARCHITECTURE ITEM

REAL_INFRA_VERIFIED:
NONE

REAL_PAYMENT_VERIFIED:
NONE

REAL_REGISTRAR_VERIFIED:
NONE

REAL_HOSTING_VERIFIED:
NONE

Dark-theme path:
PRE-EXISTING — NOT INTRODUCED AS A WAVE 5 FEATURE

Self-service account deletion / export:
NOT IMPLEMENTED

Wave 5:
CLOSED

Customer Portal Final Closure:
NOT YET DECLARED

Recommended next action:
INDEPENDENT CUSTOMER PORTAL FINAL CLOSURE REVIEW
```
