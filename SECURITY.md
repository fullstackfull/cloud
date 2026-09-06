# Security

## Reporting a vulnerability

Report security issues privately to the platform security contact configured in
`docs/production-checklist.md`. Do not open a public issue for a vulnerability.

## Controls implemented in the platform

| Area | Control |
|---|---|
| Sessions | Sanctum SPA cookies, `HttpOnly`, `Secure`, `SameSite=Lax`, CSRF token required for state-changing requests |
| Passwords | Argon2id (bcrypt fallback), breach-list rejection, minimum length enforced server-side |
| Two-factor | TOTP with single-use recovery codes; re-authentication required to disable |
| API tokens | Personal access tokens with explicit scopes, per-token rate limits, individually revocable, hashed at rest |
| Authorisation | Database-backed permissions checked through policies and gates; no hardcoded role comparisons |
| Webhooks | Provider signature verification, event-ID uniqueness (replay protection), bounded timestamp window, idempotent handlers |
| Injection | Parameterised queries only; no string-built SQL; no shell concatenation of untrusted values in IPMI/SSH adapters |
| Output | React escapes by default; a strict Content-Security-Policy is served with the SPA |
| Transport | HSTS, TLS only, secure cookie flags |
| Headers | CSP, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `X-Frame-Options: DENY` |
| Rate limiting | Per-route, per-token and per-IP limits on auth, payment and provisioning endpoints |
| Secrets | Never committed; sensitive database columns encrypted at rest; a log processor redacts secrets before writing |
| Audit | Append-oriented audit log of administrative actions with actor, target, before/after and correlation ID |
| Account safety | Login history, active-session listing and revocation, lockout and risk controls |

## Never logged

Plaintext passwords · API secrets and tokens · BMC/iLO/IPMI credentials ·
private keys · payment card data · Cloudflare tokens · Proxmox tickets.

## Infrastructure security posture

- BMC (iLO/IPMI/Redfish) interfaces live on an isolated management network and
  are never routable from customer networks. Customers never receive BMC
  credentials unless a service is explicitly designed and secured for it.
- Proxmox administrative interfaces are not publicly exposed by default.
- Automation authenticates with SSH keys. Password SSH login is disabled on
  infrastructure roles where explicitly approved in inventory.
- SSH private keys are never committed to this repository.
- DHCP/PXE is enabled only on the designated provisioning VLAN, never on an
  unknown production LAN.
- Outbound SMTP (port 25) is blocked for customer VPS by default and requires
  manual approval, as an anti-abuse control.

## Destructive-operation safety

Infrastructure automation is idempotent and supports `--check`, `--dry-run` and
`--verbose`. No playbook formats disks, repartitions, resets RAID, joins a
Proxmox cluster or re-images a machine unless that host is explicitly declared
in inventory as an installation target (`allow_reimage: true`). Preflight
inspection runs before any potentially destructive role.

## Fake providers

`FakeComputeProvider`, `FakeDedicatedProvider`, `FakeHostingProvider`,
`FakePaymentProvider`, `FakeDnsProvider` and `FakeBackupProvider` exist for
tests and local development. Their registration throws when `APP_ENV=production`
so that a misconfigured production deployment fails loudly rather than
pretending to provision infrastructure.
