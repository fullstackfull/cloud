<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Domains\Application\Actions\ReconcileDomains as Reconcile;
use Lynomia\Modules\Domains\Infrastructure\DomainRegistrarFactory;

/**
 * Ask the registries what they hold, and settle what the platform is unsure of.
 *
 * Read-only towards every registrar. It never registers, renews or transfers —
 * which is exactly what makes it safe to run on a clock against the rows the
 * Timeout Rule forbids retrying. See {@see Reconcile}.
 */
final class ReconcileDomains extends Command
{
    protected $signature = 'domains:reconcile {--limit=100 : how many uncertain names to settle in one pass}';

    protected $description = 'Settle uncertain domain registrations against the registry and record orphans';

    public function handle(Reconcile $reconcile): int
    {
        $outcome = $reconcile->execute((int) $this->option('limit'));

        $this->info(sprintf(
            'Domains: %d settled, %d disagreements recorded, %d registrars unreachable.',
            $outcome['settled'],
            $outcome['disagreed'],
            $outcome['unreachable'],
        ));

        $orphans = 0;

        foreach (DomainRegistrarFactory::drivers() as $driver) {
            $orphans += $reconcile->findOrphans($driver);
        }

        $this->info(sprintf('%d names held at a registrar with no row here.', $orphans));

        return self::SUCCESS;
    }
}
