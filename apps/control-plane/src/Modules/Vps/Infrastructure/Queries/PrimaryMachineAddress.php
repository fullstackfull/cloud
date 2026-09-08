<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Infrastructure\Queries;

use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;

/**
 * The address a machine is currently configured with, and the subnet it sits
 * in.
 *
 * Separate from {@see VirtualMachineAddresses} because the questions are
 * different. That one answers "what should the portal show", in bulk, and
 * needs nothing but the addresses themselves. This one answers "what does
 * cloud-init have to write back into the rebuilt guest", which needs the
 * prefix and the gateway too — and needs to be certain the assignment is the
 * live one, because configuring a machine with an address that has been
 * released hands one tenant another tenant's address.
 */
final class PrimaryMachineAddress
{
    public static function for(VirtualMachine $machine): ?IpAssignment
    {
        /** @var IpAssignment|null $assignment */
        $assignment = IpAssignment::query()
            ->with(['ipAddress.subnet'])
            ->where('assignable_type', (new VirtualMachine)->getMorphClass())
            ->where('assignable_id', $machine->getKey())
            ->whereNull('released_at')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        return $assignment;
    }
}
