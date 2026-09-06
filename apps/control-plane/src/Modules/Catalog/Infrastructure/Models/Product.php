<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Infrastructure\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;

/**
 * A sellable product family, e.g. "Cloud VPS".
 *
 * @property string $id
 * @property ProductKind $kind
 * @property array<string, string> $name
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ProductKind::class,
            'name' => 'array',
            'description' => 'array',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Plan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    /**
     * Localised name with a fallback, so a locale added before its translations
     * renders the default language rather than an empty string.
     */
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
    public function scopePurchasable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_public', true);
    }
}
