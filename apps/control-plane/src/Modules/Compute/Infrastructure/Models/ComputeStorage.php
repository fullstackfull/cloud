<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Infrastructure\Models;

use Database\Factories\ComputeStorageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Compute\Domain\Enums\StorageClass;

/**
 * A storage pool a machine's disks can be carved out of.
 *
 * node_id is nullable because shared storage — Ceph, an NFS export — belongs
 * to the cluster rather than to any one node, and a machine on it can move
 * between nodes without copying a disk. Local storage names its node, and a
 * machine placed on it is pinned there.
 *
 * total_gib and available_gib are a cache of what the hypervisor last
 * reported. Placement does not commit against them — the authoritative
 * commitment counter is on the node — because a pool's free space also moves
 * for reasons the platform never sees: snapshots, an operator's ISO upload,
 * another platform sharing the array.
 *
 * @property string $id
 * @property string $cluster_id
 * @property ?string $node_id
 * @property string $provider_name
 * @property StorageClass $storage_class
 * @property bool $shared
 * @property ?int $total_gib
 * @property ?int $available_gib
 * @property bool $is_active
 */
class ComputeStorage extends Model
{
    /** @use HasFactory<ComputeStorageFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'storage_class' => StorageClass::class,
            'shared' => 'boolean',
            'total_gib' => 'integer',
            'available_gib' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ComputeCluster, $this>
     */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(ComputeCluster::class, 'cluster_id');
    }

    /**
     * @return BelongsTo<ComputeNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(ComputeNode::class, 'node_id');
    }

    /**
     * Whether this pool can be used for a disk of the given size on the given
     * node.
     *
     * Unknown free space (a pool the hypervisor has not reported on yet) is
     * treated as usable rather than unusable: refusing to place anything on a
     * pool whose figures have not arrived would empty the fleet the first time
     * a sync failed.
     */
    public function canHost(string $nodeId, int $diskGib): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->node_id !== null && $this->node_id !== $nodeId) {
            return false;
        }

        return $this->available_gib === null || $this->available_gib >= $diskGib;
    }
}
