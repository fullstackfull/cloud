<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ComputeNodeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Compute\Domain\Enums\CpuArchitecture;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;

/**
 * One hypervisor.
 *
 * Two different sets of numbers live on this row and confusing them is the
 * most consequential mistake the module can make:
 *
 *  - cpu_cores / memory_mib / storage_gib and the reported_* columns are
 *    physical fact, refreshed from the hypervisor by SyncClusterInventory;
 *  - allocated_* and vm_count are the platform's own commitments, written only
 *    by ReserveNodeCapacity and ReleaseNodeCapacity under a row lock.
 *
 * An inventory sync must never write the second set. The hypervisor does not
 * know about a machine that is still being built or capacity held for an order
 * that has not shipped, so letting it overwrite the commitments would free
 * capacity that is genuinely spoken for and place a second machine on it.
 *
 * Scheduling reads the commitments rather than reported usage on purpose: a
 * machine that is provisioned but idle reports almost no memory in use, and a
 * scheduler that believed the reported figure would stack a node full of
 * machines that are merely not busy yet.
 *
 * @property string $id
 * @property string $cluster_id
 * @property string $provider_name
 * @property NodeStatus $status
 * @property int $cpu_cores
 * @property int $memory_mib
 * @property int $storage_gib
 * @property int $allocated_cpu_cores
 * @property int $allocated_memory_mib
 * @property int $allocated_storage_gib
 * @property ?float $reported_cpu_usage
 * @property ?int $reported_memory_used_mib
 * @property float $cpu_overcommit_ratio
 * @property int $memory_headroom_percent
 * @property int $vm_count
 * @property bool $is_healthy
 * @property ?CarbonImmutable $last_seen_at
 * @property ?array<string, mixed> $capabilities
 */
class ComputeNode extends Model
{
    /** @use HasFactory<ComputeNodeFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * The state a row has before anything happens to it.
     *
     * This duplicates the column default on purpose. A default declared only
     * in the database applies during the INSERT and not to the model object
     * that create() hands back, so a caller that renders its own result reads
     * null for a column the table will happily report a value for one query
     * later. Declared here, every creation path starts in the same state,
     * including the ones written after this comment.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => NodeStatus::class,
            'cpu_cores' => 'integer',
            // Explicit integer casts because PostgreSQL returns bigint columns
            // as strings through PDO, and capacity arithmetic on a string is a
            // silent float conversion waiting for a big enough fleet.
            'memory_mib' => 'integer',
            'storage_gib' => 'integer',
            'allocated_cpu_cores' => 'integer',
            'allocated_memory_mib' => 'integer',
            'allocated_storage_gib' => 'integer',
            'reported_cpu_usage' => 'float',
            'reported_memory_used_mib' => 'integer',
            'cpu_overcommit_ratio' => 'float',
            'memory_headroom_percent' => 'integer',
            'vm_count' => 'integer',
            'is_healthy' => 'boolean',
            'last_seen_at' => 'immutable_datetime',
            'capabilities' => 'array',
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
     * @return HasMany<ComputeStorage, $this>
     */
    public function storages(): HasMany
    {
        return $this->hasMany(ComputeStorage::class, 'node_id');
    }

    /**
     * @return HasMany<VirtualMachine, $this>
     */
    public function virtualMachines(): HasMany
    {
        return $this->hasMany(VirtualMachine::class, 'node_id');
    }

    /**
     * Memory the platform is allowed to commit to guests.
     *
     * The headroom is not a safety margin against over-scheduling — that is
     * what the capacity threshold is for. It is memory the hypervisor itself
     * needs: the kernel, the storage layer, the qemu processes' own overhead.
     * Commit it to a guest and the node swaps, which takes down every machine
     * on it at once.
     */
    public function usableMemoryMib(): int
    {
        return (int) floor($this->memory_mib * (100 - $this->memory_headroom_percent) / 100);
    }

    public function freeMemoryMib(): int
    {
        return max(0, $this->usableMemoryMib() - $this->allocated_memory_mib);
    }

    /**
     * Virtual cores the platform is allowed to hand out.
     *
     * CPU is overcommitted because time-slicing degrades gracefully — a busy
     * node makes everything slower, which is recoverable. Memory is not
     * overcommitted anywhere in this module for exactly the opposite reason.
     */
    public function usableCpuCores(): int
    {
        return (int) floor($this->cpu_cores * $this->cpu_overcommit_ratio);
    }

    public function freeCpuCores(): int
    {
        return max(0, $this->usableCpuCores() - $this->allocated_cpu_cores);
    }

    public function freeStorageGib(): int
    {
        return max(0, $this->storage_gib - $this->allocated_storage_gib);
    }

    /**
     * The share of committable memory already spoken for, as a percentage.
     */
    public function memoryCommittedPercent(): float
    {
        $usable = $this->usableMemoryMib();

        return $usable < 1 ? 100.0 : ($this->allocated_memory_mib / $usable) * 100;
    }

    /**
     * The architecture this node runs, defaulting to x86_64 when the
     * hypervisor has not told us. Every Proxmox node the platform has is
     * x86_64; an ARM node that failed to report would be excluded from
     * placement rather than mis-served, because the capability check compares
     * exactly.
     */
    public function architecture(): CpuArchitecture
    {
        $reported = $this->capabilities['architecture'] ?? null;

        return is_string($reported)
            ? (CpuArchitecture::tryFrom($reported) ?? CpuArchitecture::X86_64)
            : CpuArchitecture::X86_64;
    }

    /** Whether this node may take new machines at all. */
    public function isSchedulable(): bool
    {
        return $this->status->acceptsPlacement() && $this->is_healthy;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSchedulable(Builder $query): Builder
    {
        return $query->where('status', NodeStatus::Active->value)->where('is_healthy', true);
    }
}
