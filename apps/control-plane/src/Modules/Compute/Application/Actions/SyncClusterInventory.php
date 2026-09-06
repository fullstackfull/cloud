<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Application\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Application\DTOs\ClusterInventorySyncResult;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteNodeState;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteStorageState;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\Enums\StorageClass;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Refreshes what the platform believes about a cluster's hardware.
 *
 * Read-only at the hypervisor, without exception. It calls exactly one method
 * on the adapter — listNodes(), which is a GET and nothing else — and every
 * write it makes is to the platform's own tables. This runs on a schedule
 * against every cluster in the fleet, so a mutation reaching this path would
 * be a mutation applied to every machine the platform owns; that is why the
 * discovery job and the provisioning job are different objects rather than one
 * "reconcile" that could grow a repair step.
 *
 * Two invariants keep the sync from doing damage with local writes alone:
 *
 *  - allocated_cpu_cores, allocated_memory_mib, allocated_storage_gib and
 *    vm_count are NEVER written here. They are the platform's commitments, and
 *    the hypervisor does not know about a machine that is still being built or
 *    capacity held for an order that has not shipped. Letting a sync recompute
 *    them from what it can see would free capacity that is genuinely spoken
 *    for, and the scheduler would immediately place a second machine on it;
 *
 *  - a node the cluster stops reporting is flagged unhealthy, never deleted.
 *    Deleting it would cascade its machines' node_id to null and lose the only
 *    record of where a customer's server is — at the exact moment, a node that
 *    dropped off the API, when that record matters most.
 */
