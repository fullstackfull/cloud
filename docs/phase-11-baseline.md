# Phase 11 — Independently re-verified baseline

Run at the start of Phase 11 on commit `c44535a`, before any Phase 11 change,
in this environment. Nothing here is carried over from the Phase 10 report:
every command below was executed again and these are the outputs it produced.

## Result against the stated baseline

| | Stated in the brief | Measured now | |
|---|---|---|---|
| Backend tests | 1682 | **1685** | ✅ higher |
| Assertions | 41080 | **41085** | ✅ higher |
| Failures | 0 | **0** | ✅ |
| Frontend tests | 38 | **38** | ✅ |
| Migrations | 22 | **22** | ✅ |
| Tables | 68 | **68** | ✅ |
| PHPStan level 6 | 0 errors | **0 errors** | ✅ |

**The three extra tests are accounted for and are not a discrepancy.** The
brief quotes the figures from commit `c207a78`. Commit `c44535a`, which is HEAD,
added three tests to `ProductionGuardTest` when the boot guard was extended to
refuse a provider driver this build does not contain — `PAYMENT_PROVIDER=
myfatoorah` and `DNS_PROVIDER=cloudflare` previously booted a production
deployment that then failed on first use. No test was removed and no assertion
was weakened.

## Commands and outputs

Every service was started first; both had stopped, which is a property of this
container rather than of the platform:

```text
pg_ctlcluster 16 main start   → Removed stale pid file.
pg_isready -h 127.0.0.1       → 127.0.0.1:5432 - accepting connections
redis-cli ping                → PONG
```

### Backend

```text
php vendor/bin/phpunit --no-coverage
  {"tool":"phpunit","result":"passed","tests":1685,"passed":1685,
   "assertions":41085,"duration_ms":147736}

./vendor/bin/pint --test
  {"tool":"pint","result":"passed"}

composer validate --strict --no-check-publish
  ./composer.json is valid
```

### Static analysis

```text
phpstan analyse (level 6, larastan + deprecation rules, src app database routes)
  {"tool":"phpstan","result":"passed","errors":0}
```

Run through a PHPStan distribution cloned over git into a scratch composer root,
because `composer install --working-dir=tools/phpstan` cannot complete in this
environment — `phpstan/phpstan` is archive-only and both archive endpoints
answer 403 through the proxy. `apps/control-plane/tools/phpstan/README.md`
records this in full. The analysis itself is real; the CI install path is
`BLOCKED_NETWORK`.

### Migrations, from a database created empty for this run

```text
CREATE DATABASE lynomia_p11
php artisan migrate --force        → 23 DONE lines
                                     (22 migrations + "Creating migration table")
select count(*) from migrations    → 22
count of public tables             → 68
ls database/migrations/*.php       → 22
```

### Frontend

```text
npm run test --workspace=apps/web -- --run
  Test Files  5 passed (5)
  Tests       38 passed (38)

npm run typecheck   → PASS  (tsc -b --noEmit, no output)
npm run lint        → PASS  (eslint ., no output)
npm run build       → ✓ built in 1.21s
  dist/assets/index-DiukNJw4.js   427.30 kB │ gzip: 126.34 kB
  dist/assets/index-CarCthfa.css   23.27 kB │ gzip:   5.65 kB
```

### CI gates, run as CI runs them

```text
committed secrets           PASS
fake provider outside the
  three declared templates  PASS   161 files scanned
unresolved placeholders     PASS
npm audit --audit-level=high  found 0 vulnerabilities
composer audit              BLOCKED_NETWORK — advisory endpoint times out at the proxy
```

## Repository audit

```text
branch          claude/hv-t6hq1p
HEAD            c44535a
working tree    clean
modules         18
source files    575 PHP, 68 TypeScript/TSX
tests           190 files
migrations      22
factories       45
infrastructure  161 tracked files — 15 Ansible roles, 11 playbooks, OpenTofu, monitoring
docs            22 documents, 3 runbooks
```

Versions: PHP 8.4.19 · Node 22.22.2 / npm 10.9.7 · PostgreSQL 16.13 local
(18 in CI and production) · Redis 7.0.15.

## Gaps carried into Phase 11

Confirmed by inspection, not by reading the previous report:

1. **No Cloudflare DNS or reverse-DNS adapter.** `ReverseDnsProviderFactory`
   resolves one driver, `fake`. There is no forward-DNS contract at all.
2. **No application-level backup provider.** `BACKUP_PROVIDER` is read by the
   production guard and by nothing else; no contract, factory, adapter or model.
3. **No OpenAPI document.** `docs/api.md` is prose.
4. **No browser end-to-end suite.** One manual Chromium sign-in check at Phase 1.
5. **No concurrency, failure-injection or load test suite** as a standing,
   repeatable thing — concurrency is covered per-feature (IPAM allocation,
   hosting slots, wallet) but not as a suite.
6. **No query-count regression tests.**
7. **No real external provider has ever been contacted.**
