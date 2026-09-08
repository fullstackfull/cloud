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

## The series the control plane exposes

`GET /metrics` on the control plane, behind the metrics token. Every family below is
produced by a collector wired in `MonitoringServiceProvider`; a collector that is written
and not wired is a visibly missing line in that file rather than a metric that silently
does not exist.

| Family | Labels | The question it answers |
|---|---|---|
| `lynomia_orders_total` | status | Are orders getting through to payment |
| `lynomia_provisioning_jobs_total` | status, kind | What the build queue is doing, and what it could not do |
| `lynomia_provisioning_duration_seconds` | kind, le | How long a build takes, bucketed around the job timeout |
| `lynomia_resource_drift_open` | kind, severity | Where the platform and a provider disagree, unresolved |
| `lynomia_services_active` | kind | What is running for customers right now |
| `lynomia_service_status_total` | kind, status | Everything else a service can be — including `reactivating`, which is a customer who has paid and cannot use their server |
| `lynomia_plan_change_total` | status | Upgrades whose money moved and whose machine has not caught up |
| `lynomia_reinstall_operation_total` | kind, state | Rebuilds by state; `indeterminate` and `needs_review` are disks nobody can vouch for |
| `lynomia_console_connection_total` | outcome | Consoles opened, and consoles that got a socket and no hypervisor |
| `lynomia_console_refusal_total` | reason | Permits refused; a rising `machine_mismatch` from one source is somebody trying permits that are not theirs |
| `lynomia_notification_delivery_total` | channel, status | Whether customers are actually being told things |
| `lynomia_queue_depth` | queue | Work arriving faster than it is done |
| `lynomia_failed_jobs_total` | — | Jobs the queue gave up on |
| `lynomia_ip_pool_available`, `lynomia_ip_pool_runway_days` | pool | Orders that will start failing after payment |
| `lynomia_node_capacity_ratio` | cluster, node, dimension | Placement headroom as the scheduler computes it |
| `lynomia_hosting_node_disk_ratio` | node | The node where every site breaks at once |
| `lynomia_scheduled_command_*` | command | Whether the scheduler is running at all |
| `lynomia_webhook_events_total` | provider, status | Money moving without being recorded |
| `lynomia_mrr_minor`, `lynomia_failed_payments_total` | currency, — | The business numbers |
| `lynomia_metrics_collector_up`, `lynomia_metrics_collect_duration_seconds` | collector | Whether the exposition itself is healthy |
| `lynomia_invitation_total` | state | Whether invitation mail is arriving: a rising `pending` with no accepts is a mail problem, not a sales one |
| `lynomia_account_member_total` | role | Who holds what across the platform, and whether anybody is using the narrow roles |
| `lynomia_wallet_entry_total` | kind | Credit going in and coming out; the one payment path with no gateway keeping its own count |
| `lynomia_support_ticket_total` | status | The support queue, including how much of it is waiting on the customer rather than on us |
| `lynomia_support_backlog_age` | bucket | How much of the backlog is older than an hour, four, a day, three days — four buckets, deliberately few |
| `lynomia_backup_deletion_total` | state | Backups in each stage of removal; a `deleting` count that does not fall is a datastore accepting deletes and keeping the archive |
| `lynomia_backup_retention_total` | disposition | `due` is the retention sweep's queue, `held` is what a departing customer's window is protecting |
| `lynomia_service_retention_window_open` | reason | Stopped services whose data still exists, by why they stopped; only `customer_cancelled` is ever ended automatically |
| `lynomia_open_drift_total` | resource, kind | Unresolved disagreements by what disagrees and how — the hosting reconciler's output lands here |
| `lynomia_provider_task_total` | state | Tasks behind jobs already called a success; a rising `unconfirmed` means the hypervisor is not being asked |
| `lynomia_dns_zone_total`, `lynomia_dns_record_total` | state | DNS by state; `indeterminate` is the platform having lost track of something a customer's mail depends on |
| `lynomia_dns_record_by_type_total` | type | Live records by type — six values, from the enum, and no domain names anywhere near it |

### What is never a label

No `customer_id`, `service_id`, `subscription_id`, `user_id`, `email`, `hostname`,
`ip`, `provider_resource_id`, `session_id` or any other per-row identifier — enforced by
`MetricsCarryNoIdentifiersTest`, which checks label names *and* what the values look like,
because a label called `node` holding a customer's hostname passes a name-only rule.

Two reasons, and the second is the one people forget. A per-customer series grows without
bound and is never released, and the moment it hurts most is the fleet-wide incident that
creates thousands of them at once. And a label publishes its value into a system with
weaker access control than the platform's own: a dashboard is not the database, and a
screenshot of one is not either.

`node`, `cluster`, `pool`, `queue` and `command` are labels because their value sets are
bounded by the hardware and the code rather than by the customer list, and because
"which node" is the first thing an operator needs.

Every family emits its full label cross-product including zeros. A series that appears
only once something has failed is a series nobody can write an alert rule against in
advance — which is to say, before the outage.

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
