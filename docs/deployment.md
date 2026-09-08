# Deployment

## Topology

Roles are separate machines. This is not an aspiration — several of the separations are
load-bearing for security, and the automation refuses to collapse them.

```text
control_plane   Laravel API, Horizon workers, scheduler, Nginx
console_gateway Lynomia Console Gateway — customer VNC/serial WebSockets
database        PostgreSQL 18 primary (+ replica)
redis           Redis: queues, cache, sessions
monitoring      Prometheus, Alertmanager, Grafana, Loki
proxmox         Proxmox VE hypervisors — bare metal
pbs             Proxmox Backup Server — dedicated host, separate storage
hosting         cPanel / DirectAdmin nodes
dedicated       customer physical servers (inventory only; never runs platform code)
pxe             iPXE/DHCP, provisioning VLAN only
firewall        OPNsense, only where explicitly declared re-imageable
```

Never install the control plane on a hypervisor, a hosting node or the firewall. A
control plane that lives on a hypervisor cannot be used to recover that hypervisor, and a
control plane on a hosting node shares a blast radius with customer PHP.

## The console gateway

Its own process, and it may be its own host.

```bash
php artisan console-gateway:serve
```

A console lives for as long as somebody is looking at a screen and holds two
sockets the whole time. Running that inside the API's PHP-FPM pool would tie up
a worker per console; running it under Horizon would tie up a queue worker. So
it is a long-running process of its own, deployed in whatever count the fleet
needs and restarted without touching the API.

It needs exactly two things from the platform:

- **the same Redis as the API.** Permits are issued by the API and spent by the
  gateway, and the store is what makes "single use" true — on a per-node file
  cache the gateway would never see a permit the API issued.
- **the database**, to re-check at redemption that the machine still exists and
  its service is still active. A permit lives sixty seconds and a suspension
  can land inside that window.

It needs no queue, no scheduler and no HTTP server.

Bind it to loopback and put it behind the same TLS terminator as the API.
`VPS_CONSOLE_GATEWAY_URL` is the public `wss://` address handed to browsers; it
is deliberately not derived from the bind address, because only the deployment
knows the public name. Leaving it empty means consoles are not offered, and the
portal says so rather than showing a button that fails.

What never reaches a browser: the hypervisor's console ticket, the node's
address, and the cluster's API credentials. The gateway obtains all three on
its own connection after the permit has been spent — which is the whole reason
it exists rather than the portal talking to Proxmox directly.

## What a deployment does, in order

1. **Back up the database.** Before migrations, not after. The backup taken after a bad
   migration is a backup of the damage.
2. **Put the API into maintenance** only if the release contains a migration that is not
   backwards-compatible. Most are; see below.
3. **Install dependencies** with `composer install --no-dev --optimize-autoloader`.
4. **Run migrations** with `--force`.
5. **Build and publish the frontend.**
6. **Rebuild caches**: `config:cache route:cache event:cache view:cache`.
7. **Restart workers** with `queue:restart`. Skipping this leaves workers executing the
   previous release's code against the new schema — the subtlest class of deploy bug there
   is.
8. **Health check.** If it fails, roll back.

## Backwards-compatible migrations

The control plane runs several application hosts and a fleet of queue workers. During a
deployment, old and new code run against the same schema at the same time. A migration
that removes or renames a column the old code still reads takes production down for the
duration.

Structure schema changes in two releases:

- **Release N** adds the new column, writes both, reads the old.
- **Release N+1** reads the new, stops writing the old.
- **Release N+2** drops the old column.

Only release N+2 needs the previous one to be fully rolled out, and none of the three
needs maintenance mode.

## Zero-downtime and queue workers

A queued job serialises its payload with the code of the release that dispatched it. A
worker on a newer release deserialising an older payload is the failure mode to design
against: keep job payload shapes additive, and never remove a constructor parameter in the
same release that stops passing it.

## Rollback

Rollback is code, not schema. Rolling a migration back on a live database is how a
recoverable incident becomes a restore.

- **Application:** redeploy the previous tag, rebuild caches, restart workers.
- **Schema:** leave it. Backwards-compatible migrations mean the previous release runs
  against the newer schema.
- **If the schema genuinely must be reverted:** that is a restore, from the backup taken
  in step 1, with the data written since then reconciled by hand. Treat it as an incident.

## Secrets

Secrets reach the application through the environment, never through the repository. In
production they come from the deployment system's secret store; `.env.example` documents
every variable but holds no value.

Rotating `APP_KEY` requires re-encrypting every encrypted column — two-factor secrets and
provider credentials among them. It is not a routine operation.

## Environments

| Environment | Purpose | Providers |
|---|---|---|
| development | A developer's machine | fakes |
| staging | Pre-production verification | real, against sandbox/test infrastructure |
| production | Live customers | real, and the platform refuses to boot otherwise |

Staging must use real provider adapters against test infrastructure. A staging
environment running fakes verifies nothing about the code path that actually matters.

## Deploying

```bash
make deploy-staging                      # staging, from the deployment branch
```

Production is deployed only from a tagged release on the protected branch, through the
`deploy-production` workflow, which requires an approval. Never from an arbitrary branch:
the whole point of the gate is that a deploy is a decision, not a side effect of a push.
