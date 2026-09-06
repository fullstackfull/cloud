# Monitoring stack

Prometheus, Alertmanager, Grafana, Loki and Grafana Alloy, plus the exporters
that feed them. Everything here is configuration in git: nothing is created
through a UI, because a dashboard or a datasource that exists only in Grafana's
database is lost with the volume and cannot be reviewed.

**Promtail is not used anywhere in this platform.** It is end-of-life. Log
shipping is Grafana Alloy.

## What runs where

Run this stack on a dedicated monitoring host — never on a hypervisor and never
on an application host. Monitoring that shares a machine with what it monitors
goes down with it, and the graph you need is the one you cannot reach.

| Component | Image | Port (localhost only) | Job |
|---|---|---|---|
| Prometheus | `prom/prometheus:v3.6.0` | 9090 | Metrics store, rule evaluation |
| Alertmanager | `prom/alertmanager:v0.28.1` | 9093 | Grouping, inhibition, routing |
| Grafana | `grafana/grafana:12.1.1` | 3000 | Dashboards |
| Loki | `grafana/loki:3.5.7` | 3100 | Log store |
| Alloy | `grafana/alloy:v1.11.2` | 12345 | Log shipping (**not** Promtail) |
| node_exporter | `prom/node-exporter:v1.9.1` | 9100 | Host metrics |
| blackbox_exporter | `prom/blackbox-exporter:v0.27.0` | 9115 | External probes, TLS expiry |
| pve_exporter | `prompve/prometheus-pve-exporter:3.5.5` | 9221 | Proxmox cluster state |
| postgres_exporter | `prometheuscommunity/postgres-exporter:v0.17.1` | 9187 | Control-plane database |
| redis_exporter | `oliver006/redis_exporter:v1.75.0` | 9121 | Queue backend |

Every image is pinned to an exact tag. `latest` on a monitoring stack means the
alerting semantics change on the Tuesday somebody runs `docker compose pull`,
and the first sign of it is an alert that quietly stops firing.

Only this stack's own node_exporter runs in a container. Every other machine
runs node_exporter natively, deployed by the Ansible monitoring role: a
containerised node_exporter reports the container's view of the world unless it
is handed most of the host, at which point the container is only pretending to
be isolation.

## Exposure

Every published port binds to `127.0.0.1`. This is not belt-and-braces, it is
the control:

- **Prometheus** serves every metric the platform has — capacity headroom,
  customer counts, revenue. An unauthenticated read of it is a competitor's
  market research and an attacker's capacity plan in one request.
- **Alertmanager** can silence alerts. Anyone who can reach it can make the
  platform stop telling you about an outage they are causing.
- **Grafana** is reached through the reverse proxy that terminates TLS and
  enforces SSO. Anonymous access and sign-up are both off in the compose file;
  "internal network only" is not authentication.

## Running it

```bash
cd infrastructure/monitoring

# 1. Credentials the stack needs, from the secret store — never committed.
#    Both secrets/ directories are gitignored.
install -m 0600 /dev/null prometheus/secrets/control-plane-metrics-token
printf '%s' "$PROMETHEUS_METRICS_TOKEN" > prometheus/secrets/control-plane-metrics-token

install -m 0600 /dev/null alertmanager/secrets/slack-api-url
install -m 0600 /dev/null alertmanager/secrets/pagerduty-routing-key

cp pve-exporter/pve.yml.example pve-exporter/pve.yml   # then fill in the API token

# 2. Environment the compose file requires (it fails loudly if any is missing).
export GRAFANA_ADMIN_PASSWORD=...
export POSTGRES_EXPORTER_DSN='postgresql://monitoring:...@cp-db-01:5432/lynomia?sslmode=require'
export REDIS_EXPORTER_ADDR='redis://cp-redis-01:6379'
export LYNOMIA_ENV=production
export LYNOMIA_REGION=kw-central

# 3. Validate before starting. Always — see "Validating the configuration".
docker compose -f docker-compose.monitoring.yml up -d
```

The metrics token is the same value as `PROMETHEUS_METRICS_TOKEN` in the control
plane's `.env`. Rotating it means writing both and reloading Prometheus
(`curl -X POST http://127.0.0.1:9090/-/reload`); rotating only one produces a
404 on every scrape — the guard refuses without confirming the endpoint exists —
which shows up as `ControlPlaneMetricsDown`.

