# Customer Portal — independent final closure review

This document answers one question: **is the Customer Portal, as a product and
as a software system, ready to be declared finally closed?**

It is a review, not another implementation report. It does not take a prior
report's word that something is closed. Every load-bearing claim below was
re-derived from current code, current tests, current routes, current contracts
or GitHub's own CI records, and each is labelled with the *kind* of evidence it
rests on. Where a claim rests on a prior report rather than on something checked
here, it says so.

No application code was changed by this review. No real infrastructure was
contacted.

---

## 1. Provenance

| | |
| --- | --- |
| Repository | `fullstackfull/cloud` |
| Branch | `claude/hv-t6hq1p` |
| Review performed at | `60f36163001e2730e3d0e18026de918f4d9368a8` |
| Reviewed artefacts | the product/UX audit and the Wave 0–5 closure reports |
| Application code frozen since | `d3499881ae01f17d0fad7b01ff9a2d213fee7470` |

**Freeze check — CODE EVIDENCE.** `git status` clean. Branch
`claude/hv-t6hq1p`. `git rev-parse HEAD` = `60f3616`. `git diff` empty. The
only path changed between `d349988` and `60f3616` is
`docs/customer-portal-wave-5-report.md`. No application code, test, migration
or workflow changed after the Wave 5 code head, so this review and Wave 5's
closure are looking at the same software. Nothing was reset, rewritten or
force-pushed.

## 2. Reviewed HEADs

```
Wave 5 code HEAD     d3499881ae01f17d0fad7b01ff9a2d213fee7470
Wave 5 report HEAD   60f36163001e2730e3d0e18026de918f4d9368a8
```

## 3. CI evidence

**CI EVIDENCE, read from GitHub, not from any report.**

| | Code head | Report head |
| --- | --- | --- |
| Run | 162 | **163** |
| Id | 35087196101 | **35098980322** |
| Attempt | 1 | **1** |
| SHA | `d349988` | `60f3616` |
| Status | completed | completed |
| Conclusion | **success** | **success** |
| Jobs | 9 of 9 green | **9 of 9, 0 failed** |

Run 163 was polled until it completed and its failed-job count was queried
directly: `failed_jobs: 0, total_jobs: 9`. The nine jobs are **Backend (PHP
8.4, PostgreSQL 16)**, **Backend (PHP 8.4, PostgreSQL 18)**, **Static
analysis**, **Frontend**, **API description**, **Browser end-to-end**,
**Security checks**, **Infrastructure validation**, **Production guards** —
nine jobs from eight definitions, because the backend matrix runs on two
PostgreSQL majors.

With run 163 green, **Wave 5 is CLOSED.**

### A discrepancy this review is obliged to record

The Wave 5 report's verdict says its report-head CI result would be *"recorded
in the amendment that follows this commit"*. That amendment was deliberately
**not** created: writing run 163's number into the report would move the report
head and demand a new CI run for it, and then another, indefinitely. The
run-163 evidence lives in section 3 of this document instead, and GitHub's run
is the authoritative record. The Wave 5 report's forward reference is therefore
superseded rather than fulfilled. That is a documentation inconsistency, not a
software one, and it is stated here rather than left for a reader to trip over.

## 4. Audit → Waves closure map

Reconstructed from `git log`, not from the reports' own claims.

| Stage | Purpose | Starting HEAD | Ending HEAD | What closed |
| --- | --- | --- | --- | --- |
| Product/UX audit | Walk the portal and write down what is wrong | — | `4bee5c2`-era | 15 P1, 20 P2, 18 P3/P4 findings |
| Wave 0 | The findings that made the portal unusable | audit | `4bee5c2` | AR-1, AR-2, AR-3, AR-8, AR-14, AR-15 |
| Wave 1 | Reach and language | `4bee5c2` | `5c69f18` | AR-4, AR-5 |
| Wave 2 | Money made legible | `5c69f18` | `f216afe` | AR-9, AR-10, AR-11 |
| Wave 3 | One page per resource | `f216afe` | `f115205` | AR-7 |
| Wave 4 | What is happening | `f115205` | `c075400` | AR-6, AR-12, AR-13 |
| W5.1 | Team and role truth | `c075400` | `e005fbe` | role matrix computed from enforcement; four operator fields removed |
| W5.2 | Sessions, credentials, labels | `e005fbe` | `752cb5b` | device naming, token restrictions, time zone, `safeLabel` |
| W5.3 | Profile and customer API fields | — | — | **no commit of its own**; landed inside `e005fbe` and `752cb5b`, overposting finished in W5.9 |
| W5.4 | The design system | `752cb5b` | `81ef0ae` | 23 hand-written controls absorbed; two token defects |
| W5.5 | Reads, offline, session expiry | `81ef0ae` | `9636494` | failed ≠ empty; error boundary; no mutation replay |
| W5.6 | Accessibility | `9636494` | `cdff3ac` | skip link, focus, live regions, touch floor |
| W5.7 | Routes, state, translations | `cdff3ac` | `50912f4` | address-bar state, one route map, EN/AR parity |
| W5.8 | Visual and responsive | `50912f4` | `bdd84aa` | geometry gates at five widths, both languages |
| W5.9 | Technical, security, performance | `bdd84aa` | `d349988` | six defects; five adversarial matrices |
| Wave 5 closure | Clean room, report, exact-SHA CI | `d349988` | `60f3616` | Wave 5 CLOSED |

