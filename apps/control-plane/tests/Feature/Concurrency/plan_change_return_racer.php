<?php

declare(strict_types=1);

/*
 * One side of the return of a paid plan change whose service ended, in its
 * own process.
 *
 * Run by TheCloseAndTheWindUpReturnAPaidChangeOnceTest, for the reason
 * money_lock_racer.php exists: two statements on one connection are
 * serialised by definition, so a race between two actions cannot be shown
 * from one process.
 *
 * Arguments: the side ("close" - CloseAJobWhoseServiceEnded on the job - or
 * "wind-up" - EndTheSubscriptionWithItsService heard for the service), and
 * the job's id and the service's id. Writes one line of JSON: whether the
 * action completed, and what it threw when it did not.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Lynomia\Modules\Provisioning\Application\Actions\CloseAJobWhoseServiceEnded;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\Events\ServiceStatusChanged;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Subscriptions\Application\Listeners\EndTheSubscriptionWithItsService;

require __DIR__.'/../../../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$side, $jobId, $serviceId] = [$argv[1], $argv[2], $argv[3]];

$outcome = ['completed' => false, 'error' => null];

try {
    if ($side === 'close') {
        $app->make(CloseAJobWhoseServiceEnded::class)->execute(ProvisioningJob::query()->findOrFail($jobId), 'racer');
    } else {
        $app->make(EndTheSubscriptionWithItsService::class)->handle(new ServiceStatusChanged(
            serviceId: $serviceId,
            orderId: null,
            from: ServiceStatus::Active,
            to: ServiceStatus::Terminated,
        ));
    }

    $outcome['completed'] = true;
} catch (Throwable $e) {
    $outcome = ['completed' => false, 'error' => $e::class.': '.$e->getMessage()];
}

echo json_encode($outcome, JSON_THROW_ON_ERROR), PHP_EOL;
