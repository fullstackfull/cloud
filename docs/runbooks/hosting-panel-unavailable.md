# The hosting panel is unavailable

## What you are seeing

`HostingNodeDiskHigh`, or hosting operations failing against cPanel/WHM or
DirectAdmin.

## What it means

Websites on the node are usually still being served — the panel's API is not in
the request path. What stops is provisioning, suspension, SSO and reconciliation.

## Check first

```bash
ssh <node> 'df -h; uptime; systemctl is-active httpd nginx'
curl -sS -o /dev/null -w '%{http_code}\n' https://<node>:2087/    # WHM
php artisan hosting:sync-nodes --node=<node>     # disk, load and licence state
php artisan hosting:reconcile                    # accounts vs what the panel serves
```

## Disk

`HostingNodeDiskHigh` at 90% is close to the point where the panel starts
failing writes in ways that look like unrelated bugs. Find the consumer before
deleting anything:

```bash
ssh <node> 'du -sh /home/* | sort -h | tail -20'
ssh <node> 'du -sh /var/log/* | sort -h | tail -10'
```

Logs and old panel backups are usually the answer. A customer's `public_html` is
never the answer — do not delete customer data to recover disk.

## Panel API down

Placement already refuses to send new accounts to a node that fails its health
check, so new orders will queue rather than fail. Restart the panel's service
before anything more drastic.

## Licence

A lapsed cPanel or DirectAdmin licence presents as authentication failures. The
platform records that as `BLOCKED_LICENCE` rather than as an outage, because it
is a purchasing problem, not an engineering one.

## What not to do

Do not reinstall the panel to fix an API problem. Do not clear customer files.
