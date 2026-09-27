<?php

declare(strict_types=1);

/*
 * One inventory sync, in its own process.
 *
 * Run by AnInventorySyncDoesNotDeadlockAReservationTest. A deadlock cannot be
 * shown from one process: one connection never waits on itself. So the
 * reservation holds a node's row in the test's process and the sync runs here,
 * against the committed rows, through the shipped action and the shipped
 * simulator.
 *
 * Arguments: cluster id, and the fleet the simulator reports, as JSON. Writes
 * one line of JSON to stdout: whether the sync completed, and the SQLSTATE of
 * what stopped it if it did not.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Lynomia\Modules\Compute\Application\Actions\SyncClusterInventory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;

require __DIR__.'/../../../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$clusterId, $fleet] = [$argv[1], $argv[2]];

config(['compute.fake.nodes' => json_decode($fleet, true, 512, JSON_THROW_ON_ERROR)]);

$outcome = ['completed' => false, 'sqlstate' => null, 'error' => null];

try {
    $app->make(SyncClusterInventory::class)->execute(ComputeCluster::query()->findOrFail($clusterId));
    $outcome['completed'] = true;
} catch (QueryException $e) {
    $outcome['sqlstate'] = (string) ($e->errorInfo[0] ?? $e->getCode());
    $outcome['error'] = substr($e->getMessage(), 0, 300);
} catch (Throwable $e) {
    $outcome['error'] = $e::class.': '.substr($e->getMessage(), 0, 300);
}

echo json_encode($outcome, JSON_THROW_ON_ERROR), PHP_EOL;
