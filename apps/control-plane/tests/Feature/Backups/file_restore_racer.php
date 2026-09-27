<?php

declare(strict_types=1);

/*
 * One file restore request, in its own process.
 *
 * Run by AFileRestoreWaitsForADeletionThatHoldsTheArchiveTest. A lock cannot
 * be shown from one process: one connection never waits on itself, and a
 * single PHP thread blocked on a row another of its own connections holds
 * never gets to release it. So the deletion holds the archive's row in the
 * test's process and this request runs here, against the committed rows,
 * through the shipped action and the shipped simulator.
 *
 * Arguments: backup id, machine id. Writes one line of JSON to stdout: whether
 * the request was accepted, and the refusal's code if it was not.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Lynomia\Modules\Backups\Application\Actions\RestoreBackupFiles;
use Lynomia\Modules\Backups\Domain\ValueObjects\BackupPath;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

require __DIR__.'/../../../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$backupId, $machineId] = [$argv[1], $argv[2]];

$outcome = ['accepted' => false, 'code' => null, 'state' => null];

try {
    $machine = VirtualMachine::query()->findOrFail($machineId);

    $restore = $app->make(RestoreBackupFiles::class)->execute(
        Backup::query()->findOrFail($backupId),
        $machine,
        [BackupPath::of('/etc/hostname')],
        $machine->hostname,
    );

    $outcome = ['accepted' => true, 'code' => null, 'state' => $restore->state->value];
} catch (DomainException $e) {
    $outcome = ['accepted' => false, 'code' => $e->errorCode(), 'state' => null];
} catch (Throwable $e) {
    $outcome = ['accepted' => false, 'code' => $e::class.': '.$e->getMessage(), 'state' => null];
}

echo json_encode($outcome, JSON_THROW_ON_ERROR), PHP_EOL;
