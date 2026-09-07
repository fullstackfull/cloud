# Lynomia Cloud — Performance Report

Every number here was produced by a command run in this environment on the date
below, against the code at that commit. Nothing is extrapolated, and where a
measurement cannot mean what a reader might assume, the section says so before
it gives the figure.

- **Date:** 2026-09-07
- **Machine:** 4 vCPU, 15 GiB RAM, Ubuntu 24.04
- **PHP:** 8.4.19 · **PostgreSQL:** 16.13 (local) · **Redis:** 7.0.15
- **Database under test:** `lynomia_perf`, 560,000 invoices · 560,000 orders ·
  6,004 customers

## What this environment cannot measure

Stated first, because a performance report that buries its limits is a marketing
document.

1. **There is no production topology here.** No nginx, no PHP-FPM process
   manager, no separate database host, no TLS, no network between client and
   server. The harness runs PHP's own web server with forked workers. That
   models an FPM pool on one machine and nothing beyond it.
2. **Four vCPU are shared** by the load generator, the web workers and
   PostgreSQL. Under concurrency the client competes with the server for CPU, so
   the throughput figures below are a floor, not a capacity.
3. **No horizontal scale was tested**, because there is one machine. Anything
   about "N application servers" would be arithmetic, not measurement.
4. **No queue worker was running.** Everything measured is synchronous request
   work.

What the numbers are good for: comparing endpoints against each other, finding
which layer dominates a request, and catching a regression on the same hardware.

## How to reproduce

```bash
createdb lynomia_perf
DB_DATABASE=lynomia_perf php artisan migrate --force
DB_DATABASE=lynomia_perf php artisan db:seed --class=RolePermissionSeeder --force

# Volume, and the plans the list queries run against
DB_DATABASE=lynomia_perf php artisan perf:profile --customers=5000 --per-customer=100 --keep

# HTTP latency and throughput
DB_DATABASE=lynomia_perf tools/perf/load.sh <workers> <concurrency> <requests>
```

`perf:profile` and `perf:token` both refuse to run in production and refuse any
database whose name does not mark it as scratch.

## 1. Query plans at half a million rows

`perf:profile` builds the customer list queries from the platform's own query
objects — `CustomerInvoices`, `CustomerOrders` — rather than retyping them, so
what is measured is what the endpoints run.

`EXPLAIN (ANALYZE, BUFFERS)` against 560,000 invoices and 560,000 orders:

| Query | Execution time | Rows | Plan root | Sequential scans |
|---|---|---|---|---|
| Invoices for one customer, newest first, 25 | 0.15 ms | 25 | Limit | none |
| Orders for one customer, newest first, 25 | 0.08 ms | 25 | Limit | none |
| All open invoices by due date, 25 | 0.05 ms | 25 | Limit | none |

No sequential scan appears anywhere in any of the three plans. The indexes the
migrations declare are the ones these queries use, and the cost does not move
between 60,000 rows and 560,000 rows (both were measured; the difference is
0.02 ms, which is noise).

## 2. No list endpoint queries per row

`tests/Feature/Performance/ListEndpointsDoNotQueryPerRowTest.php` counts the
queries an endpoint issues for one row and for twelve, and asserts the two are
equal. Twelve endpoints are covered: invoices, orders, services, subscriptions,
VPS, IP addresses, dedicated servers, hosting accounts, and the four operator
lists.

All twelve are constant-cost. The VPS list is the one with a genuine reason to
be otherwise — every machine carries its addresses — and it batches them in a
second query by design rather than lazy-loading per row.

The assertion is a comparison rather than a ceiling on purpose. "At most nine
queries" would break the first time an eager load is added for a good reason,
and would say nothing about scaling.

## 3. HTTP latency

One client, one worker, production-style caches built (`config:cache`,
`route:cache`, `event:cache`), opcache on:

| Endpoint | p50 | p90 | p99 |
|---|---|---|---|
| `GET /sanctum/csrf-cookie` (framework floor) | 29.2 ms | 35.5 ms | 37.4 ms |
| `GET /api/v1/me` | 43.4 ms | 50.6 ms | 56.6 ms |
| `GET /api/v1/catalog/products` | 40.4 ms | 44.6 ms | 48.3 ms |
| `GET /api/v1/invoices` (25 of 100) | 50.0 ms | 54.0 ms | 60.6 ms |
| `GET /api/v1/invoices?per_page=100` | 70.8 ms | 80.8 ms | 104.9 ms |

