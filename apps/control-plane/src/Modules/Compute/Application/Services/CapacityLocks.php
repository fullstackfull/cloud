<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Application\Services;

use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;

/**
 * Takes node and pool rows in the one order every capacity path keeps
 * (ReserveNodeCapacity's class docblock): `compute_nodes` rows ascending by
 * id, then `compute_storages` rows ascending by id, nothing of the first kind
 * after one of the second. The caller takes the reservation row, when there
 * is one, before it calls this.
 *
 * One statement per row, in an order PHP chose, so the order does not depend
 * on how the database sorts the ids. Used by every path that touches more
 * than one node or pool in a transaction: a move of a commitment
 * (ReserveNodeCapacity), a commitment restated to a machine's shape or node
 * (RestateNodeCommitment), and the inventory sync (SyncClusterInventory).
 */
final readonly class CapacityLocks
{
    /**
     * @param  list<string|null>  $nodeIds  duplicates and nulls are ignored
     * @param  list<string|null>  $storageIds  duplicates and nulls are ignored
     * @return array{nodes: array<string, ComputeNode>, storages: array<string, ComputeStorage>} the locked rows by id; a row that no longer exists is absent
     */
    public function nodesThenPools(array $nodeIds, array $storageIds): array
    {
        $locked = ['nodes' => [], 'storages' => []];

        foreach (self::ascending($nodeIds) as $id) {
            /** @var ComputeNode|null $node */
            $node = ComputeNode::query()->lockForUpdate()->find($id);

            if ($node !== null) {
                $locked['nodes'][$id] = $node;
            }
        }

        foreach (self::ascending($storageIds) as $id) {
            /** @var ComputeStorage|null $storage */
            $storage = ComputeStorage::query()->lockForUpdate()->find($id);

            if ($storage !== null) {
                $locked['storages'][$id] = $storage;
            }
        }

        return $locked;
    }

    /**
     * @param  list<string|null>  $ids
     * @return list<string>
     */
    private static function ascending(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (?string $id): string => (string) $id, $ids),
            static fn (string $id): bool => $id !== '',
        )));
        sort($ids, SORT_STRING);

        return $ids;
    }
}
