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
 *
 * ---------------------------------------------------------------------------
 * Which registrars the orphan scan asks
 * ---------------------------------------------------------------------------
 *
 * The ones that may exist here: {@see DomainRegistrarFactory::availableDrivers()},
 * not every driver the build contains. The build always contains the fake, and
 * the fake refuses to be constructed in production — so walking the full list
 * made this command throw on every production run, *after* the settle pass had
 * landed and reported. A scheduled task that is red every time while doing most
 * of its job is the shape that teaches an operator to ignore it.
 *
 * There is deliberately no try/catch around the scan. A registrar that throws
 * while being built or asked is broken rather than absent, and a sweep that
 * skipped it would hide exactly the failure worth seeing. Nor is the narrowing
 * silent: the last line names how many of the build's registrars were asked
 * and which, because "found no orphans" and "did not look everywhere" must not
 * read the same.
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

        $asked = DomainRegistrarFactory::availableDrivers();
        $orphans = 0;

        foreach ($asked as $driver) {
            $orphans += $reconcile->findOrphans($driver);
        }

        $this->info(sprintf(
            '%d names held at a registrar with no row here, across %d of %d registrars (%s).',
            $orphans,
            count($asked),
            count(DomainRegistrarFactory::drivers()),
            $asked === [] ? 'none' : implode(', ', $asked),
        ));

        return self::SUCCESS;
    }
}
