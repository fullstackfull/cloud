<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Application\Collectors;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Services\IpCapacityReporter;
use Lynomia\Modules\Monitoring\Domain\Contracts\MetricsCollector;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\MetricSample;

/**
 * Address availability and runway, per pool.
 *
 * Runway — days of headroom at the rate addresses are actually being consumed —
 * is the number worth alerting on, and IpCapacityReporter already knows how to
 * compute it. It is deliberately not used here: it answers per pool and per
 * subnet, so covering the fleet means one call per pool and O(pools) queries
 * every fifteen seconds. The same arithmetic is done in two GROUP BY queries
 * instead, and the observation window is taken from that class so the two
 * cannot drift apart into two different definitions of "runway".
 *
 * A pool with no measured consumption reports +Inf rather than a large number.
 * Prometheus handles infinity natively and `runway < 30` is simply false for
 * it, whereas inventing "9999 days" would put a reassuring figure on a
 * dashboard whose real meaning is "we have no idea".
 */
final readonly class IpamCollector implements MetricsCollector
{
    public function name(): string
    {
        return 'ipam';
    }

    public function collect(): array
    {
        $observationDays = IpCapacityReporter::DEFAULT_OBSERVATION_DAYS;

        $available = $this->availableByPool();
        $assignments = $this->assignmentsByPool($observationDays);

        $availableSamples = [];
        $runwaySamples = [];

        foreach ($available as $pool => $free) {
            $rate = ($assignments[$pool] ?? 0) / $observationDays;

            $availableSamples[] = MetricSample::of(['pool' => $pool], $free);
            $runwaySamples[] = MetricSample::of(
                ['pool' => $pool],
                $rate > 0.0 ? $free / $rate : INF,
            );
        }

        return [
            Metric::gauge(
                'lynomia_ip_pool_available',
                'Addresses in the available state, per pool.',
                $availableSamples,
            ),
            Metric::gauge(
                'lynomia_ip_pool_runway_days',
                'Days until a pool is exhausted at its measured consumption rate. +Inf when no consumption has been observed.',
                $runwaySamples,
            ),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function availableByPool(): array
    {
        /*
         * Left joins so a pool with no subnets, or a subnet with no seeded
         * addresses, still produces a row with zero. A newly created pool that
         * silently had no series would be a pool nobody was watching.
         */
        /** @var array<string, int> $counts */
        $counts = DB::table('ip_pools as p')
            ->leftJoin('subnets as s', 's.ip_pool_id', '=', 'p.id')
            ->leftJoin('ip_addresses as a', 'a.subnet_id', '=', 's.id')
            ->selectRaw(
                'p.slug as pool, count(a.id) filter (where a.status = ?) as available',
                [IpAddressStatus::Available->value],
            )
            ->groupBy('p.slug')
            ->pluck('available', 'pool')
            ->map(static fn (mixed $available): int => (int) $available)
            ->all();

        return $counts;
    }

    /**
     * Addresses handed out per pool over the observation window.
     *
     * Measured from ip_assignments rather than from the current status counts,
     * because status is a snapshot and consumption is a flow: a pool that
     * churned a hundred addresses this fortnight and released ninety looks
     * static from the counts, and is not.
     *
     * A separate query from the availability one on purpose. Joining
     * ip_assignments into that query would multiply a row per historical
     * assignment and inflate every availability count, and the count(distinct)
     * needed to undo that costs more than the second query does.
     *
     * @return array<string, int>
     */
    private function assignmentsByPool(int $observationDays): array
    {
        /** @var array<string, int> $counts */
        $counts = DB::table('ip_pools as p')
            ->join('subnets as s', 's.ip_pool_id', '=', 'p.id')
            ->join('ip_addresses as a', 'a.subnet_id', '=', 's.id')
            ->join('ip_assignments as ia', 'ia.ip_address_id', '=', 'a.id')
            ->where('ia.assigned_at', '>=', now()->subDays($observationDays))
            ->selectRaw('p.slug as pool, count(*) as assignments')
            ->groupBy('p.slug')
            ->pluck('assignments', 'pool')
            ->map(static fn (mixed $assignments): int => (int) $assignments)
            ->all();

        return $counts;
    }
}
