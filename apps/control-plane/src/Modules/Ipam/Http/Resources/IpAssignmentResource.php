<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Ipam\Application\Queries\CustomerIpAssignments;
use Lynomia\Modules\Ipam\Domain\Enums\ReverseDnsStatus;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\ReverseDnsRecord;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;

/**
 * What a customer may see of an address they hold.
 *
 * The rule this resource enforces is one sentence: **the customer sees their
 * address, not the platform's address space.** An assignment row sits three
 * joins from a pool that holds every other customer's addresses, and every one
 * of those hops is a fact about the platform rather than about what was sold.
 *
 * Absent on purpose, and each for its own reason:
 *
 *  - **`ip_address_id`, `subnet_id`, `ip_pool_id`, `network_id`,
 *    `datacenter_id`.** The internal identity of the address and of the block
 *    it came out of. A customer needs the address; the ULIDs behind it are only
 *    a shape to probe with, and the subnet id in particular is the key to a
 *    table that holds the addresses of everybody else in the same block.
 *  - **`cidr` and the pool's scope, size or free count.** Capacity is an
 *    operations fact. Published per customer it is also a map: how much room
 *    the platform has left, and where.
 *  - **The address's `status`, `notes`, `quarantine_reason` and
 *    `quarantined_until`.** Lifecycle state written by the allocator and free
 *    text written by engineers. A quarantine reason names why the *previous*
 *    holder lost the address, which is somebody else's history.
 *  - **`customer_id`.** The caller already knows which account they are acting
 *    for.
 *  - **`assignable_type` and `assignable_id`.** The internal class name and
 *    row id of the thing wearing the address. `service_id` is the same fact in
 *    the vocabulary the rest of the customer API already uses.
 *  - **`released_at`.** Every row here is live by construction — see
 *    {@see CustomerIpAssignments} —
 *    so the column would be null on every response and a client would have to
 *    guess what a non-null one meant.
 *  - **A reverse-DNS record that is not this holder's.** `reverse_dns_records`
 *    is unique on `ip_address_id`: one row per address for the life of the
 *    address, across every customer that ever holds it. Releasing an address
 *    marks that row `removing` — it does not delete it and does not clear the
 *    hostname — so once the address has been quarantined and handed on, the
 *    row the next holder's assignment loads still carries the *previous*
 *    customer's PTR: their mail host, their company name, their identity.
 *    {@see self::reverseDnsFor()} is what keeps that row out of this response.
 *
 * `gateway` and `prefix_length` ARE published, under `network`. They are the
 * two things a customer cannot configure the address without, they describe the
 * address they were given rather than the block it came from, and anybody
 * holding an address and a prefix can derive the block anyway.
 *
 * `mac_address` is published because it is the customer's own NIC: it is what
 * their DHCP client will present and what they need when a static lease does
 * not come up.
 *
 * @mixin IpAssignment
 */
final class IpAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var IpAddress $address */
        $address = $this->resource->ipAddress;

        /** @var Subnet $subnet */
        $subnet = $address->subnet;

        return [
            // The assignment, not the address: this is the id the rDNS
            // endpoint takes, and it is scoped to the acting customer by
            // construction.
            'id' => $this->id,

            'ip_address' => $address->address,
            'ip_version' => $address->ip_version->value,

            // Which of the customer's addresses is the one their machine
            // answers on by default. A client rendering a server's networking
            // needs this to know which to show first.
            'is_primary' => $this->resource->is_primary,
            'mac_address' => $this->mac_address,

            // What the address is attached to, in the vocabulary the rest of
            // the customer API uses. Within the acting account by construction.
            'service_id' => $this->service_id,

            'assigned_at' => $this->assigned_at?->toIso8601String(),

            // Enough to configure an interface, and nothing more.
            'network' => [
                'gateway' => $subnet->gateway,
                'prefix_length' => $subnet->prefix_length,
            ],

            // Null rather than absent when nothing has been asked for: a client
            // showing an rDNS field needs to distinguish "not set" from "not
            // loaded", and every response from this module loads it.
            'reverse_dns' => $this->reverseDnsFor($address),
        ];
    }

    /**
     * The PTR record for this address, but only if it belongs to *this*
     * assignment.
     *
     * An address outlives the customers that hold it, and its reverse record
     * outlives them with it: the table is unique on `ip_address_id`, so there
     * is exactly one row per address forever, and releasing an assignment only
     * stamps that row `removing`. Nothing deletes it, nothing blanks the
     * hostname, and the next customer to be handed the address eager-loads the
     * same row through `ipAddress.reverseDnsRecord`.
     *
     * Two independent conditions, because either alone can be defeated:
     *
     *  - **`removing` is not published.** That status is written by
     *    `IpAllocator::releaseAssignment()` and means exactly "this record is on
     *    its way out because its holder gave the address back". Nobody who
     *    currently holds an address has a record in that state.
     *  - **A record last written before this assignment began is not
     *    published.** A record the current holder asked for is necessarily
     *    written after they were given the address, because the only way to
     *    write one is an endpoint that requires a live assignment. Anything
     *    older predates them — a release path that forgot to mark the row, an
     *    operator's default name, a reconciler that moved the status off
     *    `removing` — and is not theirs to read.
     */
    private function reverseDnsFor(IpAddress $address): ?ReverseDnsRecordResource
    {
        $record = $address->reverseDnsRecord;

        if (! $record instanceof ReverseDnsRecord) {
            return null;
        }

        if ($record->status === ReverseDnsStatus::Removing) {
            return null;
        }

        $assignedAt = $this->resource->assigned_at;
        $writtenAt = $record->updated_at;

        if ($assignedAt !== null && ($writtenAt === null || $writtenAt->lessThan($assignedAt))) {
            return null;
        }

        return new ReverseDnsRecordResource($record);
    }
}
