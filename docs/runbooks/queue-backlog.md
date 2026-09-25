# The queue is backing up

## What you are seeing

`QueueBacklog`, `QueueStalled` or `FailedJobsRising`.

## What it means

Three different things wear the same symptom, and they need opposite responses:

1. **More work than workers.** Depth rising, jobs still completing.
2. **No workers.** Depth flat, nothing completing.
3. **One provider is hanging.** Depth rising, workers busy, all on one provider.

## Check first

```bash
php artisan queue:monitor default,provisioning,notifications
systemctl status lynomia-worker
php artisan horizon:status
```

Then open the operator portal's operations queue. It tells case 3 apart from
case 1: if everything still running names the same provider, the queue is fine
and the provider is not.

## What to do

- Case 1: raise `control_plane_worker_processes` and redeploy. Watch depth fall.
- Case 2: `systemctl start lynomia-worker`, then read `journalctl -u lynomia-worker`
  for why it stopped. A worker that exits on boot is usually a bad `.env`.
- Case 3: go to the provider's own runbook. Adding workers makes it worse —
  more requests queue against the same hanging endpoint.

## Failed jobs

```bash
php artisan queue:failed
php artisan queue:retry <id>        # one at a time, after reading the exception
```

## What not to do

Do not `queue:retry all`. Some of those jobs touch money or destroy resources,
and a blanket retry is how one timeout becomes two charges. Read each exception.
Jobs whose outcome is unknown are handled by `provider-indeterminate.md`, not by
retrying.

## The Horizon dashboard

`/horizon` on the control-plane host. It is authorised by capability, the
same way as every other operator surface, and by nothing else: no environment
name appears in the gate (`AuthorizationServiceProvider::authoriseTheQueueDashboard`).

| to | you need |
|---|---|
| read — any of its 18 `GET` routes | `provisioning.view` |
| write — start or stop monitoring a tag, retry a job, retry a batch | `provisioning.retry` |

Both also need a **verified email address**, as `/api/admin` does. Super Admin
is admitted through the platform-wide bypass. Of the seeded roles,
Infrastructure Admin, Support and NOC can read; Infrastructure Admin and NOC
can write; Network Engineer cannot open it at all — it holds `monitoring.view`,
not `provisioning.view`, and nothing commercial.

What reading gives you is more than a queue depth. The read routes include the
failed-job list and each failed job's payload, exception and stack trace, kept
for seven days (`trim.failed` in `config/horizon.php`). Payments-queue payloads
carry transaction, customer and invoice identifiers, amounts and the
provider's decline reason verbatim. Grant `provisioning.view` knowing that.

**If your installation has edited roles:** the read capability used to be
`monitoring.view`. Across the seeded roles the change is narrower. A custom
role holding `provisioning.view` without `monitoring.view` now *gains* the
dashboard; check custom roles before assuming nobody's access widened.

### Getting a session — the step people miss

The application serves no web pages of its own: apart from Horizon's 22
routes, the only route on the `web` middleware stack is `sanctum/csrf-cookie`.
Horizon's gate reads the signed-in user from the session, and the only thing
that creates that session is the portal's sign-in (`POST /api/v1/login`, then
`POST /api/v1/login/two-factor` for an account with a second factor).

So: **sign in to the operator portal first, in the same browser, then open
`/horizon`** on a host the session cookie covers (`SESSION_DOMAIN`). A fresh
tab with no portal session answers 403 even for somebody who holds the
capability. That 403 is not a permission problem, and it is not fixed by any
of the things under "What not to do" below.

### What each answer means

Measured, not read off the middleware order, by
`TheQueueDashboardIsGatedByCapabilityTest::the_runbook_table_is_measured_rather_than_read_off_middleware_order`:

| request | answer |
|---|---|
| nobody signed in, any `GET` | 403 |
| nobody signed in, `POST`/`DELETE` | 419 — the CSRF check runs before the gate |
| signed in with `provisioning.retry`, write with no CSRF token | 419 |
| signed in, valid token, capability not held | 403 |
| signed in, email address not verified | 403 |
| `OPTIONS` on any Horizon URL | 200 with an `Allow` header; the router answers it and the gate never runs |

The framework's forgery check also accepts a request whose `Sec-Fetch-Site`
header is `same-origin`, so the 419 rows describe a client that sends neither
a token nor that header: `curl`, a script, another site.

### Staging

Staging is reachable by whoever holds the capability **on the staging
installation**. That is a decision, not a default: the gate does not mention
staging or any other environment, so staging follows from there being no
environment test, not from a carve-out. Keeping staging at 403 is what used to
push people toward the two worse fixes below.

### Retrying from here is not the same as retrying from the admin API

- A retry from Horizon writes **no audit record**. `POST
  /api/admin/provisioning/jobs/{job}/retry` records `provisioning.retried`.
  For a provisioning job, retry from the operator portal.
- Horizon's routes carry no rate limit.
- The warning against `queue:retry all` earlier in this runbook applies here
  too: read each exception before retrying.

### When the secret store is wired

`docs/security-review.md` names Horizon's failed-job view as one of the places
a BMC/IPMI password would surface through an exception chain. It is latent
only because `config/dedicated.php` ships no `credentials` map, so no real
password exists to leak. **Whoever wires the secret store must revisit this
gate** and decide whether `provisioning.view` is still the right bar for
reading failed jobs.

### What not to do

- Do not set `HORIZON_PATH` to something obscure instead. Obscurity is not
  a gate, and the capability check still applies at the new path.
- Do not register another `Horizon::auth()` that returns `true`, or one that
  tests `APP_ENV`. The environment test is what this replaced: an exact-string
  comparison, the same instrument as the miscapitalised `APP_ENV=Production`
  that once disarmed every production guard at once.
- Do not grant `provisioning.view` to open the dashboard for somebody who
  already holds it: a 403 in a fresh tab is a missing session (see above), or
  an unverified address.
