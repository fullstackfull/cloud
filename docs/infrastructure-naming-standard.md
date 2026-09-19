# Infrastructure naming standard

**Status:** authoritative. Enforced by code, not by this document.
**Scope:** operator and platform infrastructure — regions, datacenters, racks,
clusters, nodes, storage, networks, templates, machines, hosting nodes and
provider instances. **Not** customer resources: a customer's VM hostname, their
domains, their DNS zones and their WordPress sites follow their naming, not
this.

The rules below are implemented in
`apps/control-plane/src/Modules/Infrastructure/Domain/Naming/` and
`apps/control-plane/src/Modules/Shared/Domain/Naming/`, and checked by
`php artisan infra:naming:audit`, by the unified preflight, and by the
architecture gates in `tests/Architecture/OneNamingAuthorityTest.php`. Where
this document and the code disagree, the code is what runs — report the
difference as a defect in one of them.

---

## 1. Four kinds of name, and why the difference is load-bearing

| Kind | Who owns it | May it change? | Example |
|---|---|---|---|
| **Logical identifier** | Lynomia | No — a migration, not an edit | `ref-cluster-alpha-1` |
| **Operator code** | an operator | Yes, deliberately | `RA1` |
| **Network name** | DNS / the network | Yes, on somebody else's schedule | `web-01.reference.example` |
| **Provider-native id** | the provider | When the provider says so | `local-lvm`, `ref-image-9001` |
| **Display name** | whoever is reading | Freely | `Reference Datacenter Alpha 1` |

A **logical identifier** is what an order, an audit entry, a deployment plan
and a metric series point at. Renaming one does not tidy up a spelling; it
detaches history from the thing it happened to.

An **operator code** is the same syntax with a weaker promise: the code
stencilled on a rack, the name somebody gave a provider instance. Unique, ours,
and theirs to re-choose.

A **network name** is a hostname. It changes when DNS or the network changes,
and it is never the thing a durable reference points at.

A **provider-native id** belongs to another system's scheme. Proxmox decides
what its nodes and storages are called; a registrar decides what an account id
looks like. It is stored exactly as they spell it, never normalised, because a
normalised copy is a value their API does not recognise.

A **display name** is for people. Any script, including Arabic. Load-bearing
for nothing.

Business logic must never assume `display name == hostname == identifier`.

---

## 2. Logical identifier syntax

Lower-case ASCII alphanumeric segments joined by single dashes:

```
[a-z0-9]+(-[a-z0-9]+)*
```

No leading or trailing dash, no double dash, no empty segment, no underscore,
no dot, no whitespace, no non-ASCII. Bounded by the column that holds it —
today every one of them is `varchar(255)`, and the policy reads the ceiling
from the concept rather than assuming it.

**Operator codes** use the same shape with the case rule relaxed, because a
rack is stencilled `A1` and not `a1`. Uniqueness is still judged
case-insensitively: `A1` and `a1` are one rack.

**Case for provider-native ids is preserved, and uniqueness is
case-sensitive.** `local-lvm` and `LOCAL-LVM` may be two storages in somebody
else's scheme, and this platform is not entitled to decide that they are not.

---

## 3. Normalisation and validation are separate acts

- `normalizeForCreation()` answers *what would this become*. A creation form
  shows an operator the identifier they are about to get: `NODE 01` → `node-01`.
- `problemWith()` answers *is this one*, and reports. It never rewrites.

Nothing composes the two into a silent step. A value that already exists is
never canonicalised on the way past: it is referenced by orders, audit
entries, deployment plans and monitoring, and rewriting it to fix a spelling
breaks every one of those references.

**Collisions are judged on the normalised form.** `Node-01`, `node-01` and
`NODE 01` are one identity. The database's unique indexes compare literally, so
`infra:naming:audit` is what catches the pair the index cannot.

---

## 4. Hostnames

One parser: `Shared\Domain\Naming\DnsName`. RFC 1035 rules — at most 63 octets
a label, 253 for the name, alphanumeric ends, hyphens inside, nothing else.
Canonical form is lower case with one trailing root dot removed, because DNS is
case-insensitive and two spellings of one name are two rows the platform
believes are two machines.

A hostname field refuses everything that is a different field:

| Refused | Why |
|---|---|
| `host:8443` | a hostname and the port it is reached on are separate fields |
| `https://host` | that is an endpoint URL |
| `user@host` | user information belongs to a credential |
| `host/path` | a path belongs to an endpoint |
| `192.0.2.10` | an address is not a name |
| `[2001:db8::1]` | a bracketed literal is not a name |
| `مثال.example` | an internationalised name is stored as its `xn--` form |

A bare label (`pve-01`) is legitimate: it is what an operator types before a
zone exists. Whether a particular use needs the qualified form is that use's
question — a TLS certificate needs one, a log line does not.

