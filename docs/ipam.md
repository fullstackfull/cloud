# IP address management

## Why the platform owns this

A hosting provider's IPv4 space is finite, expensive and non-substitutable. It has to be
tracked in the same database as the services that consume it, because the question that
matters — "is this address free?" — cannot be answered by asking the hypervisor. Proxmox
knows what is configured on a bridge; it does not know what the platform has promised to a
customer whose VM has not been created yet.

## Entities

```text
IpPool        a block the provider owns, e.g. 203.0.113.0/24
Subnet        a routable division of a pool, with its gateway and VLAN
IpAddress     one address, with a state
IpAssignment  the binding of an address to a service, with its lifetime
Reservation   a short-lived hold taken during provisioning
ReverseDns    the PTR record for an assigned address
```

Both IPv4 and IPv6 are modelled. IPv6 is allocated as a delegated prefix per service
rather than as individual addresses — enumerating a /64 is neither useful nor possible.

## Address states

```text
available     free to allocate
reserved      held by an in-flight provisioning job
assigned      bound to a live service
quarantined   released but not reusable yet
unavailable   permanently withheld (network, broadcast, gateway, infrastructure)
```

### Why quarantine exists

An address released the instant a VM is destroyed is a trap. The previous tenant's
address may still be in DNS caches, in third-party allow-lists, in an attacker's scan
results, and — most concretely — in the abuse reports that arrive days later. Handing it
to a new customer within the hour means that customer inherits a reputation they did not
earn, and the provider inherits an abuse investigation against the wrong account.

Released addresses therefore sit in `quarantined` for a configurable period before
returning to `available`. An address released because of abuse is quarantined for longer,
and is flagged so an operator sees why.

## Allocation must be transactional

The failure this design exists to prevent: two provisioning jobs for two different
customers, running at the same instant, both read the same "first available" address and
both configure it. Two machines answer for one address; neither works reliably; the
symptom looks like a network fault rather than a control-plane bug.

Allocation therefore runs inside a transaction using row-level locking with
`SKIP LOCKED`:

```sql
SELECT id FROM ip_addresses
WHERE subnet_id = ? AND status = 'available'
ORDER BY address
FOR UPDATE SKIP LOCKED
LIMIT 1;
```

`SKIP LOCKED` is the important part. Without it, the second job blocks on the first job's
lock and then — after the first commits — either times out or wakes up to find the row
changed underneath it. With it, the second job simply takes the next free address and both
succeed.

The reservation is written in the same transaction as the state change, so there is no
window in which an address is marked reserved but nothing records why.

## Reservations expire

A provisioning job that dies between reserving an address and using it must not leak that
address forever. Every reservation carries an expiry and is tied to its provisioning job.
A scheduled reaper releases reservations whose job is no longer running.

The reaper is deliberately conservative: it releases a reservation only when the job is in
a terminal failed state, never merely because time has passed. A slow hypervisor is not a
dead job, and reclaiming an address that is about to be configured recreates the exact
collision the locking prevents.

## Assignment outlives the resource

An `IpAssignment` records when an address was bound to a service **and when it was
released**. Rows are not deleted. When an abuse report arrives naming an address and a
timestamp, the only way to answer "who had this address at that moment" is a history that
was never overwritten.

## Capacity

Because pools are finite, exhaustion is a business event rather than an error. The
platform reports free capacity per pool and per region, and alerts before a pool runs
dry — an order that fails at provisioning because there is no address left has already
taken the customer's money.

Plans declare how many addresses they require. Capacity checks run at **order** time as
well as at provisioning time, so a customer is told the region is full before they pay,
not after.
