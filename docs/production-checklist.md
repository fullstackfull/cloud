# Production readiness checklist

Nothing on this list is optional. Each item states what to verify and why it matters —
several of them describe failures that are silent until a customer finds them.

## Application

- [ ] `APP_ENV=production` and `APP_DEBUG=false`.
      Debug mode returns stack traces, environment variables and SQL to the client.
- [ ] `APP_KEY` is set and has never been shared between environments.
      It decrypts two-factor secrets and provider credentials. Rotating it without
      re-encrypting those columns makes every stored secret unreadable.
- [ ] `FORCE_HTTPS=true`, so HSTS is sent and session cookies carry `Secure`.
- [ ] `TRUSTED_PROXIES` names the actual load balancer addresses, never `*`.
      A wildcard lets any client spoof `X-Forwarded-For` and defeat every rate limit.
- [ ] `CORS_ALLOWED_ORIGINS` lists exactly the portal origins. Never `*` — it is
      incompatible with credentialed requests for good reason.
- [ ] `SANCTUM_STATEFUL_DOMAINS` matches those origins.
- [ ] `php artisan config:cache route:cache event:cache` run as part of deployment.
- [ ] `php artisan about` shows the expected driver for cache, queue and session
      (all Redis, not `file` or `sync`).

## Providers — the loud failure

- [ ] No `*_PROVIDER` variable is set to `fake`.
      `ProviderRegistryServiceProvider` refuses to boot if one is, and that refusal is
      deliberate: a fake provider reports payments captured and servers created while
      doing neither. Verify the guard is actually registered in `bootstrap/providers.php`.
- [ ] Stripe keys are live keys, and `STRIPE_WEBHOOK_SECRET` is the secret of the
      endpoint that will actually deliver to this environment. A test-mode secret on a
      live endpoint fails signature verification silently from the operator's side.
- [ ] Proxmox credentials are an **API token**, not a root password, and the token's
      role grants only what provisioning needs.
- [ ] `PROXMOX_VERIFY_TLS=true`. Disabling certificate verification on the link that
      creates and destroys customer machines is not an acceptable shortcut.

## Database

- [ ] PostgreSQL 18, with `--data-checksums` enabled on the cluster.
- [ ] The application's database role is not a superuser and does not own the database.
- [ ] Connections are TLS-encrypted if the database is not on the same host.
- [ ] `php artisan migrate --force` has been run and `migrate:status` shows nothing
      pending.
- [ ] A restore has been performed from the most recent backup into a scratch database
      **and the row counts checked**. A backup that has never been restored is a
      hypothesis, not a backup.
- [ ] Point-in-time recovery is configured, and the WAL archive is on different storage
      from the primary.

## Redis

- [ ] `requirepass` is set and the port is not reachable from outside the application
      subnet.
- [ ] Persistence is configured deliberately. Queues hold provisioning jobs; losing the
      Redis dataset on restart means losing paid work in flight.
- [ ] `maxmemory-policy` is **not** `allkeys-lru` on the queue instance. Evicting a
      queued job silently drops a customer's provisioning.

## Queues and scheduler

- [ ] Horizon is running under a supervisor that restarts it, with the queues
      `critical, payments, provisioning, infrastructure, notifications, monitoring, default`.
- [ ] `php artisan schedule:run` is in cron every minute, on exactly one host.
      Two hosts running the scheduler produce duplicate invoices.
- [ ] Failed-job alerting is wired to a channel a human actually reads.
- [ ] `queue:restart` is part of deployment, or workers keep running the old code.

## Security

- [ ] No `.env` file is tracked in git (CI enforces this).
- [ ] No private key is tracked in git (CI enforces this).
- [ ] Security headers verified against the live host, **including on a 401** — that is
      the response an attacker probes most, and middleware priority makes it the easiest
      one to miss.
- [ ] Rate limits verified against the live host, not just in tests.
- [ ] The admin API is not reachable from the public internet, or is behind an allow-list.
- [ ] Horizon's dashboard is authenticated.
- [ ] A dependency audit (`composer audit`, `npm audit`) is clean at the deployed commit.

## Observability

- [ ] `LOG_STACK` includes the `structured` channel and Grafana Alloy is shipping it.
- [ ] A test log line confirms secrets are redacted end to end — write one containing a
      fake token and confirm it reaches Loki masked.
- [ ] Prometheus is scraping the control plane, the database, Redis and every node.
- [ ] Alertmanager routes to a human, and a deliberately triggered alert has been seen to
      arrive. An alerting pipeline that has never fired is untested.
- [ ] Grafana is not publicly reachable without authentication.
- [ ] Certificate-expiry alerting covers the portal, the API and every BMC endpoint.

## Backups

- [ ] PostgreSQL: automated, encrypted, off-host, with a tested restore.
- [ ] Proxmox Backup Server: datastores, prune and garbage-collection schedules, and
      **verification jobs** configured.
- [ ] At least one scheduled restore test exists. Until a restore has run, the backup
      strategy is undemonstrated.
- [ ] Retention meets whatever the business has actually committed to customers.

## Infrastructure separation

- [ ] The control plane does not run on a hypervisor, a hosting node or the firewall.
- [ ] BMC/iLO/IPMI interfaces are on an isolated management network, unreachable from
      customer networks.
- [ ] Proxmox administrative interfaces are not publicly exposed.
- [ ] DHCP/PXE is enabled only on the designated provisioning VLAN.
- [ ] Port 25 is blocked outbound for customer VPS by default.

## Before the first real customer

- [ ] A full order → payment → provisioning → active flow has been completed against
      **real** infrastructure, not fakes.
- [ ] An invoice has been issued, paid and reconciled against the provider's dashboard.
- [ ] A refund has been issued and confirmed on the provider's side.
- [ ] A service has been suspended and restored.
- [ ] A VPS has been created, resized, reinstalled and destroyed.
- [ ] A backup of a customer VM has been taken **and restored**.
- [ ] The runbooks in `docs/runbooks/` have been walked through by someone who did not
      write them.
