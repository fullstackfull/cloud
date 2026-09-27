<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Application\Services;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Application\Actions\RestateNodeCommitment;
use Lynomia\Modules\Compute\Domain\Exceptions\NodeCapacityExceededException;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Throwable;

/**
 * A machine's node commitment, as a resize reads and restates it.
 *
 * One place for the three things a resize and the plan-change quote must
 * agree on: which reservation is the machine's (heldBy()), which pool it is
 * held in (poolFor()), and what a growth to a target commits before the
 * machine grows (ceiling(): the larger, per dimension, of what is held as
 * locked and the target). ResizeVpsHandler restates through it, and
 * the quote asks whyTheGrowthWouldNotFit(), the same refusal as a dry run
 * (RestateNodeCommitment::whyItWouldNotFit()). The quote used to measure the
 * growth from the machine's recorded shape against the node alone: a machine
 * whose commitment was held raised (an unknown outcome) was refused a change
 * the resize would take without asking for anything, and a growth its pool
 * could not hold was sold and then refused at the resize with the money
 * moved.
 */
final readonly class MachineCommitment
{
    public function __construct(
        private RestateNodeCommitment $commitment,
        private ComputeProviderFactory $providers,
    ) {}

    /**
     * Why a resize of this machine to the target would be refused for
     * capacity before the hypervisor is asked to change it; null when it
     * would not, or when there is no node to ask about.
     *
     * One question with the resize's (ResizeVpsHandler): the resize reads the
     * machine from the hypervisor, asks only when the target is larger than
     * that in some dimension (grows()), and asks the node and pool only for
     * what the ceiling adds above what the machine runs and what is held
     * (RestateNodeCommitment::execute()'s $alreadyRuns). So this asks the
     * same dry run of $runs, the machine as the hypervisor reported it
     * (whatItRuns()): a target no larger than that in any dimension - a
     * shrink, or a shape the machine already has - is not refused, as the
     * resize does not refuse it. Measured from the row instead, the quote
     * passed a machine behind its row that the resize then refused after the
     * money moved, and refused a machine ahead of its row a change the resize
     * would deliver (q2, q1: the verification of round seven D).
     *
     * This never asks the hypervisor itself. Its callers ask on the money
     * path under row locks (a plan change under the subscription's, a wallet
     * payment under the invoice's, a settlement under the subscription's),
     * and a hypervisor read there held those locks for as long as the
     * hypervisor took (B2, the verification of round seven D). The reading
     * is taken before any lock and passed in. With none - not taken, or the
     * hypervisor could not be read - the machine may run less than its row
     * says, so it is asked as if nothing ran beyond what is held: the
     * stricter answer, so a quote that passes is a change the resize does
     * not refuse for capacity.
     *
     * A reading is as old as the moment it was taken, and the resize reads
     * the machine again when it runs. A machine that grew in between (a
     * resize that landed) asks the resize for less, never more. One that
     * shrank in between - by hand at the hypervisor, the only way a machine
     * gets smaller outside a resize - asks the resize for what it shrank by,
     * which this answer did not ask: that, and only that, can be refused at
     * the resize after a quote passed, and it is refused there for capacity
     * as any growth that no longer fits is (nothing grown onto room the node
     * does not have; retried, then held for a person). The payment and the
     * settlement ask again, each with a reading of its own.
     */
    public function whyTheGrowthWouldNotFit(VirtualMachine $machine, ?int $vcpu, ?int $memoryMib, ?int $diskGib, ?VmResources $runs = null): ?string
    {
        if ($runs !== null && ! $this->grows($machine, $vcpu, $memoryMib, $diskGib, $runs)) {
            return null;
        }

        /** @var ComputeNode|null $node */
        $node = $machine->node()->first();

        if ($node === null) {
            return 'the machine is on no node to grow on';
        }

        $held = $this->heldBy($machine, $node);

        return $this->commitment->whyItWouldNotFit(
            $this->keyFor($held, $machine),
            $node,
            $this->poolFor($held, $machine, $node),
            $this->ceiling($machine, $vcpu, $memoryMib, $diskGib),
            $runs,
        );
    }

    /**
     * The machine's shape as the hypervisor reports it now - each figure it
     * does not report taken from the row, as the resize takes it - or null
     * when it cannot be read: no confirmed provider id, node or cluster, no
     * such machine, or any failure to ask. A provider call: taken outside
     * every transaction (whyTheGrowthWouldNotFit()).
     */
    public function whatItRuns(VirtualMachine $machine): ?VmResources
    {
        /** @var ComputeNode|null $node */
        $node = $machine->node()->first();
        /** @var ComputeCluster|null $cluster */
        $cluster = $machine->cluster()->first();

        if (! $machine->existsRemotely() || $node === null || $cluster === null) {
            return null;
        }

        try {
            $state = $this->providers->for($cluster)->getVm($node->provider_name, (string) $machine->provider_id);
        } catch (QueryException $e) {
            throw $e;
        } catch (Throwable) {
            return null;
        }

        return $state === null ? null : new VmResources(
            vcpu: $state->vcpu ?? $machine->vcpu,
            memoryMib: $state->memoryMib ?? $machine->memory_mib,
            diskGib: $state->diskGib ?? $machine->disk_gib,
        );
    }

    /**
     * Whether the target makes the machine larger than it runs now in any
     * dimension: the only change a resize asks the node or pool about.
     *
     * A shrink is never refused for capacity - the machine already occupies
     * its shape on the node, and a shrink gives room back. Committed from
     * nothing, as a machine with no live commitment is (keyFor()), the whole
     * of its shape read as growth, and a pure shrink of such a machine on a
     * full node was refused by the quote (not_deliverable) and would have
     * been refused by the resize (N3, the re-audit after round six).
     *
     * "Runs now" is $current when the caller has read it - the resize reads
     * the machine from the hypervisor before it changes anything
     * (ResizeVpsHandler), and the plan-change quote passes the reading it
     * took (whyTheGrowthWouldNotFit()); a machine ahead of its row (a growth
     * that landed unrecorded) is then compared with what it is - and the row
     * otherwise. No caller in the platform passes none today.
     */
    public function grows(VirtualMachine $machine, ?int $vcpu, ?int $memoryMib, ?int $diskGib, ?VmResources $current = null): bool
    {
        $current ??= self::shapeOf($machine);

        return ($vcpu !== null && $vcpu > $current->vcpu)
            || ($memoryMib !== null && $memoryMib > $current->memoryMib)
            || ($diskGib !== null && $diskGib > $current->diskGib);
    }

    /**
     * Restates the machine's commitment, under a lock on the machine's row
     * taken first; false, with nothing written, when the row is gone.
     *
     * The row is locked before anything else, and read again under that
     * lock: DestroyVpsHandler gives the capacity back and deletes the row
     * under the same lock, so a restatement is either wholly before a
     * destroy's release (which then gives back what it wrote) or after the
     * row is gone (and writes nothing). Without it, a destroy that ran
     * between a resize's write of the machine row and its settle released
     * the commitment and deleted the row, and the settle - finding nothing
     * live under the service - committed the machine again under a key of
     * its own (keyFor()): the node went on holding 1 machine and 4 / 8192 /
     * 80 for a machine that no longer existed, and nothing released it
     * (D7-1, round seven). The reservation it restates is found under the
     * lock too, so a key read before a destroy is never written again after
     * it. The lock order is the machine row, then the capacity rows in
     * RestateNodeCommitment's own order - the order the destroy takes them.
     *
     * @param  VmResources|Closure(NodeCapacityReservation|null): VmResources  $shape
     * @param  VmResources|null  $alreadyRuns  what the machine runs now, when read (RestateNodeCommitment::execute())
     *
     * @throws NodeCapacityExceededException when $refuse and the node or pool cannot hold an increase
     */
    public function restate(VirtualMachine $machine, ComputeNode $node, VmResources|Closure $shape, bool $refuse, ?VmResources $alreadyRuns = null): bool
    {
        return DB::transaction(function () use ($machine, $node, $shape, $refuse, $alreadyRuns): bool {
            if (VirtualMachine::query()->whereKey($machine->getKey())->lockForUpdate()->first(['id']) === null) {
                return false;
            }

            $held = $this->heldBy($machine, $node);

            $this->commitment->execute(
                reservationKey: $this->keyFor($held, $machine),
                node: $node,
                storageId: $this->poolFor($held, $machine, $node),
                shape: $shape,
                refuseWhatDoesNotFit: $refuse,
                serviceId: $machine->service_id,
                customerId: $held?->customer_id,
                alreadyRuns: $alreadyRuns,
            );

            return true;
        });
    }

    /**
     * What a growth to the target commits before the machine grows: the
     * larger of what is held (as locked - another resize of this machine may
     * have raised it) and the target, so neither the old shape nor the new
     * one ever runs on room nobody committed.
     *
     * @return Closure(NodeCapacityReservation|null): VmResources
     */
    public function ceiling(VirtualMachine $machine, ?int $vcpu, ?int $memoryMib, ?int $diskGib): Closure
    {
        $recorded = self::shapeOf($machine);

        return static function (?NodeCapacityReservation $locked) use ($recorded, $vcpu, $memoryMib, $diskGib): VmResources {
            $current = $locked === null ? $recorded : $locked->resources();

            return new VmResources(
                vcpu: max($current->vcpu, $vcpu ?? $recorded->vcpu),
                memoryMib: max($current->memoryMib, $memoryMib ?? $recorded->memoryMib),
                diskGib: max($current->diskGib, $diskGib ?? $recorded->diskGib),
            );
        };
    }

    /**
     * The machine's shape as its row says, read under the commitment's locks
     * (RestateNodeCommitment). Not the model a job holds: nothing serialises
     * two resizes of one machine, and every one writes the row before it
     * restates, so a read under the lock is after the other's write or the
     * other's restatement comes after this one - either way the last
     * restatement is the machine's shape. Settled from the model in memory, a
     * resize that ran whole between another's write and its settle left the
     * machine at 16384 MiB and the commitment at 8192 (B1, round six).
     *
     * @return Closure(NodeCapacityReservation|null): VmResources
     */
    public function asRecorded(VirtualMachine $machine): Closure
    {
        $id = (string) $machine->getKey();

        return static function () use ($id, $machine): VmResources {
            $row = VirtualMachine::query()->find($id);

            return self::shapeOf($row ?? $machine);
        };
    }

    /**
     * The machine's live reservation: found by its service, as the destroy
     * finds it (DestroyVpsHandler::releaseTheCapacity()), preferring the one
     * on the machine's node. Null when none is live.
     */
    public function heldBy(VirtualMachine $machine, ComputeNode $node): ?NodeCapacityReservation
    {
        if ($machine->service_id === null) {
            return null;
        }

        /** @var NodeCapacityReservation|null $held */
        $held = NodeCapacityReservation::query()
            ->where('service_id', $machine->service_id)
            ->whereNull('released_at')
            ->orderByRaw('case when node_id = ? then 0 else 1 end', [(string) $node->getKey()])
            ->orderBy('id')
            ->first();

        return $held;
    }

    /**
     * A machine with no live commitment found by its service - one adopted
     * before adoption committed a released build again
     * (NodeCapacityFollowsAnAdoption), or one whose commitment was released
     * some other way - is committed under a key of its own.
     */
    private function keyFor(?NodeCapacityReservation $held, VirtualMachine $machine): string
    {
        return $held !== null ? $held->reservation_key : 'machine:'.$machine->getKey();
    }

    /**
     * The pool the commitment is held in: the reservation's own when it can
     * be seen from the machine's node, otherwise the pool the machine's disk
     * is recorded on.
     */
    private function poolFor(?NodeCapacityReservation $held, VirtualMachine $machine, ComputeNode $node): ?string
    {
        if ($held !== null && $held->storage_id !== null) {
            $pool = ComputeStorage::query()->find($held->storage_id);

            if ($pool !== null && ($pool->node_id === null || $pool->node_id === (string) $node->getKey())) {
                return (string) $pool->getKey();
            }
        }

        if ($machine->storage_name === null || $machine->storage_name === '') {
            return null;
        }

        $pool = ComputeStorage::query()
            ->where('cluster_id', $node->cluster_id)
            ->where('provider_name', $machine->storage_name)
            ->where(static fn ($query) => $query->whereNull('node_id')->orWhere('node_id', $node->getKey()))
            ->orderBy('id')
            ->first();

        return $pool === null ? null : (string) $pool->getKey();
    }

    private static function shapeOf(VirtualMachine $machine): VmResources
    {
        return new VmResources($machine->vcpu, $machine->memory_mib, $machine->disk_gib);
    }
}
