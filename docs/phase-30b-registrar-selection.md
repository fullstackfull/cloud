# Phase 30B — registrar selection

Which commercial registrar or reseller Lynomia integrates with first, and how
that choice is made.

**Status: the selection is not made.** The reason is stated in section E, and it
is not a matter of opinion: this phase's environment cannot reach any
registrar's documentation, and the brief's rule — *evaluate current official
capabilities first, do not infer endpoints, do not select solely from an old
conversation* — is precisely the rule that forbids filling this document with
remembered capability tables.

What this document does contain is the part that can be established with
evidence: exactly what Lynomia requires of a registrar, derived from the code
that will call it. That requirement list is what a candidate is scored against
the moment official documentation is reachable.

---

## A. Why the selection is separate from the domain core

The domains module was built in Phase 30A++ against a capability-driven
boundary, on purpose. Nothing in `src/Modules/Domains/Domain/` or
`.../Application/` names a vendor, and an architecture test fails the build if a
line like `if ($registrar === 'opensrs')` ever appears. A registrar is a driver
behind `DomainRegistrarProvider`, and changing registrar is changing one class
in `Infrastructure/Providers/`.

That is what makes this a purchasing decision rather than an architectural one,
and it is why the decision can be deferred without blocking anything else.

---

## B. What Lynomia actually requires

Fifteen methods, from
`src/Modules/Domains/Domain/Contracts/DomainRegistrarProvider.php`:

| Method | What the registrar must expose |
| --- | --- |
| `name()` | — (local) |
| `supports()` | — (local capability declaration) |
| `supportedTlds()` | The TLD list the account is entitled to sell |
| `checkAvailability(array $names)` | Bulk availability, ideally in one call |
| `register(RegistrationRequest)` | Registration with contacts, nameservers and term |
| `renew(string, int $termYears)` | Renewal, including multi-year |
| `inspect(string)` | Authoritative current state of one name |
| `setNameservers(string, array)` | Delegation change |
| `setContacts(string, array)` | Registrant, admin, tech, billing |
| `setTransferLock(string, bool)` | Lock and unlock |
| `authorisationCode(string)` | Retrieve the transfer auth code |
| `startTransfer(string, string, array)` | Transfer in, with auth code |
| `transferStatus(string)` | Poll a transfer that takes days |
| `redeem(string)` | Restore from redemption |
| `heldNames()` | Everything the account holds, for reconciliation |

`heldNames()` is the one candidates most often fail. Without a way to enumerate
what the account actually holds, reconciliation cannot detect a name Lynomia
believes it owns and does not, or one it owns and does not know about. A
registrar with no bulk listing endpoint is not usable as the primary provider.

The twelve capabilities a driver declares are:

```
availability   registration   renewal        transfer_in
transfer_lock  auth_code      contacts       nameservers
premium_pricing redemption    multi_year_terms inspection
```

A driver declaring a capability it cannot perform fails `NoDeadCapabilitiesTest`
in CI, so a candidate's gaps become explicit rather than latent.

---

## C. The scoring sheet

Each candidate is filled in from **current official documentation**, with the
document's date recorded next to each answer. An answer from memory, a blog
post, or a previous conversation is not an answer.

| Criterion | Why it decides the outcome |
| --- | --- |
| **API maturity** | Is the current version documented, versioned, and not deprecated? |
| **Sandbox** | Is there a real test environment, and which operations does it support? A registrar with no sandbox cannot be integrated safely at all — see section D. |
| **`heldNames()` equivalent** | Bulk enumeration for reconciliation. Non-negotiable. |
| **Transfer polling** | Transfers take days. Webhook, poll endpoint, or nothing? |
| **Supported TLDs** | Which TLDs the *account* may sell, not which the registrar lists |
| **Pricing model** | Cost per TLD per year, and whether the API returns cost so the pricing engine can compute margin rather than hold a stale table |
| **Premium domains** | Premium names carry a per-name price. Does the availability response return it? Lynomia's pricing engine already handles premium margin, and needs the cost. |
| **Multi-year terms** | Registration and renewal for 1–10 years |
| **Reseller / white-label** | Whose name appears in whois and in the customer's registrar email |
| **Contacts** | Field requirements, validation, and whether contact changes trigger a 60-day transfer lock |
| **Redemption** | Whether restore-from-redemption is available through the API or only by ticket |
| **Rate limits** | Requests per second and per day. Search is customer-facing and bursty. |
| **Idempotency** | **The one that matters most.** See section D. |
| **Authentication** | Token, IP allow-list, client certificate; and whether credentials can be scoped |
| **Account funding** | Prepaid balance or invoiced. A prepaid balance that empties mid-month stops registrations, so it needs a low-balance alert. |
| **Legal availability** | Whether the registrar will contract with an entity in Lynomia's jurisdiction — the question that has ended more integrations than any technical one |

