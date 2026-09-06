<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Application\DTOs;

/**
 * What one inventory pass changed locally.
 *
 * Every figure describes a write to the platform's own tables. Nothing here
 * can describe a change at the hypervisor, because the sync makes none.
 *
 * @immutable
 */
final readonly class ClusterInventorySyncResult
{
    /**
     * @param  list<string>  $missingNodes  Nodes the platform knows about that the cluster did not
     *                                      report. They are flagged, never deleted.
     */
    public function __construct(
        public string $clusterId,
        public int $nodesReported,
        public int $nodesCreated,
        public int $nodesUpdated,
        public int $storagesCreated,
        public int $storagesUpdated,
        public array $missingNodes = [],
    ) {}

    public function hasMissingNodes(): bool
    {
        return $this->missingNodes !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'cluster_id' => $this->clusterId,
            'nodes_reported' => $this->nodesReported,
            'nodes_created' => $this->nodesCreated,
            'nodes_updated' => $this->nodesUpdated,
            'storages_created' => $this->storagesCreated,
            'storages_updated' => $this->storagesUpdated,
            'missing_nodes' => $this->missingNodes,
        ];
    }
}
