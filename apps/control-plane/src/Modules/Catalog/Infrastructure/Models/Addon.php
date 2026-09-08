<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;

/**
 * An optional extra sold alongside a plan: additional IPv4 addresses, extra
 * disk, managed backups, a licence.
 */
class Addon extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'resources' => 'array',
            'is_active' => 'boolean',
            'max_quantity' => 'integer',
        ];
    }

    /**
     * @return HasMany<AddonPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(AddonPrice::class);
    }

    public function priceFor(string $currency, BillingPeriod $period): ?AddonPrice
    {
        return $this->prices->first(
            fn (AddonPrice $price): bool => $price->currency === strtoupper($currency)
                && $price->billing_period === $period
                && $price->is_active
        );
    }

    public function nameFor(string $locale): string
    {
        return $this->name[$locale]
            ?? $this->name[config('app.fallback_locale')]
            ?? (string) (array_values($this->name)[0] ?? '');
    }
}
