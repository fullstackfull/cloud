# Phase 30A++ — Lynomia Domains and Lynomia WordPress Hosting

**A plan. Nothing in this document is implemented, and nothing in it should be
implemented before Phase 30A+ closes.**

It exists for one reason: the two product families it describes have been
approved for the next phase, and the work finished in 30A+ had to avoid making
them harder to build. Where a decision taken in 30A+ was shaped by something
below, this document says so — that is the part worth reading twice, because it
is the part that would otherwise be lost.

---

## Where this sits

```
Phase 29    Final Software Closure         complete
   ↓
Phase 30A   Final Product Closure          complete
   ↓
Phase 30A+  Core Product Completeness      this phase
   ↓
Phase 30A++ Domains + WordPress            next
   ↓
Phase 30B   Real Infrastructure Validation
   ↓
Production Validation
   ↓
Go Live
```

Phase 30B stops product expansion and starts talking to real infrastructure.
Nothing in this document may be claimed as working against a real registry, a
real registrar or a real control panel before then.

---

# Part A — Lynomia Domains

## What is being sold

A customer can search for a domain, see what it costs, buy it, and have it work
with the rest of the platform. Concretely, the product is:

| Capability | Note |
| --- | --- |
| Search and availability | Per TLD, with pricing shown before anything is committed |
| Registration | Including the contact set a registry requires |
| Transfer in | Auth code, and the waiting a transfer really involves |
| Renewal, and auto-renewal | The default, and the ability to turn it off |
| Contacts | Registrant, admin, technical, billing |
| Nameservers | Point at Lynomia DNS, or at somebody else's |
| Domain lock | On by default; off is a deliberate act |
| Transfer authorisation (EPP code) | Shown to the holder, never emailed |
| Expiry, grace, redemption | Three different deadlines with three different prices |
| Notifications | Renewal upcoming, renewal failed, expiry, redemption |
| Billing and wallet | Registration, renewal, transfer and redemption are separate prices |
| DNS integration | A registered domain offers to create its zone |
| Support | A ticket about a domain, joined to the domain |

## What 30A+ already built for it

**Forward DNS.** This is the dependency the roadmap decision was really about.
The zone and record model, the provider abstraction, the states, the
reconciliation and the customer surface all exist now, and they were built
without a single assumption that a zone's domain is registered here. A zone is
claimed by name; nothing about it references a registration, and nothing about
a future registration will need to change the DNS internals.

The join, when it comes, is one direction only: **Domains may create a zone.
DNS must never know that domains exist.** A registered domain's screen offers
to create its zone, and a zone's screen says nothing about who the domain is
registered with, because a customer may perfectly well hold a domain elsewhere
and serve its DNS here — which is exactly what the product does today.

## The provider boundary

```
                    Lynomia Domains
                           │
                 DomainRegistrarProvider
                           │
             ┌─────────────┴─────────────┐
             │                           │
          .SY direct               Global registrar
             │                           │
     Syrian registry / API          Reseller API
```

The contract is not written in this phase. What is decided is its shape:

- **Capability-driven.** A provider answers what it can do — which TLDs, whether
  it supports transfers, whether it exposes a redemption price — rather than
  the core assuming every registrar can do everything. The hosting module
  already works this way and it is why cPanel and DirectAdmin can differ.
- **No vendor in the core.** Nothing in the domain module may name a registrar.
  The first implementation being one reseller must not make the second one a
  rewrite.
- **Routing is deferred.** A future router could choose a provider per TLD by
  capability, cost, availability, health and commercial policy. Building that
  engine before there are two providers would be building a configuration
  format nobody has requirements for. One provider, one map from TLD to
  provider, and the router when it earns its place.

### The `.sy` rule

**The `.sy` registry API is not invented in this phase or the next.**

Nothing may assume EPP, REST, SOAP or a manual process until the actual
integration documentation exists. Phase 30A++ creates the provider *boundary*;
the `.sy` implementation behind it waits for the licence and the technical
documentation, and until then `.sy` is `BLOCKED_LICENSE` — a status this
project already has, and which means what it says.

## Billing, which is not the VPS model

A domain is not a subscription with a monthly price. Five prices, and they are
different numbers rather than the same number at different times:

```
registration price      what it costs to take a name for a term
renewal price           what it costs to keep it, often higher
transfer price          usually a year's renewal, sometimes not
redemption price        a registry penalty, often ten times a renewal
premium price           set by the registry per name, not per TLD
```

Each has a **registry/provider cost** behind it and a **Lynomia margin** on top,
and both need to be recorded per transaction — a margin computed at display
time is a margin nobody can reconcile a month later. Tax applies as it does
everywhere else in the platform.

Two things follow for the catalogue, and both should be designed before any
code:

1. A domain product's price depends on the TLD and sometimes on the specific
   name. The existing plan/price model is per plan; domains need a price
   *lookup*, not a plan.
2. Terms are in years, and a registry may permit one to ten. The billing period
   vocabulary the platform has is monthly through yearly; multi-year is new.

## The state machine, sketched

