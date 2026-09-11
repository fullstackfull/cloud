# Customer Portal Closure — Wave 4 — Know What Is Happening

The wave that closes the last three P1 findings from the product and UX audit:
AR-6 (no operational dashboard), AR-12 (asynchronous actions with no
follow-up) and AR-13 (no account-wide activity). With them, four supporting
findings: AS-11 (no unread indicator, inexact deep links), AS-14 (no
contextual route into support), AS-17 (no customer time zone, no relative
time) and AT-3 (no global feedback channel).

The standard the wave was held to:

> The customer must never need to guess whether Lynomia received an action,
> whether it is still running, whether it succeeded, whether it failed,
> whether it is uncertain, or what requires their attention. Dashboard,
> Activity, Notifications, operation status and Support must tell one
> consistent story derived from server truth — automatically, safely, in both
> languages and on every supported device.

---

## A. Starting HEAD

```text
f115205  Add the customer portal Wave 3 closure report
```

Verified as the tip of `claude/hv-t6hq1p` before any Wave 4 code was written.
Wave 3 was declared CLOSED at that commit and its report is untouched by this
wave.

## B. Ending code HEAD

```text
71d85ba  Wave 4: the feed answered 500 for any account that had placed an order
```

Six commits, in the order they were made:

| Commit | What it did |
| --- | --- |
| `2701433` | The activity read model and the account-wide activity API |
| `ce72667` | The operation-status contract, the dashboard aggregate, the unread count |
| `10dca0c` | One vocabulary for asynchronous work; the observation layer, toasts, time zone |
| `228c46a` | The dashboard, the activity page, the badge, contextual support |
| `7003f0d` | The tests, and the three defects they found |
| `71d85ba` | The feed's 500 on any account with an order, and failed payments as a source |

Report HEAD is recorded in the verdict block at the end of this document.

## C. Scope

What the wave was asked to close, and what it did:

| Finding | Statement | Outcome |
| --- | --- | --- |
| AR-6 | No operational dashboard. The first page was two account cards and a country-change form | CLOSED |
| AR-12 | Asynchronous actions returned a 202 and were never followed up | CLOSED |
| AR-13 | No account-wide activity. Every history was one resource's own | CLOSED |
| AS-11 | No unread indicator anywhere in the shell; notification links pointed at collections | CLOSED |
| AS-14 | Every route into support was the same empty form | CLOSED |
| AS-17 | Timestamps rendered in the browser's zone; no relative time | CLOSED |
| AT-3 | Feedback lived wherever the control lived, and died with the screen | CLOSED |

Four things the wave insisted on before writing any of it, and each is a
section of this report: server capabilities before screens (G–N), an
event-source inventory and an operation inventory written first (E, F),
activity as a derived read model rather than a second business truth (H), and
one bounded observation mechanism rather than one per screen (P, Q).

## D. Explicit out-of-scope

| Item | Status | Why |
| --- | --- | --- |
| Dedicated power idempotency | DEFERRED — ARCHITECTURE ITEM | §85. Dedicated power talks to a controller directly and has no durable operation row. Reading its reported state is not a claim of durable idempotency, and this wave did not change that architecture in any way |
| Wave 5 / Account & Polish | NOT STARTED | §87 and §95 |
| API-token features | UNTOUCHED | §87 |
| Dark mode | UNTOUCHED | §87 |
| Account deletion / export | UNTOUCHED | §87 |
| Real infrastructure | NOT TOUCHED | No real hypervisor, registrar, gateway, BMC or DNS provider was contacted. Fake providers throughout |
| Raw operator audit in customer activity | EXCLUDED BY CONSTRUCTION | `audit_log` is not a source. See H |
| DNS / reverse-DNS change history | REPORTED AS A GAP | No history table exists to source it from. See E |

---

## E. Pre-implementation event-source inventory

Written before any implementation, per §5. Every durable table that could feed
customer activity, and what it actually knows. `A` = records an actor; `R` =
identifies a resource; `T` = timestamped; `O` = carries a terminal outcome;
`raw` = carries provider or internal data that must not be published.

| Source table | Durable | Customer-safe | A | R | T | O | raw | Verdict |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `provisioning_jobs` | yes | via projection | **no** | yes (`service_id`) | yes | yes (`status`, `failure_class`) | yes (`payload`, `result`, `last_error`, `provider`, `remote_job_id`) | **USE** — the spine. Actor missing; see below |
| `domain_operations` | yes | via projection | **no** | yes (`domain_id`, `name`) | yes | yes (`state`, `failure_code`) | yes (`failure_message`, `provider`, `provider_reference`, `cost_minor`) | **USE** |
| `wordpress_site_operations` | yes | via projection | **yes** (`requested_by_user_id`) | yes (`wordpress_site_id`) | yes | yes (`state`, `failure_reason`) | yes (`failure_reason`, `impact`) | **USE** |
| `backups` | yes | via projection | **yes** | yes (`virtual_machine_id`, `service_id`) | yes | yes (`state`, `failure_reason`) | yes (`node_name`, `datastore`, `provider_task_id`, `provider`) | **USE** |
| `backup_file_restores` | yes | via projection | **yes** | yes | yes | yes | yes (`node_name`, `provider_task_id`) | **USE** |
| `vm_reinstalls` | yes | via projection | no (links a job) | yes | yes | yes | yes (`provider_node`, `provider_task_id`, `failure_message`) | **USE** — via its job; see deviations |
| `dedicated_reinstalls` | yes | via projection | no (links a job) | yes | yes | yes | yes (`bmc_endpoint_id`, `bmc_protocol`, `pxe_boot_authorisation_id`) | **USE** — via its job; see deviations |
| `dns_zone_imports` | yes | via projection | **yes** | yes (`dns_zone_id`) | yes | yes (`outcome`, counts) | no | **USE** |
| `order_transitions` | yes | via projection | **yes** (`actor_type` + `actor_user_id`) | yes (`order_id`) | yes | yes (`to_status`) | yes (`context`, `reason`) | **USE** — already a typed actor |
| `support_tickets` | yes | via projection | **yes** (`opened_by_user_id`) | yes (also `service_id`, `invoice_id`) | yes | yes (`status`) | no | **USE** |
| `invoices` | yes | yes | no | yes | yes (issued / paid) | yes | no | **USE** — issued and paid are real commercial events |
| `payment_attempts` | yes | via projection | no | yes (invoice) | yes | yes | yes (gateway payloads) | **USE** — failed and abandoned only |
| `login_activities` | yes | yes | yes (`user_id`) | n/a | yes | yes (`outcome`) | yes (`ip_address`, `user_agent`, `context`) | **DEFER** — Security already shows sign-in history; duplicating it would put IP and user agent into a new surface for no new answer |
| `customer_country_currency_changes` | yes | yes | yes | n/a | yes | yes | no | **DEFER** — has its own history screen from Wave 2 |
| `customer_invitations` / `customer_members` | yes | yes | partly | n/a | yes | partly | no | **DEFER** — the team screen owns it, and there is no durable "removed" event |
| `dns_records` | current rows only | — | no | yes | `updated_at` only | no | no | **GAP** — no history table, so "record changed" cannot be sourced without inventing it |
| `reverse_dns_records` | current rows only | — | no | yes | yes | partly | no | **GAP** — same |
| `subscriptions` | current rows only | yes | no | yes | lifecycle dates | no | no | **PARTIAL** — the cancellation date is durable; intermediate changes are not |
| `notifications` | yes | yes | no | yes (Wave 3 subject) | yes | no | no | **NOT A SOURCE** — see below |
| `audit_log` | yes | **no** | yes | yes | yes | yes | yes (operator notes, request payloads, internal metadata) | **EXCLUDED** — operator-only by construction |