**Wave 5 has 26 commits, 204 files, +18,925 / −1,252.** W5.3 has no commit and
this review does not invent one.

## 5. Original P1 findings — all fifteen

**CODE EVIDENCE** re-derived in this review for each row; **TEST EVIDENCE**
named per row; **RUNTIME EVIDENCE** where a browser journey covers it.

| # | Original defect | Closed in | Independently checked here | Status |
| --- | --- | --- | --- | --- |
| AR-1 | `idempotency_key` in the body; every guarded endpoint reads the header, so every purchase, power action, reinstall and plan change answered 422 | W0 | `lib/api.ts` sets `Idempotency-Key`; no body key survives anywhere in `src/` except two error-catalogue strings. W5.9's matrix sends the header on 77 writes | CLOSED |
| AR-2 | 2FA could not be enabled — empty body, endpoint needs `current_password` | W0 | `useTwoFactor.ts` sends `current_password`; the field's error renders; Arabic browser test drives the wrong-password refusal | CLOSED |
| AR-3 | "vCPU · NaN GiB · GiB" on every VPS row | W0 | `VpsDetailPage.tsx` reads `vm.resources.vcpu / memory_mib / disk_gib`; the old top-level reads are gone with the old page | CLOSED |
| AR-4 | Mobile navigation exposed 4 of 21 destinations | W1 | **23 customer destinations enumerated from `app/navigation.ts`**, one source read by both `Sidebar.tsx` and `MobileNavigation.tsx`; a gate asserts the drawer offers every one | CLOSED |
| AR-5 | Backend messages reached Arabic customers in English | W1 | `lib/api.ts` sends `Accept-Language` from the active locale on every request; EN/AR catalogue parity is gated | CLOSED |
| AR-6 | The dashboard carried no operational information | W4 | `GET /me/overview`, one bounded aggregate; attention-first rendering | CLOSED |
| AR-7 | No per-resource page for any product | W3 | six detail routes in `app/App.tsx`: `/vps/:id`, `/dedicated/:id`, `/hosting/:id`, `/dns/:identity`, `/domains/:identity`, `/wordpress/:id` | CLOSED |
| AR-8 | Eight destructive actions with no confirmation | W0 | `ConfirmDialog` across infrastructure, DNS, orders, security, team, account; native `<dialog>` so the browser owns the focus trap | CLOSED |
| AR-9 | Subscription rows named neither plan nor service | W2 | the row names plan, product and service and links the service | CLOSED |
| AR-10 | No invoice detail; Pay did nothing for a client-secret gateway | W2 | `InvoiceDetailPage`, `InvoicePrintPage`, `PaymentsPage`, `PaymentNextAction`, `ControlledGatewayPage`, `usePaymentLaunch` | CLOSED |
| AR-11 | No country/currency at registration; no verification resend; generic red refusal before verification | W2 | `RegisterPage` collects `country` and `currency`; `VerifyEmailPage` exists with resend; the registration journey follows a real signed link out of a real outbox | CLOSED |
| AR-12 | No refresh model for long-running operations | W4 | `lib/watchOperation.ts`: `poll_after_ms` is null at terminal, an observation window bounds it, and `nextListPollDelay` returns `false` when nothing is unfinished | CLOSED |
| AR-13 | No customer activity view | W4 | `routes/v1/activity.php` — `GET /activity`, with no `/activity/{customer}` and no `?customer=`; `ActivityPage` | CLOSED |
| AR-14 | Five design tokens referenced and never defined | W0 | `--surface-base`, `--border`, `--danger-text`, `--warning-text`, `--accent` each defined **three times**, one per theme state; a gate asserts all three | CLOSED |
| AR-15 | The catalogue never consulted product readiness | W0 | `ListPurchasableProducts`, `FindPurchasableProduct`, `FindPurchasablePlan` all consult `ProductReadiness\…\ProductSellability` — the same decision the checkout makes | CLOSED |

**Result: 15 / 15 CLOSED. None has regressed.** No finding was averaged and
none is described as "mostly closed".

## 6. Supporting findings reconciliation

The P2 set (AS-1 … AS-20) is closed; the detail is in the Wave 5 report's
reconciliation, which this review spot-checked rather than re-derived in full —
**REPORT CLAIM, spot-checked**. The P3/P4 set contains genuinely open items,
re-verified here.

