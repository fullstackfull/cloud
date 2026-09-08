<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Infrastructure\Queries;

use Lynomia\Modules\Vps\Infrastructure\Models\VmReinstall;

/**
 * The most recent reinstall for each of a set of machines.
 *
 * Batched for the same reason the address query is: asked per machine this
 * would be a query per row on the list endpoint, which is invisible until a
 * customer has forty servers and then is the slowest page on the portal.
 *
 * "Most recent" is resolved in PHP rather than with a window function because
 * the set is one page of machines, and a correlated subquery here would be a
 * clever way to make the same query harder to read for no measurable gain.
 */
final class LatestMachineReinstalls
{
    /**
     * @param  list<string>  $machineIds
     * @return array<string, VmReinstall>
     */
    public static function forMachines(array $machineIds): array
    {
        if ($machineIds === []) {
            return [];
        }

        $operations = VmReinstall::query()
            ->whereIn('virtual_machine_id', $machineIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $latest = [];

        foreach ($operations as $operation) {
            $machineId = $operation->virtual_machine_id;

            // First wins: the query is already newest-first.
            $latest[$machineId] ??= $operation;
        }

        return $latest;
    }
}
