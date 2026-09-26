<?php

declare(strict_types=1);

/*
 * One plan change, in its own process, released at the same instant as its
 * rivals.
 *
 * Run by APlanChangeClaimsItsUnitUnderTheLockTest, for the reason
 * Orders/place_order_racer.php exists: two statements on one connection are
 * serialised by definition, so a race for the last unit of a plan cannot be
 * shown from one process.
 *
 * The barrier is PostgreSQL advisory lock 424243. The test holds it
 * exclusively while it starts every racer; each racer blocks taking it in
 * shared mode and all are woken by the single unlock.
 *
 * Arguments: subscription id, plan id, price id. Writes one line of JSON.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Lynomia\Modules\Subscriptions\Application\Actions\ApplyPlanChange;
use Lynomia\Modules\Subscriptions\Domain\Enums\PlanChangeRefusal;
use Lynomia\Modules\Subscriptions\Domain\Exceptions\PlanChangeRefusedException;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

require __DIR__.'/../../../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$subscriptionId, $planId, $priceId] = [$argv[1], $argv[2], $argv[3]];

$subscription = Subscription::query()->findOrFail($subscriptionId);
$plan = Plan::query()->findOrFail($planId);
$price = PlanPrice::query()->findOrFail($priceId);

// Queue behind the starter's pistol.
DB::select('SELECT pg_advisory_lock_shared(424243)');

$outcome = ['accepted' => false, 'code' => null, 'refusals' => []];

try {
    $app->make(ApplyPlanChange::class)->execute($subscription, $plan, $price);
    $outcome = ['accepted' => true, 'code' => null, 'refusals' => []];
} catch (PlanChangeRefusedException $e) {
    $outcome = [
        'accepted' => false,
        'code' => $e->errorCode(),
        'refusals' => array_map(static fn (PlanChangeRefusal $r): string => $r->value, $e->refusals),
    ];
} catch (DomainException $e) {
    $outcome = ['accepted' => false, 'code' => $e->errorCode(), 'refusals' => []];
} catch (Throwable $e) {
    $outcome = ['accepted' => false, 'code' => $e::class, 'refusals' => []];
}

echo json_encode($outcome, JSON_THROW_ON_ERROR), PHP_EOL;