### Notifications are not the activity database

`notifications` carries a `read_at`. Activity must not: marking a notification
read must never erase history. The two remain separate records that may point
at the same resource. Notifications are read or unread; activity is neither.
This is asserted, not asserted-about:
`marking_every_notification_read_erases_nothing_from_the_history` reads a page,
empties the inbox, proves the unread count really is zero, and asserts the page
comes back identical.

### Operator audit is excluded

`audit_log` rows hold operator notes, raw request payloads and internal
metadata. Nothing from that table reaches the customer feed. Activity is
projected from the domain tables above, each through an allow-listed column
selection — never `Model::toArray()`, and never `select *`.

### The actor gap, stated honestly

Six sources recorded who acted: WordPress operations, backups, file restores,
zone imports, order transitions and support tickets. **Provisioning jobs did
not** — and provisioning jobs are where power actions and rebuilds live, which
is exactly the audit's own example ("a team of three cannot see who rebooted
what"). Domain operations did not either.

Two responses, both taken:

1. `provisioning_jobs` gained a nullable `requested_by_user_id`, populated from
   the acting user on every customer-initiated path. New events answer "who".
2. Rows that predate the column, and system-initiated work, resolve to a typed
   `system` or `unknown` actor. Never inferred, never correlated by timestamp.

`domain_operations` keeps no actor: its rows are created by orders and by the
renewal scheduler, and the person who placed the order is already recorded on
the order transition. Reported as a gap rather than papered over.

### Deviations from this inventory, and why

| Source | Inventory said | What was built | Why |
| --- | --- | --- | --- |
| `vm_reinstalls`, `dedicated_reinstalls` | USE | Not separate branches | Every reinstall has a provisioning job, and that job already produces the event. A second branch would put two rows in the history for one rebuild |
| `payment_attempts` | USE | Built in `71d85ba` | Skipped by the first implementation pass and added before closure. Failures and abandonments only: a successful payment is already the invoice being paid |
| `copy_wordpress_site`, `push_wordpress_to_production` provisioning kinds | — | Excluded from the provisioning branch | The WordPress operations table is the authoritative record of those two, and the job is its shadow. Excluding one of the pair is what stops a copy appearing twice |

## F. Pre-implementation operation inventory

Written before any implementation, per §11. Every customer mutation.
`SYNC` = the response is the outcome; `DURABLE` = a durable operation row
exists to poll; `RES_STATE` = no operation row, but authoritative resource
state changes; `EXT_IND` = an external result that can be genuinely unknown.

| Operation | Class | Durable row | Terminal states | Follow-up before Wave 4 |
| --- | --- | --- | --- | --- |
| Order → provisioning | DURABLE | `provisioning_jobs` + `order_transitions` | succeeded / failed / needs_review | order and service badge, manual reload |
| VPS power | DURABLE | `provisioning_jobs` | succeeded / failed / needs_review | 202 returned the job; **nothing read it back** |
| VPS reinstall | DURABLE | `provisioning_jobs` + `vm_reinstalls` | completed / failed / needs_review | resource state on reload |
| Dedicated power | RES_STATE + EXT_IND | none (direct controller) | reported state; 504 indeterminate | resource state only — **and stays that way** (§85) |
| Dedicated reinstall | DURABLE | `provisioning_jobs` + `dedicated_reinstalls` | completed / failed / needs_review | resource state on reload |
| Hosting provisioning | DURABLE | `provisioning_jobs` | succeeded / failed | account status |
| Domain register | EXT_IND | `domain_operations` | completed / failed / indeterminate / needs_review | card state |
| Domain renew | EXT_IND | `domain_operations` | same | card state |
| Domain transfer | EXT_IND | `domain_operations` | same | card state |
| Domain redemption | EXT_IND | `domain_operations` | same | redemption panel |
| Backup create | DURABLE | `backups` | completed / failed / needs_review | row state |
| Backup restore | DURABLE | `backups` (restore state) | completed / failed / needs_review | row state |
| Backup delete | DURABLE | `backups` | deleted / failed | grace window and Keep |
| File restore | DURABLE | `backup_file_restores` | completed / failed / needs_review | history row |
| WordPress install | DURABLE | `provisioning_jobs` | ready / failed | **already polled, 15 s, hard-coded** |
| WordPress clone / staging | DURABLE | `wordpress_site_operations` | completed / failed / needs_review | operations list |
| WordPress push | DURABLE | `wordpress_site_operations` | completed / failed / needs_review | same |
| DNS publication | SYNC | none | active / failed / indeterminate | row state, immediate |
| Reverse DNS | RES_STATE | none | queued → published | row state |

### What this inventory decided

- **A durable operation status endpoint was the missing piece**, not a new
  operation model. Every DURABLE row above already existed; nothing read one
  back by id. The wave added that read, in the customer vocabulary.
- **Dedicated power stays RES_STATE.** Its state may be refreshed from the
  resource; its idempotency architecture is untouched. Polling its reported
  state is not a claim of durable idempotency (§85).
- **DNS publication is synchronous** and needs no polling at all. Adding it
  would have been polling for the sake of uniformity.
- **The four EXT_IND domain operations are where `indeterminate` lives**, and
  they already carried it as a first-class state. The client must never
  collapse it and must never offer a retry that repeats the mutation.
- **WordPress already polled.** Its semantics — one operation in flight,
  needs_review, indeterminate, the impact preview — are the strongest async UX
  in the portal, so consolidation was allowed only if every one of them
  survived. It did: the site list's schedule now comes from the shared rule and
  nothing else about it changed.

---

## G. Account-wide activity architecture

A **query-time union over the existing durable tables**, chosen over a
projection table.

The alternative was an `activity_events` table written to by every product.
That is the conventional answer and it was rejected for one reason: it makes
the same fact exist twice. A projection row that disagrees with the
provisioning job it was written from is a second business truth, and the
reconciliation problem it creates is exactly the class of bug this platform
spends its effort avoiding elsewhere. A union has one copy of every fact, and
a read model that cannot drift because there is nothing for it to drift from.