| # | Subject | Class | Why |
| --- | --- | --- | --- |
| AT-4 | No loading skeletons | FUTURE | Loading is announced (`role="status"`) and translated. Skeletons are a design change, never promised |
| AT-5 | Plan change prints `MiB` | OPEN_NON_BLOCKING | `PlanChangePage.tsx:84` prints `quote.new_resources.memory_mib` as MiB. The number is correct; the unit is less friendly than GiB. No deception, no wrong value |
| AT-8 | Terms checkbox has no link | **OPEN_NON_BLOCKING — escalated, see below** | The control asks the customer to accept "the terms of service and the acceptable use policy" and links neither |
| AT-9 | Notification preferences live on Profile | OPEN_NON_BLOCKING | `NotificationPreferencesSection` is owned by `features/notifications/` but rendered only from `ProfilePage`. Placement, not function |
| AT-13 | Empty states carry no call to action | OPEN_NON_BLOCKING | `EmptyState.tsx` renders `children` in a muted paragraph and takes no action prop |
| AT-16 | No status-page link | FUTURE | There is no status page to link to |
| AT-17 | "No dark theme; tokens for one theme only" | NO_LONGER_RELEVANT | Three theme states exist and are gated. See section 16 |
| AT-18 | "A A record needs an IPv4 address." | OPEN_NON_BLOCKING | `InvalidDnsRecordException::contentIsNot` is `sprintf('A %s record needs %s.', $type->value, $expected)`. `MX` is special-cased; the vowel-initial types are not. An article, in a fallback sentence |
| AT-7 | "Published out of band" | MITIGATED | Still the opening phrase, now followed by "The change is queued, not immediate." |
| AT-15 | Two controls both named "Sign out" | MITIGATED | The session list marks "This device" and confirms before revoking; both labels still read "Sign out" |

### AT-8, escalated rather than buried

§6 of the review brief says to reclassify a finding as a closure blocker if it
actually represents security, data loss, wrong money, cross-tenant exposure,
irreversible action risk **or customer deception**. AT-8 is the only item that
required that test, and it took more than a glance.

**CODE EVIDENCE.** `RegisterPage.tsx` renders a required `CheckboxField` whose
label is `auth.acceptTerms` — *"I accept the terms of service and the
acceptable use policy."* — with no link, and `accepts_terms` is a required
field on the server. Searching the whole repository for a terms of service, an
acceptable use policy or a privacy document returns **nothing**: no route, no
page, no `TERMS_URL` config, no markdown file. The only matches are
`CouponTerms`, which is subscription pricing and unrelated.

So the portal obtains a required consent to two documents that this repository
does not contain and does not link.

**Judgement.** This is *not* a software closure blocker, and I am not going to
inflate it into one:

- The registration journey works end to end; the software path is complete.
- Nothing is misstated. The sentence describes what the customer is accepting;
  it does not claim the documents are reachable from that screen.
- A terms of service and an AUP are business artefacts that normally live
  outside an application repository. I cannot verify from here whether Lynomia
  has published them elsewhere, and their absence *from this repo* is not
  evidence of their absence *from the business*.

**But it is a launch prerequisite, and it is outside software scope.** Before
the portal takes a real registration, somebody must confirm the two documents
exist and link them from that checkbox. If they do not exist, then asking for
that consent would be a genuine legal and fairness problem — at that point it
becomes a blocker for *launch*, not for *software closure*. Recorded here so
the decision is made deliberately by whoever owns it rather than discovered by
a customer.

Every other open item is cosmetic or a placement preference. None touches
security, money, tenancy, data loss, irreversibility or deception.

## 7. Core customer journey review

