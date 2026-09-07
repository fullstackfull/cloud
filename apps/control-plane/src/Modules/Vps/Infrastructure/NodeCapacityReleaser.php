<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Infrastructure;

use Lynomia\Modules\Compute\Application\Actions\ReleaseNodeCapacity;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;
use Lynomia\Modules\Provisioning\Domain\Contracts\ResourceReservationReleaser;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;

/**
 * Gives a node's committed capacity back when a build fails.
 *
 * CreateVpsHandler reserves cpu, memory, storage and a slot in the node's
 * anti-affinity count before it calls the hypervisor, and ReleaseNodeCapacity
 * has always been able to hand all of that back. Nothing called it. Every
 * failed build therefore took a permanent bite out of the fleet: the node went
 * on advertising less room than it had, the scheduler went on believing it,
 * and the only way capacity ever came back was somebody editing the row by
 * hand. A platform that fails a few builds a week fills up.
 *
 * ---------------------------------------------------------------------------
 * Why quarantine does nothing
 * ---------------------------------------------------------------------------
 *
 * The two halves of the releaser interface point opposite ways for capacity
 * than they do for addresses.
 *
 * An address that may be in use must be held OUT of the pool, so quarantine is
 * an action. Capacity that may be in use must be held AS committed, so
 * quarantine is deliberately a no-op: a machine the platform is unsure about
 * may be running on that node right now, consuming exactly the cpu and memory
 * that were reserved for it, and handing that commitment back would let the
 * scheduler place another machine on top of it. Doing nothing is the safe
 * action, and it is what "we do not know" should cost.
 *
 * The reservation is left live, so the operator who resolves the job — by
 * adopting the machine or by confirming it does not exist — is the one who
 * decides.
 */
final readonly class NodeCapacityReleaser implements ResourceReservationReleaser
{
    public function __construct(
        private ReleaseNodeCapacity $release,
    ) {}

    public function release(ProvisioningJob $job): int
    {
        $reservation = $this->liveReservationFor($job);

        if ($reservation === null) {
            return 0;
        }

        $node = ComputeNode::query()->find($reservation->node_id);

        if ($node === null) {
            // The node was removed from the fleet while the job was failing.
            // There is no counter left to decrement, and the reservation goes
            // with the node's row.
            return 0;
        }

        $this->release->execute(
            node: $node,
            resources: new VmResources(
                vcpu: $reservation->vcpu,
                memoryMib: $reservation->memory_mib,
                diskGib: $reservation->disk_gib,
            ),
            storageId: $reservation->storage_id,
            // The key is what makes a duplicate release a no-op rather than a
            // second decrement, and duplicates are normal: a compensation and
            // a reconciliation can both arrive for one machine.
            reservationKey: $reservation->reservation_key,
        );

        return 1;
    }

    public function quarantine(ProvisioningJob $job, string $reason): int
    {
        // Deliberately nothing. See the class docblock: for capacity, holding
        // the commitment IS the quarantine.
        return 0;
    }

    private function liveReservationFor(ProvisioningJob $job): ?NodeCapacityReservation
    {
        $key = $job->idempotency_key;

        if ($key === '') {
            return null;
        }

        /*
         * Matched on the key the handler reserved with, not on service_id. A
         * service that has been rebuilt has more than one reservation in its
         * history, and releasing the wrong one gives back capacity a live
         * machine is using.
         */
        $reservation = NodeCapacityReservation::query()
            ->where('reservation_key', $key)
            ->first();

        return $reservation !== null && $reservation->isLive() ? $reservation : null;
    }
}