## Validating the configuration

These run without starting anything, and they are the difference between a
config that is syntactically fine and one that has a rule which can never fire.

```bash
promtool check config prometheus/prometheus.yml --lint=all --lint-fatal
promtool check rules  prometheus/rules/*.yml    --lint=all --lint-fatal
amtool  check-config  alertmanager/alertmanager.yml
alloy   validate      alloy/config.alloy
loki    -config.file=loki/loki-config.yml -verify-config
docker compose -f docker-compose.monitoring.yml config --quiet
```

Two things about `promtool check config` are worth knowing before it surprises
you.

It resolves `rule_files` and `file_sd_configs` against the absolute paths in the
config — the paths the container mounts — so run it where `/etc/prometheus`
points at this directory:

```bash
sudo ln -sfn "$PWD/prometheus" /etc/prometheus
```

And it **fails while `prometheus/secrets/control-plane-metrics-token` does not
exist**:

```text
FAILED: error checking authorization credentials or bearer token file ...
```

That is the check doing its job rather than being awkward. The file is
gitignored and deliberately absent from a fresh clone, so the failure is
Prometheus telling you the secret has not been provisioned on this host yet —
which, discovered here, costs a minute, and discovered after `up -d` costs a
404 on every scrape and a `ControlPlaneMetricsDown` page. Create it (step 1
above) and run the check again.

Dashboard PromQL is checked the same way — every expression in every panel,
extracted into a throwaway rules file:

```bash
python3 - <<'EOF' > /tmp/dash-exprs.yml
import json, glob, yaml
rules = [{'record': 'dash:%s%s' % (p['id'], t['refId'].lower()), 'expr': t['expr']}
         for f in sorted(glob.glob('grafana/dashboards/*.json'))
         for p in json.load(open(f))['panels']
         for t in p.get('targets', [])
         if t.get('datasource', {}).get('type') == 'prometheus']
print(yaml.safe_dump({'groups': [{'name': 'dash', 'rules': rules}]}, width=10000))
EOF
promtool check rules /tmp/dash-exprs.yml
```

## Dashboards

Five, provisioned from `grafana/dashboards/*.json` and read-only in the UI —
editing a provisioned dashboard produces a change that is silently reverted on
the next restart, which is more confusing than being refused.

| Dashboard | UID | What it answers |
|---|---|---|
| Platform overview | `lynomia-platform` | Is the control plane healthy? Queues, provisioning outcomes, Postgres, Redis, edge latency, errors |
| Proxmox | `lynomia-proxmox` | Node and storage availability, saturation, allocated versus installed capacity |
| Shared hosting | `lynomia-hosting` | Per-node disk, load, memory, account provisioning |
| Dedicated servers | `lynomia-dedicated` | Reachability, SMART health, provisioning state |
| Business | `lynomia-business` | MRR, active services by product, orders, failed payments, IP runway |

The business dashboard is not decoration. A provisioning failure rate climbing
from 1% to 8% is visible there hours before it is visible in support tickets.

The JSON files are generated by `grafana/build-dashboards.py`, so that all five
share one definition of what a panel looks like. Edit the generator and re-run
it rather than editing the JSON:

```bash
python3 grafana/build-dashboards.py grafana/dashboards
```

## Alerts

55 alert rules and 9 recording rules across six files in `prometheus/rules/`.
Every alert carries a
`runbook_url`, the machine or pool it is about, and the measured value.

| Rule file | Covers |
|---|---|
| `nodes.yml` | Node down, hypervisor offline, hosting node unavailable, dedicated unreachable, CPU, memory, disk (per role), read-only filesystem, degraded RAID, PVE storage failure, SMART |
| `backups.yml` | Backup job failed, backup too old, **backup verification failed**, verification stale, unverified snapshots accumulating, dead collector |
| `platform.yml` | Metrics endpoint down, failing or slow collector, database unavailable, Redis unavailable, Redis evicting, provisioning failure spike, provisioning stalled, jobs awaiting review, queue backlog, failed jobs |
| `business.yml` | Payment webhook failures, webhooks stopped, failed payments, stalled revenue recognition, IP pool runway, IP pool nearly empty, compute capacity, hosting disk ratio, stuck orders |
| `probes.yml` | Endpoint down/slow/5xx, certificate expiring and expired, and the monitoring watching itself |
| `recording.yml` | Recording rules for the expressions used by more than one place |

