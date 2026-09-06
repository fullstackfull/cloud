<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Domain\Services;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\ValueObjects\CapacitySnapshot;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;

/**
 * Answers "how much address space is left, and how long will it last".
 *
 * The counts are the easy half. The half that matters is runway: days of
 * headroom at the rate addresses are actually being consumed, measured from
 * the assignment history rather than assumed.
 *
 * A fixed threshold cannot do this job. "Alert below 10% free" is a decision
 * about how much time an operator has, disguised as a decision about a
 * percentage — and the amount of time it buys changes with the business. At
 * ten orders a week, 10% of a /24 is months of comfort. At a hundred, it is
 * four days, and the alert fires far too late to be useful, because acquiring
 * more address space is not a purchase, it is an RIR justification and a
 * routing change measured in weeks. Runway restates the same measurement in
 * the unit the decision is actually made in.
 */
final readonly class IpCapacityReporter
{
    /**
     * How far back the allocation rate is measured.
     *
     * Two weeks smooths out the weekly shape of demand — orders arrive on
     * working days — without being so long that a recent launch is averaged
     * away into invisibility.
     */
    public const int DEFAULT_OBSERVATION_DAYS = 14;

    public function forSubnet(Subnet $subnet, int $observationDays = self::DEFAULT_OBSERVATION_DAYS): CapacitySnapshot
    {
        return $this->snapshot(
            scopeType: 'subnet',
            scopeId: (string) $subnet->getKey(),
            label: $subnet->cidr,
            subnetIds: [(string) $subnet->getKey()],
            observationDays: $observationDays,
        );
    }

    public function forPool(IpPool $pool, int $observationDays = self::DEFAULT_OBSERVATION_DAYS): CapacitySnapshot
    {
        return $this->snapshot(
            scopeType: 'pool',
            scopeId: (string) $pool->getKey(),
            label: $pool->slug,
            subnetIds: $this->subnetIdsOf($pool),
            observationDays: $observationDays,
        );
    }

    /**
     * Every subnet in a pool, reported separately.
     *
     * A pool that is 40% free can still be unable to place a service: address
     * space is only fungible within a subnet, because a VM's netmask and
     * gateway come from the subnet it sits in. The per-subnet breakdown is
     * what shows the one full subnet hiding inside a healthy pool.
     *
     * @return list<CapacitySnapshot>
     */
    public function forPoolSubnets(IpPool $pool, int $observationDays = self::DEFAULT_OBSERVATION_DAYS): array
    {
        return Subnet::query()
            ->where('ip_pool_id', $pool->getKey())
            ->orderBy('cidr')
            ->get()
            ->map(fn (Subnet $subnet): CapacitySnapshot => $this->forSubnet($subnet, $observationDays))
            ->all();
    }

    /**
     * @param  list<string>  $subnetIds
     */
    private function snapshot(
        string $scopeType,
        string $scopeId,
        string $label,
        array $subnetIds,
        int $observationDays,
    ): CapacitySnapshot {
        $observationDays = max(1, $observationDays);
        $counts = $this->countsByStatus($subnetIds);
        $available = $counts[IpAddressStatus::Available->value] ?? 0;

        $rate = $this->allocationsPerDay($subnetIds, $observationDays);

        return new CapacitySnapshot(
            scopeType: $scopeType,
            scopeId: $scopeId,
            label: $label,
            total: array_sum($counts),
            available: $available,
            reserved: $counts[IpAddressStatus::Reserved->value] ?? 0,
            assigned: $counts[IpAddressStatus::Assigned->value] ?? 0,
            quarantined: $counts[IpAddressStatus::Quarantined->value] ?? 0,
            unavailable: $counts[IpAddressStatus::Unavailable->value] ?? 0,
            allocationsPerDay: $rate,
            /*
             * Null, not infinity and not a large number: a scope with no
             * measured demand has no runway to report, and inventing one
             * ("9999 days") would put a reassuring number on a dashboard that
             * is really saying "we have no idea".
             */
            runwayDays: $rate > 0.0 ? round($available / $rate, 1) : null,
            observationDays: $observationDays,
        );
    }

    /**
     * @param  list<string>  $subnetIds
     * @return array<string, int>
     */
    private function countsByStatus(array $subnetIds): array
    {
        if ($subnetIds === []) {
            return [];
        }

        /** @var array<string, int> $counts */
        $counts = DB::table('ip_addresses')
            ->selectRaw('status, count(*) as total')
            ->whereIn('subnet_id', $subnetIds)
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        return $counts;
    }

    /**
     * Addresses assigned per day over the observation window.
     *
     * Measured from ip_assignments rather than from the current status counts,
     * because status is a snapshot and consumption is a flow: a subnet that
     * churned a hundred addresses this fortnight and released ninety of them
     * looks static, and is not.
     *
     * @param  list<string>  $subnetIds
     */
    private function allocationsPerDay(array $subnetIds, int $observationDays): float
    {
        if ($subnetIds === []) {
            return 0.0;
        }

        $assignments = DB::table('ip_assignments')
            ->join('ip_addresses', 'ip_addresses.id', '=', 'ip_assignments.ip_address_id')
            ->whereIn('ip_addresses.subnet_id', $subnetIds)
            ->where('ip_assignments.assigned_at', '>=', now()->subDays($observationDays))
            ->count();

        return round($assignments / $observationDays, 4);
    }

    /**
     * @return list<string>
     */
    private function subnetIdsOf(IpPool $pool): array
    {
        /** @var list<string> $ids */
        $ids = Subnet::query()
            ->where('ip_pool_id', $pool->getKey())
            ->pluck('id')
            ->map(static fn (string $id): string => trim($id))
            ->all();

        return $ids;
    }
}