The cost is paid in SQL rather than in correctness. Eleven branches, each a
`Illuminate\Database\Query\Builder` over one table with an allow-listed
`select`, each wrapped in `DB::query()->fromSub(...)` with its own cursor
predicate, `ORDER BY` and `LIMIT`, and the whole thing merged by an outer
`fromSub`. PostgreSQL therefore reads one page from each branch's own index
and never a history: the plan for an account with four hundred events is the
plan for an account with four.

Files: `src/Modules/Activity/Application/Queries/ActivitySources.php`,
`CustomerActivity.php`, `ActivityProjection.php`, `ActivityIdentities.php`.

## H. Activity source-of-truth model

Activity is a **derived read model**. Nothing writes to it, nothing can be
edited through it, and no screen treats it as authoritative for business
state:

- Every branch selects named columns. No `select *`, no model hydration, no
  `toArray()` of a domain model. A column added to a source table cannot
  appear in the feed by accident.
- No provider text, provider reference, node, datastore, BMC protocol, remote
  job id, attempt counter or free-form metadata map is selected. This is
  asserted against the rendered JSON, not against the class: the test reads
  the response body and fails if any of a list of operational words is in it.
- Every source state reaches the customer through an exhaustive `match` with
  **no default arm** in `ActivityProjection`. A state added to a product's
  engine stops the build rather than being reported as whichever of the seven
  customer words happened to be first.
- `notifications` is not a source, so marking a message read cannot alter
  history.
- `audit_log` is not a source, so no operator note can reach a customer.

## I. Activity API

```text
GET /api/v1/activity?category=&cursor=&per_page=
```

- Scoped to the acting account by the `ActingCustomer` service, never by a
  parameter. There is no account identifier in the request, so there is
  nothing to tamper with.
- `category` accepts the six values of `ActivityCategory` and nothing else;
  an unknown value is a 422 from the form request rather than a silently
  unfiltered page.
- `per_page` defaults to 25 and is capped at 100.
- `throttle:reads` — 300 requests a minute per user. See AH.
- The response is `{data: [...], meta: {per_page, next_cursor, has_more}}`.

Each row publishes: `id`, `occurred_at`, `category`, `message_code`, `state`,
`is_terminal`, `needs_attention`, `retry_advice`, `actor` (`{type,
display_name}`), `resource` (`{kind, id, identity}` or null) and `reference`.

`message_code` is a translation key rather than a sentence. Arabic orders these
words differently and inflects around them, so a client that assembled "Your
{thing} was {verb}" from pieces would produce something no translator could
repair. The server says what happened; the portal says it in the reader's
language.

`GET /api/v1/operations/{operation}` and `GET /api/v1/me/overview` are
documented in M and V. All four new operations are in `docs/openapi.yaml`,
which the specification test regenerates and compares byte for byte.

## J. Activity page

`/activity`, in the one navigation model, beside the inbox rather than inside
it. The two are deliberately separate destinations: the inbox is messages,
which can be marked read; the feed is history, which cannot be marked
anything.

- Rows carry the identity the customer knows the thing by, linked through
  Wave 3's `pathForResource` — the single kind-to-route map — so a row opens
  the machine rather than the machine list. A kind the portal has no page for
  renders as text rather than as a link to nowhere.
- Filters are six buttons with `aria-pressed`, and choosing one sends
  `?category=` to the server. Nothing is trimmed in the browser.
- The state is a `StatusBadge` over the same vocabulary every other screen
  uses, so `needs_review` reads the same on the feed as on the machine's page.
- Relative time is the visible value, with the exact instant in `title` and in
  `<time dateTime>`.
- "Ask support about this" appears on the rows the server marked
  `needs_attention` and nowhere else.
- Loading, LoadFailure and EmptyState are all distinct, and an empty feed says
  the account is quiet rather than showing an error.

## K. Actor semantics

Three answers and no fourth, from `ActorType`:

| Type | Meaning | Rendered as |
| --- | --- | --- |
| `customer_user` | A person on the account | Their display name |
| `system` | The platform's own scheduled work | "Lynomia" |
| `unknown` | The source row records no requester | "Not recorded" |

There is deliberately **no operator actor**. An operator acting on an account
is recorded in `audit_log`, which is not a source, and inventing an
`operator` actor in the customer feed would be the first step towards
publishing operator identities to customers.

`actor_user_id` is carried inside the DTO and **is not published**. The name is
what a customer needs; the id is an internal join. Names are resolved once per
page for the users a page actually mentions.

Nothing is inferred. There is no code path that correlates a timestamp with a
session, and the one honest answer for work whose requester was never recorded
is `unknown`.

## L. Activity pagination and boundedness

Cursor pagination with a deterministic tie-breaker:

- The cursor is base64url of `occurredAtIso|activityId`.
- `activity_id` is `source:rowId` — globally unique, names its own branch, and
  is the tie-breaker, so two events in the same millisecond have a total order.
- The predicate is a row-value comparison,
  `(branch.occurred_at, branch.activity_id) < (?, ?)`, which PostgreSQL can
  satisfy from an index rather than by filtering a sorted result.
- An unreadable or tampered cursor yields the newest page, not an error: a
  bookmarked link with a mangled query string should show the feed, not a 422.
- `has_more` is computed by asking for one row beyond the page and dropping it.

Forwards only. A backwards cursor was not built, and the page's "Previous"
control walks the cursors this session has already visited rather than asking
the server to paginate backwards — which is honest about what the API
supports.

Measured boundedness is in AG.

---

## M. Operation-status contract

```text
GET /api/v1/operations/{operation}
```

The missing half of every 202 the platform issues. Published fields:

| Field | Meaning |
| --- | --- |
| `id`, `kind` | The operation and the engine's own word for what it is |
| `action` | The customer's verb where the payload carries one — `reboot`, not the `restart` kind |
| `state` | One of the seven customer words |
| `is_terminal` | Whether the platform expects this to change on its own |
| `needs_attention` | Whether it is waiting on somebody |
| `retry_advice` | What may safely be done next |
| `failure_reason` | The customer's bounded reason, or null. Never the provider's sentence |
| `resource` | `{service_id}` — what to read again when it finishes |
| `requested_at`, `started_at`, `updated_at`, `finished_at` | Timestamps |
| `poll_after_ms` | When to read again, **null once terminal** |

Deliberately absent: `last_error`, `payload`, `result`, `provider`,
`remote_job_id`, `failure_class`, `attempts`, `max_attempts`,
`timeout_seconds`, `next_attempt_at`. A test reads the response body of an
operation seeded with a Proxmox UPID, a node name and a datastore in its error
text, and fails if any of those strings is present.

Another tenant's operation is a **404, not a 403**: a refusal would confirm
that the id names real work.

`poll_after_ms` is the server half of "polling stops on terminal states". A
client that only ever schedules its next read from that field cannot keep
asking about an operation that finished, however the screen was written.

## N. Operation state vocabulary

Seven words, `CustomerOperationState`:

