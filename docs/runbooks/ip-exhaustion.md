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
php artisan ipam:reap-reservations --dry-run
php artisan ipam:reap-reservations
```

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

```bash
php artisan catalog:plan-stock --plan=<PLAN_SLUG> --out-of-stock \
  --reason='IPv4 capacity in <region>'
```

Capacity is checked at order time as well as at provisioning time precisely so this is
possible. Use it.

## 5. Prevention

- Alert on free addresses per pool with a threshold measured in **days of runway** at the
  current sell rate, not a fixed count. A count that is comfortable at ten orders a week
  is not comfortable at a hundred.
- Track quarantine volume separately from available: a growing quarantine queue with flat
  availability means churn, and churn is a different business problem.
- Offer IPv6-only plans where the workload allows. It is the only option on this list that
  does not run out.
