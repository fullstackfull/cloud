<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Infrastructure\Queries;

use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedReinstall;
use Lynomia\Modules\Vps\Infrastructure\Queries\LatestMachineReinstalls;

/**
 * The most recent rebuild for each of a set of machines.
 *
 * Batched for the same reason every other list query here is: asked per
 * machine this is a query per row, invisible until a customer has a rack of
 * them.
 *
 * @see LatestMachineReinstalls
 */
final class LatestServerReinstalls
{
    /**
     * @param  list<string>  $serverIds
     * @return array<string, DedicatedReinstall>
     */
    public static function forServers(array $serverIds): array
    {
        if ($serverIds === []) {
            return [];
        }

        $operations = DedicatedReinstall::query()
            ->whereIn('dedicated_server_id', $serverIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $latest = [];

        foreach ($operations as $operation) {
            // First wins: the query is already newest-first.
            $latest[$operation->dedicated_server_id] ??= $operation;
        }

        return $latest;
    }
}
