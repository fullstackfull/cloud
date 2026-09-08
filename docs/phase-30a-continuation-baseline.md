# Phase 30A continuation — baseline

Measured, not inherited. Every number below was produced by running the gate
on this working tree at the commit named, in this session.

**HEAD:** `aecec9b` · **Branch:** `claude/hv-t6hq1p`

Two commits exist after the `b044471` reference the continuation brief names.
Both are this session's own — `b56e352` wrote `docs/phase-30a-progress.md`, and
`aecec9b` corrected a miscount in its header. No third party has pushed to this
branch, and nothing has been overwritten.

The brief lists `docs/phase30aprogress.md`; the file is
`docs/phase-30a-progress.md`. Same document.

## Gates as they actually stand

| Gate | Command | Result |
| --- | --- | --- |
| Backend suite | `php artisan test` | **1978 passed**, 48 998 assertions, 0 failed |
| Static analysis | PHPStan level 6, larastan, no baseline | **0 errors** |
| Formatting | `vendor/bin/pint --test` | pass |
| Frontend types | `npm run typecheck` (`tsc -b`, `exactOptionalPropertyTypes`) | pass |
| Frontend lint | `npm run lint` | pass |
| Frontend unit | `npm test` (Vitest) | **45 passed** in 7 files |
| Browser | `npm run test:e2e` (Playwright, real API + PostgreSQL + Redis) | **45 passed** |
| API description | `npm run openapi:lint` | valid, 1 warning |

The historical numbers quoted in the continuation brief match exactly. Nothing
drifted between the report and this re-measurement, which is the answer to the
question the brief was really asking.

The single OpenAPI warning is pre-existing and correct behaviour: the email
verification endpoint answers 302 and has no 2xx response, because it redirects
back to the portal.

## What this baseline does not cover

These gates are the ones that run today. The continuation brief requires
several that do not yet exist as repeatable commands, and their absence is part
of the baseline rather than a footnote to it:

- no clean-room build has been run since the console gateway was specified;
- no queue-integration proof exists for the notification, reinstall or
  suspension chains added in this phase — Phase 29's proof covered provisioning
  only;
- CI has not been observed on a run containing this phase's commits.

Each is scheduled in the continuation plan and reported in
`docs/phase-30a-final-product-closure.md` when it has actually run.
