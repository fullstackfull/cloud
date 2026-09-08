# Phase 30A++ — Domains and WordPress hosting

What was built, what it cost, what it found, and what it still cannot do.

Two product families, implemented rather than planned: **Lynomia Domains**
(search, price, buy, manage, renew, transfer) and **Lynomia WordPress Hosting**
(shared hosting with WordPress, a name and a certificate on top).

Every figure below was produced by running the thing it describes. Nothing here
is inherited from an earlier report.

---

## A. Where the phase started and where it ended

| | Start (`1327a82`) | End (`56ed5f1`) |
| --- | --- | --- |
| Backend tests | 2 302 | 2 446 |
| Assertions | 65 124 | 69 585 |
| Browser specs | 94 | 105 |
| Frontend tests | 54 | 54 |
| Documented API operations | 134 | 150 |
| Metric families | 38 | 41 |
| Audit actions | 43 | 52 |
| Notification types | 36 | 44 |
| Scheduled commands | 17 | 20 |
| Migrations | 42 | 45 |
| PHPStan errors | 0 | 0 |

Nine commits. Two of them exist because of defects the work found in itself,
and one because a dependency advisory turned the security gate red.

---

## B. What a customer can now do that they could not

- Search for a domain and be shown all five answers, including the one that is
  neither yes nor no.
- Be quoted a price the platform will honour, and buy the name at it.
- Change its delegation, its registrant and its transfer lock; take the
  authorisation code and leave.
- Renew it, or have auto-renew do that — and be warned before it lapses either
  way.
- Transfer a name in from another registrar.
- Order a WordPress site with any of four answers to "where does the name come
  from", and watch which of the four provisioning steps is outstanding.
- Pay for a domain and its hosting on one invoice.

The rows, with what proves each, are in `docs/customer-capability-matrix.md`.

---

## C. The shape of the domain module

A domain is not a Service and not a Subscription. Everything else this platform
sells runs until somebody stops paying; a domain is a term, bought for years,
expiring on a date a registry decides, and renewed at a different price from
the one it was bought at. Modelling it as a subscription would have meant a
monthly price that does not exist and a suspension that means nothing.

An **operation** is its own row, the way a `ProvisioningJob` is separate from a
`Service`. A domain has a state — do we hold it — and an attempt to register,
renew, transfer or redeem it has its own. That separation is what makes the
Timeout Rule expressible: an unanswered registration leaves the operation
`indeterminate` and the domain `registration_pending`, and nothing retries.

Eleven domain states, and there is deliberately no `expiring`. It is the one
state customers ask for by name and the one that cannot be stored honestly:
"expiring" is `expires_at` being close, so a stored copy is a second copy of a
date that is wrong for as long as the sweep maintaining it is late.

---

## D. Money before registry, everywhere

A registry fee is spent the moment a registration succeeds and cannot be
reclaimed. Unlike a machine, a domain cannot be suspended and repossessed.

So every path invoices first and asks the registrar only when the payment
arrives. The automatic renewal and the one a customer clicks reach the
registrar through the same listener, so they cannot drift apart — and a
settlement webhook that arrives twice, which they do, buys one name.

---

## E. The Timeout Rule, and the half of it that is usually missing

An unanswered registration or renewal is never retried: it may have succeeded,
and a second attempt buys a second term nobody asked for.

That leaves rows saying "nobody knows", which is only honest if something
settles them. `domains:reconcile` asks the registry what it actually holds,
every three hours. It is a read throughout — it never registers, renews or
transfers — which is exactly what makes it safe to run on a clock against the
rows a retry must never touch.

The same rule, and the same second half, applies to a WordPress install: an
installer that goes quiet has often finished the work, and installing again
rewrites `wp-config` and re-seeds the database.

---

## F. Quotes, because the client cannot be trusted with a price

A premium name can cost a hundred times the list price for its namespace. The
amount is computed on the server, written to a row, and referred to afterwards
by id. There is no price field on any request.

One pricing service serves both the search and the quote. The interesting
failure of two implementations would not have been that they disagree loudly —
it is that they agree on ordinary names and diverge on premium ones, which is
precisely where the money is and precisely the case nobody clicks through by
hand.

---

## G. `.sy`, and the refusal to invent it

`SyRegistryProvider` answers false to every capability and throws on every
call. The Syrian registry's technical contract is not available to this
project: not the protocol, not the endpoints, not the authentication, not the
contact requirements, not the term limits, not the grace and redemption rules.

Writing an EPP or REST client against a guess would produce tests that pass and
a first real registration that fails — with every downstream decision, from the
shape of a contact to when a name enters redemption, made from fiction.

Status: `BLOCKED_LICENCE`. The search reports the namespace as not sold rather
than pretending a name is available.

---

## H. The same reasoning, applied to WordPress

