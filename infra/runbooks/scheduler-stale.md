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
php artisan lynomia:scheduled-commands --json | jq '.[] | select(.consecutive_failures > 0)'
journalctl -u lynomia-scheduler --since '6 hours ago' | tail -60
```

The alert names the specific command. Start there rather than restarting
everything.

## What to do

If the timer is inactive, start it and find out why it stopped — a timer that
stopped once will stop again.

If one command is failing repeatedly, run it by hand and read the error:

```bash
php artisan <the command> --dry-run
```

Most repeat failures are a provider being unreachable, not a bug in the command.
Check the provider's runbook before changing code.

## Catching up

Commands are written to be safe to run again, and skip what they already did.
Running a missed sweep by hand is fine. Running it four times because you were
not sure is also fine.

## What not to do

Do not disable a failing scheduled command to silence the alert. The alert is
the only thing telling you that renewals stopped.
