# Security architecture

`SECURITY.md` at the repository root lists the controls. This document explains the
reasoning behind the ones where the reasoning is not obvious, because a control whose
purpose is not understood is a control that gets removed during the next refactor.

## Authentication

### Two token models, one API

The portals authenticate with Sanctum's SPA cookie session; the public customer API
authenticates with scoped personal access tokens. Both hit the same versioned API, so
there is exactly one implementation of every operation and no capability exists in the UI
that a customer cannot also automate.

A bearer token is never placed in browser storage. Any script that gets injected into the
page can read `localStorage`; an `HttpOnly` cookie it cannot. That is the entire reason
the cookie flow exists, and it is why the SPA carries an XSRF token instead.

### Login does not distinguish "no such account" from "wrong password"

Status, error code, message and the work performed are identical in both cases — a hash is
computed even when no user matched, so response time does not leak either. Anything else
turns the login form into an account enumeration oracle, which is the first step of every
credential-stuffing campaign.

### A password alone never produces a session

On a two-factor account, a correct password yields a short-lived, single-use challenge
token — not authenticated state, not even for one request. The token is stored server-side
under a hash, so a cache dump does not yield usable challenges.

A wrong second factor counts towards lockout. Without that, the second factor is
brute-forceable in six digits once the password is known.

### Lockout is time-based, never permanent

A permanent lock lets an attacker deny a legitimate customer access indefinitely simply by
guessing their password often enough. The window is short enough to defeat automation and
short enough that a customer who fat-fingers their password five times can try again after
a coffee.

### Rate limits key on address AND source IP together

Keying on the address alone lets anyone lock a known customer out. Keying on the IP alone
lets one host spray many addresses. Requiring both to match bounds each attack without
enabling the other.

## Second factor and recovery

TOTP secrets are encrypted at rest; recovery codes are **hashed**, exactly like passwords.
A database dump must not hand an attacker a working bypass of the second factor. Recovery
codes are shown once, at generation, because the platform genuinely cannot show them
again.

Enrolment is two-stage: a secret is issued, and the account only becomes protected once
the customer proves they can generate a valid code. Protecting the account at issue time
locks out anyone whose authenticator was misconfigured.

Disabling the second factor requires the current password even inside an authenticated
session. A hijacked session must not be sufficient to remove the control that would have
stopped the hijack.

## Authorisation

Checks name a **permission**, never a role. Roles are database rows an operator can
reshape without a deploy; permissions are the stable contract the code depends on.

Super Admin is granted through a `Gate::before` rule that returns `null` — not `false` —
for everyone else. Returning `false` there short-circuits every other gate and policy in
the application, silently denying everything. This is subtle enough to have its own test.

Super Admin holds no enumerated permissions, so a permission added in a future release is
never accidentally missing from it.

## Webhooks

The order of operations is the control:

1. Verify the signature. An unverified payload is not parsed and not stored as trusted.
2. Reject a payload whose timestamp is outside the tolerance window, even with a valid
   signature — otherwise a captured request replays forever.
3. Write the provider's event ID under a unique constraint **before** acting on it.
4. Act.

Step 3 is what makes redelivery safe. Every payment provider redelivers routinely, and a
duplicate that reaches step 4 twice charges, credits or provisions twice. A unique index
is a guarantee; an `if (exists)` check is a race.

**A browser returning to a success URL confirms nothing.** That URL is under the
customer's control. Provisioning follows a verified webhook or an explicit server-side
retrieve, never a redirect.

## Secrets

Redaction is a Monolog processor, not a call-site convention. A developer cannot leak a
credential by logging a raw exception or an unfiltered request body, because the scrubbing
is not opt-in.

It works on two axes, because secrets leak in two shapes:

- **By key** — any key matching a configured secret name, at any depth.
- **By pattern** — credential shapes inside free text: bearer tokens, Stripe keys, Proxmox
  tickets, PEM blocks, and passwords passed as `ipmitool -P` or `sshpass -p` arguments.
  IPMI has no way to pass a password other than argv, so a command echoed into an error
  message would otherwise print a BMC credential verbatim.

Sensitive database columns are encrypted with the application key. Rotating that key
without re-encrypting them makes every stored secret unreadable — noted here because it is
the kind of thing discovered during an incident.

## The production provider guard

The platform refuses to boot in production with any provider set to `fake`.

A fake provider reports payments captured and servers created while doing neither. In
production that means charging customers for services that do not exist, and it stays
invisible until someone asks where their VPS is. Failing at boot converts the worst
possible silent failure into a deployment error visible immediately.

## Infrastructure

- BMC interfaces live on an isolated management network. An iLO or IPMI interface is a
  complete out-of-band computer with power control and virtual media; reachable from a
  customer VLAN, it hands over the physical host and every tenant on it.
- Proxmox administrative interfaces are not publicly exposed, and the control plane
  authenticates with an API token scoped to what provisioning needs — never a root
  password.
- DHCP and PXE run only on the designated provisioning VLAN. A rogue DHCP server takes a
  production LAN down; a PXE server reinstalls whatever reboots on it.
- Anti-spoofing is enforced at the hypervisor, not in the guest, because a customer with
  root can override anything inside their own machine.
- Outbound port 25 is blocked by default. An unprotected new machine becomes a spam source
  within hours, and the resulting listings cost every other customer on the range their
  mail delivery for weeks.

## Audit

Administrative actions are recorded append-only with actor, target, before/after and
correlation ID. The correlation ID is the same one carried on the HTTP request, the
queued job and every provider call it caused, so a customer's report at 14:02 becomes one
query rather than an archaeology exercise.
