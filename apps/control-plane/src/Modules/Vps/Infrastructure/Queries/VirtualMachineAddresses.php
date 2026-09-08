<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Infrastructure\Queries;

use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;

/**
 * The addresses currently configured on a set of machines.
 *
 * A join rather than a relation, because there is no relation to use: IPAM's
 * assignment is polymorphic and the Compute module is deliberately not allowed
 * to depend on IPAM, so `VirtualMachine` has no `addresses()` and adding one
 * would put an IPAM import inside Compute for the sake of a serialiser.
 *
 * Batched by design. Asked per machine this would be a query per row on the
 * list endpoint — the classic N+1 that only shows up once a customer has forty
 * servers.
 *
 * Only live assignments are returned. The assignment table is append-only
 * history, kept because the record of who held which address when is what
 * every abuse report and law-enforcement request is answered from; a released
 * row is somebody else's address now, and serialising it would show a customer
 * an address that no longer routes to them — or worse, one that routes to
 * another tenant.
 */
final class VirtualMachineAddresses
{
    /**
     * @param  list<string>  $machineIds
     * @return array<string, list<array{address: string, ip_version: int, is_primary: bool}>>
     */
    public static function forMachines(array $machineIds): array
    {
        if ($machineIds === []) {
            return [];
        }

        $assignments = IpAssignment::query()
            ->with('ipAddress')
            // getMorphClass() rather than ::class, so a morph map added later
            // keeps this query working instead of silently matching nothing.
            ->where('assignable_type', (new VirtualMachine)->getMorphClass())
            ->whereIn('assignable_id', $machineIds)
            ->whereNull('released_at')
            // Primary first, then a stable order, so a client rendering "the"
            // address of a machine renders the same one on every request.
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();

        $byMachine = [];

        foreach ($assignments as $assignment) {
            $address = $assignment->ipAddress;

            if ($address === null) {
                continue;
            }

            $byMachine[(string) $assignment->assignable_id][] = [
                'address' => $address->address,
                // Named and typed as the IPAM resource names and types it. The
                // enum is int-backed, so this is 4 or 6 and not "4" or "6":
                // one endpoint answering with a string and another with a
                // number is how a client ends up comparing them wrongly.
                'ip_version' => $address->ip_version->value,
                'is_primary' => $assignment->is_primary,
            ];
        }

        return $byMachine;
    }
}