---

## D. Two criteria that are disqualifying, not weighted

**Idempotency on registration.** Lynomia's Timeout Rule says an indeterminate
money operation is never retried automatically. When a registration request
times out, the platform must be able to ask "did this happen?" and get a true
answer — by an idempotency key, or by an order-status endpoint keyed to a
reference the client supplied. A registrar where a timed-out registration is
indistinguishable from a failed one forces every timeout into manual settlement,
and manual settlement at volume is how a name gets registered twice.

**A sandbox that supports registration.** Registration is the operation that
spends money and takes a name out of a global namespace. A registrar whose test
environment only mocks availability lets Lynomia prove nothing before its first
real registration, and the first real registration is not an acceptable place to
discover the contact schema is wrong.

Either failure disqualifies a candidate as the *first* provider, regardless of
price.

---

## E. Why no candidate is scored here

The scoring sheet above is empty because the environment running this phase
cannot reach any registrar's documentation:

```
api.cloudflare.com   CONNECT tunnel failed, response 403
api.stripe.com       no connection
```

The outbound proxy permits GitHub and the language package registries. It
permits no third-party API or documentation site. Full detail is in
`docs/phase-30b-real-infrastructure-inventory.md` section B.

The brief names OpenSRS, NameSilo and ResellerClub as possible candidates and
immediately adds: *do not select solely from an old conversation; evaluate
current official capabilities first.* Writing a comparison table from training
data would satisfy the form of this document and violate its purpose. Registrar
pricing, TLD entitlements, sandbox coverage and API versions change quarterly,
and a table that looks researched is worse than an empty one — somebody would
act on it.

**Blocker: `BLOCKED_NETWORK`.** Removed by an environment that can reach the
candidates' documentation, or by the documentation being supplied directly.

**Second blocker: `BLOCKED_CREDENTIALS`.** Even with a selection made, the
adapter cannot be validated without a sandbox account, and this phase's standard
is that an adapter is not `REAL_INFRA_VERIFIED` until it has run the full
lifecycle against a real sandbox.

---

## F. Why no adapter was written

It would have been easy to add `OpenSrsDomainRegistrarProvider.php` implementing
fifteen methods against endpoints recalled from training data, and it would have
passed every gate this repository has: PHPStan, the layering test, the dead
capability test. CI would be green.

It would also be a capability that exists in code and cannot complete in the
product — the exact defect class Phases 30A+ and 30A++ spent their time deleting.
An unvalidated registrar adapter is worse than the others, because the thing on
the other side of it is a customer's money and a name in a global namespace.

The fake provider stays the only implementation. `SyRegistryProvider` remains
`BLOCKED_LICENSE` with no invented endpoints, as it has since Phase 30A++.

---

## G. What happens when a selection can be made

1. Fill section C from current official documentation, dating each answer.
2. Eliminate anything failing section D.
3. Record the decision here: which provider, on what evidence, and what was
   given up by not choosing the runners-up.
4. Record the integration facts the brief requires: API version, documentation
   date, authentication type, sandbox base URL, rate limits, capability
   limitations.
5. Write the adapter in `src/Modules/Domains/Infrastructure/Providers/`, against
   the documentation, with no endpoint inferred.
6. Run the full lifecycle against the sandbox: search, availability, quote,
   register, inspect, nameservers, contacts, lock, auth code, renew, transfer.
7. Only then, and only if commercially authorised, register **one** inexpensive
   disposable name as the first real proof — never a name the business needs.
8. Mark capabilities `REAL_INFRA_VERIFIED` one at a time, each with its own
   evidence. Registering successfully does not verify renewal.
