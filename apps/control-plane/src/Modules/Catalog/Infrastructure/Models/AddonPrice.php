<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

class AddonPrice extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billing_period' => BillingPeriod::class,
            'recurring_amount_minor' => 'integer',
            'setup_amount_minor' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Addon, $this>
     */
    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }

    public function recurring(): Money
    {
        return Money::ofMinor($this->recurring_amount_minor, $this->currency);
    }

    public function setup(): Money
    {
        return Money::ofMinor($this->setup_amount_minor, $this->currency);
    }
}
