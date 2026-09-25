# The monitoring has gone blind

## What you are seeing

One of the alerts that send you here. Three are about the control plane's
metrics, and they are three different failures:

- **`ControlPlaneMetricsDown`** — Prometheus cannot scrape the endpoint at all:
  `up{job="control-plane"} == 0` for five minutes.
- **`MetricsCollectorFailing`** — the scrape succeeds, but one collector threw on
  its last run: `lynomia_metrics_collector_up == 0` for 15 minutes. The series
  it owns are missing, not zero.
- **`MetricsCollectionSlow`** — one collector is taking more than two seconds:
  `lynomia_metrics_collect_duration_seconds > 2` for 15 minutes.

The other three are about the monitoring stack itself:
`PrometheusRuleEvaluationFailing`, `AlertmanagerNotificationsFailing` and
`LokiIngestionStopped`.

## What it means

Every other alert is derived from this collector. While it is down, a quiet
dashboard means nothing at all — you are not seeing health, you are seeing
absence. Alertmanager inhibits the derived alerts for exactly this reason, so
this may be the only alert you get during a real incident.

## Check first

```bash
curl -sS -w '\n%{time_total}s\n' \
     -H "Authorization: Bearer $(cat /etc/prometheus/secrets/metrics-token)" \
     https://<control plane>/metrics | tail -5
journalctl -u prometheus --since '1 hour ago' | tail -40
```

The endpoint reports, per collector, how long it took as
`lynomia_metrics_collect_duration_seconds` and whether it succeeded as
`lynomia_metrics_collector_up`. There is no separate command; the endpoint is
the collector.

## What to do

If the endpoint answers but Prometheus is not scraping it, the problem is
Prometheus: check the target's status in its own UI, and the bearer token file.

If the endpoint itself is slow or failing, the collector is running expensive
queries against a struggling database. `MetricsQueryBudgetTest` bounds the query
count in CI, so a sudden slowdown usually means the database, not a new query.
Go to the database.
<!-- not-an-alert: MetricsQueryBudgetTest - a PHPUnit test class in apps/control-plane/tests/Feature/Monitoring, named here in prose -->

## While you are blind

Check the things the alerts would have told you, by hand:

```bash
php artisan queue:monitor default,provisioning
php artisan queue:failed
php artisan schedule:list
php artisan provisioning:detect-stale
```

and open the operator portal's operations and drift queues, which do not depend
on Prometheus being up.

## What not to do

Do not assume the platform is healthy because the alerts went quiet.
