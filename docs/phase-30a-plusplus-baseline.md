# Phase 30A++ — baseline

Measured, not inherited. Every number below was produced by running the gate
named, on this working tree, at the commit named, in this session.

**HEAD:** `1327a82` · **Branch:** `claude/hv-t6hq1p` · **Working tree:** clean

The brief named `1327a82` as the head when it was written and told me to check
for newer commits before trusting it. There are none: `git rev-parse HEAD` is
that commit exactly, nothing is uncommitted, and no third party has pushed to
this branch. Nothing needed preserving.

## What the gates actually said

| Gate | Command | Result |
| --- | --- | --- |
| Backend suite | `vendor/bin/phpunit` | **2 302 passed, 65 124 assertions**, 272 s |
| Architecture gates | `--testsuite=Architecture` | 23 passed, 37 assertions |
| Code style | `vendor/bin/pint --test` | passed |
| Composer manifest | `composer validate --strict` | `./composer.json is valid` |
| Static analysis | `phpstan analyse -c tools/phpstan/phpstan.neon` | passed, **0 errors** |
| Typecheck | `npm run typecheck` | passed |
| Lint | `npm run lint` | passed |
| Frontend unit | `npm run test` | **54 passed** (9 files) |
| Production build | `npm run build` | built in 626 ms, 534.24 kB JS (151.89 kB gzip) |
| Browser E2E | `npx playwright test` | **94 passed**, 3.7 min |
| OpenAPI generation | `npm run openapi:generate -- --check` | up to date: **134 operations** |
| OpenAPI validation | `npm run openapi:lint` | valid, 2 warnings |
| Scheduler | `php artisan schedule:list` | **17 commands** registered |
| Metrics scrape | recorded by the suite | 38 metrics, 415 series, 33 queries, 57 ms |

**The historical numbers the brief quotes reproduce exactly** — 2 302 tests,
65 124 assertions, 94 browser specs, 54 frontend tests, 134 operations, 38
metric families, 43 audit actions, 17 scheduled commands, PHPStan 0. Nothing
drifted between the close of Phase 30A+ and the start of this one, and nothing
in the historical record needed correcting.

## The shape of the repository at this commit

| Measure | Count |
| --- | --- |
| Modules under `src/Modules` | 24 |
| Backend test files | 282 |
| Migrations | 41 |
| Browser spec files | 13 |
| Documented API operations | 134 |
| Metric families | 38 |
| Audit actions | 43 |
| Domain events | 11 |
| Scheduled commands | 17 |

## Environment these numbers came from

| Component | Version |
| --- | --- |
| PHP | 8.4.19 |
| Node | 22.22.2 |
| PostgreSQL | 16.13 (CI also runs 18) |
| Redis | 7.0.15 |
| Infrastructure credentials | none, of any kind |

## What this phase must not disturb

The preservation list in the brief is the set of things Phases 29 through 30A+
built and proved: teams, wallet, support, forward and reverse DNS, the backup
lifecycle, customer termination, hosting reconciliation, provider task polling,
VPS, dedicated, shared hosting, the whole billing chain, and the audit, metrics,
queue, scheduler, reconciliation and architecture gates.

The measurements above are what "not disturbed" means concretely. Any number
here that moves during Phase 30A++ moves because this phase added something,
and the final report says which.
