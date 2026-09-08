<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\SharedHosting\Application\Actions\ReconcileHostingNodes;

/**
 * Compare what the platform believes about its hosting nodes with what the
 * panels are holding.
 *
 * Read-only in both directions: nothing here creates, deletes, suspends or
 * adopts an account. See the action for why.
 */
final class ReconcileHosting extends Command
{
    protected $signature = 'hosting:reconcile';

    protected $description = 'Compare recorded hosting accounts with what each panel is serving';

    public function handle(ReconcileHostingNodes $reconcile): int
    {
        $outcome = $reconcile->execute();

        $this->info(sprintf(
            'Hosting reconciliation: %d nodes checked, %d accounts compared, %d disagreements recorded.',
            $outcome['nodes'],
            $outcome['accounts'],
            $outcome['drifts'],
        ));

        return self::SUCCESS;
    }
}
