<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Dns\Application\Actions\ReconcileZones;

/**
 * Compare the platform's picture of its zones with the zones themselves.
 *
 * Read-only towards the provider, always. See {@see ReconcileZones} for what
 * that costs and why it is worth it.
 */
final class ReconcileDnsZones extends Command
{
    protected $signature = 'dns:reconcile';

    protected $description = 'Compare recorded DNS records with what the provider is serving';

    public function handle(ReconcileZones $reconcile): int
    {
        $outcome = $reconcile->execute();

        $this->info(sprintf(
            'DNS reconciliation: %d zones checked, %d disagreements recorded, %d rows settled.',
            $outcome['zones'],
            $outcome['drifts'],
            $outcome['settled'],
        ));

        return self::SUCCESS;
    }
}
