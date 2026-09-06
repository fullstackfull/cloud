<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Infrastructure\Models;

use Database\Factories\DatacenterFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical facility inside a region.
 *
 * It is the unit of correlated failure — one building, one power feed, one
 * carrier room — which is why networks, address pools and hypervisor clusters
 * all hang off it rather than off the region.
 *
 * @property string $id
 * @property string $region_id
 * @property string $slug
 * @property string $name
 * @property ?string $facility
 * @property bool $is_active
 */
class Datacenter extends Model
{
    /** @use HasFactory<DatacenterFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Region, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * @return HasMany<ComputeCluster, $this>
     */
    public function clusters(): HasMany
    {
        return $this->hasMany(ComputeCluster::class, 'datacenter_id');
    }
}
