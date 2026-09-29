# A dedicated power request has no answer

## What you are seeing

`DedicatedPowerOperationIndeterminate`, `DedicatedPowerClaimsAbandoned`, or a
customer asking whether the server they rebooted is actually rebooting.

## What it means

Every power request a customer sends to a dedicated server — on, off, cycle —
claims its idempotency key in `dedicated_power_operations` before the
platform calls the machine's management controller, and records the
controller's answer afterwards. A request that never gets an answer is
**indeterminate**, and there are two ways to get there:

- **The controller stopped answering** mid-request. The failure code is
  `dedicated.power_operation_indeterminate`.
- **The process died** between claiming the key and hearing back — a deploy, a
  killed web worker, an out-of-memory kill. Its claim sits there until its
  lease runs out (fifteen minutes by default, `DEDICATED_POWER_CLAIM_LEASE_MINUTES`),
  and is then settled as indeterminate by a sweep that runs every five minutes,
  or by the customer repeating the request. The failure code is
  `dedicated.power_claim_abandoned`.

Either way the instruction may or may not have reached the chassis, and the
platform will not send it again: a second reset interrupts the first, and the
first may be halfway through a boot. The customer has been told the platform
does not know, and every repeat of the same key gets the same answer.

An **abandoned claim** is one past its lease that nothing has settled yet. The
sweep empties that count within minutes of each crash, so a number that stays
up for half an hour is one of two different problems, and the alert cannot say
which. See below.

## Check first

What has been left without an answer lately:

```sql
SELECT id, dedicated_server_id, action, outcome, failure_code, requested_at, settled_at
FROM dedicated_power_operations
WHERE outcome IN ('indeterminate', 'claimed')
  AND requested_at > now() - interval '1 day'
ORDER BY requested_at DESC;
```

Then, for each machine, ask the controller rather than the platform — at the
BMC's own console: power state, and what is on the screen. To bring the
platform's recorded power state up to date from the controller:

```bash
php artisan dedicated:sync-inventory --server=<id>
```

## Abandoned claims: the sweep, or the workers

```sql
SELECT id, action, requested_at
FROM dedicated_power_operations
WHERE outcome = 'claimed'
ORDER BY requested_at;
```

Run it twice, ten minutes apart.

- **The same old claims both times**, hours old: the sweep is not running.
  Check the scheduler — `scheduler-stale.md` — and the sweep's own last success,
  `lynomia_scheduled_command_last_success_timestamp_seconds{command="dedicated:expire-abandoned-power-claims"}`.
  Running it by hand is safe; it prints how many claims it examined and
  settled, and it never sends anything to a chassis:

  ```bash
  php artisan dedicated:expire-abandoned-power-claims
  ```

- **Different, recent claims each time**: the sweep is keeping up and requests
  keep dying mid-call. Power requests are answered inside the web request, so
  look at the web tier — worker restarts during deploys, request time limits
  shorter than the controller timeouts, out-of-memory kills — around the
  `requested_at` times.

## What to do

For an indeterminate request, find out what the machine is doing from its
controller, tell the customer, and let them send a new request — with a new
idempotency key — once they know what they want. There is nothing to resolve
on the row: it records that the platform does not know, which stays true.

If a controller answers late, after its claim was settled as abandoned, the
platform records the controller's answer over the guess. That is intended:
the late answer is a fact.

## What not to do

- **Do not delete a `claimed` row, or edit it back to `claimed`, to unstick a
  key.** The unique index on the key is the only thing that stops one intent
  reaching a chassis twice. A released claim lets the next repeat send a second
  reset to a machine that may still be acting on the first.
- Do not send a power request yourself "to see". That is the second reset.
- Do not raise `REDFISH_TIMEOUT_SECONDS` or `IPMI_TIMEOUT_SECONDS` towards the
  claim lease without raising the lease too. A slow but live request would then
  look abandoned, and a customer repeating it would be told "we do not know"
  about a reset that was in fact accepted.
