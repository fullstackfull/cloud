# Leaving the platform

What happens between a customer clicking cancel and the last copy of their data
being destroyed. Three acts, four messages, and one boundary that automation is
not allowed to cross.

## The two forms of cancelling

Both live on `POST /subscriptions/{subscription}/cancel`.

**Scheduled** — the default, and no confirmation beyond the request. The
subscription stops renewing and `cancel_at` is stamped with the end of the
period the customer has already paid for. Nothing else changes: the service
keeps running, because the customer owns that time. It is reversible right up
until the date arrives.

**Immediate** — takes the subscription's own id typed back, compared with
`hash_equals`. The subscription ends now, the remainder of the paid period is
not refunded, and the service stops within the second. There is no edge out of
`cancelled` in the state machine, so this one cannot be undone.

The portal offers both inside one dialogue rather than as two buttons, because
the choice is between two forms of one decision. Its confirm button never reads
"Cancel": the dismiss button says that, and in a dialogue it means *do not do
this*.

## The messages a departing customer gets

| When | Message | Sent by |
| --- | --- | --- |
| The cancellation is arranged | `billing.cancellation_scheduled` | `CancelCustomerSubscription`, on the day the customer asks |
| The service stops | `service.ended` | the listener that ends the service |
| A few days before the data goes | `service.data_retention_ending` | the sweep |
| The data has gone | `service.terminated` | the sweep |

The first was missing entirely before this work, and the reason is worth
recording: a *scheduled* cancellation changes no status at all — only a date —
so the listener that watches status changes never saw it. A customer who
cancelled heard nothing until the day their service stopped.

The second is sent by the listener that ends the service rather than by the
notifications listener, because the sentence has to quote the date the data
goes and that listener is the one that writes it down. Two queued listeners on
one event have no order between them, and a message that raced the fact it
describes would quote an empty date.

## The retention window

When a service stops serving — cancelled, or suspended for non-payment — three
things are written at once:

- `retention_ends_at`: the day the data is destroyed. **Stored, not computed.**
  `suspended_at` plus a number from configuration gives the same answer today
  and a different one the morning after somebody changes the number, and the
  customer has already been told a date. A promise that moves when a config
  file changes is not a promise.
- `ended_reason`: `customer_cancelled`, `non_payment` or `operator`.
- A hold on every backup of that service, through `protected_until`. That
  column was written by the backup work and had no producer at all until now,
  which meant a departing customer's copies were still subject to the ordinary
  backup retention sweep — the one thing the window exists to prevent.

Paying, or otherwise coming back, clears all three.

## What the sweep may do, and what it may not

`services:end-expired`, daily at 03:20.

**It ends only what a customer cancelled.** A service suspended for non-payment
and a service cancelled by its owner look identical in the database — suspended,
window elapsed — and they are completely different decisions. The customer who
cancelled chose the date and was told it twice. The customer who has not paid is
somebody the business may still want back, and destroying their data thirty days
into a billing dispute is a decision that needs a person's name on it. That
person has `DELETE /api/admin/services/{service}`.

`provisioning.termination.sweep_cancelled` can switch the automation off
entirely. The warnings keep going out: a deployment that has decided to end
services by hand still owes its customers the date.

One service that cannot be ended does not stop the others. The failure is
logged and the row is untouched, so the next run tries it again.

## Ending one service, three ways

`EndOfService` dispatches by product kind and lives outside the modules,
because it is wiring: the sweep must not know that a VPS is destroyed by a
provisioning job, a hosting account by a control-panel call, and a physical
server by a person with a screwdriver.

| Kind | What happens |
| --- | --- |
| VPS | `TerminateVpsService` queues a `destroy_vps` job — one attempt, quarantined on timeout like every other destructive provider call |
| Shared hosting | `TerminateHostingAccount` asks the panel, and releases the node's capacity only once the panel confirms the account is gone |
| Dedicated | `DecommissionDedicatedServer` — **and stops there** |

An unknown kind raises rather than falling through to a default. Guessing would
mean sending a physical server down the path that destroys a virtual machine.

### The line automation does not cross

A dedicated server ends at `maintenance`. The machine leaves the customer and
leaves the sellable pool, and a **second, deliberate act by a person** —
`ReturnDedicatedServerToStock` — is what makes it sellable again, on the record
that its disks have been erased.

Collapsing the two would mean a machine returning to stock with the last
customer's data on it, sold to the next one. That is not a race or an edge
case; it is what would happen every single time. No call this platform can make
proves a disk was wiped, so nothing automated may complete that act — the sweep
can start a decommission and can never finish one.

## Where the numbers live

| Key | Default | Meaning |
| --- | --- | --- |
| `provisioning.termination.suspended_retention_days` | 30 | How long data is kept after a service stops |
| `provisioning.termination.warn_days_before` | 3 | How much notice before it goes |
| `provisioning.termination.sweep_cancelled` | true | Whether the sweep may end cancelled services on its own |

One consequence worth knowing: `TerminateVpsService` and
`TerminateHostingAccount` each check the window themselves, from `suspended_at`
plus the config value, as well as the stored date. Lengthening the retention
setting therefore holds a service past its promised date until both agree —
which is the safe direction, and the failures are logged rather than silent.
