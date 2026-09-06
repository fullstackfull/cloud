<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Infrastructure\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;

/**
 * A purchasable configuration within a product.
 *
 * @property string $id
 * @property array<string, mixed> $resources
 * @property ?int $stock_limit
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'resources' => 'array',
            'placement_constraints' => 'array',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'stock_limit' => 'integer',
            'per_customer_limit' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<PlanPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    /**
     * @return BelongsToMany<Addon, $this>
     */
    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(Addon::class, 'plan_addon')->withPivot('is_required');
    }

    /**
     * The active price for a currency and period, or null when the plan is not
     * sold on those terms.
     *
     * Returning null rather than falling back to another currency is
     * deliberate: the platform never converts between currencies at checkout,
     * because a converted price is a price nobody set.
     */
    public function priceFor(string $currency, BillingPeriod $period): ?PlanPrice
    {
        return $this->prices
            ->first(fn (PlanPrice $price): bool => $price->currency === strtoupper($currency)
                && $price->billing_period === $period
                && $price->isAvailable());
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
    public function scopePurchasable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_public', true);
    }
}
