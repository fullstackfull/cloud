<?php

declare(strict_types=1);

/*
 * One subscription-side action, in its own process.
 *
 * Run by ARenewalAndAPlanChangeDoNotDeadlockTest, for the reason
 * money_lock_racer.php exists: a lock-order cycle between two actions cannot
 * be shown from one connection.
 *
 * Arguments: the action, then the subscription's id, then
 *  - renew:  the instant to renew at (ISO 8601);
 *  - cancel: nothing more (an immediate cancellation);
 *  - change: the plan id, the price id and the id of the user making it;
 *  - void:   an invoice id (the operator's VoidInvoice).
 * Optionally paused: with RACER_PAUSE_AFTER (a regular expression) and
 * RACER_PAUSE_LOCK (an advisory lock key) in the environment, the racer stops
 * after the first statement matching the expression until the test releases
 * that advisory lock - a barrier placed inside the action, between two of its
 * own statements.
 * Writes one line of JSON: whether the action completed, and the class and
 * SQLSTATE of what it threw when it did not.
 */

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Application\Actions\VoidInvoice;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Subscriptions\Application\Actions\ApplyPlanChange;
use Lynomia\Modules\Subscriptions\Application\Actions\CancelSubscription;
use Lynomia\Modules\Subscriptions\Application\Actions\RenewSubscription;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

require __DIR__.'/../../../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// A downgrade queues a resize; nothing here is about the queue.
config(['queue.default' => 'null']);

// For `void` the second argument is the invoice's id.
[$action, $subscriptionId] = [$argv[1], $argv[2]];

$subscription = $action === 'void' ? null : Subscription::query()->findOrFail($subscriptionId);

$pauseAfter = getenv('RACER_PAUSE_AFTER');
$pauseLock = getenv('RACER_PAUSE_LOCK');

if (is_string($pauseAfter) && $pauseAfter !== '' && is_string($pauseLock) && $pauseLock !== '') {
    $paused = false;

    DB::listen(static function (QueryExecuted $query) use (&$paused, $pauseAfter, $pauseLock): void {
        if ($paused || preg_match($pauseAfter, $query->sql) !== 1) {
            return;
        }

        $paused = true;
        DB::select('SELECT pg_advisory_lock_shared(?)', [(int) $pauseLock]);
        DB::select('SELECT pg_advisory_unlock_shared(?)', [(int) $pauseLock]);
    });
}

$outcome = ['completed' => false, 'error' => null, 'sqlstate' => null];

try {
    match ($action) {
        'renew' => $app->make(RenewSubscription::class)->execute($subscription, CarbonImmutable::parse($argv[3])),
        'cancel' => $app->make(CancelSubscription::class)->execute($subscription, immediately: true),
        'void' => $app->make(VoidInvoice::class)->execute(Invoice::query()->findOrFail($subscriptionId), 'an operator withdrew it'),
        'change' => $app->make(ApplyPlanChange::class)->execute(
            $subscription,
            Plan::query()->findOrFail($argv[3]),
            PlanPrice::query()->findOrFail($argv[4]),
            'raced-change',
            User::query()->findOrFail($argv[5]),
        ),
    };

    $outcome['completed'] = true;
} catch (QueryException $e) {
    $outcome = ['completed' => false, 'error' => $e::class, 'sqlstate' => $e->getCode()];
} catch (Throwable $e) {
    $outcome = ['completed' => false, 'error' => $e::class.': '.$e->getMessage(), 'sqlstate' => str_contains($e->getMessage(), 'SQLSTATE[40P01]') ? '40P01' : null];
}

echo json_encode($outcome, JSON_THROW_ON_ERROR), PHP_EOL;
