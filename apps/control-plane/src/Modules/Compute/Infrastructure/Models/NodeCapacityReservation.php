<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;

/**
 * A node's committed capacity, attributed to the machine it belongs to.
 *
 * @property string $id
 * @property string $reservation_key
 */
class NodeCapacityReservation extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vcpu' => 'integer',
            'memory_mib' => 'integer',
            'disk_gib' => 'integer',
            'released_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ComputeNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(ComputeNode::class, 'node_id');
    }

    /**
     * @return BelongsTo<ComputeStorage, $this>
     */
    public function storage(): BelongsTo
    {
        return $this->belongsTo(ComputeStorage::class, 'storage_id');
    }

    public function isLive(): bool
    {
        return $this->released_at === null;
    }

    public function resources(): VmResources
    {
        return new VmResources(
            vcpu: $this->vcpu,
            memoryMib: $this->memory_mib,
            diskGib: $this->disk_gib,
        );
    }
}
