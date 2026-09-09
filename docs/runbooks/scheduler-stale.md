# The scheduler has stopped or keeps failing

## What you are seeing

`ScheduledCommandStale` or `ScheduledCommandFailingRepeatedly`.

## What it means

Renewals, expiry sweeps, reconciliation, retention and backup scheduling all run
here. A stale scheduler is silent: nothing errors, things simply stop happening.
Domains stop being renewed. Grace periods stop advancing. This is the quietest
serious failure in the platform.

## Check first

```bash
systemctl status lynomia-scheduler.timer
systemctl list-timers lynomia-scheduler.timer
php artisan schedule:list
journalctl -u lynomia-scheduler --since '6 hours ago' | tail -60

# Which command is failing, and for how long, comes from the metrics endpoint —
# lynomia_scheduled_command_consecutive_failures and
# lynomia_scheduled_command_last_success_timestamp_seconds, by command label.
curl -sS -H "Authorization: Bearer $(cat /etc/prometheus/secrets/metrics-token)" \
     https://<control plane>/metrics | grep lynomia_scheduled_command
```

The alert names the specific command. Start there rather than restarting
everything.

## What to do

If the timer is inactive, start it and find out why it stopped — a timer that
stopped once will stop again.

If one command is failing repeatedly, run it by hand and read the error:

```bash
php artisan schedule:test        # pick the failing command from the list
php artisan <the command>        # or run it directly and read the output
```

None of the platform's scheduled commands takes a `--dry-run`; most take a
`--limit`, so run one against a small limit first if you want a smaller blast
radius. Most repeat failures are a provider being unreachable rather than a bug
in the command — check the provider's runbook before changing code.

## Catching up

Commands are written to be safe to run again, and skip what they already did.
Running a missed sweep by hand is fine. Running it four times because you were
not sure is also fine.

```bash
php artisan domains:sweep          # renewals, expiry, grace, redemption
php artisan subscriptions:renew
php artisan services:end-expired
php artisan backups:enforce-retention
php artisan ipam:reclaim
```

## What not to do

Do not disable a failing scheduled command to silence the alert. The alert is
the only thing telling you that renewals stopped.
