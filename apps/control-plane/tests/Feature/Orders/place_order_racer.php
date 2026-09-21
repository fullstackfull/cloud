<?php

declare(strict_types=1);

/*
 * One checkout, in its own process, released at the same instant as its rivals.
 *
 * Run by FiniteCapacityIsClaimedNotGuessedTest. It exists because a race for
 * finite capacity cannot be demonstrated from one process: two statements on
 * one connection are serialised by definition, and a sequential loop commits
 * each order before the next one reads, which is the opposite of the condition
 * that oversells.
 *
 * The barrier is a PostgreSQL advisory lock rather than a sleep. The test
 * holds lock 424242 exclusively while it starts every racer; each racer blocks
 * trying to take it in shared mode, and they are all woken by the single
 * unlock. Nothing here waits for a fixed duration.
 *
 * Arguments: customer id, plan id, and the idempotency key (or "-" for none).
 * Writes one line of JSON to stdout so the parent can count outcomes.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

require __DIR__.'/../../../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$customerId, $planId, $key] = [$argv[1], $argv[2], $argv[3] ?? '-'];

// Queue behind the starter's pistol.
DB::select('SELECT pg_advisory_lock_shared(424242)');

$outcome = ['accepted' => false, 'code' => null, 'order' => null];

try {
    $order = $app->make(PlaceOrder::class)->execute(
        Customer::query()->findOrFail($customerId),
        new CheckoutRequest(
            lines: [new CheckoutLine($planId, 1)],
            billingPeriod: BillingPeriod::Monthly,
            couponCode: null,
            idempotencyKey: $key === '-' ? null : $key,
        ),
    );

    $outcome = ['accepted' => true, 'code' => null, 'order' => (string) $order->getKey()];
} catch (DomainException $e) {
    $outcome = ['accepted' => false, 'code' => $e->errorCode(), 'order' => null];
} catch (Throwable $e) {
    $outcome = ['accepted' => false, 'code' => $e::class, 'order' => null];
}

echo json_encode($outcome, JSON_THROW_ON_ERROR), PHP_EOL;
