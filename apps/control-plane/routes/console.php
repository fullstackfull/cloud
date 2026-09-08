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
 * Wallets hold customers' money, and their balance is cached because it is
 * read on every page that mentions a total. Nightly rather than hourly: a
 * cache that has drifted stays drifted, and the check reads every wallet.
 *
 * It corrects nothing — see the command for why.
 */
Schedule::command('wallet:verify')
    ->dailyAt('03:20')
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
 * The hosting fleet's own check-up.
 *
 * Every twelve hours by default, from the licence recheck interval: the
 * expensive part is the vendor's licence server, and disk and load move slowly
 * enough on a shared node that reading them twice a day is the right trade.
 * Until this existed the scheduler placed accounts using a snapshot of the
 * fleet taken by hand at seed time — including whether each panel was still
 * licensed.
 */
Schedule::command('hosting:sync-nodes')
    ->cron(sprintf('17 */%d * * *', max(1, (int) config('hosting.licence.recheck_hours', 12))))
    ->withoutOverlapping(30)
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

/*
 * Hourly, not every thirty minutes like the hypervisors.
 *
 * A BMC is slow — a full inventory read is seconds per machine, not
 * milliseconds — and the facts it reports change on the timescale of somebody
 * physically opening a chassis. Asking every controller in the fleet twice an
 * hour would spend real time on every machine to learn nothing, and the
 * controllers themselves are not built for it.
 */
Schedule::command('dedicated:sync-inventory')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/schedule.log'));

Schedule::command('backups:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/schedule.log'));

/*
 * Retention.
 *
 * Hourly rather than more often, because the unit it works in is days and the
 * grace period between marking and acting is an hour: running it every five
 * minutes would make the grace period the only thing standing between a
 * mis-click and a destroyed backup, and would ask a datastore to prune twelve
 * times as often for no gain.
 */
Schedule::command('backups:enforce-retention')
    ->hourlyAt(35)
    ->withoutOverlapping(30)
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/schedule.log'));

/*
 * Inventory.
 *
 * Six-hourly. It reads every datastore listing for every machine that has
 * backups, which is the most expensive read this platform makes of a provider,
 * and the drift it looks for — an archive that has silently gone — does not
 * appear and disappear within a day.
 */
Schedule::command('backups:reconcile-inventory')
    ->cron('45 */6 * * *')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/schedule.log'));

/*
 * Provider task confirmation, every five minutes.
 *
 * Frequent, because what it is closing is the window between the platform
 * telling a customer their server is ready and the hypervisor finishing the
 * build — and the failure it catches is the platform billing for a machine
 * that does not exist. Cheap, too: one read per unconfirmed task, with an
 * exponential backoff per task on top, so a fleet of slow builds does not
 * become a fleet of API calls.
 */
Schedule::command('compute:poll-tasks')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/schedule.log'));

/*
 * Hosting reconciliation, every four hours at :50.
 *
 * Less often than the VPS reconciler and more often than the backup one. Each
 * pass is a full account listing per node over a panel API with its own rate
 * limit — expensive — but what it looks for is a customer's website not being
 * served under a row that says it is, and a day of that is a day of a customer
 * ringing support about something the platform could have told them.
 *
 * Nothing it finds is repaired automatically. See ReconcileHostingNodes.
 */
Schedule::command('hosting:reconcile')
    ->cron('50 */4 * * *')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/schedule.log'));

/*
 * The end of a service's life, daily at 03:20.
 *
 * Daily rather than hourly because the unit it works in is days: a retention
 * window is measured in them, and running this twenty-four times a day would
 * be twenty-three passes finding nothing. Early morning because what it does
 * is irreversible, and a mistake found at nine is better than one found at
 * five past nine.
 *
 * It ends only services a customer cancelled. Non-payment keeps a person's
 * name on it — see EndExpiredServices.
 */
Schedule::command('services:end-expired')
    ->dailyAt('03:20')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/schedule.log'));

/*
 * DNS reconciliation, every two hours.
 *
 * More often than the backup sweep and for the opposite reason: this read is
 * cheap — one listing per stale zone, bounded by `dns.reconcile_batch` — and
 * what it looks for is a name that has stopped resolving while the portal says
 * it is live. Six hours of that is six hours of a customer's site being down
 * while their control panel says everything is fine.
 *
 * Nothing it does writes to a zone. See ReconcileZones.
 */
Schedule::command('dns:reconcile')
    ->cron('20 */2 * * *')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/schedule.log'));

/*
 * Address reclamation, every ten minutes.
 *
 * Both directions out of a pool were one-way: a reservation whose build never
 * finished stayed reserved, and an address quarantined by a timed-out call
 * stayed quarantined. A pool is finite, and the symptom of running one dry is
 * not "the pool is empty" — it is orders failing to place, days later, for
 * reasons nobody connects to builds that failed weeks before.
 *
 * Ten minutes rather than hourly: this is cheap, local, and the thing it
 * returns is the thing an order needs to complete.
 */
Schedule::command('ipam:reclaim')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/schedule.log'));

/*
 * Hosting usage, hourly.
 *
 * Every account's disk and bandwidth read zero, for ever, because nothing ran
 * the sync: an account filling a node looked exactly like an empty one until
 * the node ran out of space. Hourly is the cadence the panels themselves
 * recompute at, so asking more often would return the same numbers.
 */
Schedule::command('hosting:sync-usage')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/schedule.log'));

/*
 * Payment reconciliation, every five minutes.
 *
 * The webhook is the primary path and this is the backstop for when one is
 * lost: a customer who paid, closed the tab, and whose order would otherwise
 * sit unfulfilled for ever. ConfirmPaymentFromReturn had no caller at all, so
 * a dropped webhook was permanent.
 *
 * It only looks at attempts older than its grace period, so it does not race
 * the webhook and double every provider call the platform makes.
 */
Schedule::command('payments:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/schedule.log'));
