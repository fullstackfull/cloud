<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Backups\Application\Actions\ReconcileBackupInventory as Reconciler;

/**
 * Asks each datastore what it is actually holding.
 *
 * Until this existed, the platform's belief that a customer had four
 * restorable backups rested entirely on its own record of having taken them —
 * and a customer finds out that belief was wrong at the worst possible moment,
 * which is while they are restoring.
 *
 * Read-only. It records drift and settles deletions the provider has since
 * completed; it never removes anything, and never adopts an archive whose
 * provenance nobody knows.
 */
final class ReconcileBackupInventory extends Command
{
    protected $signature = 'backups:reconcile-inventory';

    protected $description = 'Compare each datastore against what the platform believes it holds.';

    public function handle(Reconciler $reconciler): int
    {
        $result = $reconciler->execute();

        $this->info(sprintf(
            'Inventory: %d datastore listings read, %d disagreements recorded, %d deletions confirmed.',
            $result['checked'],
            $result['drifts'],
            $result['settled'],
        ));

        return self::SUCCESS;
    }
}
