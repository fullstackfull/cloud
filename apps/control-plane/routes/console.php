<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
 * The platform's clock.
 *
 * Everything in this file drives work that nothing else can start. An order is
 * placed by a customer and a payment is announced by a provider, but a
 * subscription renews because a date passed, a cancellation takes effect
 * because a date passed, a suspended service is terminated because a date
 * passed, and a backup finishes without telling anybody. If nothing runs on a
 * timer, none of it happens — and for the whole of this build nothing did: the
 * deployment installed a `schedule:run` cron and the application scheduled
 * nothing for it to run, so the cron fired every minute and did nothing at all.
 *
 * Two protections on every entry:
 *
 *  - withoutOverlapping, because these sweeps take row locks and a run that
 *    starts while the previous one is still working doubles the lock
 *    contention to do work that is already being done. The lock is released
 *    automatically after the stated expiry so a killed process cannot wedge
 *    the schedule permanently.
 *  - onOneServer, because the control plane runs on more than one application
 *    server in any deployment worth calling production, and every one of them
 *    has the same cron. Without this, each sweep would run once per host.
 *
 * Output goes to the log rather than to a mailbox: each command prints one JSON
 * line, which is what the structured log stack is for.
 */

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('subscriptions:renew')
    ->hourly()
    ->withoutOverlapping(30)
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/schedule.log'));

Schedule::command('subscriptions:sweep')
    ->hourly()
    ->withoutOverlapping(30)
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/schedule.log'));

/*
 * Reconciliation fans out: this command only dispatches one job per cluster, so
 * a hypervisor that is down delays nobody else and no single process holds a
 * conversation with the whole estate. Read-only at every provider — drift is
 * recorded and alerted on, never silently repaired.
 */
Schedule::command('infrastructure:reconcile')
    ->everyThirtyMinutes()
    ->withoutOverlapping(25)
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/schedule.log'));

/*
 * More often than reconciliation, because a stuck provisioning job is a
 * customer waiting for a machine while nothing at all is happening.
 */
Schedule::command('provisioning:detect-stale')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/schedule.log'));

Schedule::command('backups:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/schedule.log'));
