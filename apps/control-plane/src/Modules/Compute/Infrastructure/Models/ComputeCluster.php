<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ComputeClusterFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Compute\Domain\Enums\ClusterStatus;
use Lynomia\Modules\Compute\Domain\Enums\ComputeDriver;

/**
 * A hypervisor cluster the platform drives through one API endpoint.
 *
 * The row holds where the cluster is and how to reach it, but never the
 * credential itself: credentials_reference names an entry in configuration,
 * which is resolved at the moment a request is made. A token in this table
 * would be a token in every database backup, every replica and every support
 * export of a cluster listing.
 *
 * verify_tls is a column rather than a global setting because a lab cluster
 * with a self-signed certificate is a real thing, and the alternative — an
 * environment variable that disables verification everywhere — is how a
 * production cluster ends up unverified.
 *
 * @property string $id
 * @property string $datacenter_id
 * @property string $slug
 * @property string $name
 * @property ComputeDriver $driver
 * @property ?string $credentials_reference
 * @property ?string $api_endpoint
 * @property bool $verify_tls
 * @property ClusterStatus $status
 * @property ?CarbonImmutable $last_synced_at
 * @property ?string $last_sync_error
 */
class ComputeCluster extends Model
{
    /** @use HasFactory<ComputeClusterFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver' => ComputeDriver::class,
            'status' => ClusterStatus::class,
            'verify_tls' => 'boolean',
            'last_synced_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Datacenter, $this>
     */
    public function datacenter(): BelongsTo
    {
        return $this->belongsTo(Datacenter::class);
    }

    /**
     * @return HasMany<ComputeNode, $this>
     */
    public function nodes(): HasMany
    {
        return $this->hasMany(ComputeNode::class, 'cluster_id');
    }

    /**
     * @return HasMany<ComputeStorage, $this>
     */
    public function storages(): HasMany
    {
        return $this->hasMany(ComputeStorage::class, 'cluster_id');
    }

    /**
     * @return HasMany<VmTemplate, $this>
     */
    public function templates(): HasMany
    {
        return $this->hasMany(VmTemplate::class, 'cluster_id');
    }

    /**
     * @return HasMany<VirtualMachine, $this>
     */
    public function virtualMachines(): HasMany
    {
        return $this->hasMany(VirtualMachine::class, 'cluster_id');
    }

    public function acceptsPlacement(): bool
    {
        return $this->status->acceptsPlacement();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSchedulable(Builder $query): Builder
    {
        return $query->where('status', ClusterStatus::Active->value);
    }
}
