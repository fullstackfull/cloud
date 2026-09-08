# Lynomia Cloud

A unified control plane for a commercial hosting provider: **Cloud VPS**,
**Dedicated Servers** and **Shared Hosting** sold, billed, provisioned and
operated from one platform.

Lynomia Cloud is the *orchestration and commercial layer*. It does not
reimplement hypervisors, monitoring engines or backup engines — it drives
Proxmox VE, Redfish/iLO/IPMI, cPanel/DirectAdmin, Prometheus/Grafana/Loki and
Proxmox Backup Server through explicit provider interfaces.

```text
                        LYNOMIA CLOUD
                              │
                 ┌────────────┴────────────┐
            Customer Portal            Admin Portal
                 └────────────┬────────────┘
                      Lynomia Control Plane
                      Laravel API / Workers
                              │
        ┌─────────────────────┼─────────────────────┐
      VPS                Dedicated             Shared Hosting
        │                     │                     │
 Proxmox VE API        Redfish/iLO/IPMI      cPanel/DirectAdmin
```

## Repository layout

| Path | Contents |
|---|---|
| `apps/control-plane` | Laravel 13 API, domain modules, queue workers, scheduler |
| `apps/web` | React 19 + TypeScript + Vite SPA (customer and admin portals) |
| `packages/` | Shared TypeScript types, generated API client, UI component library |
| `infrastructure/` | Ansible, OpenTofu, Proxmox, PBS, networking, monitoring, PXE, security |
| `deployments/` | Per-environment deployment configuration |
| `docs/` | Architecture, runbooks, operational and deployment documentation |
| `scripts/` | Bootstrap and developer tooling |

## Quick start

Requirements: PHP 8.4+, Composer 2, Node 20+, Docker (for local Postgres/Redis/Mailpit).

```bash
make bootstrap   # install dependencies, start services, migrate from an empty DB
make serve       # API + queue worker + scheduler + frontend
make test        # full test suite
```

See [`docs/local-development.md`](docs/local-development.md) for details.

## Honest status

Build status per subsystem is tracked in
[`docs/build-status.md`](docs/build-status.md) using explicit classifications —
`CODE_COMPLETE`, `TESTED`, `RUNTIME_VERIFIED`, `REAL_INFRA_VERIFIED`,
`BLOCKED_CREDENTIALS`, `BLOCKED_HARDWARE`, `BLOCKED_LICENSE`.

A subsystem exercised only against fake providers is **never** reported as done.

## Documentation

- [Architecture](docs/architecture.md) · [API](docs/api.md) · [Portal](docs/portal.md)
- [Local development](docs/local-development.md)
- [Deployment](docs/deployment.md)
- [Production checklist](docs/production-checklist.md)
- [Billing](docs/billing.md) · [IPAM](docs/ipam.md) · [Networking](docs/networking.md)
- [Proxmox](docs/proxmox.md) · [Dedicated](docs/dedicated.md) · [Shared hosting](docs/shared-hosting.md)
- [Security](SECURITY.md) · [Monitoring](docs/monitoring.md) · [Backups](docs/backups.md)
- [Disaster recovery](docs/disaster-recovery.md) · [Runbooks](docs/runbooks/)

## Licence and third-party software

cPanel/WHM, DirectAdmin, CloudLinux, LiteSpeed Enterprise and WHMCS are
commercial products. This repository contains integrations and guarded
installers for them; it never bypasses their licensing. Without a valid
licence those installers stop cleanly and report `LICENSE_REQUIRED`.
