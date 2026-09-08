<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Backups\Application\Actions\ReconcileRunningBackups;

/**
 * Asks the backup provider what became of the tasks the platform is waiting on.
 *
 * Every five minutes. A backup that has finished is not finished as far as this
 * platform is concerned until somebody asks, and a customer looking at a backup
 * that says "running" an hour after it completed has been told something false.
 * Five minutes also bounds how long a failed backup goes unnoticed, which
 * matters more: a backup nobody knows failed is a backup somebody is relying on.
 */
final class ReconcileBackups extends Command
{
    protected $signature = 'backups:reconcile
        {--limit=200 : The most backups to poll in this run}';

    protected $description = 'Reconcile in-flight backups with their provider';

    public function handle(ReconcileRunningBackups $reconcile): int
    {
        $sweep = $reconcile->execute(limit: (int) $this->option('limit'));

        $this->line(json_encode([
            'command' => 'backups:reconcile',
            'considered' => $sweep->considered,
            'settled' => $sweep->settled,
            'failed' => $sweep->failed,
        ], JSON_THROW_ON_ERROR));

        /*
         * A provider that cannot be reached fails every row in the run, so this
         * exit code is how an operator finds out the backup provider is down —
         * which is a great deal more useful than the individual log lines.
         */
        return $sweep->failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
