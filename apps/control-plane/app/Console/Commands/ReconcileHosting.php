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
            'Hosting reconciliation: %d nodes checked, %d accounts compared, %d disagreements recorded, %d listings not read, %d nodes failed.',
            $outcome['nodes'],
            $outcome['accounts'],
            $outcome['drifts'],
            $outcome['unread'],
            $outcome['failed'],
        ));

        /*
         * A node whose reconciliation failed — its adapter or the platform's
         * own database, not the panel's answer — fails the run. Before a
         * failure was kept on the node such a failure left the command as an
         * exception, and the scheduler saw a failed run; it still does, now
         * after every other node has been compared.
         *
         * A listing the adapter refused, or a panel that did not answer, does
         * not: that is the adapter doing its job with an answer it could not
         * use, it is kept on the node and logged as a warning, and it has
         * never failed this command. A panel outage is the node's health
         * sync's to report, not the reconciliation's.
         */
        return $outcome['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