Two things in this set are worth reading before changing:

**Backup verification is a higher severity than a failed backup.** A failed
backup is visible: the job is red and everyone knows recoverability is degraded.
A failed *verification* means the snapshot exists, looks healthy in every
listing, has the right size and a green tick on the job that produced it — and
will not restore. An unverified backup looks exactly like a backup. So
`BackupVerificationFailed` pages at critical while `BackupJobFailed` is a
warning, even though the words sound the other way round.

**IP pool alerts are in days, not percent free.** "Alert below 10% free" is a
decision about how much time an operator has, disguised as a decision about a
percentage. At ten orders a week, 10% of a /24 is months; at a hundred it is
four days, and acquiring more space is an RIR justification measured in weeks.
`lynomia_ip_pool_runway_days` restates the measurement in the unit the decision
is actually made in. A pool with no measured consumption exports `+Inf` and
never trips the alert — deliberately, because inventing "9999 days" would put a
reassuring number on a dashboard that really means "we have no idea".

**An alert nobody acts on gets deleted, not muted.** There is no low-priority
receiver in `alertmanager.yml` for exactly this reason: a muted alert still
fires, still adds noise, and still trains the on-call to ignore the channel the
real one will arrive in. If it is not worth waking someone for, it belongs on a
dashboard.

### Runbooks

Every alert's `runbook_url` points at `https://docs.lynomia.internal/runbooks/<slug>`,
which is served from `docs/runbooks/` in this repository.

Already written: `ip-exhaustion`, `payment-reconciliation`, `provisioning-stuck`.

Still to write — the alerts reference them and will link to a 404 until they
exist: `node-down`, `hosting-node-down`, `dedicated-unreachable`,
`node-saturation`, `disk-pressure`, `storage-failure`, `backup-failed`,
`backup-verification-failed`, `database-down`, `redis-down`, `queue-backlog`,
`metrics-endpoint`, `endpoint-down`, `certificate-expiry`, `capacity-planning`,
`billing-run`, `monitoring-health`.

## Metric contracts this stack depends on

Two sets of metrics are produced elsewhere and consumed here. If either stops,
the alerts that read them go silent rather than red, which is why both have a
staleness alert of their own.

### 1. The control plane — `lynomia_*`

Served by `Lynomia\Modules\Monitoring` at `GET /metrics`, bearer-token
protected. See that module for the full list. The series used here:

```
lynomia_orders_total{status}              lynomia_provisioning_jobs_total{status,kind}
lynomia_provisioning_duration_seconds     lynomia_services_active{kind}
lynomia_queue_depth{queue}                lynomia_failed_jobs_total
lynomia_ip_pool_available{pool}           lynomia_ip_pool_runway_days{pool}
lynomia_node_capacity_ratio{node,dimension}
lynomia_hosting_node_disk_ratio{node}     lynomia_webhook_events_total{provider,status}
lynomia_mrr_minor{currency}               lynomia_failed_payments_total
```

Plus two the module reports about itself, and which the rules in `platform.yml`
depend on:

```
lynomia_metrics_collector_up{collector}            0 when that collector threw
lynomia_metrics_collect_duration_seconds{collector} seconds its last run took
```

A collector that throws does not fail the scrape — one broken query must not
cost the platform its queue depth and its IP runway as well. The families that
collector owns are simply absent, and absence cannot be alerted on, so the
failure is reported as a zero on `lynomia_metrics_collector_up` instead.

`lynomia_orders_total`, `lynomia_provisioning_jobs_total` and
`lynomia_webhook_events_total` are exported with `TYPE gauge` despite the
`_total` suffix: they are counts of rows in each state, and a row moves between
states, so the `queued` series goes *down* when a worker picks a job up. Only
the terminal states are monotonic. Every rule that measures change over these
uses `delta()`, never `rate()` or `increase()` — those assume a counter and
would read each transition as a wrap.

