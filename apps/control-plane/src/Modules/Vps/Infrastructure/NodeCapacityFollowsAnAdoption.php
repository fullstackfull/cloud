<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Infrastructure;

use Illuminate\Database\QueryException;
use Lynomia\Modules\Compute\Application\Actions\RestateNodeCommitment;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteVmState;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Domain\Contracts\ReservationsFollowAnAdoption;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Throwable;

/**
 * Moves a VPS build's node commitment to the node the adopted machine is on,
 * and records the machine there.
 *
 * AdoptOrphanResource never touched capacity. A build whose create was lost
 * on pve-01, retried by an operator onto pve-02 (moving its commitment there),
 * and whose first create then landed on pve-01, was adopted on pve-01 with its
 * commitment left on pve-02: pve-01 ran the machine and was charged nothing,
 * pve-02 was charged for a machine it does not run (D5, round six).
 *
 * Where the machine is, is asked of the hypervisor (lookFor()): the node the
 * commitment is on first, then the nodes the build's attempts were sent to,
 * then the rest of the cluster. Asked before the adoption's transaction, with
 * nothing locked: the lookup used to run inside it, holding the job's row
 * lock and the adoption's advisory locks for as long as a hypervisor took to
 * answer each node (X7-3, round seven). The answer is acted on under the lock
 * (follow()), and re-checked there: the build's commitment is read again, the
 * answer is used only while the build still names the cluster that was
 * asked, and the node it names is read again - a node no longer recorded in
 * that cluster since is not written to.
 *
 * Found elsewhere than the commitment, the commitment is moved there by
 * RestateNodeCommitment - in the one lock order, recorded rather than
 * refused, because the machine is already there and a refusal would not move
 * it, only leave the ledger wrong. The pool follows: a shared pool the
 * commitment is in stays; a pool of the old node's is replaced by one on the
 * new node of the same class, or by none when the new node has none.
 *
 * Not found, or the hypervisor cannot be asked for any reason, and the commitment is left
 * where it is, and the adoption's record says so: the adoption is the
 * operator's statement that the machine is theirs, and refusing it for want
 * of a hypervisor answer would leave the machine unbilled for longer. A
 * failure of the platform's own database is not a hypervisor that cannot be
 * asked: it is re-thrown and the adoption does not happen.
 *
 * A build whose commitment was already given back - released on a failure
 * taken for "nothing was built" - holds none to move. Its machine is
 * committed again, at the shape the build last held, on the node it is found
 * on (or, not found, on the node it was last placed on), as
 * RestateNodeCommitment writes one for a machine with no live reservation.
 * An adoption that found nothing live used to commit nothing, and the
 * machine was charged to no node until its next resize.
 *
 * And the machine gets its `virtual_machines` row, written from what the
 * hypervisor reported where it was found - the fields a successful create
 * records (CreateVpsHandler): service, cluster, node, provider id, storage,
 * hostname, shape, power state, OS family. An adopted VPS used to have none:
 * it could not be resized, destroyed, terminated or seen by its customer,
 * and TerminateVpsService refused it, telling the operator to adopt what
 * they had already adopted (D7-3, round seven). A row is written only from
 * a hypervisor answer: a machine not found, or a hypervisor that could not
 * be asked, leaves no row - one written on a guess would send the destroy to
 * a node the machine is not on, where "no such machine" reads as already
 * gone - and the adoption's record says so. Nor is one written over a row
 * the service already has, or one another row holds for the same machine.
 * The address the build was given is not committed here: a timed-out build's
 * address is quarantined and waits for a person
 * (IpamQuarantineController::adopt(), which now names this row).
 */