```
AVAILABLE → REGISTRATION_PENDING → REGISTERED → ACTIVE
                                                  │
                            ┌─────────────────────┼─────────────────┐
                            ↓                     ↓                 ↓
                        EXPIRING              TRANSFER_PENDING   RENEWAL_PENDING
                            ↓
                        EXPIRED → GRACE → REDEMPTION → DELETED
```

plus `REGISTRY_LOCKED`, `INDETERMINATE` and `NEEDS_REVIEW`, which this platform
uses everywhere for the same three reasons: a registry may lock a name for its
own purposes, a call may not answer, and some things need a person.

**The exact states are not final.** They should be settled once a real
registrar's actual behaviour is known — registries differ on what "grace"
means and on whether a redeemed domain returns to the same state it left. A
state machine written from a specification and then found wrong is worse than
one written a fortnight later from a sandbox.

## What the platform must not do

- Claim a domain is registered because an API accepted the request. The Timeout
  Rule applies exactly as it does to a hypervisor: an unanswered registration is
  `INDETERMINATE`, never retried blindly, because a retried registration is a
  second year somebody pays for.
- Auto-renew a domain the customer has cancelled, or fail to auto-renew one they
  have not. Both are expensive and only one is recoverable.
- Show an EPP authorisation code in an email or a notification. It is the
  credential that moves a domain to another registrar.
- Delete a zone when a domain is transferred away. The customer may still be
  serving DNS here.

---

# Part B — Lynomia WordPress Hosting

## What it is, and what it is not

**It is shared hosting with WordPress installed and managed.** Three plans —
Starter, Business, Pro — sold as a product of their own, provisioned onto the
same nodes and through the same `HostingProvider` contract the platform already
has.

It is explicitly **not**, in its first version:

- a Kubernetes WordPress platform
- custom container orchestration
- a bespoke PHP runtime

Each of those is a plausible second version and a bad first one. The first
version's job is to sell a working WordPress site to a customer who does not
want to think about hosting, and the existing hosting stack already does the
hard parts: placement, capacity, suspension, usage, panel sessions, and now
reconciliation.

## The customer's path

```
Choose a WordPress plan
   ↓
Choose a domain:  register a new one  ·  use a Lynomia domain  ·  transfer one in  ·  point an external one
   ↓
Checkout, paid by card or from the wallet
   ↓
Hosting account provisioned
   ↓
DNS: zone created and pointed, or instructions for an external domain
   ↓
SSL issued
   ↓
WordPress installed
   ↓
Health check
   ↓
READY
```

Every arrow is a step that can fail, and the platform's existing vocabulary
covers all of them: a provisioning job per step, `needs_review` where a person
is required, and no step reporting success on the strength of an accepted
request. The fourth domain option — an external domain — is the one most often
forgotten and the one most customers choose.

## The customer's screen

```
Site:              domain, WordPress version, PHP version
State:             SSL, disk usage, backup state, update state
Actions:           open WordPress admin · open hosting panel · back up · restore
```

Staging, cloning and the rest are a separate assessment. They are the features
that turn a hosting product into a platform, and they are also the features
that turn a two-week build into a six-month one.

## How it reaches the panel

Through `HostingProvider`, extended with the capabilities WordPress needs:

```
create account          exists
install WordPress       new
open management session  exists (SSO)
apply package           exists
read usage              exists
```

**WordPress logic must not live in a cPanel controller.** A provider that
installs WordPress through WP Toolkit and one that installs it by unpacking a
tarball are two implementations of one capability, and the product must not be
able to tell which it is talking to. This is the same rule the platform already
applies to hypervisors and to DNS.

---

## What Phase 30B will validate, and nobody may claim before then

```
Proxmox · Proxmox Backup Server · Cloudflare · cPanel or DirectAdmin
WordPress tooling · a registrar sandbox · .SY once licensed and documented
a payment gateway · SMTP · BMC
```

Every one of those is `BLOCKED_CREDENTIALS`, `BLOCKED_HARDWARE` or
`BLOCKED_LICENSE` today, and stays so until something real has answered.

---

## The decisions 30A+ took on this phase's behalf

Recorded because they are invisible in the code and expensive to rediscover:

1. **DNS zone ownership is a claim, not a verification** (docs/dns.md). When
   domains arrive, a *registered* domain will be able to create its zone with
   authority the platform actually has — but the unverified claim must remain,
   because customers will go on holding domains elsewhere.
2. **`DnsProvider` and `ReverseDnsProvider` stay separate contracts.** A
   registrar provider will be a third, for the same reason: holding a name and
   serving its DNS and controlling its reverse delegation are three different
   authorities.
3. **The zone name is unique across the platform, on live rows only.** A domain
   transferred away and back must be claimable again.
4. **Record validation is the platform's own.** A registrar-supplied nameserver
   set will go through the same rules; nothing gets a pass for coming from a
   provider.
5. **Money is minor units with a recorded currency, everywhere.** Domain
   pricing introduces registry cost and margin, which are two more amounts on
   the same footing — not a float and a percentage.
