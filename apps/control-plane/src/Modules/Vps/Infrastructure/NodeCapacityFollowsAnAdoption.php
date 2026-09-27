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
 * of a hypervisor answer would leave the machine unbilled for longer.
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

        /** @var ComputeNode|null $holding */
        $holding = $held === null ? null : ComputeNode::query()->find($held->node_id);

        if ($held === null || $holding === null) {
            return [];
        }

        $identity = $job->reservedProviderIdentity();

        /** @var ComputeCluster|null $cluster */
        $cluster = ComputeCluster::query()->find($identity !== null ? $identity->clusterId : $holding->cluster_id);

        if ($cluster === null) {
            return ['node' => $holding->provider_name, 'moved' => false, 'reason' => 'the build\'s cluster is gone'];
        }

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
            return [
                'node' => $holding->provider_name,
                'moved' => false,
                'reason' => 'the hypervisor could not be asked where the machine is: '.$this->redactor->redactString($e->getMessage()),
            ];
        }

        if ($found === null) {
            return ['node' => $holding->provider_name, 'moved' => false, 'reason' => 'the machine was not found on any node of the cluster'];
        }

        if ($found->is($holding)) {
            return ['node' => $holding->provider_name, 'moved' => false];
        }

        $moved = $this->commitment->execute(
            reservationKey: $held->reservation_key,
            node: $found,
            storageId: $this->poolOn($found, $held),
            shape: $held->resources(),
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