final readonly class NodeCapacityFollowsAnAdoption implements ReservationsFollowAnAdoption
{
    public function __construct(
        private ComputeProviderFactory $providers,
        private RestateNodeCommitment $commitment,
        private SecretRedactor $redactor,
    ) {}

    public function lookFor(ProvisioningJob $job, string $providerReference): ?WhereAnAdoptedMachineWasFound
    {
        if (! self::isAVpsBuild($job)) {
            return null;
        }

        ['holding' => $holding] = $this->commitmentOf($job);
        $cluster = $this->clusterOf($job, $holding);

        if ($cluster === null) {
            return null;
        }

        try {
            $found = $this->whereItIs($cluster, $providerReference, $holding, $job->reservedProviderIdentity()?->nodes ?? []);
        } catch (QueryException $e) {
            // The platform's own database, not the hypervisor: the adoption
            // cannot go on, and says so.
            throw $e;
        } catch (Throwable $e) {
            /*
             * Any failure to ask - a provider error, a cluster whose
             * credentials are gone (ClusterNotConfiguredException, a domain
             * exception, not a provider one), a connection reset. It used to
             * catch provider errors alone, and the rest escaped the adoption
             * as a 500 and rolled it back: a machine the operator had found
             * stayed unadopted for want of a lookup the adoption does not
             * need.
             */
            return new WhereAnAdoptedMachineWasFound(
                clusterId: (string) $cluster->getKey(),
                unasked: 'the hypervisor could not be asked where the machine is: '.$this->redactor->redactString($e->getMessage()),
            );
        }

        return new WhereAnAdoptedMachineWasFound(
            clusterId: (string) $cluster->getKey(),
            nodeId: $found === null ? null : (string) $found[0]->getKey(),
            machine: $found[1] ?? null,
        );
    }

    public function follow(ProvisioningJob $job, string $providerReference, mixed $looked): array
    {
        if (! self::isAVpsBuild($job)) {
            return [];
        }

        // Read again, under the job's lock: the lookup ran before it.
        ['released' => $released, 'last' => $last, 'holding' => $holding] = $this->commitmentOf($job);
        $cluster = $this->clusterOf($job, $holding);

        if ($cluster === null) {
            return $last === null || $holding === null
                ? []
                : ['node' => $holding->provider_name, 'moved' => false, 'reason' => 'the build\'s cluster is gone', 'machine_recorded' => false];
        }

        [$found, $machine, $unasked] = $this->theAnswer($looked, $cluster);

        $capacity = $last === null || $holding === null
            ? []
            : $this->followTheMachine($job, $last, $released, $holding, $found, $unasked);

        return [...$capacity, ...$this->recordTheMachine($job, $providerReference, $found, $machine, $unasked)];
    }

    /**
     * @return array<string, scalar|null>
     */
    private function followTheMachine(
        ProvisioningJob $job,
        NodeCapacityReservation $last,
        ?NodeCapacityReservation $released,
        ComputeNode $holding,
        ?ComputeNode $found,
        ?string $unasked,
    ): array {
        if ($released !== null) {
            return $this->commitAgain($released, $found ?? $holding, $found === null ? ($unasked ?? 'the machine was not found on any node of the cluster') : null, $job);
        }

        if ($unasked !== null) {
            return ['node' => $holding->provider_name, 'moved' => false, 'reason' => $unasked];
        }

        if ($found === null) {
            return ['node' => $holding->provider_name, 'moved' => false, 'reason' => 'the machine was not found on any node of the cluster'];
        }

        if ($found->is($holding)) {
            return ['node' => $holding->provider_name, 'moved' => false];
        }

        $moved = $this->commitment->execute(
            reservationKey: $last->reservation_key,
            node: $found,
            storageId: $this->poolOn($found, $last),
            shape: $last->resources(),
            refuseWhatDoesNotFit: false,
        );

        return [
            'node' => $found->provider_name,
            'moved' => true,
            'moved_from' => $holding->provider_name,
            'storage_id' => $moved->storage_id,
        ];
    }

    /**
     * The lookup's answer, as it may be acted on under the lock: the node it
     * names read again, and nothing taken from a lookup of another cluster
     * than the one the build names now.
     *
     * @return array{0: ComputeNode|null, 1: RemoteVmState|null, 2: string|null}
     */
    private function theAnswer(mixed $looked, ComputeCluster $cluster): array
    {
        if (! $looked instanceof WhereAnAdoptedMachineWasFound) {
            return [null, null, 'the hypervisor was not asked where the machine is'];
        }

        if ($looked->clusterId !== (string) $cluster->getKey()) {
            return [null, null, 'the build\'s cluster changed while the hypervisor was asked where the machine is'];
        }

        if ($looked->unasked !== null || $looked->nodeId === null) {
            return [null, null, $looked->unasked];
        }

        /** @var ComputeNode|null $node */
        $node = ComputeNode::query()->whereKey($looked->nodeId)->where('cluster_id', $cluster->getKey())->first();

        if ($node === null) {
            return [null, null, 'the node the machine was found on is no longer recorded in the build\'s cluster'];
        }

        return [$node, $looked->machine, null];
    }

    /**
     * The adopted machine's `virtual_machines` row, from what the hypervisor
     * reported where it was found (the class docblock).
     *
     * @return array<string, scalar|null>
     */
    private function recordTheMachine(ProvisioningJob $job, string $providerReference, ?ComputeNode $found, ?RemoteVmState $machine, ?string $unasked): array
    {
        /** @var VirtualMachine|null $existing */
        $existing = $job->service_id === null ? null : VirtualMachine::query()->where('service_id', $job->service_id)->first();

        if ($existing !== null) {
            return [
                'machine_recorded' => false,
                'virtual_machine_id' => (string) $existing->getKey(),
                'machine_reason' => 'the service already has a machine row',
            ];
        }

        if ($found === null || $machine === null) {
            return [
                'machine_recorded' => false,
                'machine_reason' => ($unasked ?? 'the machine was not found on any node of the cluster')
                    .'; no machine row was recorded, so the platform cannot manage the machine until one is',
            ];
        }

        /** @var VirtualMachine|null $claimed */
        $claimed = VirtualMachine::query()
            ->where('cluster_id', $found->cluster_id)
            ->where('provider_id', $providerReference)
            ->first();

        if ($claimed !== null) {
            return [
                'machine_recorded' => false,
                'machine_reason' => 'another machine row already holds this provider reference on the cluster',
            ];
        }

        /** @var array<string, mixed> $payload */
        $payload = $job->payload ?? [];

        /** @var NodeCapacityReservation|null $committed */
        $committed = NodeCapacityReservation::query()
            ->where('reservation_key', $job->idempotency_key)
            ->whereNull('released_at')
            ->first();

        $pool = $committed?->storage_id === null ? null : ComputeStorage::query()->find($committed->storage_id);

        $row = VirtualMachine::query()->create([
            'service_id' => $job->service_id,
            'cluster_id' => $found->cluster_id,
            'node_id' => $found->getKey(),
            'provider_id' => $providerReference,
            'storage_name' => $pool?->provider_name,
            'hostname' => $machine->name ?? (string) ($payload['hostname'] ?? $providerReference),
            'vcpu' => $machine->vcpu ?? $committed?->vcpu ?? (int) ($payload['vcpu'] ?? 0),
            'memory_mib' => $machine->memoryMib ?? $committed?->memory_mib ?? (int) ($payload['memory_mib'] ?? 0),
            'disk_gib' => $machine->diskGib ?? $committed?->disk_gib ?? (int) ($payload['disk_gib'] ?? 0),
            'power_state' => $machine->powerState,
            'os_family' => (string) ($payload['os_family'] ?? 'debian'),
        ]);

        return ['machine_recorded' => true, 'virtual_machine_id' => (string) $row->getKey()];
    }

    /**
     * A machine adopted after its build's commitment was given back is
     * committed again, at the shape the build last held, on the node it was
     * found on - or, when it could not be found or the hypervisor could not
     * be asked, on the node the build was last placed on, and the record says
     * why. Recorded, never refused (RestateNodeCommitment writes a new row
     * when none is live): the machine exists, and an adoption that committed
     * nothing left it charged to no node until its next resize.
     *
     * @return array<string, scalar|null>
     */
    private function commitAgain(NodeCapacityReservation $released, ComputeNode $node, ?string $reason, ProvisioningJob $job): array
    {
        $committed = $this->commitment->execute(
            reservationKey: $released->reservation_key,
            node: $node,
            storageId: $this->poolOn($node, $released),
            shape: $released->resources(),
            refuseWhatDoesNotFit: false,
            serviceId: $released->service_id ?? $job->service_id,
            customerId: $released->customer_id ?? $job->customer_id,
        );

        return [
            'node' => $node->provider_name,
            'moved' => false,
            'recommitted' => true,
            'storage_id' => $committed->storage_id,
            ...($reason === null ? [] : ['reason' => $reason.'; committed on the node the build was last placed on']),
        ];
    }

    private static function isAVpsBuild(ProvisioningJob $job): bool
    {
        return $job->kind === ProvisioningJobKind::CreateVps && $job->idempotency_key !== '';
    }

    /**
     * The build's live commitment, or - none live - the last one it held,
     * and the node that one is on.
     *
     * @return array{held: NodeCapacityReservation|null, released: NodeCapacityReservation|null, last: NodeCapacityReservation|null, holding: ComputeNode|null}
     */
    private function commitmentOf(ProvisioningJob $job): array
    {
        /** @var NodeCapacityReservation|null $held */
        $held = NodeCapacityReservation::query()
            ->where('reservation_key', $job->idempotency_key)
            ->whereNull('released_at')
            ->first();

        /*
         * None live: the build's commitment was given back (a failure taken
         * for "nothing was built") before the machine was found and adopted.
         * The last one it held says what shape the machine was committed at
         * and where it was placed.
         */
        /** @var NodeCapacityReservation|null $released */
        $released = $held !== null ? null : NodeCapacityReservation::query()
            ->where('reservation_key', $job->idempotency_key)
            ->whereNotNull('released_at')
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->first();

        $last = $held ?? $released;

        /** @var ComputeNode|null $holding */
        $holding = $last === null ? null : ComputeNode::query()->find($last->node_id);

        return ['held' => $held, 'released' => $released, 'last' => $last, 'holding' => $holding];
    }

    /**
     * The cluster the build names: its reserved identity's, else the one its
     * commitment is on, else the one its payload was placed in.
     */
    private function clusterOf(ProvisioningJob $job, ?ComputeNode $holding): ?ComputeCluster
    {
        /** @var array<string, mixed> $payload */
        $payload = $job->payload ?? [];
        $id = $job->reservedProviderIdentity()?->clusterId ?? $holding?->cluster_id ?? ($payload['cluster_id'] ?? null);

        /** @var ComputeCluster|null $cluster */
        $cluster = is_string($id) && $id !== '' ? ComputeCluster::query()->find($id) : null;

        return $cluster;
    }

    /**
     * @param  list<string>  $sentTo  node names the build's creates were sent to
     * @return array{0: ComputeNode, 1: RemoteVmState}|null
     *
     * @throws Throwable whatever asking the hypervisor throws
     */
    private function whereItIs(ComputeCluster $cluster, string $providerReference, ?ComputeNode $holding, array $sentTo): ?array
    {
        /** @var list<ComputeNode> $nodes */
        $nodes = ComputeNode::query()->where('cluster_id', $cluster->getKey())->orderBy('id')->get()->all();

        usort($nodes, static function (ComputeNode $a, ComputeNode $b) use ($holding, $sentTo): int {
            $rank = static fn (ComputeNode $n): int => $holding !== null && $n->is($holding) ? 0 : (in_array($n->provider_name, $sentTo, true) ? 1 : 2);

            return $rank($a) <=> $rank($b);
        });

        $provider = $this->providers->for($cluster);

        foreach ($nodes as $node) {
            $machine = $provider->getVm($node->provider_name, $providerReference);

            if ($machine !== null) {
                return [$node, $machine];
            }
        }

        return null;
    }

    private function poolOn(ComputeNode $node, NodeCapacityReservation $held): ?string
    {
        /** @var ComputeStorage|null $pool */
        $pool = $held->storage_id === null ? null : ComputeStorage::query()->find($held->storage_id);

        if ($pool !== null && $pool->node_id === null) {
            return (string) $pool->getKey();
        }

        /** @var ComputeStorage|null $there */
        $there = ComputeStorage::query()
            ->where('node_id', $node->getKey())
            ->where('is_active', true)
            ->when($pool !== null, static fn ($query) => $query->where('storage_class', $pool->storage_class))
            ->orderBy('id')
            ->first();

        return $there === null ? null : (string) $there->getKey();
    }
}
