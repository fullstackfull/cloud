# Runbook — IPv4 exhaustion

**When to use this:** provisioning is failing with no address available, or the capacity
alert for a pool has fired.

## 0. Confirm the shape of the problem

```sql
SELECT p.name, s.cidr,
       count(*) FILTER (WHERE a.status = 'available')   AS available,
       count(*) FILTER (WHERE a.status = 'reserved')    AS reserved,
       count(*) FILTER (WHERE a.status = 'assigned')    AS assigned,
       count(*) FILTER (WHERE a.status = 'quarantined') AS quarantined
FROM ip_pools p
JOIN subnets s ON s.ip_pool_id = p.id
JOIN ip_addresses a ON a.subnet_id = s.id
GROUP BY p.name, s.cidr
ORDER BY available;
```

The distribution tells you which of three different problems you have.

## 1. High `reserved` — leaked reservations, not real exhaustion

A reservation is held by an in-flight provisioning job. A large reserved count means jobs
died between reserving an address and using it.

```sql
SELECT r.id, r.ip_address_id, r.provisioning_job_id, r.expires_at, j.status
FROM ip_reservations r
LEFT JOIN provisioning_jobs j ON j.id = r.provisioning_job_id
WHERE r.released_at IS NULL
ORDER BY r.created_at;
```

Reservations whose job is in a terminal failed state are safe to release:

```bash
php artisan ipam:reclaim --reservations=25    # a small pass first
php artisan ipam:reclaim
```

`ipam:reclaim` returns *expired* reservations and elapsed quarantines. It has no
dry run; the `--reservations` and `--quarantined` limits are how you keep a first
pass small enough to read the result of.

**Never release a reservation whose job is still running**, however old it looks. A slow
hypervisor is not a dead job, and reclaiming an address that is seconds from being
configured recreates exactly the collision the reservation exists to prevent.

If a reservation has no job at all, that is a bug worth recording — the job row should
outlive the reservation.

## 2. High `quarantined` — capacity is coming back on its own

Quarantine is deliberate. A released address stays unusable for a configured period
because the previous tenant's reputation follows it: DNS caches, third-party allow-lists,
and abuse reports that arrive days after the machine is gone. Handing it straight to a new
customer means that customer inherits an abuse investigation they had nothing to do with.

Check when the queue drains:

```sql
SELECT date_trunc('hour', quarantined_until) AS releases_at, count(*)
FROM ip_addresses WHERE status = 'quarantined'
GROUP BY 1 ORDER BY 1;
```

Shortening the quarantine window is a business decision with a real cost, not an
operational quick fix. If you do it, do it in configuration for new releases — never by
hand-editing existing rows.

Addresses quarantined **because of abuse** are held longer and flagged. Those are not
candidates for early release at all.

**Two kinds of quarantine do not drain on their own.**

The first is a **timeout's** (`quarantine_reason = 'provisioning_timed_out'`). It has a
`quarantined_until` like any other, and nothing acts on it: the build that reserved the
address stopped answering, the platform does not know whether a machine was made with
the address configured, and waiting does not tell it. `GET
/api/admin/infrastructure/ip-addresses/awaiting-clearance` lists them, each with the job
the timeout closed. Look at the provider for that job's machine, then either:

- **adopt** it (`POST /api/admin/infrastructure/ip-addresses/{address}/adopt`) when the
  machine is there — the address stays with it, as an assignment that names no machine,
  and **it never comes back to the pool**: nothing on the platform ends an assignment that
  names no machine; or
- **release** it (`POST /api/admin/infrastructure/ip-addresses/{address}/release`) when
  the machine demonstrably does not exist — the address is available again at once.

Both need `ipam.manage` and the evidence you looked at, which is audited. Both refuse
(409) any other kind of quarantine.

The second kind is *held*: a row with `quarantined_until`
null. It came off a dedicated server that was decommissioned and is still
racked with the address configured on its disks. Its clock starts only when an
operator returns that machine to stock (`POST /api/admin/dedicated/{server}/return-to-stock`)
or retires it (`POST /api/admin/dedicated/{server}/retire`). `php artisan ipam:capacity`
lists them per pool with the machine each is waiting for; a list that only grows is a
machine somebody forgot. Do not clear these by hand — erase or dispose of the machine,
then use one of the two endpoints.

## 3. Genuinely low `available` — you are out of addresses

The order in which to reach for options:

1. **Reclaim from terminated services.** Addresses still assigned to services that ended
   are the cheapest capacity there is:
   ```sql
   SELECT a.address, s.id AS service_id, s.status, s.terminated_at
   FROM ip_addresses a
   JOIN ip_assignments ia ON ia.ip_address_id = a.id AND ia.released_at IS NULL
   JOIN services s ON s.id = ia.service_id
   WHERE s.status IN ('terminated','cancelled');
   ```
   That an address is still assigned to a terminated service is itself a bug — the
   release should have happened at termination. Fix the release path, not just the rows.

2. **Check for infrastructure addresses marked available.** Gateways, broadcast and
   management addresses should be `unavailable`. If any are allocatable, that is a
   configuration error waiting to take out a subnet.

3. **Bring an unused pool into service**, if one is provisioned but not enabled.

4. **Stop selling what you cannot deliver.** Mark the affected plans or region out of
   stock. An order that fails at provisioning has already taken the customer's money and
   costs a refund plus the support conversation; an order that never completes costs
   neither.

5. **Acquire more space.** A new allocation from the RIR or a broker is weeks, not hours.
   That is why the capacity alert exists well above zero.

## 4. Stop the bleeding while you fix it

Put affected plans out of stock rather than letting checkout succeed:

Take the plan out of stock from the operator portal's catalogue screen. There is
no CLI for it — putting a plan out of stock stops the platform selling something,
which is a commercial decision with an audit entry, not a shell command.

Capacity is checked at order time as well as at provisioning time precisely so
this is possible. Use it.

## 5. Prevention

- Alert on free addresses per pool with a threshold measured in **days of runway** at the
  current sell rate, not a fixed count. A count that is comfortable at ten orders a week
  is not comfortable at a hundred.
- Track quarantine volume separately from available: a growing quarantine queue with flat
  availability means churn, and churn is a different business problem.
- Offer IPv6-only plans where the workload allows. It is the only option on this list that
  does not run out.