```text
queued  processing  succeeded  failed  needs_review  indeterminate  cancelled
```

Wave 4 found **three** customer-facing vocabularies for one provisioning job
and consolidated them into this one:

| Where | Used to say | Now says |
| --- | --- | --- |
| VPS power / rebuild 202 receipt | the engine's raw `status`, plus `is_settled` | the seven words, plus `is_terminal` |
| Service event list (`/services/{id}/events`) | `scheduled` / `in_progress` / `completed` / `under_review` from a second enum | the seven words |
| `GET /operations/{id}` | the seven words | unchanged |

So a customer could read "queued" on the receipt, "scheduled" in the history
and "queued" again on the poll, about one reboot — and `in_progress` had no
Arabic string at all, because the translation gate did not know that enum
existed. `CustomerProvisioningEventState` is deleted. There is one mapping,
`ProvisioningJobStatus::customerState()`, with no default arm, and every
customer-facing resource asks it.

`is_settled` became `is_terminal` in the same pass: two names for one predicate
is the same problem as two words for one state.

`CustomerOperationState` and `RetryAdvice` are now in the translation gate, so
a state added to either fails the build until somebody writes the two
sentences a person will read.

The three that must never be collapsed:

- **`needs_review` is not `failed`.** The work stopped and a person at Lynomia
  has to look at it. Telling a customer it failed invites them to do it again,
  on a machine that may be half-built.
- **`indeterminate` is neither `failed` nor `succeeded`.** The platform asked
  something outside itself and never heard back. The registration may have
  happened. Presenting either guess as fact is how a customer buys a name
  twice.
- **`cancelled` is not `failed`.** Nothing went wrong; somebody changed their
  mind.

## O. Retry semantics

`RetryAdvice`, decided by the server and published beside the state:

| State | Advice | Why |
| --- | --- | --- |
| `queued`, `processing` | `wait` | Still ours to finish. Asking again would queue a duplicate |
| `succeeded` | `not_retryable` | Nothing to retry |
| `failed` | `safe_to_retry` | The platform knows it did not happen |
| `needs_review` | `support_required` | Somebody is already looking; a second attempt lands on top of them |
| `indeterminate` | `support_required` | The action may have taken effect. Repeating it is the one thing a customer must not do |
| `cancelled` | `safe_to_retry` | The customer stopped it; they can start it again |

**There are no automatic mutation retries anywhere.** Three things enforce it:

1. There is no generic retry endpoint. A test asserts that
   `POST /operations/{id}/retry` is a 404, with the reason written beside it:
   a generic retry would be a second path to every mutation in the platform,
   wired to whatever the last status read said, which is exactly how an
   indeterminate operation gets asked for twice.
2. Nothing in the observation layer can send a mutation. It reads.
3. The query client sets `mutations: { retry: false }` explicitly, with the
   reason in a comment beside it, so that nobody can turn one on without
   deleting the explanation.

Retrying is re-asking the product for the same thing, through that product's
own endpoint and its own idempotency key, on a deliberate press by a person,
on a state the server has said is safe to retry.

## P. Polling architecture

One module, `apps/web/src/lib/watchOperation.ts`, decides when the portal asks
again. Before this wave there was one `refetchInterval` on the WordPress list,
with its own hard-coded fifteen seconds, and nothing anywhere else.

- **The interval is the server's.** `poll_after_ms` — 3 s while the work is
  settling, 10 s after its first thirty seconds, and **null** the moment the
  state is terminal. A finished operation cannot be polled by a screen that
  takes its schedule from here.
- **It backs off.** The server's hint is a floor; each landed read widens the
  gap, to a 30-second ceiling.
- **A tab nobody is looking at does not poll.** TanStack runs an interval only
  while the window has focus unless told otherwise, and it is not told
  otherwise.
- **Coming back reads immediately.** `refetchOnWindowFocus` and
  `refetchOnReconnect` are on for this query and this query alone — the
  application default is off, which is right for a list and wrong for
  something in flight.
- **A read failure is not an operation failure.** §54. When a poll errors the
  last state the server actually reported stays on screen, and the failure is
  reported separately as a failure to read. Overwriting "processing" with
  "something went wrong" because a request timed out is how a customer is told
  their server broke when their wifi dropped.
- **The window closes without lying.** Past fifteen minutes, measured from the
  operation's own `requested_at`, polling stops and the customer is told the
  work is *taking longer than usual*. Not that it failed: the platform has not
  said that.

Lists that hold work in flight — backups, file restores, WordPress sites — ask
the same module for their schedule, through `nextListPollDelay`, using each
resource's own server-published `is_in_flight` predicate. The browser never
holds a list of which states are finished, because that would be a second copy
of a vocabulary that lives on the server.

## Q. Polling boundedness

A source-level gate, `one-polling-mechanism.test.ts`, walks every file under
`apps/web/src`:

- `refetchInterval` appears only in `lib/watchOperation.ts` and `lib/queries.ts`.
- `setInterval` / `setTimeout` appear only in those two, plus two files listed
  by name with their reasons: the resend countdown on the verification page and
  the toast channel's own auto-dismiss. Neither asks the server anything.
- `lib/adminQueries.ts` is excluded by name, with the reason written down: an
  operator dashboard refreshes for as long as somebody is watching it, which is
  a different thing from watching one customer's reboot until it settles.

A gate over source text rather than over behaviour, deliberately: the
behaviour of a timer nobody knows about is precisely what cannot be asserted.

Beside it, the rule itself is unit-tested without a React tree — first read
scheduled by the query and not by an interval, the server's hint honoured,
`false` for every terminal state, the gap widening with reads, the ceiling, and
the window both closing after it and *not* closing one millisecond before it.

In the browser, the claim is measured rather than asserted: a reboot is
started, the "Reboot completed" message is waited for, the number of requests
to `/api/v1/operations/` is recorded, twelve seconds pass — four of the
server's own three-second floors — and the count must be unchanged.

## R. Reload and navigation recovery

Three separate properties, each with its own journey:

1. **Navigate away.** The watcher and the feedback channel both live above the
   routes, inside the router. A reboot started on a machine's page and finished
   after the customer moved to their invoices still reports itself, and a
   browser journey drives exactly that.
2. **Reload.** What is being watched is written to `sessionStorage` — an id and
   a label, never a state — so a reload resumes watching rather than
   forgetting. The state is always whatever the server says on the next read,
   because a state cached in the browser is a second truth and a stale one.
3. **The truth is on the server either way.** The machine's own page reads the
   resource, and the resource is authoritative. The journey reloads mid-reboot
   and the page still says what happened.

The observation window is measured from the operation's `requested_at` rather
than from when the tab opened, which is why the contract gained that field: a
reload must not restart the clock on a rebuild that has been stuck for an hour.

## S. Indeterminate behaviour

The state the wave exists for. What the platform does with it:

- The API publishes it as itself. `CustomerOperationState::Indeterminate` is a
  first-class value, and the mapping into it is written by hand in the module
  that owns the source state.
- Its retry advice is `support_required`, from the enum, not from a screen.
- The portal's terminal-message switch has an arm for it that says "We could
  not confirm the result of X" and offers support. There is no default arm, so
  a state added to the API cannot fall through into the failure message.
- `ProvisioningJobStatus` has no mapping onto `indeterminate`, and that is
  deliberate: the provisioning engine always knows whether it ran its own
  work, and a job that lost contact with a provider is parked as
  `needs_review` by the worker rather than guessed at. Indeterminate belongs to
  the operations that genuinely have an unknown result — the ones that call out
  to a registry or a controller.
- The client cannot turn it into a failure by giving up: past the observation
  window the message is "taking longer than usual", and the state shown is
  still the last one the server reported.
- A browser journey asserts that the word "failed" does not appear on a page
  whose operation stopped mid-flight, and that the way out is support rather
  than a retry.

---

## T. Global acknowledgement and toast

`apps/web/src/components/Toasts.tsx`, one channel, mounted inside the router
and above the routes.

- **Announced.** The list is a `role="region"` with an accessible name,
  containing an `aria-live="polite"` `<ol>` that is in the DOM whether or not
  it has children — a region announced only once it has content is a region
  assistive technology was not watching when the content arrived. Polite, not
  assertive: an operation finishing is worth saying at the next pause, not
  worth interrupting the sentence somebody is reading.
- **Keyed by what it is about.** `announce` takes a stable id — the operation's
  own — and a message with the same id replaces its predecessor in place. Four
  polls of one rebuild produce one message that changes, not four stacked ones.
  Transitions are tracked by operation identity in a ref, so a repeated read of
  the same terminal state announces nothing new.
- **Acknowledged in the lifecycle's own words.** "Reboot requested" — which is
  what happened — and never "Success", which would be a claim about a machine
  nothing has touched yet. The acknowledgement comes from the receipt the
  server just returned.
- Actions whose progress is a property of the resource rather than of an
  operation the platform reports on — dedicated power, backup create, restore,
  file restore — are acknowledged and not watched. Saying "Power off
  requested" is the truth; claiming a result would not be.

## U. Failure and needs-review feedback

- Good news clears itself after six seconds. A warning or a failure stays until
  it is dismissed: the one class of message a customer must not miss is the one
  that says their machine needs somebody to look at it.
- The terminal message is chosen by a `switch` with an arm for every member of
  the state union. `needs_review` says the work stopped and a person is looking
  at it, and carries "Please contact support before trying again".
  `indeterminate` says the result could not be confirmed, and carries the same.
  Neither can reach a customer as a failure.
- `failed` carries "You can try this again", which is the only state that does.
- Every message offers a way to the resource it is about.

---

## V. Dashboard aggregate architecture

```text
GET /api/v1/me/overview
```

One read answers: what needs attention, what the account holds, what it owes,
what renews next, how many notifications are unread, and what just happened.

The alternative the audit itself floated — "four existing list calls" — was
rejected for two reasons. It would put the priority rules in the browser, so
every client would reimplement the question "is an overdue invoice more urgent
than a stopped rebuild"; and it would make a phone pay for four list payloads
to draw six numbers.

Bounded by construction:

- the service summary is one grouped count, not a page of services;
- money due is one grouped sum per currency;
- renewals and recent activity are short, capped lists;
- the attention list is capped per class (5) and overall (12);
- resource handles for the whole response resolve in **one** pass — the
  attention list and the recent-services list both hold service ids, and
  resolving them separately asked every fulfilling table twice for one
  dashboard.

There is no identifier in the request. The account is the token's.

## W. Attention model

`AccountAttention` reads seven sources and returns a capped, sorted list.

| Kind | Severity | Source |
| --- | --- | --- |
| `attention.invoice.overdue` | critical | open invoices past `due_at` |
| `attention.invoice.dueSoon` | warning | open invoices due within 7 days |
| `attention.operation.needsReview` | critical | provisioning jobs in `needs_review` |
| `attention.domain.uncertain` | critical | domain operations in `indeterminate` or `needs_review` |
| `attention.domain.lapsed` | critical | names in redemption, expired or grace |
| `attention.domain.expiring` | warning | active names expiring within 30 days |
| `attention.backup.needsReview` | critical | backups and file restores in `needs_review` |
| `attention.support.waitingForYou` | warning | tickets waiting for the customer |

Sorted by `[severity rank, occurred_at, id]` — server-side, so an Arabic
dashboard and an English one agree about what matters most, and the order does
not change between two reads of the same data.

Each item carries a resource handle, and the portal turns it into an address
through the one routing map. A browser journey follows the top row and asserts
it lands on a resource rather than on an index: a dashboard row that linked to
`/invoices` would hand back the work it exists to save.

Two things the model does not do. It does not publish a provisioning job's
ULID as a customer "reference" — that field is for something a customer can
quote, and twenty-six characters of identifier beside a hostname is noise the
support link already carries properly. And it does not hide itself when the
account is healthy: "Nothing needs your attention" is information, and a first
section that disappears when things are fine teaches nobody where to look when
they are not.

## X. Service summary

One grouped count over `services`, in the customer-facing state vocabulary, so
that a dashboard saying "1 needs attention" agrees with the services index
about which one. Total plus a breakdown by state, each state rendered through
the same `StatusBadge` as everywhere else, and a link to the services index.

An account with no services gets the sentence and the catalogue link rather
than a zero.

## Y. Billing and renewal summary

**Money is grouped by currency and never summed across them.** One row per
currency, each already money-shaped by `SerialisesMoney`, and the owed section
carries no total at all — not a nulled one, not a hidden one: an account billed
in two currencies is owed two amounts, and `10 KWD + 20 USD = 30` is not a
number that exists. (The service summary does carry a `total`, because a count
of machines is a number that exists.) A unit test asserts that
the card renders both currencies and that the number 30 is not on the page.

The sums come from `amount_due_minor`, the column Wave 2 made authoritative.
Nothing is recomputed from line items, and nothing is computed in the browser.

Renewals come from the two tables that hold a real date: a subscription's
`next_invoice_at` and a domain's `expires_at`, within a 45-day horizon, capped
at five and merged by date.

A renewal carries an amount **only where the platform has an authoritative
one**. A subscription has `recurring_amount_minor`. A domain has none — its
renewal price comes from the catalogue at the moment of renewal — so it says
"Priced at renewal" rather than quoting today's price as a commitment.

## Z. Dashboard recent activity

The dashboard's activity rows come from `CustomerActivity` — the same class
`/activity` reads, asked for a shorter page. §30: the dashboard has no history
logic of its own, so a row cannot read one way on the dashboard and another
way in the feed, and there is one place where "what happened" is decided.

## AA. Empty and new-account dashboard