There is no cPanel or DirectAdmin implementation of `WordPressInstaller`, and
that is a refusal rather than an omission. Installing WordPress is done by a
toolkit bolted onto the panel — WP Toolkit, Softaculous, Installatron — which
is separately licensed and absent from plenty of healthy nodes.

Making it a separate interface rather than more methods on `HostingProvider`
turns "can this node install WordPress" into a type question. A provider that
can, implements it. Nothing throws, and nothing is dead — which matters,
because this platform's architecture gates hunt exactly the contract full of
methods that exist to refuse.

---

## I. `ready` means somebody looked

Every other signal in the WordPress flow is a claim by somebody else. The panel
says the account exists. The installer says WordPress is installed. The
certificate authority says a certificate was issued. All three can be true
while the site serves a database error, a blank page, or the panel's holding
page — and the customer finds out by visiting.

`wordpress:verify` fetches every site every fifteen minutes. A site that stops
answering loses its badge: a green tick over a site nobody can reach is the
specific lie the sweep exists to stop telling.

---

## J. The site probe, and why it is the most dangerous code in the phase

It is the one outbound request this platform makes to an address a customer
chose. Without a guard it is a request-forgery primitive with a scheduler
attached, running every fifteen minutes from inside the platform's own network.

The attack is not theoretical: point `mysite.example` at 169.254.169.254, order
a WordPress site, and the platform fetches its own cloud metadata service on a
timer.

Three defences, all necessary:

1. The name is resolved first and every address checked — loopback,
   link-local, the private ranges, carrier-grade NAT and the IPv6 equivalents
   are refused before a request is made.
2. Redirects are not followed. A public address answering with a redirect to an
   internal one would walk past the first check.
3. Nothing from the response reaches the customer. The probe answers in
   booleans and a status code; a relayed body would make the platform an open
   proxy with a nice interface.

`TheSiteProbeCannotBeAimedInwardsTest` asserts the refusal happens before any
request is made — `Http::fake()` would record one if the guard let it through.

---

## K. Four defects the work found in itself

Each was found by building a proof or walking a customer's path, not by reading
code. All four passed every gate at the moment they existed.

### K.1 Nothing consulted the registrar capability model

The capability enum, the `supports()` method and the whole capability-driven
boundary existed, and the search asked only the catalogue. A `.sy` row switched
on by hand would have been offered for sale by a platform holding no licence,
and the refusal would have arrived after the customer's money moved.

### K.2 The factory routed any TLD to any driver

A `.com` row pointed at the wrong driver would have sent real registrations to
a registry that does not run `.com`. The first symptom would have been a paid
order refused by a registrar nobody meant to ask.

### K.3 The provider contract declared one exception and the seat threw another

`DomainRegistrarProvider` documented `DomainRegistrarException`;
`SyRegistryProvider` threw `RegistrarNotAvailableException`. A caller catching
the documented one — which is every caller — would have left an operation in
`running` for ever. Found by PHPStan calling a catch dead, which it was, for
the wrong reason.

### K.4 A WordPress order never became a WordPress site

The contract, the fake, the install handler, the job kind, the state machine,
the portal screen and the verification sweep all existed. Nothing joined them.
A customer could order a site, pay for it, watch the hosting account appear,
and the site would sit at `requested` for ever.

Both architecture gates were green throughout, and were right to be: the
handler is registered in a service provider, which looks like a caller to any
textual search. It took walking the path end to end.

Writing the fix surfaced a fifth immediately: the new listener set the site to
`installing` before queueing, and the handler refuses to install a site in a
state that does not permit installation — so the job it had just queued
declined to do anything.

---

## L. A sixth: a name held for ever by an order nobody paid for

A registration claims the name inside the platform before the invoice is paid,
so two customers cannot buy it in the same minute. That claim had no expiry.
One unpaid order would have blocked a name against everybody — this platform
included — permanently.

Released after seven days, and only when no payment arrived: a claim with a
queued registration behind it is left alone, because releasing a name under a
registration that is running is how a customer pays for a name the platform
just gave away.

---

## M. What the sweeps do, and what they refuse to do

| Command | Every | What it does | What it never does |
| --- | --- | --- | --- |
| `wordpress:verify` | 15 min | Fetches sites, records what answered | Installs, re-points DNS, re-orders a certificate |
| `domains:reconcile` | 3 h | Asks registries what they hold; settles uncertainty; records orphans | Registers, renews, transfers — anything that spends |
| `domains:sweep` | daily | Orders auto-renewals, warns before expiry, releases abandoned claims, advances registry states | Talks to a registrar at all |

`domains:sweep` never contacts a registry. Every date it works with comes from
the platform's own record of what a registry told it, and where the two
disagree it is reconciliation — a read — that settles it. A sweep that both
guessed at registry state and acted on the guess would delete a domain the
registry still holds.

