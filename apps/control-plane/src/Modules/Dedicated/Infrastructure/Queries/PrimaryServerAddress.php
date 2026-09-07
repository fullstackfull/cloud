<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Infrastructure\Queries;

use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;

/**
 * The address a physical machine is currently configured with.
 *
 * A join rather than a relation, because IPAM's assignment is polymorphic and
 * the Dedicated module owns no part of IPAM's schema. Only live assignments
 * are returned: the table is append-only history, and a released row is
 * somebody else's address now — rendering an install profile with one would
 * bring a machine up on an address that routes to another tenant.
 */
final class PrimaryServerAddress
{
    public static function for(DedicatedServer $server): ?IpAssignment
    {
        /** @var IpAssignment|null $assignment */
        $assignment = IpAssignment::query()
            ->with(['ipAddress.subnet'])
            ->where('assignable_type', (new DedicatedServer)->getMorphClass())
            ->where('assignable_id', $server->getKey())
            ->whereNull('released_at')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        return $assignment;
    }
}
