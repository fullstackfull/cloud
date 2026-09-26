# Forward DNS

Zones an account holds, and the records in them. Reverse DNS is a different
capability on a different zone with a different owner, and it lives in
[ipam.md](ipam.md) — an account holding `example.com` has no authority
whatsoever over the `in-addr.arpa` delegation for the addresses it points at,
because reverse zones are delegated with the address block.

## What a claim is, and what it is not

**Claiming a domain here is not verification of ownership, and nothing in the
product says otherwise.**

There is no way for a control plane to establish, on its own, that the person
in front of it owns a domain:

- A registry lookup names a registrant this platform cannot authenticate.
- A token written into the zone is a token the claimant cannot publish, because
  the zone they would publish it in is the one they are asking for.
- An email to the address in WHOIS is an email to an address most registries no
  longer publish.

What settles ownership is **delegation**. A zone here serves nothing at all
until the registrar points the domain's nameservers at this platform's
provider, and only whoever controls the registration can do that. So a claim is
not a grant of authority; it is a request to be ready, and the proof arrives
later, from the registrar, in the only currency DNS accepts.

Two consequences, both deliberate:

1. **No field on the zone payload is called `verified`, `owner` or anything
   like them,** and a test asserts it. A field the platform cannot support is a
   field a customer would rely on.
2. **The customer surface leads with the nameservers**, not with the zone's
   state badge, and says in words that nothing takes effect until the domain is
   delegated. A green "active" badge at the top of that screen would be telling
   somebody their domain was working when the platform has no idea whether it
   is.

What a claim *does* take is the zone name on this platform, and nothing more.
Two accounts cannot both hold `example.com` because DNS itself will not have it
— one delegation, one holder. First claim wins; the refusal is a 409 so that an
account whose domain somebody claimed by mistake goes to an operator rather
than into a retry loop. A domain that is given up becomes claimable again,
which is why the unique index covers live rows only.

Three names are refused outright:

| Refused | Why |
| --- | --- |
| The platform's own zones, any parent of one, and anything beneath one | An account holding `lynomia.com` can serve `panel.lynomia.com` whatever this platform thinks it owns; an account holding `db.panel.lynomia.com` holds a piece of the platform's name space, in the provider account the platform publishes from. The names are those listed in `dns.reserved_zones` plus the hosts of `APP_URL` and `FRONTEND_URL` — see [Reserved zones](#reserved-zones). |
| Anything under `in-addr.arpa` or `ip6.arpa` | Reverse zones follow the address block. Set per address in the IPAM surface. |
| Anything that is not a domain name | ASCII only (internationalised names must arrive as punycode — converting them here would mean this platform deciding what `münchen.de` means, and homograph attacks are exactly a disagreement about that), at least two labels, no empty labels, no leading or trailing hyphen, and no all-numeric final label. |

## States

One vocabulary for zones and records, because they are the same kind of thing
to this module — something the platform has asked a provider to hold.

| State | Means |
| --- | --- |
| `pending` | Written here, not yet at the provider |
| `active` | The provider took it |
| `failed` | The provider answered and said no. **The customer's to fix.** |
| `indeterminate` | Nobody knows: the platform stopped waiting. **The platform's to resolve.** |
| `deleting` | A removal has been asked for |
| `deleted` | Confirmed gone |
| `needs_review` | A person has to look |

The distinction worth reading twice is `failed` against `indeterminate`. A
platform that called the second one "failed" would invite the customer to
publish the record again, and a second publish against a provider that took the
first is the duplicate the Timeout Rule exists to prevent. Every provider call
in this module is `$tries = 1` for the same reason: a refusal will be refused
again in thirty seconds, and a timeout must not be retried.

## What the platform validates, and why it does not just forward

A provider will accept a great deal that does not work: an AAAA holding an IPv4
address, a CNAME beside an MX, an MX pointing at an address. Each of those is
published happily and then serves nothing, which the customer discovers as an
outage rather than the platform discovering it as an error. So the rules are
applied here, before anything is sent, in `DnsRecordRules` (pure, per value)
and `AssertRecordFitsTheZone` (everything that needs the zone's other rows).

| Type | Rules |
| --- | --- |
| A | A real IPv4 address, reachable from the internet |
| AAAA | A real IPv6 address, reachable from the internet |
| CNAME | Not at the apex; the only record at its name; target is a host name |
| MX | Priority 0–65535 required; target is a host name, never an address |
| TXT | At most 2048 characters, no control characters or line breaks |
| CAA | `flags` 0–255, `tag` one of issue/issuewild/iodef, `value` 1–255 characters |

"Reachable from the internet" excludes loopback, link-local and private ranges.
The first two are refusals of things that cannot mean what the customer thinks
— a name resolving to `127.0.0.1` resolves to *the visitor's own machine*, and
`169.254.169.254` is the cloud metadata endpoint. The third is a judgement
rather than a rule of the protocol: a public name pointing into RFC 1918 space
resolves to whatever happens to be at that address on the visitor's network,
which is the mechanism DNS rebinding is built on.

### The address rule

**A name may not be pointed at an address this platform allocates to another
account.**

Only the platform's own addresses are checked. A customer may point their own
domain anywhere on the internet — that is what DNS is for, and a platform that
policed it would be a platform deciding where its customers' names may point.
Inside this platform's space the question is different: an address belongs to
whoever holds it, and a name resolving to a stranger's machine is where
virtual-host hijacking begins, and where a certificate authority is persuaded
to issue for a domain the requester does not control.

The rule is applied on create **and on edit**. An edit that skipped it would be
the way round it: publish something allowed, then change it into something that
is not.

## Reconciliation

`dns:reconcile`, every two hours, bounded by `dns.reconcile_batch`.

**It never writes to a zone.** Not once, in any case below, and that is the
design rather than timidity. A customer's zone is not this platform's document:
people add records through a provider's own console, through Terraform, through
their previous host's migration tool. A sweep that deleted what it did not
recognise would delete somebody's mail routing at three in the morning — and it
would be *right* that the record was not in this database, which is what makes
it such a convincing way to lose a business.

| What it finds | What happens |
| --- | --- |
| A record the platform calls `active`, absent from the zone | Drift, critical: the name is not resolving and the portal says it is |
| A record left `indeterminate` by a **publish**, whose value *is* in the zone | Settled to `active`, claiming the provider's identifier (see below) |
| A record left `indeterminate` by a **publish**, whose value is not in the zone | Left `indeterminate`. The record never arrived; it is not a deletion the customer asked for |
| A record in `deleting`, or left `indeterminate` by a **delete**, whose value is gone | Settled to `deleted` — the answer the platform was waiting for |
| A record left `indeterminate` by a **delete**, whose value is still in the zone | `needs_review`. The customer asked for it to go and it is still answering; it is not `active` |
| An `indeterminate` record that cannot say which call left it there | Left alone. Rows written before `indeterminate_after` existed; guessing would be wrong either way |
| A record in the zone that this platform did not write | Drift, warning. Left exactly where it is, for ever |
| A zone at the provider with no live row here | Drift, warning. Usually a claim that timed out after the zone was created |

A provider that will not answer produces no drift at all: nothing is concluded
and the zone stays stale so the next run looks again. A sweep that recorded
"missing at the provider" every time an API was down would fill an operator's
queue with the platform's own outage, and the one real missing record would be
somewhere in the middle of it.

## One record, one row: identity

A name holds as many records as were written to it. Several A records at one
name are round robin; two MX records are a primary and a backup exchanger. So
nothing in this module identifies a record by `(type, name)`.

**Which record a record *is*** is answered in one place,
`DnsRecordIdentity::findAmong()`, and both provider implementations ask it:

1. the provider's identifier, if the record carries one the zone still holds at
   the same type and name — which is what makes an edit change a value in place;
2. otherwise the value (`DnsRecord::saysTheSameAs()`: type, name, content,
   priority and structured fields; content case-insensitive exactly where
   `DnsRecordType::contentIsCaseInsensitive()` says so). An identifier the zone
   no longer knows falls through to this tier, and so does a publish whose
   answer was lost, which then finds the record it already made instead of
   making a second.

A publish skips its write only when the zone already holds the record
*exactly* — `isPublishedExactlyAs()`, which adds the TTL. A delete reads before
it deletes, even with an identifier in hand, and removes the one record found
and nothing else at the name. The Cloudflare adapter reads every page of a
listing. The adapter once took `$existing[0]` of whatever sat at a name, and the
controlled fake keyed its store by `type|name`; both collapsed a name's records
into one, and because the fake shared the defect no test could see it (F-11).
The contract is now one suite run against both implementations in the same
invocation — `CloudflareAdapterKeepsTheRecordContractTest`, over a simulated
zone that keys records by identifier, and `TheDnsSimulatorKeepsTheRecordContractTest`.

A CAA record is sent to Cloudflare as its three fields and no `content`, while
the platform's row carries the presentation form built from the same fields.
The adapter's read rebuilds `content` from the fields, so the two sides compare
as one record; before it did, a correctly published CAA was reported missing
*and* orphaned against one identifier on every sweep.

**Two rows never answer for one provider record.** The value tier cannot tell
"the record this row created and never heard about" from "another row's record
whose value has moved onto this one" — from inside a provider they are the same
read. The platform opens exactly that window by itself: an edit writes the new
value to its row at once and publishes afterwards, so for a moment a second row
may be added at the value the first is leaving, and its publish adopts the
first row's identifier. `ClaimProviderRecord` settles it where the fact lives:
whichever writer stamps an identifier (`PublishRecord`, and the sweep settling
an unanswered publish) takes it from any other live row in the zone that held
it. That row's claim is refuted, not doubted — the record has just been read
carrying the claimant's value — and the row is reported by the sweep as missing
at the provider, which is the truth: a record too many is recoverable, a record
silently destroyed is not. The partial unique index
`dns_records_one_live_provider_record` (`dns_zone_id, provider_record_id` where
the row is not deleted and holds an identifier) holds the same rule for any
writer not written yet.

Known and recorded rather than changed: a record **renamed at the provider**
under an identifier the platform holds is not removed by a delete, because the
delete's read is narrowed to the record's own type and name. It now sits at a
name the platform never asked to remove, and the sweep reports it as a record
nobody here wrote. Both implementations resolve it the same way.

The table holds each value once per `(type, name)` —
`dns_records_one_live_value` hashes content alone — so the same MX host at a
second priority is refused. It is refused as `dns.record.one_value_per_name`,
not as a duplicate: the two records are different, and a customer is not told
otherwise.

## Giving a domain up

`DELETE /dns/zones/{zone}` takes the domain name typed back, compared with
`hash_equals`. It is the most destructive call on the customer surface: a zone
that is gone answers NXDOMAIN for every name under it at once, including names
this platform never wrote.

There is **no grace period**, and that is deliberate rather than an omission. A
backup sits on a datastore doing nothing while a customer thinks; a zone is
being served, and "we will stop answering for your domain in an hour" is an
outage scheduled for a time the customer cannot see. The confirmation is the
pause.

If the provider does not answer, nothing is marked gone — not the zone and not
its records. Saying otherwise would tell a customer their names had stopped
resolving while they may still be live.

## Audit and metrics

Audited: `dns.zone.created`, `dns.zone.deleted`, `dns.record.created`,
`dns.record.updated`, `dns.record.deleted`. An update records the previous
value, because "what did this person do" is the question an audit trail answers
and the row already says what it holds now.

Metrics carry **no domain, zone id, record value, customer or address** — only
states and record types, both from enums. A domain name in a metric is a
customer's domain name in whatever scrapes it and in every dashboard built on
top, and a series per zone grows without limit with the customer base, which is
how a monitoring system is taken down from inside.

## Configuration

| Key | Default | Meaning |
| --- | --- | --- |
| `dns.zones_per_customer` | 50 | Zones one account may hold |
| `dns.records_per_zone` | 250 | Records in one zone |
| `dns.reserved_zones` | empty | Names no account may claim, with their parents and their children, added to the hosts of `APP_URL` and `FRONTEND_URL` — see below |
| `dns.reconcile_after_hours` | 6 | How stale a zone's picture may be |
| `dns.reconcile_batch` | 50 | Zones looked at per run |

The provider is chosen by `billing.providers.dns`, the same key the reverse-DNS
factory reads: an operator who has configured Cloudflare has configured
Cloudflare, and two keys would let one deployment hold a forward provider and a
reverse provider talking to different accounts. They remain two contracts,
because the capabilities are genuinely different.

### Reserved zones

`DNS_RESERVED_ZONES` ships empty, and it is not the whole reservation: the
host of `APP_URL` and the host of `FRONTEND_URL` are reserved as well, whenever
they are domain names. An estate that has put the control plane and the portal
on their real names is covered without saying so twice. The shipped
configuration has not: both addresses are on `localhost`, which is not a
domain name, so **nothing is reserved until an estate is given its names** —
and the estate preflight says so, as a warning.

A host contributes itself, not its registrable domain — the registrable domain
of `panel.example.co.uk` cannot be worked out without a public-suffix list, and
guessing would reserve `co.uk`. Holding the host still refuses every parent of
it, so the registrable domain cannot be claimed either; what it does not cover
is a sibling. **List the registrable domain in `DNS_RESERVED_ZONES`** and every
name beneath it is covered, siblings included.

A host written in Unicode is held in the form DNS carries: `https://münchen.example.net`
reserves `xn--mnchen-3ya.example.net`, the name a resolver is asked for and the
one an account would type to claim it. The host is percent-decoded and, where
it is not ASCII, converted by UTS #46 with nontransitional processing, as the
URL standard specifies. That is not the conversion the name rules refuse to make for a
claim (see *Anything that is not a domain name*, above): converting a claim
decides what is admitted, while converting a reservation only adds to what is
refused. The older transitional processing maps four characters — `ß`, `ς` and
the two zero-width joiners — elsewhere (`straße` to `strasse`); the name that
produces is a different name, and is held only if it is listed. Entries in
`DNS_RESERVED_ZONES` are not converted: list an internationalised name in its
`xn--` form.

An address that gives no name says why, because what it leaves unheld depends
on the reason:

| The address | What it leaves unheld |
| --- | --- |
| Unset | Whatever name it was meant to carry |
| No host can be read — `panel.example.net` with no `https://` in front | The name it was meant to carry, perhaps: here `panel.example.net`, which an account can claim |
| An IP address, IPv4 or bracketed IPv6 | Nothing: no zone at, above or beneath an address is a name |
| A single label, such as `localhost` | The names beneath it, such as `x.localhost`: names whenever the label is well formed, and then an account can claim them. The label itself is not a name and has nothing above it |
| A host the name rules refuse, such as `my_panel.example.net` | Everything at, above and beneath it; above this one, that includes `example.net`, which an account can claim |

Unheld means not held because of that address: the other address or a listed
entry may still hold the same name. A single label is not reserved, and nor is
anything beneath it: holding the label would mean relaxing the name rules for
the one caller that needs them strictest.

An entry in `DNS_RESERVED_ZONES` that is not a domain name refuses **every**
claim, by every account, until it is corrected. The whole list is read before
any claim is compared with it; skipping a bad entry would protect less than was
asked for and say nothing.

The estate preflight (`php artisan infra:preflight --mode=…`, or the Control
Center) carries one finding about all of this, `dns.reserved_zones`:

| Status | When |
| --- | --- |
| `fail` | An entry in `DNS_RESERVED_ZONES` is not a domain name. The only blocking state. |
| `warning` | Nothing is reserved at all, or `APP_URL` or `FRONTEND_URL` contributed no name — each named with its reason, from the table above. |
| `pass` | Otherwise: a count of the entries held and the variables they came from. |

It names variables and counts entries. It never quotes a reserved name or a
configured value. The count is of entries, so a name and a host beneath it are
two although the first covers the second.

A change to the reservation applies to the next claim. It does not reach back
to zones already held: nothing re-checks existing zones when the reservation
changes, so a name that became reserved after an account claimed it stays with
that account until the account deletes it. There is no operator route for DNS
zones; taking one back means changing the database.