**A hostname is not a URL and neither is an endpoint.** `EndpointPolicy`
decides where the platform may send a request; this standard decides what a
name may look like. They are separate classes on purpose: merging them
produces one class that is the weaker half of both.

---

## 5. The DNS suffix is the operator's, and there is no default

```
config('infrastructure.naming.internal_dns_suffix')   # INFRASTRUCTURE_INTERNAL_DNS_SUFFIX
```

It starts unset. That is the decision, not an omission: a real zone is a fact
about an operator's network, resolver and certificate authority, and a default
shipped here would become the naming authority for every deployment that
inherited it.

Unset is a reportable state, not an error. The platform stores the hostnames it
is given and composes none — which is correct when a provider hands back a
fully qualified name, or an operator's inventory already has one.

**One key, and there used to be two.** `public_dns_suffix` existed for
customer-facing names and was read by nothing. Nothing in the approved product
scope composes a customer-facing hostname from a platform suffix: a VPS is
named for its own service id, and a hosting account, a WordPress site and a DNS
zone all carry the customer's own domain. Gap 8 removed it rather than
recording it as prepared — a setting an operator can fill in that changes
nothing teaches them that some settings might not work — and the day a product
does compose such a name, the key returns with the consumer that needs it.

**The internal zone must be a zone the operator actually holds.** It is a real
delegated subdomain, such as `dc1.operator-chosen-zone.net`, and deliberately
not a `.internal` or `.local` name. `EndpointPolicy` refuses names under those
suffixes everywhere, in every mode, and that stays: they are unqualified
multicast or resolver-local namespaces, they cannot carry a certificate from a
public authority, and the one thing a management endpoint must never be is
ambiguous about which host it names. Private management targets are supported
by *address* — `EndpointPolicy` accepts RFC1918 for a provider on our own
hardware — and by a private zone the operator has delegated to themselves.
Nothing in the approved scope needs `.internal`, and allowing it would widen
SSRF surface to every resolver-local name for no product gain.

**Composition is one place.** `DnsSuffix::compose()` joins a host label and a
zone. A label that is already qualified is refused rather than concatenated,
because `pve-01.old.example.dc1.new.example` is a real name for nothing.

**A reference suffix can never be the production one.** `.example`, `.test`,
`.invalid`, `.localhost` and the reserved example domains are refused for
production by `DnsSuffix`, which asks `ReferenceValues` rather than keeping a
second list.

### One constraint on the zone an operator may choose

`EndpointPolicy::FORBIDDEN_SUFFIXES` refuses `.localhost`, `.local`,
`.internal` and `.localdomain` in **every** mode, because names under them are
"this host, this network or a metadata service". So an estate whose zone is
under `.internal` cannot be reached by this platform at all.

ICANN reserved `.internal` for private use in 2024, so that refusal is
arguably stricter than it needs to be. It is left exactly as Gap 2 wrote it —
this gap does not weaken an endpoint guard — and recorded here as an open
question, because it is a constraint an operator has to know before choosing a
zone.

---

## 6. Uniqueness scopes

Read off the unique indexes this database already has, not chosen:

| Value | Unique |
|---|---|
| `regions.slug` | across the platform |
| `datacenters.slug` | across the platform |
| `compute_clusters.slug` | across the platform |
| `hosting_nodes.slug` | across the platform |
| `managed_servers.name` | across the platform |
| `provider_instances.name` | across the platform |
| `dedicated_servers.serial` | across the platform |
| `racks.name` | within its datacenter |
| `vm_templates.slug` | within its cluster |
| `compute_nodes.provider_name` | within its cluster |
| `compute_storages.provider_name` | within its cluster and node |
| `datacenters.name` (display) | not unique |
| `hosting_nodes.hostname` | not unique |

Two datacenters may each have a rack `A1`, and two clusters may each stage
`debian-stable`. A standard that demanded global uniqueness would refuse both,
and an operator would work around it by inventing a prefix nobody agreed on —
which is how a second naming scheme is born.

---

## 7. Renaming

| Change | Allowed? |
|---|---|
| Display name | Yes. Nothing references it. |
| Hostname | Yes. It is network identity and the network changes. |
| Provider-native id | When the provider's own name changed. It is a remap, and the mapping is the field. |
| Operator code | Yes, within its uniqueness scope. |
| **Logical identifier** | **No.** A migration with a mapping, never an in-place edit. |

An operator who wants a friendlier label wants the display name. That is what
the Admin forms offer, and the fields say which is which:

- **Logical ID** — "Stable identifier. Orders, audit history and monitoring
  reference it; renaming the display name does not change it."
- **Display name** — "What people read on screens, in any language. Rename it
  freely: nothing references it."
