<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Application\Handlers;

use Lynomia\Modules\Compute\Application\Actions\ReleaseNodeCapacity;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Provisioning\Domain\Contracts\ProvisioningHandler;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * The end of a machine's life, and the return of everything it was holding.
 *
 * Termination was the one lifecycle edge the platform described and could not
 * perform. `destroy_vps` sat in the job vocabulary with no handler, the
 * service state machine had its `active → terminated` edge, `ReleaseReason`
 * had `service_terminated`, and `IpAllocator::releaseAssignment` — the method
 * that puts a departing customer's address into quarantine — had no caller at
 * all. A customer who cancelled kept their machine, their address and their
 * share of a node's capacity for ever, and the only way to get any of it back
 * was to delete rows by hand.
 *
 * ---------------------------------------------------------------------------
 * The order, and why it is this way round
 * ---------------------------------------------------------------------------
 *
 * The hypervisor first, then the address, then the capacity, then the row.
 *
 * A machine that still exists must keep its address reserved: handing it to
 * the next customer while a running guest still answers on it produces a
 * duplicate-address incident that no amount of retrying fixes. So nothing is
 * released until the machine is confirmed gone — and "confirmed" means the
 * hypervisor says it has no such machine, not that a delete call returned.
 *
 * The address goes to quarantine rather than back to the pool, which is
 * `releaseAssignment`'s whole reason for existing: for days after a
 * termination the address keeps arriving at its old destination, and the next
 * customer to receive it would inherit somebody else's blocklist entries,
 * monitoring probes and abuse reports.
 *
 * Capacity goes back by the key the build reserved with, so a destroy that is
 * delivered twice decrements a node once.
 *
 * ---------------------------------------------------------------------------
 * Already gone is success
 * ---------------------------------------------------------------------------
 *
 * A machine the hypervisor does not have is the state this job exists to
 * reach. It is reported as success and the release still runs, because the
 * common way to arrive here is a redelivered message or an operator who
 * removed the machine by hand — and in both cases the address and the capacity
 * are still held by the platform, which is the part that matters.
 *
 * A provider call that ends indeterminately is a timeout, never a retry: the
 * platform does not know whether the machine is gone, and releasing an address
 * that may still be configured on a live guest is precisely the thing this
 * ordering exists to prevent. The engine escalates it, the compensation step
 * quarantines what the job held, and a person looks.
 */
final readonly class DestroyVpsHandler implements ProvisioningHandler
{
    public function __construct(
        private ComputeProviderFactory $computeProviders,
        private IpAllocator $addresses,
        private ReleaseNodeCapacity $capacity,
        private SecretRedactor $redactor,
    ) {}

    public function kind(): ProvisioningJobKind
    {
        return ProvisioningJobKind::DestroyVps;
    }

    public function execute(ProvisioningJob $job): ProvisioningResult
    {
        /** @var array<string, mixed> $payload */
        $payload = $job->payload;

        $machineId = (string) ($payload['virtual_machine_id'] ?? '');
        $machine = $machineId === '' ? null : VirtualMachine::query()->find($machineId);

        if ($machine === null) {
            /*
             * Nothing to destroy and nothing to release: the row is gone, so a
             * previous delivery of this job finished. Reported as success
             * rather than as a permanent failure, because a service left in
             * `terminating` because its second message arrived is a support
             * case about work that is already done.
             */
            return ProvisioningResult::succeeded(
                metadata: ['virtual_machine_id' => $machineId, 'already_removed' => true],
            );
        }

        $node = $machine->node()->first();
        $cluster = $machine->cluster()->first();

        if ($machine->existsRemotely() && $node !== null && $cluster !== null) {
            $verdict = $this->removeFromTheHypervisor($machine, $node, $cluster);

            if ($verdict !== null) {
                return $verdict;
            }
        }

        $addresses = $this->releaseTheAddresses($machine);
        $capacity = $this->releaseTheCapacity($machine);

        /*
         * The row goes last. Everything above is keyed on it — the assignments
         * by the machine, the reservation by the service — so a worker that
         * died halfway through leaves a machine row whose redelivery finds the
         * releases already done and idempotent, rather than an orphaned
         * address nothing can be traced back to.
         */
        $machine->delete();

        return ProvisioningResult::succeeded(
            metadata: [
                'virtual_machine_id' => (string) $machine->getKey(),
                'addresses_released' => $addresses,
                'capacity_reservations_released' => $capacity,
            ],
        );
    }

    /**
     * @return ProvisioningResult|null a result when the machine could not be confirmed gone, null when it is
     */
    private function removeFromTheHypervisor(
        VirtualMachine $machine,
        ComputeNode $node,
        ComputeCluster $cluster,
    ): ?ProvisioningResult {
        $provider = $this->computeProviders->for($cluster);

        try {
            if ($provider->getVm($node->provider_name, (string) $machine->provider_id) === null) {
                // Already gone. The release below is the work that is left.
                return null;
            }

            $provider->destroyVm($node->provider_name, (string) $machine->provider_id, purge: true);
        } catch (ComputeProviderException $e) {
            if ($e->isIndeterminate()) {
                return ProvisioningResult::failed(
                    FailureClass::Timeout,
                    $e->errorCode(),
                    $e->getMessage(),
                    metadata: $this->redactor->redact([
                        ...$e->context(),
                        'virtual_machine_id' => (string) $machine->getKey(),
                        'provider_resource_id' => (string) $machine->provider_id,
                        'node' => $node->provider_name,
                        'cluster_id' => (string) $cluster->getKey(),
                    ]),
                );
            }

            /*
             * The hypervisor refused and said why. Nothing is released: the
             * machine is still there, still holding its address, and a
             * platform that freed the address here would hand a live guest's
             * address to the next customer.
             */
            return ProvisioningResult::failed(
                FailureClass::Transient,
                $e->errorCode(),
                $e->getMessage(),
                metadata: $this->redactor->redact($e->context()),
            );
        }

        return null;
    }

    /**
     * Ends every live assignment this machine holds, into quarantine.
     */
    private function releaseTheAddresses(VirtualMachine $machine): int
    {
        $released = 0;

        $assignments = IpAssignment::query()
            ->where('assignable_type', $machine->getMorphClass())
            ->where('assignable_id', $machine->getKey())
            ->whereNull('released_at')
            ->get();

        foreach ($assignments as $assignment) {
            $this->addresses->releaseAssignment($assignment, ReleaseReason::ServiceTerminated);
            $released++;
        }

        return $released;
    }

    /**
     * Hands the node back what the build committed for this machine.
     *
     * Found by service rather than by this job's key: the reservation belongs
     * to the build, and a destroy carries a key of its own. Releasing by the
     * wrong key would give back nothing and leave the node advertising less
     * room than it has for ever, which is the failure that made
     * NodeCapacityReleaser necessary in the first place.
     */
    private function releaseTheCapacity(VirtualMachine $machine): int
    {
        $released = 0;

        $reservations = NodeCapacityReservation::query()
            ->where('service_id', $machine->service_id)
            ->whereNull('released_at')
            ->get();

        foreach ($reservations as $reservation) {
            $node = ComputeNode::query()->find($reservation->node_id);

            if ($node === null) {
                continue;
            }

            $this->capacity->execute(
                node: $node,
                resources: $reservation->resources(),
                storageId: $reservation->storage_id,
                reservationKey: $reservation->reservation_key,
            );

            $released++;
        }

        return $released;
    }
}
