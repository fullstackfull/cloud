<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Infrastructure\Models;

use Database\Factories\RegionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A geography the platform sells capacity in.
 *
 * The two flags are separate on purpose. is_active takes a region out of the
 * catalogue entirely; accepts_new_services keeps it visible and running for
 * the customers already there while refusing new orders, which is what a
 * region that is out of space or being wound down actually needs.
 *
 * @property string $id
 * @property string $slug
 * @property array<string, string> $name
 * @property string $country
 * @property bool $is_active
 * @property bool $accepts_new_services
 */
class Region extends Model
{
    /** @use HasFactory<RegionFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'is_active' => 'boolean',
            'accepts_new_services' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Datacenter, $this>
     */
    public function datacenters(): HasMany
    {
        return $this->hasMany(Datacenter::class);
    }

    public function nameFor(string $locale): string
    {
        return $this->name[$locale]
            ?? $this->name[config('app.fallback_locale')]
            ?? (string) (array_values($this->name)[0] ?? '');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('accepts_new_services', true);
    }
}