An account with no services, nothing needing attention and no activity gets
one card: what the portal is for, and a link to the catalogue. Not six empty
cards, which read as a broken dashboard, and not a wall of zeros.

The condition is all three of those being empty, so an account that has bought
nothing but has an unpaid invoice still gets the attention list.

---

## AB. Notification unread count

```text
GET /api/v1/notifications/unread-count  →  {data: {unread: N}}
```

One integer. The badge is drawn on every page, and the only way to know the
number before was to fetch a page of the inbox and read its meta — which
downloads twenty-five notifications to render a digit, on every navigation.
A test asserts the endpoint's payload has exactly one key, and another that it
costs fewer than six queries with sixty unread notifications in the inbox
(measured: 2).

The badge lives inside the notifications link in the shared navigation
definition, so it appears in both the desktop sidebar and the phone drawer
from one place. It is keyed under `['notifications']`, so marking one read
invalidates the count with the list.

Two properties, both of which were defects before they were tests:

- **The count is part of the link's accessible name.** A coloured circle beside
  a link announces as "Notifications" with no number in it. The visible digit
  is `aria-hidden` and a screen-reader-only phrase carries the count, so the
  link announces as "Notifications, 3 unread". The comma is load-bearing:
  without a separator the name computes as "Notifications3 unread", because the
  two are adjacent nodes and the name algorithm trims each one before joining.
- **It caps at 99+.** Not for space. The difference between 142 and 143 unread
  notifications is not a difference anybody acts on, and rendering it suggests
  it is. The accessible name keeps the true number.

Nothing at all is rendered at zero: an empty badge is a thing to look at that
says nothing happened.

## AC. Contextual Support

"Ask support about this", from the rows that need it, carrying what it is
about in the URL — which is what makes it a link: it survives a copy, a
middle-click, a bookmark and a reload.

```text
/support?about=<translation key>&kind=&id=&identity=&ref=&service=
```

- **Prefilled, never submitted.** The subject and the opening lines of the
  body are the initial values of the form's fields, read once rather than
  pushed in by an effect — an effect that wrote to a field the customer was
  already typing in would overwrite their sentence on every render. A notice
  says the form was filled in from what they were looking at. The ticket does
  not exist until somebody presses send.
- **The subject travels as a key, not a sentence**, so the draft is written in
  the language the customer is reading when they follow the link.
- **The key is checked.** `t()` on a key that does not exist returns the key,
  so an arbitrary dotted string in the URL would land in the subject line of a
  support form. Only the `activity.` and `attention.` namespaces are accepted,
  and only if the key resolves to a real string. A link whose subject is not
  one of ours is ignored entirely rather than producing half a draft.
- **The server does not trust any of it.** `TicketController` resolves a
  `service_id` and an `invoice_id` against the acting customer with
  `firstOrFail()` and answers 404 otherwise. A test posts another tenant's
  service id and gets a 404; the same post with the account's own service id is
  a 201.
- A resource id is not a service id, so `service` is only ever set by a caller
  that actually holds one, and left out rather than guessed at.

## AD. Request and reference ids

- Every API failure carries a `request_id`, which the portal renders under the
  error so support can find the request in the logs. Unchanged from Wave 1 and
  used by the new surfaces.
- `reference` on an activity row is something a customer can quote — an invoice
  number, a ticket reference — and never an internal id chosen for uniqueness
  alone. The attention list stopped publishing a job ULID as one during this
  wave.
- An operation's own id **is** published on the operation contract, because it
  is the one thing a customer can usefully quote about a piece of work, and it
  names a job already scoped to their own account.

---

## AE. Timezone architecture

The customer's own IANA zone, from their profile, applied in one place.

`applyTimeZone(user.timezone)` is called in `AppLayout` during render rather
than in an effect. An effect runs after the first paint, so every date in the
tree would be drawn once in the browser's zone and then corrected — which on a
laptop still set to Europe/London is a visible flicker between two different
times for the same reboot. The children of the layout render after that call
returns, so they see the right zone on their first pass.

Every formatter reads it at call time, so a change on the profile page takes
effect on the next render rather than the next reload. A zone Intl cannot
resolve is ignored rather than thrown — the server validates the name, but a
stored value from an older tzdata must degrade to the browser's zone rather
than blanking every date on the page.

**Date-only values are never shifted.** `2027-03-09` is a calendar fact, not a
moment; `new Date('2027-03-09')` is midnight UTC, so rendering it in a zone
behind UTC moves it to the 8th — a renewal date wrong by a day, on the screen
that says when money is taken. Values matching `^\d{4}-\d{2}-\d{2}$` are
formatted in UTC, and a unit test renders one in Asia/Kuwait,
America/Los_Angeles and Pacific/Kiritimati and asserts it reads as the 9th in
all three.

## AF. Relative time

`formatRelative` uses `Intl.RelativeTimeFormat` with `numeric: 'auto'`, which
is what produces "yesterday" and "أمس" rather than "1 day ago". Arabic plural
rules — six of them — come from the language rather than from a string table.

Deliberately not zone-aware: the distance between two instants is the same
number of seconds in every time zone, which makes relative time the one date
format that cannot be wrong because a browser's clock is set elsewhere. A test
asserts that changing the active zone does not change the rendered value.

Feed rows and attention rows show relative time with the exact instant — in
the customer's zone — in `title` and `<time dateTime>`.

---

## AG. Performance and query boundedness

Measured, not asserted. The same account shape at two sizes, counting queries
through the query log:

| Read | 5 machines, 15 events | 45 machines, 450 events | Growth |
| --- | --- | --- | --- |
| `GET /activity?per_page=25` | 7 queries | 7 queries | **none** |
| `GET /me/overview` | 23 queries | 23 queries | **none** |
| `GET /notifications/unread-count` | 2 queries | 2 queries | **none** |

Nine times the machines and thirty times the events, and not one extra query.
`TheDashboardAndFeedStayBoundedTest` asserts the equality rather than a
threshold, because what matters is that the number does not grow; the ceilings
beside it are generous on purpose.

What makes it hold:

- identities and actor names resolve once per page, in one query per
  fulfilling table, reusing Wave 3's `ServiceIdentities::handlesFor`;
- the dashboard resolves handles once for the whole response rather than once
  per section;
- every union branch carries its own `LIMIT`, so the merge sorts eleven small
  pages rather than a history;
- a category filter chooses branches instead of filtering the union, so asking
  about support reads one source instead of eleven. A test asserts the filtered
  read costs strictly fewer queries than the unfiltered one.

The scale account asserts **structural** boundedness. There is no wall-clock
assertion anywhere: a time-based test passes or fails with CI's mood.

## AH. Rate limiting

A new `reads` limiter, 300 requests per minute keyed per authenticated user,
configured in `config/security.php` and registered in
`RateLimitServiceProvider`. It is applied to the three read endpoints a
polling client touches: `/activity`, `/operations/{operation}` and
`/notifications/unread-count`.

