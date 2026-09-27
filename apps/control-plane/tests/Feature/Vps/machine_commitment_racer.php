<?php

declare(strict_types=1);

/*
 * One resize's settle of a machine's commitment, in its own process.
 *
 * Run by ADestroyAndAResizeInTwoWorkersLeaveNothingCommittedTest. A lock
 * cannot be shown to hold from one process: one connection never waits on
 * itself. So the destroy runs in the test's process and the settle a resize
 * makes once the hypervisor has confirmed its shape runs here - the machine
 * read before, as the resize reads it at its start, then restated through
 * the shipped MachineCommitment, against the committed rows.
 *
 * Argument: the machine's id. Writes one line of JSON to stdout: whether the
 * machine was restated, and what stopped it if anything did.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Vps\Application\Services\MachineCommitment;

require __DIR__.'/../../../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$outcome = ['read' => false, 'restated' => null, 'sqlstate' => null, 'error' => null];

try {
    $machine = VirtualMachine::query()->findOrFail($argv[1]);
    $node = ComputeNode::query()->findOrFail($machine->node_id);
    $outcome['read'] = true;

    $commitment = $app->make(MachineCommitment::class);
    $outcome['restated'] = $commitment->restate($machine, $node, $commitment->asRecorded($machine), refuse: false);
} catch (QueryException $e) {
    $outcome['sqlstate'] = (string) ($e->errorInfo[0] ?? $e->getCode());
    $outcome['error'] = substr($e->getMessage(), 0, 300);
} catch (Throwable $e) {
    $outcome['error'] = $e::class.': '.substr($e->getMessage(), 0, 300);
}

echo json_encode($outcome, JSON_THROW_ON_ERROR), PHP_EOL;