---

## N. Where a name can be lost, and where it cannot

`SweepDomainLifecycle` will move a name to `deleted` on the registry's own
clock — but only for a namespace whose grace and redemption windows the
platform has actually been told. A TLD with either unknown keeps its names in
`expired` for ever rather than being marched through invented deadlines.
Deleting a domain on a guessed schedule is the one mistake in that file that
cannot be undone.

---

## O. Personal data, and what is not stored

- **Registrant contacts** are encrypted at rest and hidden from every payload.
  A registration is a snapshot: what was filed in 2026 was filed with the
  registrant the registry recorded in 2026, and a customer who moves next year
  has not retroactively changed what was submitted.
- **Authorisation codes** are bearer credentials for a whole domain. One is
  fetched on request, handed to the caller, and never stored. The single
  exception is a transfer in, where the code has to survive the minutes between
  payment and dispatch: it is encrypted, hidden from every payload, and erased
  *before* the call that sends it, so a job that dies leaves nothing behind.
- **WordPress administrator passwords** are generated from the CSPRNG, carried
  in a job payload, and written nowhere. The customer resets from their own
  dashboard, which is why keeping no copy costs them nothing.
- **Audit entries** record that an act happened and to which name — never the
  address, never the code, never the password.

---

## P. What the screens refuse to simplify

**Five availability answers, not two.** `unknown` gets its own words, its own
colour and no buy button. Folding it into "taken" tells a customer a name they
could have had is gone; folding it into "available" sells them a name somebody
else owns.

**Four WordPress steps, not one spinner.** Each finishes minutes or days apart.
One spinner labelled "setting up" would put every customer waiting on a
different step into the same support queue.

**A site waiting on its own registrar is told so.** That customer is waiting on
themselves, and a spinner would leave them refreshing while nothing happens.

**A name the platform cannot vouch for offers nothing to act on** — and says
not to try again, because a screen that merely looks broken invites a second
order on top of a registration that may already have succeeded.

Every one of those sentences is asserted in Arabic as well as English.

---

## Q. The gates, and what they caught

| Gate | Caught in this phase |
| --- | --- |
| `NoDeadMethodsTest` | Nine registrar methods with no caller, which is how the whole lifecycle came to be built rather than deferred |
| `NoDeadCapabilitiesTest` | Three actions with no HTTP surface |
| `EveryStateAScreenShowsIsTranslatedTest` | Three missing strings the moment it was pointed at the domain enums, and one for the new job kind |
| `OpenApiSpecificationTest` | Fourteen undocumented routes, and one resource that passed its fields through so the description could not be checked against it |
| `MetricsQueryBudgetTest` | Three new gauges, each held to one query |
| PHPStan | The contract inconsistency in K.3 |

The gate that did not catch anything is the interesting one: nothing static
could see K.4.

---

## Q.1 A real worker, in its own process

Two things the feature tests cannot see, because they run the job inline in the
transaction that queued it: whether the payload survives Redis, and what a
worker that dies mid-registration leaves behind.

A worker started by `queue:work` against a real Redis, in a separate process,
registers a committed name and the row says so.

The second test constructs the state a dead worker leaves — claimed, `running`,
nobody knowing whether the registry took it — and dispatches the job again
against a real worker. The row is untouched: `mayBeStarted()` excludes
`running`, so a redelivered purchase is a no-op. That state is constructed
rather than raced on purpose: `running` is written before the provider call and
nothing runs after the process dies, so it is the same row, and a timing-based
version of this assertion would be worth less than a precise one.

---

## R. Clean room

A fresh clone of `claude/hv-t6hq1p`, a new database, and the documented
bootstrap:

```
./scripts/bootstrap.sh          exit 0
vendor/bin/phpunit              2 444 passed, 69 575 assertions
npm run typecheck / lint / test / build   all clean, 54 frontend tests
npm run openapi:generate -- --check       up to date: 150 operations
npm run openapi:lint                      valid
```

Nothing was needed that the repository does not contain.

---

## S. Observed CI

Every commit in this phase was pushed and watched. `b264009` went out with
`NoDeadMethodsTest` red — nine registrar methods with no caller — and CI said
so on both PostgreSQL 16 and 18. That is recorded rather than tidied away: it
is why the lifecycle was built in the following commit instead of the
capability being reserved with an excuse.

---

## S.1 One red gate that was not the code's

CI's security job failed on `npm audit --audit-level=high`: a path-traversal
advisory against js-yaml 4.3.1, reached through `openapi-typescript` →
`@redocly/openapi-core`, which pins that version exactly.

`openapi-typescript` was declared in `apps/web` and used by nothing. Removing
it fixed the advisory by deleting the reason it was there. An npm override was
tried first and does not take against an exact pin; regenerating the lockfile
to force it dropped ninety unrelated entries, which is not a change worth
shipping to silence an audit.

