# Lynomia Cloud — Build Status

Updated after every phase. This document is the single honest record of what
actually works versus what has only been written.

## Status vocabulary

| Status | Meaning |
|---|---|
| `CODE_COMPLETE` | Implementation written, no placeholders left |
| `TESTED` | Covered by automated tests that pass |
| `RUNTIME_VERIFIED` | Executed against real Postgres/Redis in this environment |
| `REAL_INFRA_VERIFIED` | Executed against genuine external infrastructure |
| `BLOCKED_CREDENTIALS` | Implementation done; no credentials to verify against |
| `BLOCKED_HARDWARE` | Implementation done; no physical hardware available |
| `BLOCKED_NETWORK` | Implementation done; egress policy blocks verification |
| `BLOCKED_LICENSE` | Implementation done; commercial licence required |

`DONE` is never used for anything exercised only against mocks.

## Subsystem status

| Component | Code | Tests | Runtime | Real infra |
|---|---|---|---|---|
| Repository scaffolding | ✅ | — | ✅ | N/A |
| Control plane (Laravel 13.30) | ✅ | ✅ | ✅ | N/A |
| Web app (React 19 SPA) | ✅ | ✅ | ✅ | N/A |
| Shared kernel (Money, state machine) | ✅ | ✅ | ✅ | N/A |
| Identity / RBAC / 2FA | ✅ | ✅ | ✅ | N/A |
| API foundation & error contract | ✅ | ✅ | ✅ | N/A |
| Structured logging & secret redaction | ✅ | ✅ | ✅ | — |
| Arabic / RTL support | ✅ | ✅ | ✅ | N/A |
| CI/CD pipeline | ✅ | — | ⬜ | — |
| Catalog & pricing | ⬜ | — | — | N/A |
| Orders & checkout | ⬜ | — | — | N/A |
| Billing & invoicing | ⬜ | — | — | N/A |
| Stripe payments | ⬜ | — | — | `BLOCKED_CREDENTIALS` |
| Provisioning engine | ⬜ | — | — | N/A |
| IPAM | ⬜ | — | — | N/A |
| Proxmox / VPS | ⬜ | — | — | `BLOCKED_CREDENTIALS` |
| Dedicated / Redfish / iLO / IPMI | ⬜ | — | — | `BLOCKED_HARDWARE` |
| PXE / iPXE provisioning | ⬜ | — | — | `BLOCKED_HARDWARE` |
| Shared hosting (cPanel) | ⬜ | — | — | `BLOCKED_LICENSE` |
| Shared hosting (DirectAdmin) | ⬜ | — | — | `BLOCKED_LICENSE` |
| CloudLinux integration | ⬜ | — | — | `BLOCKED_LICENSE` |
| DNS (Cloudflare) | ⬜ | — | — | `BLOCKED_CREDENTIALS` |
| Monitoring stack | ⬜ | — | — | — |
| Backups / PBS | ⬜ | — | — | `BLOCKED_HARDWARE` |
| Ansible / OpenTofu automation | ⬜ | — | — | `BLOCKED_HARDWARE` |

Legend: ✅ complete · 🚧 in progress · ⬜ not started · — not applicable yet

---

## Phase 0 — Discovery (complete)

### Execution environment

| Item | Finding |
|---|---|
| OS | Ubuntu 24.04.4 LTS (Noble), kernel 6.18 |
| CPU / RAM | 4 vCPU / 15 GiB |
| Disk | 252 GB volume, ~26 GB writable allowance remaining |
| PHP | 8.4.19 (`/usr/bin/php`) |
| Composer | 2.8.12 |
| Node / npm | 22.22.2 / 10.9.7 |
| PostgreSQL | 16.13 client+server, started locally and accepting connections |
| Redis | 7.0.15, started locally, responds `PONG` |
| Docker | 29.3.1, daemon started successfully |
| Ansible / OpenTofu / gh | Not installed |
| Privileges | root |

### Repository state

Empty as expected: a single `Initial commit` containing only a 7-byte
`README.md`. Working branch `claude/hv-t6hq1p`.