- **Rack code** — "The code on the cabinet. Unique within this datacenter, and
  A1 and a1 are the same rack."

---

## 8. Reference naming

Everything committed to this repository names an example. The reserved `.example`
TLD, in two sub-zones with two owners:

| Zone | Owner | What it names |
|---|---|---|
| `reference.example` | `apps/control-plane/resources/reference-topology/topology.php` | the modelled estate the app loads |
| `prod.example`, `dev.example` | `infrastructure/ansible/inventories/*`, and the monitoring target lists that watch them | the example deployment inventories |

Both are reserved, so neither can ever resolve and neither can be mistaken for
somebody's estate. Logical ids in the modelled estate additionally carry the
`ref-` prefix, which is what the production guards match on.

Addresses follow the same principle and predate this standard: RFC 5737
(`192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24`) and RFC 3849
(`2001:db8::/32`).

**The production standard does not require the reference suffix, and cannot
accept it.** That is the whole point of the pair of rules.

---

## 9. Legacy values

A value written before this standard is a **warning** with a next action, and
it stays a warning. The application does not refuse to start over it, and
nothing renames it automatically.

```
php artisan infra:naming:audit          # [PASS]/[WARN]/[FAIL] per check
php artisan infra:naming:audit --json   # the same findings, machine-readable
```

Exit `0` with warnings, `1` on a failure, `2` on a bad invocation — the same
three codes `infra:preflight` uses. A legacy rack code does not fail a
pipeline; the alternative is a flag to silence the command, and then the
failures are silenced too.

**Failures** are things that are wrong now:

| Finding | Meaning |
|---|---|
| `naming.collision` | two rows normalise to one identity, so nothing downstream can say which one is meant |
| `naming.reference_value_in_production` | a row declared for production carries a name reserved for examples |
| `naming.dns_suffix` | the configured production zone is under a reserved domain, so every composed name would fail to resolve |

**Warnings**:

| Finding | Meaning |
|---|---|
| `naming.noncanonical` | a value does not match the rule for its kind; the finding carries the canonical spelling |
| `naming.one_authority` | a hostname is qualified under a zone other than the configured one |

The unified preflight reports the same findings in its own vocabulary, as
`CONFIGURATION` findings with `BlockerReason::Configuration`. There is no
`BLOCKED_NAMING`: the external blocker vocabulary stays
`BLOCKED_CREDENTIALS`, `BLOCKED_HARDWARE`, `BLOCKED_NETWORK`,
`BLOCKED_LICENSE`, `NOT_IMPLEMENTED`.

---

## 10. What a real estate needs, and what it does not

Arriving with real infrastructure requires configuring:

- the site's logical code and display name (Admin → Control Center → Sites)
- the cluster's logical code, endpoint and credential reference
- each node's provider-native name (discovered from the provider)
- storage and network mappings, by logical role
- template logical keys and their provider references
- machine names, hostnames and BMC addresses
- the internal and public DNS suffixes, if the platform is to compose names

It requires **no source edit**. That claim is a gate, not a promise:
`tests/Architecture/NoReferenceIdentifierIsRequiredByBusinessLogicTest.php`
fails if any reference identifier appears in application source, and
`OneNamingAuthorityTest.php` fails if a DNS naming authority is hardcoded
there or if the configured suffix acquires a second reader.

---

## 11. Metrics, logs and audit

Infrastructure metric labels carry stable identifiers and roles — `role`,
`service`, `cluster_name`, `instance` — and never a customer's hostname. Gap 1
established that rule for a different reason (cardinality and PII) and this
standard keeps it: a customer-controlled string in a label is unbounded
cardinality and an information leak in one field.

Log lines prefer the stable identifier over a mutable display name, so that a
rename does not orphan the history of a machine. Audit entries record the
subject's id; the name is recorded alongside as the operator saw it.

Changing a metric label changes the series. That is a migration with a
compatibility window, not an edit — and `prometheus/rules/*` reads the labels
the control plane exports, which
`tests/Architecture/EveryBackupAlertMetricHasAProducerTest.php` and
`infrastructure/scripts/validate-monitoring.py` hold to each other.

---

## 12. What this standard deliberately does not do

- It does not merge Region, Datacenter and Site into one model. They are
  separate rows today with separate slugs; naming rules were written for what
  exists.
- It does not invent provider naming constraints. Proxmox's, WHM's,
  DirectAdmin's and a registrar's own limits are theirs to state; where this
  repository has not read a contract, the generic rule applies and the unknown
  is recorded rather than guessed.
- It does not add IDN support for infrastructure identifiers. Display names
  carry any script; identifiers are ASCII.
- It does not translate identifiers or hostnames. A machine identity is not
  localised.