The webhook family is the least obvious of the three and the one that matters
most, because it is wired to a critical page. `WebhookEventStatus::Failed` is
deliberately *not* settled: the provider's own redelivery retries a failed
event, and when the retry succeeds the row moves to `processed` and
`{status="failed"}` goes down. Under `increase()`, that decrease is a counter
reset and the pre-reset value is reported as brand-new failures — so
`PaymentWebhookFailures` would page the on-call at the moment the failures
cleared. Only the sum across every status is monotonic, which is why
`PaymentWebhooksStopped` sums before it takes a delta.

`lynomia_failed_jobs_total` and `lynomia_failed_payments_total` *are* real
counters: those rows are appended and never re-classified.

### 2. Proxmox Backup Server — `lynomia_backup_*`

PBS has no Prometheus endpoint. These are written by a textfile collector on the
backup host, read by its node_exporter from
`/var/lib/node_exporter/textfile_collector/`. The collector belongs to
`infrastructure/pbs`; this is the contract the alerts in `backups.yml` expect:

```
# 0 = last run succeeded, 1 = last run failed
lynomia_backup_task_last_status{datastore,guest_type,guest_id,guest_name}
lynomia_backup_last_success_timestamp_seconds{datastore,guest_type,guest_id}

# 0 = verification passed, 1 = verification failed
lynomia_backup_verify_last_status{datastore,snapshot_group}
lynomia_backup_verify_last_run_timestamp_seconds{datastore}
lynomia_backup_unverified_snapshots{datastore}

# The heartbeat that makes the silence of the above meaningful
lynomia_backup_collector_last_run_timestamp_seconds
```

Write the file atomically — `.prom.tmp` then `rename(2)`. node_exporter reads
the directory on every scrape, and a half-written file is a parse error that
drops *every* textfile metric on that host, not just the one being written.

## What to verify by hand

Automated checks prove the configuration parses. These prove it works. Do them
after the first deploy and after any change to routing or shipping.

**1. An alert reaches a human, end to end.**

An alerting pipeline that has never fired is untested, and the first time it
matters is the wrong time to discover the webhook URL is wrong.

```bash
amtool --alertmanager.url=http://127.0.0.1:9093 alert add \
  alertname=PipelineTest severity=critical component=monitoring \
  summary='End to end test, ignore' \
  --end="$(date -u -d '+5 minutes' +%Y-%m-%dT%H:%M:%SZ)"
```

Confirm it arrives in `#alerts-monitoring`, then confirm the resolved
notification arrives when it expires. Then repeat with `severity=critical
component=compute` and confirm PagerDuty pages — the Slack path working proves
nothing about the paging path.

**2. A fake secret reaches Loki masked.**

Redaction tested only in unit tests has not been tested against the real
serialiser, the real handler and the real shipper.

```bash
php artisan tinker --execute="Log::channel('structured')->warning('redaction check', \
  ['authorization' => 'Bearer '.'sk_'.'live_0123456789abcdef']);"
```

Then in Grafana Explore: `{service="control-plane"} |= "redaction check"`. The
line must show `[redacted]`. If the token is visible, stop and fix it before
anything else — every log line written since the last deploy is suspect.

**3. Correlation IDs are queryable.**

Place an order in staging, take its `request_id` from the `X-Request-Id`
response header, and run:

```
{service="control-plane"} | request_id=`<the id>`
```

You should see the HTTP request, the queued job and the provider call. If the
lines are there but the filter matches nothing, the IDs are being shipped as
part of the message instead of as structured metadata — check `stage.json` and
`stage.structured_metadata` in `alloy/config.alloy`.

**4. Timestamps are the application's, not the shipper's.**

Restart Alloy after generating a few minutes of logs. The replayed lines must
land at their original times, not bunched at the restart. If they bunch,
`stage.timestamp` is not matching Monolog's format and the ordering that makes
a log readable during an incident is gone.

**5. The metrics endpoint refuses an unauthenticated scrape.**

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://cp-app-01.kw.lynomia.internal/metrics
# expect 404 — not 401. The endpoint does not confirm its own existence.
```

**6. A scrape is cheap.**

```bash
curl -s -o /dev/null -w 'total: %{time_total}s\n' \
  -H "Authorization: Bearer $PROMETHEUS_METRICS_TOKEN" \
  https://cp-app-01.kw.lynomia.internal/metrics
```

Well under a second. A metrics endpoint that takes ten seconds gets scraped
every fifteen and becomes the outage it was installed to detect.
