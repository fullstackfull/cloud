# Teams

A customer account is a commercial counterparty. A user is a login. Between
them sits a membership, and this document is about the membership: who may
create one, what it grants, and what stops it granting more.

## The two role systems, and why they do not touch

| System | Answers | Granted through |
| --- | --- | --- |
| Platform roles (`Super Admin`, `NOC`, `Finance`, …) | What a member of Lynomia staff may do **to the platform** | spatie/laravel-permission, globally |
| `CustomerRole` | What a person may do **inside one customer account** | the `customer_members` row for that account |

A user can be the owner of their own account and a read-only member of a
colleague's. Neither system can grant the other's permissions, and
`AuthorisesWithinAccount` reads only the second — a platform role never widens
what somebody may do inside a customer.

## The five roles

| Role | Money | Machines | Membership | Notes |
| --- | --- | --- | --- | --- |
| `owner` | Pay, methods | Everything, including ending a service | Invite, remove, change roles, close the account | Exactly one per account |
| `administrator` | See invoices | Everything, including ending a service | Invite, remove, change roles | Cannot pay, cannot hand the account over |
| `billing` | Pay, methods | See only | — | The finance department |
| `technical` | — | Power, rebuild, plan change | — | Cannot end a service: that ends something the account pays for |
| `member` | — | See only | — | The read-only tier |

`Billing` and `Technical` exist because a hosting account asks two different
questions of two different people: who may spend money, and who may touch the
machines. Somebody who needs both is made an administrator deliberately, rather
than by accumulation.

## Invitations

```text
Owner or administrator invites an address
        ↓
customer_invitations row + a 32-byte token, hashed into the row
        ↓
mail to the address, carrying the only copy of the token
        ↓
invitee signs in with that address (registering first if they must)
        ↓
POST /api/v1/invitations/{token}/accept
        ↓
customer_members row, accepted_at stamped, offer marked spent
```

Six properties hold, and each is a test:

1. **The token is never stored.** The row keeps a SHA-256. A copy of the
   database is not a set of working invitations, and no API response — not the
   creation, not the listing — carries a token or its hash.
2. **A resend replaces the link.** The platform cannot repeat a token it never
   kept, and keeping one so that it could would mean storing a live credential
   for every open offer. One live link per offer, always the most recent.
3. **The address is the credential; the mail is a notification.** Acceptance
   compares the invitation's address against the accepting user's own, and
   that address must be verified. A forwarded invitation admits nobody.
4. **An offer is spent once.** Redemption takes the row under a lock, so two
   clicks make one membership.
5. **Inviting does not disclose.** Inviting an address that already has a
   Lynomia login and inviting one nobody has ever seen produce identical
   responses. The endpoint cannot be turned into an oracle for "does this
   person bank with Lynomia".
6. **A token that names nothing and a token that names a spent offer answer
   identically**, so a wrong guess cannot be told from a stale link.

At most one live offer per address per account, enforced by a partial unique
index rather than by a check — without it, pressing Invite twice makes two
tokens, and revoking the one on screen leaves the other working.

## Ownership

Ownership is transferred, never granted. `POST /team/transfer-ownership` moves
both sides in one transaction with both rows locked: the outgoing owner becomes
an administrator and the incoming one becomes owner, so the account is never
for an instant ownerless and never for an instant owned twice.

- Only the current owner may ask. An administrator who could hand the account
  away could take it.
- The successor must already be an accepted member. An invitation that could
  confer ownership would make a mistyped address the end of somebody's
  business.
- The account's own id must be typed back, the same device the irreversible
  cancellation and the reinstall use.
- The owner cannot be removed or demote themselves. Both would leave an account
  whose invoices nobody can pay.

## Removal

Removing somebody deletes their membership and any personal access token scoped
to that account. The token would already be refused — `ResolveActingCustomer`
re-checks membership on every request, which is why a token outliving a
membership is not an access hole — but a credential that is refused on use is
still a credential somebody holds. Tokens they hold for *other* accounts are
untouched.

A role change takes effect on the next request. The role is read from the
membership row every time and nothing caches it.

## Limits

| Setting | Default | Why it exists |
| --- | --- | --- |
| `teams.invitation_ttl_days` | 14 | Long enough to survive a holiday; short enough that a forgotten invitation is not a standing key |
| `teams.max_members` | 25 | Blast radius, not commerce: a stolen owner session can otherwise add logins faster than the notifications are read, and each survives the password change that closes the original hole |
| `security.rate_limits.team_invitations` | 30/hour per account | The resource being spent is somebody else's inbox |
| `security.rate_limits.invitations` | 10/minute per caller | Redemption answers differently for a live offer than for nothing; the limit is what stops that difference being measured at scale |

## What is audited

`membership.invited`, `membership.invitation_revoked`, `membership.joined`,
`membership.role_changed`, `membership.removed` and
`membership.ownership_transferred` — each written in the same transaction as
the act, through `RecordActAtomically`, so an act that cannot be recorded does
not happen.
