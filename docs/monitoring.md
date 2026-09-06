# Monitoring and logging

## Stack

```text
node_exporter · blackbox_exporter · application metrics
                        │
                   Prometheus ──────► Alertmanager ──► on-call
                        │
   Grafana Alloy ──► Loki ──► Grafana ◄────────────────┘
```

Grafana Alloy ships logs. **Promtail is not used** — it is end-of-life, and building a new
platform on a component that is already past its support window means migrating before the
first year is out.

## Logs

Application logs are structured JSON, one object per line. Every record carries the
correlation IDs that make an incident traceable:

```text
request_id · order_id · provisioning_job_id · service_id · customer_id
```

The correlation ID is assigned at the HTTP edge and flows into every queued job and every
provider call that request causes. "A customer's VPS order failed around 14:02" becomes one
query instead of an archaeology exercise across three systems.

### What is never logged

Plaintext passwords · API secrets and tokens · BMC/iLO/IPMI credentials · private keys ·
payment card data · Cloudflare tokens · Proxmox tickets.

Enforcement is a Monolog processor, not a convention. A developer cannot leak a credential
by logging a raw exception or an unfiltered request body, because the scrubbing is not
opt-in and does not depend on anyone remembering it.

## Dashboards

**Platform** — request rate, latency percentiles, error rate, queue depth per queue,
failed jobs, database connections and slow queries, Redis memory and evictions, payment
webhook success rate, provisioning job outcomes.

**Proxmox** — per-node CPU, memory, storage, VM count, network throughput, node
availability, and allocated-versus-total capacity.

**Dedicated** — reachability, hardware alerts where the BMC exposes them, provisioning
state distribution.

**Shared hosting** — per-node health, disk, load average, account count.

**Business** — active services by product, MRR, new orders, failed payments, provisioning
failure rate, churn.

The business dashboard is not decoration. A provisioning failure rate that climbs from 1%
to 8% is visible there hours before it is visible in support tickets.

## Alerts

| Alert | Why it matters |
|---|---|
| Node down | Customer machines are affected right now |
| Sustained high CPU or memory on a node | The next placement will make it worse |
| Disk almost full | On a hypervisor, snapshots and running VMs stop. On a hosting node, every site on it breaks at once |
| Storage failure | Data loss risk |
| Backup job failed | Recoverability is degraded and nothing else shows it |
| Backup verification failed | Worse than a failed backup: an unverified backup looks like a backup |
| Provisioning failure spike | Something systemic broke — a template, a token, a full pool |
| Queue backlog growing | Work is arriving faster than it is being done; paid provisioning is waiting |
| Database or Redis unavailable | Total outage |
| Payment webhook failures | Money is moving without being recorded |
| Certificate expiring | Predictable, preventable, and takes the portal down |
| Hosting node unavailable | Every account on it is offline |
| IP pool below runway threshold | Orders will start failing after payment |

Every alert carries enough context to act: which node, which cluster, which customer where
one is implicated, and a link to the runbook.

**An alert that nobody acts on gets deleted, not muted.** A muted alert still fires, still
adds noise to the channel, and still trains the on-call to ignore it. If an alert is not
worth waking someone for, it belongs on a dashboard.

## Exposure

Grafana is never publicly reachable without authentication. Prometheus and Alertmanager
are not publicly reachable at all — Prometheus exposes every metric the platform has,
including capacity and customer counts, and Alertmanager can silence alerts.

## Verifying the pipeline

Two checks that are easy to skip and expensive to have skipped:

1. **Trigger a real alert and confirm it arrives.** An alerting pipeline that has never
   fired end-to-end is untested, and the first time it matters is the wrong time to find
   the webhook URL was wrong.
2. **Log a fake secret and confirm it reaches Loki masked.** Redaction that is only tested
   in unit tests has not been tested against the real serialiser, the real handler and the
   real shipper.