Chosen against the observation layer's actual worst case: the server's floor is
3 s, the client's ceiling is 30 s, only a focused tab polls, and at most six
operations are watched at once — so a pathological tab sits around 120 requests
a minute, well inside the allowance, while a client that ignored
`poll_after_ms` entirely would be throttled rather than served.

`/me/overview` keeps the standard authenticated limiter: it is a page load, not
a poll.

## AI. Tenant isolation

- Both new read endpoints derive the account from the session. There is no
  account parameter anywhere in the activity, overview or unread-count
  surfaces, so there is nothing to tamper with.
- `/operations/{operation}` scopes the lookup to the acting customer and
  answers **404** for another tenant's operation — not 403, which would
  confirm the id names real work.
- Every union branch carries `where customer_id = ?`, or a join through the row
  that holds the customer where the source table does not (order transitions
  through `orders`, payment attempts through `invoices`).
- A test seeds two accounts with events at the same instants and asserts that
  neither account's page contains a single one of the other's rows.
- Support context is validated server-side, as AC describes.

## AJ. Security and the customer/operator boundary

- `audit_log` is not an activity source. No operator note, request payload or
  internal metadata can reach a customer.
- There is no `operator` actor type, and the enum says why.
- No provider name, node, datastore, BMC protocol, remote job id, attempt
  counter, provider reference or free-form metadata map is published by any of
  the new contracts. Asserted against rendered JSON on both the operation
  contract and the feed, with a list of operational words.
- The payment-failed branch selects neither `failure_code` nor
  `failure_message`: the first is the gateway's vocabulary and the second is
  its prose.
- `actor_user_id` is carried internally and never published.
- No secret, password, token, API key or registrant PII is logged, persisted or
  echoed by anything this wave added. `current_password` is untouched by this
  wave.
- A browser journey reads the whole rendered document of a machine whose
  rebuild stopped and asserts that neither "hypervisor" nor the node name
  appears anywhere on it.

---

## AK. Desktop verification

The desktop browser project drives thirteen Wave 4 journeys, in a real Chromium
against the real API:

| # | Journey |
| --- | --- |
| 1 | Leads with attention, links to the exact thing, and never sums two currencies |
| 2 | Opens the invoice the dashboard is complaining about |
| 3 | Names the person who asked, for each of two people on one account |
| 4 | Filters on the server rather than trimming the page it has |
| 5 | Never calls work that stopped for a person a failure, and offers support |
| 6 | Carries what it is about into a support draft, and does not send it |
| 7 | Walks the feed forwards by cursor rather than by page number |
| 8 | Acknowledges a reboot in the lifecycle's own words and follows it to the end |
| 9 | Keeps the acknowledgement when the customer walks away from the page |
| 10 | Resumes watching after a reload rather than forgetting |
| 11 | Stops asking once the operation is over |
| 12 | Says a rebuild that stopped for a person needs review, on the machine's own page |
| 13 | Counts unread notifications in the link that opens them, and clears with them |

Journey 3 is worth a note: it signs in as the account owner, reboots a
machine, signs out, signs in as the teammate, reboots the same machine, and
asserts that the feed names both people on their own rows. It is the audit's
own example — "a team of three cannot see who rebooted what" — driven end to
end rather than asserted at the unit level.

The journeys do not depend on a fixture's position in a shared list: an
earlier draft asserted that seeded rows were on page one, which held until
another spec placed an order on the same account. They now either create the
events they read or read the dashboard's own capped, severity-ordered list.

## AL. Mobile verification

Three journeys on the Pixel 5 profile at 393 px:

- The dashboard leads with attention and does not scroll sideways; the cards
  stack rather than squeeze, asserted as a measured card width rather than as
  a screenshot.
- The feed reads and filters with a thumb: the filter buttons are at least
  32 px tall, pressing one sets `aria-pressed`, and the page still does not
  scroll sideways after the filter is applied.
- The acknowledgement panel appears without covering what it is about, and its
  dismiss control is reachable and works.

Sideways overflow is measured from `scrollWidth - clientWidth` rather than
eyeballed, on every one of the three.

## AM. Arabic verification

Four journeys in a browser whose locale is Arabic:

- The dashboard's attention region is found by its Arabic accessible name, its
  first row is Arabic prose, and **no translation key shows through anywhere in
  `main`** — asserted as the absence of `/\battention\.[a-z]/i` and
  `/\bactivity\.[a-z]/i`. This is the assertion that would have caught the
  `attention.domain.lapsed` defect described in AX.
- The feed reads in Arabic with Arabic relative time, asserted as Arabic script
  inside the `<time>` element: an English "3 hours ago" there would mean the
  formatter was handed the wrong locale.
- A reboot is acknowledged in Arabic, in the lifecycle's own words.
- "اسأل الدعم عن هذا" leads to a support draft whose subject is in Arabic —
  which is what a key travelling in the URL buys, and what a sentence
  travelling in the URL would not.

Assertions are "is this Arabic prose" rather than exact strings, so that
improving a translation is not a failing test.

## AN. Accessibility

- The feedback channel is a named `role="region"` containing an
  `aria-live="polite"` list that is in the DOM from the first render.
- Every `Card` is a named region: the heading gets a generated id and the
  `<section>` an `aria-labelledby`. A `<section>` with no accessible name is
  not a landmark at all, which is what it was before this wave.
- The unread badge's count is in the notifications link's accessible name, as
  AB describes.
- Filters are buttons with `aria-pressed`, not links that look pressed.
- Times are `<time dateTime>` with the exact instant in `title`, so the precise
  value is available to anybody who needs it while the readable value is the
  relative one.
- Attention and feed rows are list items in real lists, so their count is
  announced.
- The phone's filter controls meet a 32 px minimum, measured.
- Nothing added by this wave conveys state by colour alone: every badge and
  every toast carries words.

## AO. Wave 0 regression

Unchanged and passing. Idempotency-Key transport is what the operation receipts
in T are built on: the power journey posts with a key and the receipt it asserts
against is the one that transport returns. Graded confirmations still gate the
eight actions; the reinstall-blocked state and `LoadFailure` are both reused by
the new surfaces rather than reimplemented.

## AP. Wave 1 regression

Unchanged and passing, and extended rather than altered:

- The Accept-Language pipeline carries the new message codes.
- The shared navigation source gained `/activity` and the badge, so both the
  desktop sidebar and the phone drawer changed in one place. The mobile
  navigation spec was updated to expect the new destination.
- `EveryStateAScreenShowsIsTranslatedTest` gained `CustomerOperationState` and
  `RetryAdvice`, so the new vocabulary is inside the existing parity gate.
- `EveryMessageCodeIsTranslatedTest` is new beside it, for the codes that are
  strings on the wire rather than enum cases.

## AQ. Wave 2 regression

