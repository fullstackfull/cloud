# The scheduler has stopped or keeps failing

## What you are seeing

A scheduled command that has stopped running, or keeps failing: renewals not
happening, sweeps not advancing.

A command that keeps failing raises `ScheduledCommandFailing` (warning, to the
platform channel): it has failed three runs in a row, read from
`lynomia_scheduled_command_consecutive_failures`, for five minutes. Each failed
run counts once, so for `hosting:reconcile`, which runs every four hours, the
alert comes no sooner than eight hours and five minutes after the first failure. The alert
names the command in its `command` label. A success resets the count, so the
alert clears on the first run that succeeds.

A command that has stopped being invoked at all raises nothing: its failure
count simply stops moving. `lynomia_scheduled_command_last_success_timestamp_seconds`
is exported per command and no rule reads it. You get to that case by noticing,
or by checking the timer below.

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

Both series carry the command's name in their `command` label. Start with that
command rather than restarting everything.

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

Do not disable a failing scheduled command to make the failures stop. Its
failures are the only record that renewals stopped, and a disabled command is
one that has stopped being invoked: no alert watches that.

Do not silence `ScheduledCommandFailing` for a command you expect to keep
failing. `hosting:reconcile` and `backups:reconcile` exit non-zero on purpose
when reconciling a node, a backup or a file restore failed. For
`hosting:reconcile` the node list says which (the node's `reconcile_error`).
For `backups:reconcile` there are two log lines, and either one makes the run
exit non-zero. A backup stays in its in-flight state and is named only in the
log line "A backup could not be reconciled with its provider." with its
`backup_id` — it is not in the review queue. A file restore is named in the log
line "A file restore could not be reconciled with its provider." with its
`file_restore_id`. The alert clears once a run succeeds.