**RUNTIME EVIDENCE** — the four browser projects at `d349988`: 364 passed, 14
skipped, 0 failed, reproduced three times independently (working tree, clean
room, CI's Browser end-to-end job).

| Journey | Software path | Evidence |
| --- | --- | --- |
| Registration → verification → sign in | complete | the registration spec follows the real signed link out of the real outbox file, rather than setting `email_verified_at` and calling that proof |
| Dashboard | complete | attention-first, English and Arabic |
| Catalogue → product → order | complete | server-priced quote with setup fee and renewal |
| Invoice → payment launch → status | complete | redirect, client-secret and failure all have visible states |
| Service creation / provisioning status | complete | operation status in the customer's vocabulary |
| VPS / Dedicated / Hosting / WordPress management | complete | resource pages with actions, graded confirmations |
| Domains / DNS / Backups | complete | renew, auto-renew, contacts, transfer; zone records; browse and restore |
| Subscription lifecycle | complete | named rows, cancel dialogue that names the service |
| Activity / notifications / support | complete | account feed, unread badge, contextual support |
| Profile / team / security / sessions / API tokens / sign out | complete | see sections 13 |

**The distinction the brief asks for, stated plainly:** every journey above is
*software path complete*, exercised against controlled fake providers. **None
of it is real external infrastructure verification.** See section 22.

## 8. Security review

Re-derived here rather than quoted.

| Area | Evidence kind | Finding |
| --- | --- | --- |
| Tenant isolation | TEST | 77 adversarial cases over the named object inventory; attacker is Owner of their own account so no capability check masks a tenancy hole; 0 breaches, 0 existence disclosures. Lookups start from the acting customer, so **404 is indistinguishable from nonexistent** |
| Indirect references | CODE, chosen independently | Support-ticket context is the case I picked myself, because it is the kind a matrix can miss. `OpenTicketRequest` validates *shape* (`nullable, string, ulid`); `TicketController::ownServiceId` / `ownInvoiceId` then run `where('customer_id', acting)->whereKey($id)->firstOrFail()`. A foreign service or invoice id 404s instead of being attached — **the owned parent does not authorise a foreign child** |
| Customer/operator separation | TEST | 51 customer GET addresses, 317 keys collected at every depth, 34 banned substrings, 11 exemptions each with a written reason and a staleness check; 0 leaks. An operator's internal note attached to a ticket is absent when the customer reads it, while the customer's own message remains |
| Overposting | TEST | 12 cases, 70 assertions, sentinel sweep; 0 landings. Frontend omission is never treated as protection |
| Authorization | TEST | 45 checks — five roles × nine capabilities — read from the published matrix and exercised against live endpoints; 0 disagreements. 404 for strangers, 403 for members whose role forbids it, because `AuthorisesWithinAccount` runs before any lookup |
| Sessions | CODE | no invented city or device certainty; "This device" marks the current session; revoke confirms |
| API tokens | CODE | see section 13 |
| Secret leakage | TEST | log-stack redaction, exception-chain redaction, fail-closed redaction when PCRE aborts, plus CI's committed-secret gate |
| Error responses | TEST | documented codes, no stack traces, no internal identifiers |
| Dependencies | RUNTIME, re-run in this review | `composer audit` **no advisories**; `npm audit` **2 moderate**, both the same development-only advisory |

## 9. Money and billing review

**CODE EVIDENCE, read in this review.** `Shared/Domain/ValueObjects/Money.php`:

- `ofMinor(int $minorUnits, string $currency)` — integer minor units.
- `of(int|string $amount, string $currency, RoundingMode $roundingMode = RoundingMode::Unnecessary)`. The signature is `int|string` **precisely so a float cannot be passed**, and the default rounding mode makes any rounding an explicit decision rather than a silent one.
- `plus`, `minus`, `multipliedBy`, `dividedBy` and every comparison call `assertSameCurrency`. Unlike currencies cannot be combined.
- `brick/money` carries real ISO exponents: KWD 3, USD 2, JPY 0.

No float appears on any money path. The portal renders what the API published;
it has no price authority.

**Dashboard multi-currency — CODE EVIDENCE.** `OverviewController` emits one
entry per currency with the reason written beside it: *"There is deliberately
no `total`: an account billed in two currencies has two amounts owing, and a
single number would be arithmetic nobody can perform."* No regression to
summing unlike currencies.

## 9a. Payment truth

**CODE EVIDENCE, traced in this review.** `usePaymentLaunch` sets its state
exclusively from the *server's* `started` response — `redirect`,
`client_confirmation`, `completed`, `failed` — and everything else, *including
a redirect the provider promised and did not send a URL for*, falls through to
`pending` with the comment: the money may already be moving, so the screen says
wait rather than offering a second attempt.

The controlled gateway page does not decide anything either: it posts a
*decision* to the server (`decide.mutate({ reference, decision })`) and the
server records the outcome. There is no code path in which a success URL, a
redirect return, or anything the browser observes marks a payment paid.

So, against the five things section 16 of the brief asks about: the portal
cannot mark a payment paid from a success URL, cannot invent gateway success,
does not render failure as success, does not double-apply (the launch is
idempotency-keyed and the already-captured guard is server-side), and does not
confuse pending with paid — pending is its own visible state with its own copy.

## 10. Resource architecture review

Six resource families, each with a canonical detail route, identity, status,
sections, related billing, support context and tenant-safe direct refresh:
`/vps/:id`, `/dedicated/:id`, `/hosting/:id`, `/wordpress/:id`,
`/domains/:identity`, `/dns/:identity`. No return to table-only management: the
list pages link into the detail pages, and the actions live on the resource.

Direct refresh is tenant-safe by the same construction as everything else — the
lookup starts from the acting customer, so a foreign identity 404s on a cold
load exactly as it does on a warm one.

## 11. Routing and state review

**CODE + TEST EVIDENCE, enumerated in this review.** 23 customer destinations
in `CUSTOMER_NAV_GROUPS`: `/`, `/catalogue`, `/services`, `/vps`, `/dedicated`,
`/hosting`, `/wordpress`, `/domains`, `/dns`, `/ips`, `/backups`, `/orders`,
`/invoices`, `/payments`, `/subscriptions`, `/wallet`, `/activity`, `/support`,
`/notifications`, `/profile`, `/settings/team`, `/security`, `/api-tokens`.

That is exactly the information architecture the brief lists. Checked against
it: no dead primary destination, no duplicate product destination, no critical
destination hidden behind a disclosure, and **no operator destination in
customer navigation** — the 18 operator and Control Center destinations are
separate exported lists, and the intersection with the customer set is empty.
The legacy 17-item "More" menu is gone and cannot return, because both shells
read one source.

`every-route-a-customer-can-reach.test.ts` holds it with eight assertions,
including: the route count the wave report records, no navigation entry that
lands nowhere, no link to a route that does not exist, every customer route
reachable from navigation *or* listed as deep-link-only with a written reason,
no stale deep-link entry, the pre-resource-page addresses still served, one
place that turns a server-published handle into a path, and **"keeps the
operator area out of the customer navigation"**.

Direct refresh, back/forward and URL filter state were exercised by the W5.7
browser specs, and a query-string-only change does not move focus.

## 12. Error, offline and session review

| Property | Evidence | Finding |
| --- | --- | --- |
| A failed read is not an empty business state | TEST | W5.5 separated them; the shared failure surface is referenced from 65 files |
| No indefinite offline spinner | TEST | offline is a stated state, not a spinner |
| Error boundary | CODE, read here | `RouteErrorBoundary` shows **no message, no stack, no component path**, invents no reference number (there is no client telemetry, so a reference would be a string nobody could look up), keeps the shell, and offers the dashboard and support carrying the route |
| No crash-on-demand in production | CODE | the only `throw new Error` in production `.tsx` is `main.tsx`'s missing-root-container boot assertion |
| Mutation no-replay | TEST | section 18 |
| No automatic mutation on reconnect | TEST | same proof — reconnect is one of the five things it drives |

## 13. Account, team, security and token review

**Profile field classification — CODE EVIDENCE, read in this review.**
`ProfileController::update` validates and accepts **exactly** `name`, `locale`,
`timezone`, `phone` — the EDITABLE set, and nothing else. `password` is a
separate endpoint that requires `current_password` and invalidates every other
session and every API token. `email`, `display_name`, `legal_name`, `status`
and `type` are not accepted by any customer write. The UI offers what the
server accepts; the classification in the Wave 5 brief holds.

**Team — CODE + TEST.** `GET /team/roles` computes the matrix from
`CustomerRole::permissions()`, the same list `AuthorisesWithinAccount` reads
before every write, and an architecture test asserts the published set and the
enforced set are equal **in both directions**. There are no React-only
permissions: the 45 live parity checks are the proof that the text matches the
authorization.

**API tokens — CODE EVIDENCE, traced in this review.**

- The plaintext appears in exactly one response, `IssuedApiTokenResource`, whose own docblock states there is no second endpoint and no column to read it back from: *the table holds a SHA-256 digest*. A lost token is re-issued, not recovered.
- `meta.plaintext_shown_once` is a contract flag, not decoration.
- The CIDR allow-list is enforced **server-side**: `PersonalAccessToken::allowsRequestFrom()` is called from `ApiTokenServiceProvider`, and it **fails closed** — a list that is set but cannot be evaluated against a resolvable client address refuses, because an allow-list that silently stops applying is worse than none.
- Expiry and revocation are server state; `isUsable()` requires neither revoked nor expired.
- Abilities are deliberately not advertised as a scope UI, because the field holds the wildcard on every token and printing "full access" from it would read a field as a promise.

No UI-only restriction pretends to be security.

## 14. English, Arabic and RTL review

English is complete and gated: the error catalogue, the status vocabulary and
the state labels come from one source with parity assertions.

Arabic — **RUNTIME EVIDENCE**, 32 browser tests with the browser's own language
set to `ar`, so the portal picks it the way a customer's browser would. They
assert the things that only break in the second language: amounts not mirrored,
a time zone read as a place rather than mirrored glyphs, the role matrix as a
table of words, a destructive action that asks first *in Arabic* and takes the
cancellation, Arabic relative time, and technical values held left-to-right
inside a right-to-left page — domain, hostname, address, CIDR, email, invoice
reference, token scope, time-zone id.

The corrected power wording survives: `"shutdown": "Shut down"` and
`"stop": "Force off"` are distinct labels, so the safe action and the abrupt one
do not read alike. Dynamic translation is bounded by `safeLabel` and a source
gate, and the 27 dynamically-composed keys are **not** claimed dead — they
remain because nobody proved them dead.

## 15. Accessibility review

**Not a WCAG conformance claim.** What exists is a set of measurements against
published numbers and a set of assertions about semantics.

Skip link, landmarks, headings, deliberate focus on route change, keyboard
journey, dialogue focus and focus return, form semantics, live regions, table
semantics, reduced motion, touch targets, and the Arabic equivalents of all of
it.

**The touch-target gate measures settled screens — verified here.** It takes
two readings and only trusts them when they agree on the control count, and it
fails with a stated reason if a screen never stops drawing. That correction is
the reason it is trustworthy: before it, the sweep measured the dashboard's
loading shell, found only the shell's controls, and its own "controls were
found" guard was satisfied by them — so the screen with the most inline links
was the screen it proved the least about. Measured properly: 0 immediately after
the heading appeared, 22 after settling. The drawer test now measures inside the
drawer and requires more than ten destinations, so an empty drawer is not a pass.

Current result: **0 targets below the tested 24 × 24 CSS-pixel floor on all
nine covered screens**, exemption list empty.

## 16. Responsive and visual review

Programmatic geometry gates at **1440, 1024, 768** in both languages, plus the
**393** (Pixel 5) and **360** (Galaxy S8) projects on real device descriptors
rather than a desktop window made narrow. Asserted: no page-level horizontal
overflow, wide tables scroll inside the table, dialogues fit the viewport, long
technical values fold rather than break the shell, and the right-to-left start
edge is safe.

The W5.8 repaired classes — the sr-only static-scroller overflow, the one-time
token rendering as an empty credential, technical string wrapping, hand-rolled
control state, the missing verify-email `h1`, the Loading/EmptyState visual
distinction, tone border consistency — are all covered by suites that are green
at `d349988`, so none has regressed.

**Dark theme, in the required wording.** Dark-theme path: **PRE-EXISTING**.
Wave 5 did not introduce or expand dark mode as a product feature; it repaired
defects in the path that already existed, where two tokens were defined in
`:root` and in the media query but not in the `[data-theme='dark']` stamp, so a
customer who had chosen dark explicitly read every destructive button in the
light theme's red. Three theme states exist in `styles/index.css`, a gate
asserts every theme-dependent token is defined in all three, and a browser spec
drives the dark path. **No product toggle was added** — no component writes
`data-theme`, so a customer reaches the dark theme through their operating
system.

## 17. OpenAPI and client-contract review

`TheClientAndTheDescriptionAgreeTest` compares `docs/openapi.yaml` against the
portal's handwritten response types, following `extends` and one written alias,
and asserts a minimum paired-schema count so it cannot pass by comparing
nothing. It is bounded and says so: a scanner, not a YAML parser, with a third
test that checks known properties by name so a silently-broken scanner fails.

It earned its place by catching three real drifts, each of which is now closed
and re-checked here:

| Drift | Now |
| --- | --- |
| Order line read `description`; the API publishes `name` | the page reads `item.name`, and a test asserts the line is *named* on screen, not only priced |
| `StartedPayment.failure_message` — a field the API had deliberately dropped | removed from the type and the hook; the failed state carries `failureCode` |
| `IssuedApiToken` documented as one property with `additionalProperties: false` | the properties are named once and spread into both schemas, and the extractor that was blind to `array_merge` now follows one |

**Carried architecture debt, recorded as such:** the portal's response types are
handwritten. A generated TypeScript client is not required for closure and was
not built; the bounded drift gate is what stands in for it.

## 18. Performance and boundedness review

**TEST EVIDENCE.** Asserted as scale invariance, not as magic numbers:

| Assertion | Result |
| --- | --- |
| Activity feed: 5 machines == 45 machines | equal |
| Dashboard: 5 machines == 45 machines | equal |
| Unread count: one query whatever the inbox holds | one |
| A category filter reads fewer branches than the whole feed | fewer |

The Wave 4 structural baseline — Activity 7, Dashboard 23, Unread 2 — is
reference information, not a constant to fail against. No N+1.

Polling is read-only, terminal-aware and bounded: `poll_after_ms` is null the
moment a state is terminal, an observation window caps how long a screen keeps
asking, and `nextListPollDelay` returns `false` when nothing is unfinished — so
an idle screen goes quiet rather than grinding a rate limit.

**Mutation no-replay**, the mandatory W5.5 invariant, counted rather than read
off a screen because a replay is silent:

```
one deliberate press                      → 1 power request
session expires, the dialogue appears     → still 1
the session returns, /me is refetched     → still 1
the window is focused                     → still 1
the network reconnects                    → still 1
client.resumePausedMutations() explicitly → still 1
the customer presses again, deliberately  → exactly 2
```

`POST /vps/{id}/power` was chosen over something harmless on purpose: a reboot
is idempotent in shape and emphatically not in effect.

**Async truth** holds: accepted ≠ completed, queued ≠ succeeded, indeterminate ≠
failed, needs_review ≠ failed, network loss ≠ business failure. There are no
blind mutation retries.

**Activity** is query-time derived, customer-safe, tenant-scoped and bounded: no
operator audit rows, no notifications rendered as history, no invented actor,
and a NULL actor stays *system* or *unknown* rather than being attributed to the
account owner.

## 19. Clean-room evidence review

| Step | Result |
| --- | --- |
| Fresh clone at `d349988`, nothing carried over | verified: no `vendor/`, no `node_modules/`, no `tools/phpstan/vendor`, no `.env`, no build output |
| `composer install`, `npm ci` from lockfiles | OK |
| Empty PostgreSQL database, 0 tables before | OK |
| Migrations from zero | **55 migrations, 109 tables** |
| `migrate:rollback --step=100` then `migrate` | 109 → 1 → 109 |
| Pint | passed |
| PHPStan | 0 errors |
| Backend suite, sequential, one process | **3,027 / 3,027, 138,182 assertions** |
| `tsc -b`, `eslint .`, `vitest run`, build, OpenAPI lint | clean; **443 tests in 81 files** |
| Browser suite, four projects, fresh E2E database | **364 passed, 14 skipped, 0 failed** |

**The one exception, and this review's judgement on it.** The PHPStan analysis
toolchain has its own `composer.json` and installing it needs `github.com`,
which this sandbox cannot authenticate to. The failure was reproduced
deliberately twice. The toolchain was therefore **copied from the working copy
— not clean-installed**, and the Wave 5 report says so under its own heading
rather than in a footnote.

**Verdict on the exception: adequately documented and non-blocking.** Three
reasons. It is disclosed prominently and not described as clean room. It is an
environment limitation of this sandbox, reproduced rather than assumed. And it
is independently covered: CI's **Static analysis** job installs the toolchain
from the network in its own step and runs it, green in both run 162 and run
163. The clean room proves the application installs and passes from nothing;
CI proves the analysis toolchain does. Neither claim is doing the other's work.

## 20. CI-history honesty review

**No "latest green" shortcut.** The full Wave 5 record was read, not just the
tip.

| Run | SHA | Conclusion | What it actually means |
| --- | --- | --- | --- |
| 148 | `752cb5b` | failure | Browser end-to-end only; the other 8 jobs green |
| 149 | `81ef0ae` | failure | Browser end-to-end only; the other 8 jobs green |
| 150 | `9636494` | success | |
| 151 | `e497eeb` | failure | Browser end-to-end only; the other 8 jobs green |
| 152 | `cdff3ac` | success | |
| 153 | `272c3d5` | cancelled | **Both backend jobs had already failed at the code-style step** before the fixing push cancelled the run. The recorded conclusion hides a real Pint failure |
| 154, 156–159 | | cancelled | superseded by the next push; `cancel-in-progress` is on |
| 155 | `50912f4` | success | |
| 160 | `bdd84aa` | success | W5.8 closed here |
| 161 | `25263ed` | success | |
| 162 | `d349988` | **success** | the frozen code head, 9 / 9 |
| 163 | `60f3616` | **success** | the report head, 9 / 9, 0 failed |

Two rules applied. **Cancelled is not passed** — and run 153 shows cancelled
can be worse than not passed, because it can conceal a failure. And **a
superseded failure is not a current product failure**: 148, 149 and 151 were
each corrected by the next commit, and the exact-SHA runs that matter — 162 and
163 — are green.

## 21. Deferred and non-blocking items

| Item | Class |
| --- | --- |
| Dedicated power idempotency — no durable operation row exists | **DEFERRED — ARCHITECTURE ITEM** |
| 27 dynamically-composed translation keys, not proven dead | CARRIED |
| 3 FUTURE_PREPARED operator hooks | CARRIED by decision |
| In-app dirty-form navigation: tab close, reload and leaving the portal are guarded; an internal SPA route change is not | **KNOWN NON-LAUNCH POLISH GAP** — closing it needs a Data Router migration, and no half-fix was introduced |
| No loading skeletons | FUTURE |
| Handwritten frontend response types | CARRIED architecture debt, with the bounded drift gate |
| Dark-theme path without a product toggle | PRE-EXISTING |
| `@vitest/mocker` GHSA-82fw-gwwq-j7x9, 2 moderate | DEFERRED — development-only; re-verified in this review; remedy is a `vitest` major upgrade, which a final review is not the place for |
| Self-service account deletion and export | **NOT IMPLEMENTED** — deliberately; launch handles it as a support process |
| AT-5, AT-8, AT-9, AT-13, AT-16, AT-18 | OPEN_NON_BLOCKING, with AT-8 escalated as a launch prerequisite outside software scope (section 6) |

## 22. Real-provider boundary

```
REAL_INFRA_VERIFIED:      NONE
REAL_PAYMENT_VERIFIED:    NONE
REAL_REGISTRAR_VERIFIED:  NONE
REAL_HOSTING_VERIFIED:    NONE
```

Every provider exercised in the audit and in Waves 0–5 is a controlled fake. No
Proxmox, PBS, BMC or iLO, registrar, payment gateway, hosting panel or real DNS
provider was contacted, in any wave or in this review. Mocks, fakes, CI runs,
provider adapters and provider documentation are **not** real verification and
are not counted as any.

What is closed here is **software**. Real infrastructure validation is Phase
30B and has not begun.

## 23. Final closure blockers

**ZERO.**

Nothing found in this review meets any of the blocker classes: no cross-tenant
access, no money corruption or misplaced money authority, no security bypass,
no secret exposure, no automatic destructive mutation replay, no data loss, no
broken core customer journey, no false paid-or-provisioned success, no core
journey that is inaccessible or unusable.

The one item that required a real judgement rather than a glance is AT-8, and
section 6 records both the judgement and the reasoning: a launch prerequisite
that belongs to legal and product, not a software closure blocker.

No micro-fix was required, so none was made, and no code was touched.

## 24. Final verdict

```
CUSTOMER PORTAL — FINAL CLOSURE REVIEW

Status:
FINALLY CLOSED

Reviewed Wave 5 Code HEAD:
d3499881ae01f17d0fad7b01ff9a2d213fee7470

Reviewed Wave 5 Report HEAD:
60f36163001e2730e3d0e18026de918f4d9368a8

Original P1:
15 / 15 CLOSED

Wave 0:
CLOSED

Wave 1:
CLOSED

Wave 2:
CLOSED

Wave 3:
CLOSED

Wave 4:
CLOSED

Wave 5:
CLOSED

Backend:
GREEN — 3,027 / 3,027, 138,182 assertions, Pint passed, PHPStan 0 errors

Frontend:
GREEN — 443 tests / 81 files, tsc clean, eslint clean, build OK, OpenAPI valid

Browser:
GREEN — 364 passed, 14 skipped, 0 failed across four projects

Security:
GREEN — 77 tenant cases / 0; 51 surfaces, 317 keys / 0 leaks;
12 overposting cases / 0; 45 parity checks / 0

Money:
GREEN — integer minor units + ISO currency, no float, no frontend authority,
no cross-currency aggregation, server-only payment truth

Tenant isolation:
GREEN — 404-safe for strangers, 403 for members without the capability,
indirect references owner-scoped

Accessibility:
GREEN — 0 targets below the tested 24x24 floor on nine screens, measured
after settling. Not a WCAG conformance claim

Mobile:
GREEN — 393 and 360 on real device descriptors, no page-level overflow

Arabic:
GREEN — 32 Arabic browser tests, EN/AR parity gated, technical values LTR

Responsive:
GREEN — 1440, 1024, 768, 393, 360, English and Arabic

Performance boundedness:
GREEN — scale invariance proven for Activity, Dashboard and Unread

Clean room:
ACCEPTED — with the documented PHPStan-toolchain exception, covered
independently by CI's Static analysis job

Wave 5 Code-head CI:
Run 162 / id 35087196101 / attempt 1 / SUCCESS / 9 of 9

Wave 5 Report-head CI:
Run 163 / id 35098980322 / attempt 1 / SUCCESS / 9 of 9, 0 failed

Closure blockers:
0

Known non-blocking items:
Dedicated power idempotency (DEFERRED - ARCHITECTURE ITEM)
27 dynamically-composed translation keys, not proven dead
3 FUTURE_PREPARED operator hooks
in-app dirty-form navigation gap (BrowserRouter architecture)
no loading skeletons
handwritten frontend response types (bounded drift gate in place)
dark-theme path pre-existing, no product toggle
@vitest/mocker GHSA-82fw-gwwq-j7x9, development-only, 2 moderate
self-service account deletion / export NOT IMPLEMENTED
AT-5 MiB on plan change
AT-8 terms of service and acceptable use policy not linked and not present
     in this repository - escalated as a launch prerequisite outside
     software scope
AT-9 notification preferences placement
AT-13 empty states without a call to action
AT-16 no status page to link to
AT-18 "A A record needs an IPv4 address." article in a fallback sentence

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

Customer Portal:
FINALLY CLOSED

Recommended next project step:
PHASE 30B — REAL INFRASTRUCTURE VALIDATION
```

---

## What this review checked itself, and what it took on trust

Stated explicitly, because a review that cannot say which claims it verified is
a review that verified none of them.

**Independently re-derived here (CODE / TEST / RUNTIME / CI evidence):** run 163
and its job count; the freeze and the absence of any post-`d349988` code change;
all fifteen P1s against current code; the `Money` value object's float and
currency guards; the payment-state decision path and the controlled gateway's
lack of authority; the API token's single-plaintext response, digest storage and
fail-closed CIDR enforcement; the profile update's exact accepted field set;
the route error boundary's refusal to show a message, stack or component path;
the 23 customer navigation destinations and their zero overlap with operator
routes; the eight route-map assertions; support-ticket context ownership, chosen
as an indirect-reference case a matrix could miss; the dashboard's per-currency
grouping with no total; polling's terminal-awareness and stop condition;
`provisioning_jobs.requested_by_user_id` nullable with `nullOnDelete`; the
power-action labels; both dependency audits, re-run; the model mass-assignment
posture, recounted; the six open P3/P4 items including AT-18's exact `sprintf`
and AT-8's missing documents; and the full CI history 148–163 including run
153's concealed Pint failure.

**Taken from the Wave 5 report and spot-checked rather than fully re-derived:**
the P2 (AS-1 … AS-20) reconciliation, and the clean-room suite numbers — which
were produced during the Wave 5 closure in this same session, from a fresh clone,
and are corroborated by CI runs 162 and 163 at the same commits.

**Not verified, and not claimed:** anything about real infrastructure, real
payments, a real registrar or a real hosting panel. See section 22.