Two moderate advisories remain, both against vitest, both fixable only by a
major version bump. The gate allows moderate. They are recorded here rather
than hidden.

---

## T. What is `TESTED` and what is `RUNTIME_VERIFIED`

`RUNTIME_VERIFIED` in this phase means a browser drove it against a running API
and a real database. Eleven rows in the matrix reach it.

Everything else that talks to a registrar or a panel is `TESTED`: proven
against a fake that models the failures that cost money, and against nothing
else.

Nothing in this phase is `REAL_INFRA_VERIFIED`. Nothing in this platform is.

---

## U. Five questions, answered before sign-off

### U.1 If a customer buys a domain and the registrar goes silent, what does the platform do, and what does the customer see?

The operation ends in `indeterminate` and the domain with it. Nothing retries.
The customer gets a notification that says, in both languages, that the
registry did not confirm and asks them not to try again — because a second
order on top of a registration that may have succeeded is what turns an
uncertainty into a double charge. The portal shows the name with no management
controls at all.

Within three hours, `domains:reconcile` asks the registry what it holds. If the
name is there, the domain goes `active` and the operation `completed`, and the
customer has what they paid for. If the registry says it is not, the row goes
to `needs_review` with a reason, and appears in the operator queue at
`GET /api/admin/domains?needs_attention=1`.

If the registrar cannot be inspected at all, the row stays for a person, and
`lynomia_domains_total{disposition="unsure"}` stays above zero — which is the
alert.

### U.2 Can a customer be charged for a premium domain at an ordinary price?

No, and the defence is structural rather than a check. No request carries an
amount. A quote is computed on the server from the registrar's own premium
price, written to a row with both the price and the cost, and handed back as an
id. Redeeming it checks the acting account, the operation kind, the expiry and
whether it has been spent — under a row lock.

The only thing a tampered request can change is which quote is redeemed, and a
quote belonging to another account answers as though it does not exist rather
than 403, so it cannot be used to enumerate. `PricingADomainTest` submits the
attack directly: a premium name and an ordinary price, and the server writes
what it will honour.

### U.3 What happens if two customers try to buy the same name at the same instant?

The second is refused, and by the database rather than by luck. A partial
unique index — `domains_one_live_holder` — allows one row per name in any state
that holds it, and `OrderDomainRegistration` takes a row lock and re-checks
inside the transaction so the loser reads a sentence instead of a constraint
violation.

The enum and that index are two copies of the same rule, one in PHP and one in
a `WHERE` clause no PHP can see, so a test asserts they agree. Adding a state
and forgetting the index would mean either a name sold twice or a name nobody
can ever buy again, and neither shows up until it does.

### U.4 What stops a WordPress site being reported as live when it is not?

Nothing else is allowed to set `ready`. The installer's success writes
`installed` and moves the site to `awaiting_certificate`; only the verification
sweep, having fetched the site and found WordPress answering over HTTPS, writes
`ready` and `verified_at`.

The sweep is symmetric: a site that stops answering goes back to
`awaiting_dns` and loses `verified_at`. A site that answers and is not
WordPress — almost always a name still pointed at the customer's old host — is
reported as waiting on DNS rather than failed, because that sends them to their
registrar, where the fix is, instead of to support.

### U.5 What would have to be true before any of this could take real money?

Six things, none of which this repository can supply:

1. A registrar account and credentials, and an adapter written against that
   registrar's real API — the fake models behaviour, not a protocol.
2. A `.sy` registry licence and technical contract, or the namespace stays
   marked not sold.
3. A cPanel or DirectAdmin licence, plus a WP Toolkit or Softaculous client
   written against the version each node runs.
4. A payment gateway account. No real money has moved through this platform in
   any phase.
5. Egress from the production network to arbitrary customer domains for the
   verification sweep, with the SSRF guard reviewed against that network's own
   private ranges — the list in `HttpSiteProbe` is the standard one and may not
   be complete for a given deployment.
6. A decision about the redemption price for every TLD sold. The catalogue
   refuses to quote a redemption it has not been told, which is correct and
   means a customer in redemption currently has no self-service path.

---

## V. What is deliberately not built

- `.sy` registrations (U.5.2).
- Redemption recovery: the state and the price column exist; no operation does
  it, because every registry requires a manual step.
- WordPress on real panels (U.5.3).
- Anything the brief ruled out: no Kubernetes, no CDN, no marketplace, no
  staging or cloning, no email hosting, no site migration tooling.

---

## W. Where this phase stops

At the boundary the brief set. Nothing here begins Phase 30B.

The two families are on the platform's own rails: one checkout, one invoice
path, one provisioning engine, one drift table, one audit trail, one metrics
endpoint, one set of rules about what a timeout means. Neither is a parallel
system, and that was the point of doing them together.
