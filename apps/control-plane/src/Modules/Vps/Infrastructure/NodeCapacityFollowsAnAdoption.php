<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Infrastructure;

use Illuminate\Database\QueryException;
use Lynomia\Modules\Compute\Application\Actions\RestateNodeCommitment;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;
use Lynomia\Modules\Provisioning\Domain\Contracts\ReservationsFollowAnAdoption;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Throwable;

/**
 * Moves a VPS build's node commitment to the node the adopted machine is on.
 *
 * AdoptOrphanResource never touched capacity. A build whose create was lost
 * on pve-01, retried by an operator onto pve-02 (moving its commitment there),
 * and whose first create then landed on pve-01, was adopted on pve-01 with its
 * commitment left on pve-02: pve-01 ran the machine and was charged nothing,
 * pve-02 was charged for a machine it does not run (D5, round six).
 *
 * Where the machine is, is asked of the hypervisor: the node the commitment is
 * on first, then the nodes the build's attempts were sent to, then the rest of
 * the cluster. Found elsewhere than the commitment, the commitment is moved
 * there by RestateNodeCommitment - in the one lock order, recorded rather than
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
 * asked: it is re-thrown and the adoption rolls back.
 *
 * A build whose commitment was already given back - released on a failure
 * taken for "nothing was built" - holds none to move. Its machine is
 * committed again, at the shape the build last held, on the node it is found
 * on (or, not found, on the node it was last placed on), as
 * RestateNodeCommitment writes one for a machine with no live reservation.
 * An adoption that found nothing live used to commit nothing, and the
 * machine was charged to no node until its next resize.
 */
final readonly class NodeCapacityFollowsAnAdoption implements ReservationsFollowAnAdoption
{
    public function __construct(
        private ComputeProviderFactory $providers,
        private RestateNodeCommitment $commitment,
        private SecretRedactor $redactor,
    ) {}

    public function follow(ProvisioningJob $job, string $providerReference): array
    {
        if ($job->kind !== ProvisioningJobKind::CreateVps || $job->idempotency_key === '') {
            return [];
        }

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

        if ($last === null || $holding === null) {
            return [];
        }

        $identity = $job->reservedProviderIdentity();

        /** @var ComputeCluster|null $cluster */
        $cluster = ComputeCluster::query()->find($identity !== null ? $identity->clusterId : $holding->cluster_id);

        if ($cluster === null) {
            return ['node' => $holding->provider_name, 'moved' => false, 'reason' => 'the build\'s cluster is gone'];
        }

        $found = null;
        $unasked = null;

        try {
            $found = $this->whereItIs($cluster, $providerReference, $holding, $identity?->nodes ?? []);
        } catch (QueryException $e) {
            // The platform's own database, not the hypervisor: the adoption's
            // transaction cannot go on, and says so.
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
            $unasked = 'the hypervisor could not be asked where the machine is: '.$this->redactor->redactString($e->getMessage());
        }

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
     * A machine adopted after its build's commitment was given back is
     * committed again, at the shape the build last held, on the node it was
     * found on - or, when it could not be found or the hypervisor could not
     * be asked, on the node the build was last placed on, and the record says
     * why. Recorded, never refused (RestateNodeCommitment writes a new row
     * when none is live): the machine exists, and an adoption that committed
     * nothing left it charged to no node until its next resize.
     *
     * @return array<string, mixed>
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

    /**
     * @param  list<string>  $sentTo  node names the build's creates were sent to
     *
     * @throws Throwable whatever asking the hypervisor throws
     */
    private function whereItIs(ComputeCluster $cluster, string $providerReference, ComputeNode $holding, array $sentTo): ?ComputeNode
    {
        /** @var list<ComputeNode> $nodes */
        $nodes = ComputeNode::query()->where('cluster_id', $cluster->getKey())->orderBy('id')->get()->all();

        usort($nodes, static function (ComputeNode $a, ComputeNode $b) use ($holding, $sentTo): int {
            $rank = static fn (ComputeNode $n): int => $n->is($holding) ? 0 : (in_array($n->provider_name, $sentTo, true) ? 1 : 2);

            return $rank($a) <=> $rank($b);
        });

        $provider = $this->providers->for($cluster);

        foreach ($nodes as $node) {
            if ($provider->getVm($node->provider_name, $providerReference) !== null) {
                return $node;
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