**The floor is the story.** A request that does no application work at all
costs 29 ms here, so the application's own share of a `/api/v1/invoices`
response is about 21 ms, of which the database is 0.15 ms. What the rest is:
PHP bootstrapping the framework on every request. On this hardware, in this
server, that is the dominant cost of every endpoint, and it is the number a
production deployment changes with OPcache preloading and FPM tuning — not by
optimising a query that already takes a seventh of a millisecond.

Four workers, eight concurrent clients, same build:

| Endpoint | p50 | p90 | p99 | throughput |
|---|---|---|---|---|
| `GET /sanctum/csrf-cookie` | 62.4 ms | 87.9 ms | 132.4 ms | ~124 req/s |
| `GET /api/v1/me` | 94.2 ms | 130.8 ms | 209.1 ms | ~81 req/s |
| `GET /api/v1/catalog/products` | 76.2 ms | 107.2 ms | 150.9 ms | ~101 req/s |
| `GET /api/v1/invoices` | 96.8 ms | 132.5 ms | 209.1 ms | ~79 req/s |
| `GET /api/v1/invoices?per_page=100` | 141.0 ms | 198.5 ms | 300.2 ms | ~55 req/s |

Latency roughly doubles while throughput rises threefold, which is what a
CPU-bound service on four cores does when it is given eight clients and has to
share those cores with the load generator.

### Caching the framework is worth about 15%

Same single-client run without `config:cache` / `route:cache` / `event:cache`:
the floor is 35.7 ms instead of 29.2 ms and `/api/v1/invoices` is 54.5 ms
instead of 50.0 ms. The deployment builds these caches; this is what it buys.

### OPcache measured, and a harness bug it exposed

With OPcache off, the same run gives 35.9 ms at the floor and 57.9 ms on
`/api/v1/invoices` — a few per cent, not the large factor one would expect,
because the framework caches above already remove most of the parsing OPcache
would otherwise save.

The first attempt at this comparison produced *identical* numbers for both
settings, because the harness passed `-d opcache.enable_cli=1` to
`artisan serve`, which spawns the actual server as a separate process that never
saw the flag. The harness now runs PHP's server directly. A benchmark that
cannot tell two configurations apart is reporting nothing, and the fact that it
looked plausible is the reason it is written down here.

## 4. The rate limiter is the first ceiling a single client meets

The authenticated API allows **120 requests per minute per user** by default
(`security.rate_limits.api_token.attempts`), and a personal access token may
carry its own ceiling in `rate_limit_per_minute`.

The first load run spent itself on 429s at 120 requests and reported them as
failures. That is the limiter working exactly as designed — and it means no
single customer can drive the load figures above. The harness raises the ceiling
on its own throwaway token, through the mechanism the platform already provides,
rather than disabling the limiter.

## 5. Checkout, end to end

The business path, measured in-process against the 560,000-row database:

```
checkout (place order + issue invoice)   n=60  p50=72.6ms  p90=84.6ms  p99=131.6ms
queries per checkout                     24
```

The 24 queries are: the plan and its price, the tax rule, the customer, the
order and its item, the order transition, the invoice, its item, and the
sequence reads that allocate an order number and an invoice number — plus the
transaction bookkeeping around them. There is no repetition per line and no
lazy-loaded relation in the path.

72 ms for a checkout that writes two documents, allocates two numbers from
sequences and commits twice is dominated by the same framework boot as
everything else.

## 6. What this says to do next, in order

1. **Nothing about the queries.** 0.15 ms at half a million rows, no sequential
   scans, no N+1 anywhere. There is no database work worth doing on the strength
   of these numbers.
2. **Tune the runtime, not the code.** OPcache preloading and an FPM pool sized
   to the host are what move a 29 ms floor. This is a deployment task and it is
   not measurable here.
3. **Re-run this on the staging host** once one exists, against the real
   topology, before any capacity claim is made to a customer. The commands are
   committed; the numbers in this file are not transferable.
4. **Measure the queue.** Nothing here exercises a worker, and the provisioning
   path — which is where a customer actually waits — is queue work.
