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
 *  - change: the plan id, the price id and the id of the user making it.
 * Writes one line of JSON: whether the action completed, and the class and
 * SQLSTATE of what it threw when it did not.
 */

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
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

[$action, $subscriptionId] = [$argv[1], $argv[2]];

$subscription = Subscription::query()->findOrFail($subscriptionId);

$outcome = ['completed' => false, 'error' => null, 'sqlstate' => null];

try {
    match ($action) {
        'renew' => $app->make(RenewSubscription::class)->execute($subscription, CarbonImmutable::parse($argv[3])),
        'cancel' => $app->make(CancelSubscription::class)->execute($subscription, immediately: true),
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
    $outcome = ['completed' => false, 'error' => $e::class.': '.$e->getMessage(), 'sqlstate' => null];
}

echo json_encode($outcome, JSON_THROW_ON_ERROR), PHP_EOL;
