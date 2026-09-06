<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;

/**
 * How many machines one customer already has on a node.
 *
 * Its own object for the same reason NodeCapacityPolicy is: two callers ask
 * the question at two different moments — the scheduler while excluding, and
 * ReserveNodeCapacity again under the node's row lock — and two
 * implementations of "already has one here" that disagreed would produce a
 * placement the scheduler allowed and the reservation refused forever, or the
 * reverse, which is worse.
 *
 * Ownership is joined through services rather than read from a column on the
 * machine, because whose machine it is, is a commercial fact that lives with
 * the service. The status filter is deliberate: a terminated or failed
 * service's machine is on its way out and must not keep a node excluded for
 * the customer's next order.
 */
final readonly class CustomerNodeCensus
{
    /**
     * Machines this customer has on each of the given nodes.
     *
     * @param  list<string>  $nodeIds
     * @return array<string, int> Node id to count; nodes with none are absent.
     */
    public function countByNode(?string $customerId, array $nodeIds): array
    {
        if ($customerId === null || $nodeIds === []) {
            return [];
        }

        /** @var array<string, int> $counts */
        $counts = $this->query($customerId)
            ->whereIn('virtual_machines.node_id', $nodeIds)
            ->groupBy('virtual_machines.node_id')
            ->selectRaw('virtual_machines.node_id as node_id, count(*) as vm_count')
            ->pluck('vm_count', 'node_id')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        return $counts;
    }

    /**
     * Machines this customer has on one node.
     *
     * Called inside the transaction that holds the node's row lock, so it sees
     * every reservation that committed before this one took the lock — which
     * is the whole point, and the only moment at which the answer is not
     * already stale.
     */
    public function countOnNode(string $customerId, string $nodeId): int
    {
        return $this->query($customerId)
            ->where('virtual_machines.node_id', $nodeId)
            ->count();
    }

    private function query(string $customerId): Builder
    {
        return DB::table('virtual_machines')
            ->join('services', 'services.id', '=', 'virtual_machines.service_id')
            ->where('services.customer_id', $customerId)
            ->whereNotIn('services.status', [
                ServiceStatus::Terminated->value,
                ServiceStatus::Failed->value,
            ]);
    }
}