final readonly class SyncClusterInventory
{
    public function __construct(
        private ComputeProviderFactory $providers,
        private SecretRedactor $redactor,
    ) {}

    /**
     * @throws ComputeProviderException
     */
    public function execute(ComputeCluster $cluster): ClusterInventorySyncResult
    {
        $provider = $this->providers->for($cluster);

        try {
            $reported = $provider->listNodes();
        } catch (ComputeProviderException $e) {
            /*
             * Recorded and re-thrown. The error is written outside any
             * transaction the caller may be holding, because a cluster whose
             * sync keeps failing is something an operator has to be able to
             * see in the cluster row without reading the logs — and a message
             * from a provider can quote the request that carried the token.
             */
            $cluster->fill([
                'last_sync_error' => $this->redactor->redactString($e->getMessage()),
            ])->save();

            throw $e;
        }

        return DB::transaction(function () use ($cluster, $reported): ClusterInventorySyncResult {
            $counts = ['nodes_created' => 0, 'nodes_updated' => 0, 'storages_created' => 0, 'storages_updated' => 0];
            $seen = [];

            foreach ($reported as $remote) {
                $node = $this->upsertNode($cluster, $remote, $counts);
                $seen[] = $node->provider_name;

                foreach ($remote->storages as $storage) {
                    $this->upsertStorage($cluster, $node, $storage, $counts);
                }
            }

            $missing = $this->flagMissingNodes($cluster, $seen);

            $cluster->fill([
                'last_synced_at' => now(),
                'last_sync_error' => null,
            ])->save();

            return new ClusterInventorySyncResult(
                clusterId: (string) $cluster->getKey(),
                nodesReported: count($reported),
                nodesCreated: $counts['nodes_created'],
                nodesUpdated: $counts['nodes_updated'],
                storagesCreated: $counts['storages_created'],
                storagesUpdated: $counts['storages_updated'],
                missingNodes: $missing,
            );
        });
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function upsertNode(ComputeCluster $cluster, RemoteNodeState $remote, array &$counts): ComputeNode
    {
        $node = ComputeNode::query()
            ->where('cluster_id', $cluster->getKey())
            ->where('provider_name', $remote->name)
            ->first();

        // Physical fact, refreshed on every pass. Note what is absent: the
        // allocated_* columns and vm_count.
        $observed = [
            'reported_cpu_usage' => $remote->cpuUsage,
            'reported_memory_used_mib' => $remote->memoryUsedMib,
            // Observed health, which is what excludes a node from placement.
            // The status column is left alone: it records an operator's
            // intent, and a node put into maintenance by a human must not be
            // returned to service by a background job that noticed it is
            // pingable.
            'is_healthy' => $remote->online,
            'last_seen_at' => now(),
            'capabilities' => $this->redactor->redact($remote->capabilities),
        ];

        /*
         * Hardware is only believed from a node that is up. Proxmox lists a
         * node that has dropped out of the cluster with maxcpu and maxmem at
         * zero, and writing those through would erase the recorded size of a
         * box that is still holding customers' machines — the same loss the
         * action refuses to take by deleting a node that stopped being
         * reported, arrived at through the back door. Zero also lies in the
         * dangerous direction on the way out: a node whose memory reads 0 is
         * one an operator cannot tell apart from a node with nothing on it.
         *
         * The figures come back on the first pass the node answers again.
         */
        if ($remote->online && $remote->cpuCores > 0) {
            $observed['cpu_cores'] = $remote->cpuCores;
        }

        if ($remote->online && $remote->memoryTotalMib > 0) {
            $observed['memory_mib'] = $remote->memoryTotalMib;
        }

        if ($remote->storageTotalGib !== null) {
            $observed['storage_gib'] = $remote->storageTotalGib;
        }

        if ($node === null) {
            $counts['nodes_created']++;

            return ComputeNode::create([
                'cluster_id' => $cluster->getKey(),
                'provider_name' => $remote->name,
                // A newly discovered node starts in maintenance rather than
                // active. Discovery is not authorisation: a node that appears
                // in the API has not necessarily been cabled, patched or added
                // to the monitoring the platform relies on, and placing a
                // customer on it the moment it answers a GET is how a machine
                // ends up on a box somebody is still building.
                'status' => NodeStatus::Maintenance,
                // Defaults for the NOT NULL columns the guard above may have
                // withheld: a node first discovered while it is offline is
                // recorded with no capacity rather than invented capacity, and
                // is picked up properly on the pass where it answers.
                'cpu_cores' => 0,
                'memory_mib' => 0,
                'storage_gib' => $remote->storageTotalGib ?? 0,
                'cpu_overcommit_ratio' => (float) config('compute.proxmox.default_cpu_overcommit_ratio', 4.0),
                'memory_headroom_percent' => (int) config('compute.proxmox.default_memory_headroom_percent', 10),
                ...$observed,
            ]);
        }

        $counts['nodes_updated']++;

        $node->fill($observed)->save();

        return $node;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function upsertStorage(
        ComputeCluster $cluster,
        ComputeNode $node,
        RemoteStorageState $remote,
        array &$counts,
    ): void {
        /*
         * Shared pools are recorded once for the cluster with a null node,
         * because a Ceph pool is the same pool seen from every node and a row
         * per node would let the scheduler commit the same space several times
         * over. The lookup handles the null explicitly: PostgreSQL treats
         * NULLs as distinct in a unique index, so the constraint on
         * (cluster_id, node_id, provider_name) would not stop a second row
         * being inserted for a shared pool on the next pass.
         */
        $query = ComputeStorage::query()
            ->where('cluster_id', $cluster->getKey())
            ->where('provider_name', $remote->name);

        $remote->shared
            ? $query->whereNull('node_id')
            : $query->where('node_id', $node->getKey());

        $storage = $query->first();

        $observed = [
            'total_gib' => $remote->totalGib,
            'available_gib' => $remote->availableGib,
            'is_active' => $remote->active,
        ];

        if ($storage === null) {
            $counts['storages_created']++;

            ComputeStorage::create([
                'cluster_id' => $cluster->getKey(),
                'node_id' => $remote->shared ? null : $node->getKey(),
                'provider_name' => $remote->name,
                // Inferred only at creation. What a pool is sold as is a
                // commercial decision an operator makes; overwriting it on
                // every pass with a guess drawn from the pool's name would
                // move machines onto the wrong tier the first time somebody
                // renamed a volume group.
                // An unclassifiable pool is recorded as the slowest class the
                // platform sells, never the fastest: the failure mode of
                // guessing low is a pool nobody places on until an operator
                // looks at it, and of guessing high is an NVMe plan served
                // from spinning disks.
                'storage_class' => $remote->storageClass ?? StorageClass::Hdd,
                'shared' => $remote->shared,
                ...$observed,
            ]);

            return;
        }

        $counts['storages_updated']++;

        $storage->fill($observed)->save();
    }

    /**
     * @param  list<string>  $seen
     * @return list<string>
     */
    private function flagMissingNodes(ComputeCluster $cluster, array $seen): array
    {
        /** @var list<ComputeNode> $missing */
        $missing = ComputeNode::query()
            ->where('cluster_id', $cluster->getKey())
            ->when($seen !== [], static fn (Builder $query): Builder => $query->whereNotIn('provider_name', $seen))
            ->where('is_healthy', true)
            ->get()
            ->all();

        foreach ($missing as $node) {
            $node->fill(['is_healthy' => false])->save();
        }

        return array_map(static fn (ComputeNode $node): string => $node->provider_name, $missing);
    }
}