### Environment constraints discovered

These are properties of *this* build environment, not of the platform:

1. **No infrastructure credentials of any kind are present.** No Proxmox API
   token, no BMC/iLO/IPMI access, no cPanel/DirectAdmin licence or host, no
   Stripe key, no Cloudflare token, no SSH access to any external machine.
   Everything that depends on them is therefore delivered as complete,
   test-covered code plus runnable automation, and marked `BLOCKED_*`.

2. **Egress policy blocks several hosts (HTTP 403 at the proxy).** Confirmed
   blocked: `api.github.com`, `codeload.github.com`,
   `ppa.launchpadcontent.net`. Consequences:
   - Composer cannot use dist (zip) downloads and falls back to git clones.
     Installs are slow and produce large working trees; `vendor/**/.git` is
     pruned after install to reclaim disk.
   - `php-bcmath` and `php-gmp` cannot be installed here. `brick/math` falls
     back to its pure-PHP calculator, which is *correct* but slower. Money
     handling is unaffected in terms of accuracy. Production deployment
     installs `php-bcmath` via Ansible.
   - The npm registry is reachable, so frontend installs are unaffected.

3. **The container is ephemeral.** Anything not committed and pushed is lost.

### Deviations from the requested baseline, and why

| Requested | Used here | Reason |
|---|---|---|
| PostgreSQL 18 | 16.13 locally | 16 is what this image ships. `docker-compose.dev.yml` and the production Ansible role both target **18**; no 17/18-only features are used, and migrations are validated against both. |
| Promtail | Not used | EOL, as the specification requires. Log shipping uses Grafana Alloy. |


---

## Phase 1 — Foundation (complete)

### Evidence

Commands actually executed in this environment, with their real results:

```text
php artisan test                    → PASS  99 tests, 532 assertions (PostgreSQL 16)
php artisan migrate:fresh --seed    → PASS  from an empty database
php artisan migrate:rollback --step=100 && php artisan migrate
                                    → PASS  migrations are reversible
./vendor/bin/pint --test            → PASS
composer validate --strict          → PASS
npm run typecheck                   → PASS
npm run lint                        → PASS
npm run test (vitest)               → PASS  13 tests
npm run build                       → PASS  289 kB JS / 91 kB gzipped
```

Browser verification (real Chromium, not a mock): the sign-in flow was exercised
end to end in English/LTR and Arabic/RTL, in light and dark, including a failed
sign-in showing a translated error with its correlation id, and a successful
sign-in reaching the dashboard.

### Not verified here, and why

| Item | Status | Reason |
|---|---|---|
| PHPStan / Larastan | `BLOCKED_NETWORK` | `phpstan/phpstan` is dist-only on `api.github.com`, which this environment's egress policy blocks with HTTP 403. It is configured and runs in CI, which has unrestricted access. |
| GitHub Actions run | not yet observed | The workflow is committed but has not been executed; no run has been observed from this session. |
| Pest | replaced with PHPUnit | Pest's install path also required the blocked host. PHPUnit provides equivalent coverage; no test quality is lost. |
| `bcmath` / `gmp` | `BLOCKED_NETWORK` | `ppa.launchpadcontent.net` returns 403. `brick/math` uses its pure-PHP calculator: correct, measurably slower. Production installs `php-bcmath` via Ansible, and CI installs it in the matrix. |

### Security decisions worth recording

- Login responses are identical for an unknown address and a wrong password, in
  status, code, wording and work performed.
- A correct password on a two-factor account yields a single-use challenge
  token, never a session.
- Recovery codes are hashed at rest; TOTP secrets are encrypted.
- Password change and reset revoke every other session and API token.
- Sessions are addressed by digest; the raw session id is never sent to a client.
- Security headers and correlation ids are global middleware, because Laravel's
  middleware priority would otherwise skip them on every 401.
- Secret redaction is a Monolog processor rather than a call-site convention, so
  it cannot be forgotten; it covers both secret-named keys and credential shapes
  embedded in free text.
