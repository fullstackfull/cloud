# The monitoring has gone blind

## What you are seeing

`MetricsCollectorDown` or `MetricsCollectorSlow`.

## What it means

Every other alert is derived from this collector. While it is down, a quiet
dashboard means nothing at all — you are not seeing health, you are seeing
absence. Alertmanager inhibits the derived alerts for exactly this reason, so
this may be the only alert you get during a real incident.

## Check first

```bash
curl -sS -H "Authorization: Bearer $(cat /etc/prometheus/secrets/metrics-token)" \
     https://<control plane>/metrics | head
php artisan lynomia:metrics --json | jq '.duration_ms'
journalctl -u prometheus --since '1 hour ago' | tail -40
```

## What to do

If the endpoint answers but Prometheus is not scraping it, the problem is
Prometheus: check the target's status in its own UI, and the bearer token file.

If the endpoint itself is slow or failing, the collector is running expensive
queries against a struggling database. `MetricsQueryBudgetTest` bounds the query
count in CI, so a sudden slowdown usually means the database, not a new query.
Go to the database.

## While you are blind

Check the things the alerts would have told you, by hand:

```bash
php artisan queue:monitor default,provisioning
php artisan lynomia:operations --state=indeterminate
php artisan lynomia:scheduled-commands --json | jq '.[] | select(.consecutive_failures > 0)'
```

## What not to do

Do not assume the platform is healthy because the alerts went quiet.
