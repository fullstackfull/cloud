<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Ipam\Domain\Services\IpCapacityReporter;
use Lynomia\Modules\Ipam\Domain\ValueObjects\CapacitySnapshot;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;

/**
 * What is left in each address pool, and in each subnet inside it.
 *
 * The per-subnet half is the point, and it is why this exists as a command
 * rather than another series on the metrics endpoint. Address space is only
 * fungible within a subnet — a machine's netmask and gateway come from the
 * subnet it sits in — so a pool that is reported 40% free can still be unable
 * to place a service because the one subnet with room is the one nothing may
 * be placed on. The scrape reports pools, because a per-subnet series on every
 * scrape is cardinality that grows with the estate; this reports subnets,
 * because it is run by a person who has just seen a pool look tight.
 *
 *     php artisan ipam:capacity
 *     php artisan ipam:capacity --pool=kw-public-v4 --days=30
 */
final class ReportIpCapacity extends Command
{
    protected $signature = 'ipam:capacity
        {--pool= : Limit the report to one pool, by slug}
        {--days= : How many days of history to measure the consumption rate over}';

    protected $description = 'Report address capacity and runway for each pool, broken down by subnet.';

    public function handle(IpCapacityReporter $reporter): int
    {
        $days = is_numeric($this->option('days'))
            ? max(1, (int) $this->option('days'))
            : IpCapacityReporter::DEFAULT_OBSERVATION_DAYS;

        $slug = $this->option('pool');

        $pools = IpPool::query()
            ->when(is_string($slug) && $slug !== '', fn ($query) => $query->where('slug', $slug))
            ->orderBy('slug')
            ->get();

        if ($pools->isEmpty()) {
            $this->warn('No address pools matched.');

            return self::SUCCESS;
        }

        $tight = 0;

        foreach ($pools as $pool) {
            $summary = $reporter->forPool($pool, $days);

            $this->line('');
            $this->info(sprintf('%s — %s', $pool->slug, $this->describe($summary)));

            $rows = [];

            foreach ($reporter->forPoolSubnets($pool, $days) as $subnet) {
                if ($subnet->needsMoreSpace()) {
                    $tight++;
                }

                $rows[] = [
                    $subnet->label,
                    $subnet->total,
                    $subnet->available,
                    $subnet->assigned,
                    $subnet->quarantined,
                    sprintf('%.1f%%', $subnet->utilisation() * 100),
                    $subnet->runwayDays === null ? 'no consumption' : sprintf('%.0f days', $subnet->runwayDays),
                ];
            }

            $this->table(
                ['Subnet', 'Total', 'Available', 'Assigned', 'Quarantined', 'Used', 'Runway'],
                $rows,
            );
        }

        if ($tight > 0) {
            /*
             * A non-zero exit, so this can be run from a check rather than
             * only read by a person. "The pool is fine and one subnet inside
             * it is nearly full" is exactly the state that is invisible until
             * a customer's order cannot be placed.
             */
            $this->warn(sprintf('%d subnet(s) will run out inside the usual lead time for adding more.', $tight));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function describe(CapacitySnapshot $snapshot): string
    {
        return sprintf(
            '%d of %d free (%.1f%% used), %s',
            $snapshot->available,
            $snapshot->total,
            $snapshot->utilisation() * 100,
            $snapshot->runwayDays === null
                ? 'no consumption measured'
                : sprintf('about %.0f days of runway', $snapshot->runwayDays),
        );
    }
}