Unchanged and passing. The dashboard's money is the same `SerialisesMoney`
shape as the invoice and wallet surfaces, and its sums come from
`amount_due_minor` — the column Wave 2 made authoritative — rather than from
anything recomputed. Two existing money specs needed a locator scoped more
tightly because the dashboard added a second heading containing "welcome";
neither assertion changed.

## AR. Wave 3 regression

Unchanged and passing, and leaned on heavily:

- `pathForResource` is the one kind-to-route map, and every deep link in the
  dashboard, the feed and the notification list goes through it.
- `ServiceIdentities::handlesFor` is what makes the batched hydration in AG
  bounded; the new code reuses it rather than adding a second resolver.
- The resource pages are where the watcher's `onFinished` invalidation lands,
  which is why a settled reboot updates the page it was started from.
- The service-events endpoint changed vocabulary — from its own third enum to
  the canonical seven words — which is the one breaking change in this wave's
  API surface, described in N.

---

## AS. Backend tests

The whole backend suite, one process, on a fresh database:

```text
2974 tests, 137869 assertions, 0 failures
```

Pint clean. PHPStan **0 errors**. `docs/openapi.yaml` regenerated by the
specification test and valid to redocly with the same 6 pre-existing warnings
Waves 0–3 carried — **247 operations**.

Six new test files, 40 tests:

| File | Tests | What it holds |
| --- | --- | --- |
| `Feature/Activity/AnAccountKnowsWhatHappenedTest` | 10 | The feed's contract: who asked, system-initiated work, the union's time order, an indeterminate registrar operation never reported as failed, another tenant's history unreachable, nothing operational travelling with a row, a bounded page whose cursor walks the whole history, an unreadable cursor returning the newest page, a category filter answered in SQL, and marking every notification read erasing nothing |
| `Feature/Activity/AnAccountKnowsWhatNeedsDoingTest` | 10 | The dashboard: a new account told it has nothing rather than shown zeros, an overdue invoice outranking a name expiring next month, two currencies never added together, a needs-review operation linking its machine, a healthy account given nothing to worry about, a lapsed name outranking an expiring one, the recent rows being the feed's own, a renewal with no authoritative price carrying no amount, another tenant's overview unreachable, and support waiting for the customer |
| `Feature/Activity/AnOperationCanBeWatchedTest` | 11 | The operation contract: running work saying when to look again, finished work telling the client to stop, work waiting on a person not reported as a failure, a clean failure being the one retryable state, nothing operational published, another tenant's operation 404 rather than 403, no generic retry endpoint, the unread count as its own small read, marking one read moving the count, the receipt and the poll agreeing word for word, and support context pointed at another tenant's service refused |
| `Feature/Activity/EverySourceSurvivesOnePageTest` | 3 | Every name column the feed reads existing in the schema; one account that has done one of everything reading its own page whole; the dashboard surviving the same account |
| `Feature/Performance/TheDashboardAndFeedStayBoundedTest` | 4 | The feed costing the same at 5 machines as at 45; the dashboard the same; the unread count bounded whatever the inbox holds; a filtered read costing strictly fewer queries than the whole feed |
| `Architecture/EveryMessageCodeIsTranslatedTest` | 2 | Every `activity.*` and `attention.*` code the server emits resolving in `en` and `ar`, plus a floor assertion so the gate cannot pass by finding nothing |

Four existing files were updated to the canonical vocabulary
(`ServiceEventsEndpointTest`, `VpsPowerEndpointTest`,
`VpsReinstallEndpointTest`) or extended with the new enums
(`EveryStateAScreenShowsIsTranslatedTest`).

Both new gates were verified by breaking them on purpose:
`EveryMessageCodeIsTranslatedTest` fails when a key is deleted from `ar.json`,
and `EverySourceSurvivesOnePageTest` fails when a name column is renamed —
which is exactly the defect it was written for.

## AT. Frontend tests

```text
54 test files, 243 tests, 0 failures
```

Typecheck clean (`tsc -b`), eslint clean with zero warnings, production build
clean.

Eight new test files, 41 tests:

| File | Tests | What it holds |
| --- | --- | --- |
| `lib/__tests__/when-to-look-again.test.ts` | 6 | The polling rule without a React tree: no read scheduled before the first one lands, the server's interval honoured, the gap widening as reads add up, the ceiling never crossed, asking given up once the work outruns the observation window, and watching continued when the server sent no timestamp to measure from |
| `lib/__tests__/one-polling-mechanism.test.ts` | 4 | The source gate: it has files to check at all, a repeat read is scheduled from one module and nowhere else, no timer loops outside the two places that are not polling, and the rule stays where the tests can reach it |
| `lib/__tests__/the-customers-own-clock.test.ts` | 5 | An instant read in the zone the customer chose, a date with no time in it never moved, an unresolvable zone ignored rather than blanking the page, a cleared zone falling back to the browser, and "how long ago" coming from Intl rather than a string table |
| `features/operations/__tests__/watching-what-was-started.test.tsx` | 5 | The request acknowledged in the lifecycle's own words, asking continued while unfinished and stopped when over, work waiting on a person never reported as a failure, an unknown outcome reported as neither failure nor success, and a failed read never turned into a failed operation |
| `features/account/__tests__/the-first-page.test.tsx` | 6 | Attention above what the account holds, an attention row linking the exact thing rather than its list, two currencies as two amounts never added up, a renewal priced at renewal rather than quoted as a guess, a brand new account given one sentence and one way forward, and the dashboard saying so when it cannot be read |
| `features/activity/__tests__/what-happened-to-this-account.test.tsx` | 6 | The person who asked named from the field rather than guessed, the category sent to the server, the feed walked by cursor and never by page number, support offered beside a row that stopped and nothing beside one that did not, a row opening the thing it is about through the one routing map, and a quiet account saying so rather than showing an error |
| `app/__tests__/the-unread-badge.test.tsx` | 4 | The count inside the link that opens the inbox, one integer read rather than a page of it, counting stopped past ninety-nine, and nothing at all rendered when the inbox is clear |
| `features/support/__tests__/asking-support-about-this.test.tsx` | 5 | What it is about carried and read back, a subject that is not one of ours refused, the resource named in the subject, the body left unfinished, and a service id nobody gave omitted rather than guessed |

## AU. Browser E2E

The whole browser suite, all three projects, one worker, real Chromium against
the real API and a real PostgreSQL:

```text
Running 248 tests using 1 worker
242 passed (12.2m)
6 skipped
```

| Project | Passed | Skipped |
| --- | --- | --- |
| `chromium` (desktop, 1440) | 201 | 6 |
| `customer-mobile` (Pixel 5, 393 px) | 20 | 0 |
| `customer-arabic` (locale `ar`, RTL) | 21 | 0 |

The six skipped are the visual-record spec, which is opt-in and skipped in a
plain run exactly as in Waves 0–3.

Twenty of those journeys are this wave's: thirteen desktop (AK), three phone
(AL) and four Arabic (AM). The other 222 are Waves 0–3 and the operator
surface, all green — the regression evidence for AO through AR.
