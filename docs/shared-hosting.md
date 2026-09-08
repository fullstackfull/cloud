# Shared hosting

## Two panels, one interface

cPanel/WHM and DirectAdmin are both supported through a single `HostingProvider`
interface. Ordering, billing and provisioning contain no panel-specific branch; only the
adapters do.

Operations: create account, suspend, unsuspend, terminate, change package, change
password, read usage, disk quota, bandwidth, domains, databases, email accounts where the
API allows, and SSL status.

Both adapters use the panels' **official APIs**. Neither scrapes a UI: a scraper breaks on
a point release and fails in ways that look like the customer's site is broken.

## Licensing is not negotiable

cPanel/WHM, DirectAdmin, CloudLinux and LiteSpeed Enterprise are commercial products.

This repository contains complete integrations and guarded installers for them. It
contains nothing that bypasses, patches or circumvents their licensing, and no
configuration flag enables such a thing.

Without a valid licence, an installer performs its preflight, stops cleanly, and reports:

```text
LICENSE_REQUIRED
```

The corresponding subsystem is then reported as `IMPLEMENTATION_COMPLETE` and
`DEPLOYMENT_BLOCKED_LICENSE_REQUIRED` — never as done.

## Installation preflight

A hosting panel takes over a machine: its firewall, its mail stack, its web server, its
user accounts. Installing one onto a host that is already doing something else does not
fail cleanly; it produces a half-broken machine that is easier to reinstall than to repair.

The preflight therefore **refuses** rather than warns when:

- the OS is not a supported version for the panel;
- the machine is not clean — an existing web server, database server or panel is present;
- the hostname is not a fully-qualified domain that resolves;
- forward and reverse DNS do not agree;
- required ports are already bound;
- there is no valid licence for the panel being installed.

Every one of these is a check, not a warning, because each produces a broken node that
looks installed.

## CloudLinux

Where licensed, CloudLinux provides the per-account isolation that makes shared hosting
survivable: one account's runaway process cannot consume the node.

Hosting packages capture, where technically supported: CPU limit, memory limit, IO,
process count and entry processes. Without CloudLinux those limits are recorded on the
package but not enforced by the kernel, and the platform says so rather than implying
isolation it does not have.

## LiteSpeed

Optional, never required. Without a LiteSpeed licence the platform uses the panel's
Apache or Nginx stack. Nothing in the ordering or provisioning path assumes LiteSpeed is
present.

## Node scheduling

Account placement considers: whether the node is enabled and healthy, whether it is in
maintenance, its account count, disk utilisation, resource utilisation, region, and
package compatibility.

Nodes above a configurable safety threshold receive no new accounts. Filling a shared
node to capacity degrades every account on it simultaneously, and the customers who notice
first are the ones who were there longest.

The threshold is deliberately conservative on disk: a shared node that runs out of disk
stops accepting mail and breaks every site on it at once.

## Account lifecycle

```text
Order paid → select node → create account → configure DNS → issue SSL → ACTIVE
Suspension keeps data and stops service; termination releases it after retention.
```

Suspension is reversible and preserves everything. That distinction matters commercially:
most suspensions are billing disputes that end with the customer paying, and a suspension
that destroyed data would turn a late invoice into a lost customer and a liability.

## Control panel single sign-on

Where a panel officially supports session creation, the platform brokers SSO so a customer
reaches their control panel without a second password.

The session is created server-side and is short-lived. The panel password is never sent to
the browser, and the SSO endpoint authorises against the platform's own session before
brokering anything.

## Reconciliation

`hosting:reconcile`, every four hours, bounded by `hosting.reconcile_batch`.

`listAccounts()` was implemented against cPanel and DirectAdmin in Phase 12 and
had no caller until this one, which meant the platform's belief that a customer
had a working website rested entirely on its own record of having built one.

| What it finds | Recorded as | Severity |
| --- | --- | --- |
| The platform says the account exists; the panel has never heard of it | `missing_at_provider` | critical — the customer is paying for a site that is not served, and they will notice first |
| An account on the panel that no row claims | `orphan_at_provider` | warning |
| A row marked terminated whose account is still serving | `orphan_at_provider` | warning |
| The two disagree about whether the account is switched off | `suspension_mismatch` | critical |
| `account_count` disagrees with the rows | `spec_mismatch` on the node | warning |

**Nothing is repaired.** Not at the panel and not in the platform's own rows.
An account whose provenance nobody knows must not be handed to a customer as
theirs, and one the platform cannot see must not be terminated on the strength
of a single listing that might have paged badly. Whichever way the sweep
guessed on a suspension mismatch, half the time it would be switching off a
customer who has paid.

A panel that will not answer produces no drift at all: nothing is concluded and
the node keeps its old timestamp, so the next run looks again. A sweep that
recorded "missing at the panel" for every account on a node whose API was down
would report an outage as data loss, and bury the one real missing account in
the middle of it. Nodes in maintenance are not asked, for the same reason.

The capacity check is the one finding that is not about the panel at all.
`account_count` is what the scheduler places against, and it is incremented and
decremented by hand in two different actions; when it drifts, an over-counted
node quietly refuses accounts it could hold and an under-counted one oversells
its disk. Neither is visible from any screen.
