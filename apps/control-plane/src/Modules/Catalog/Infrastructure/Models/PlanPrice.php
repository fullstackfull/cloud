<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Infrastructure\Models;

use Database\Factories\PlanPriceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * The price of one plan, in one currency, for one billing period.
 *
 * @property string $currency
 * @property BillingPeriod $billing_period
 * @property int $recurring_amount_minor
 * @property int $setup_amount_minor
 */
class PlanPrice extends Model
{
    /** @use HasFactory<PlanPriceFactory> */
    use HasFactory, HasUlids;

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
            'available_from' => 'immutable_datetime',
            'available_until' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function recurring(): Money
    {
        return Money::ofMinor($this->recurring_amount_minor, $this->currency);
    }

    public function setup(): Money
    {
        return Money::ofMinor($this->setup_amount_minor, $this->currency);
    }

    public function isAvailable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();

        return ($this->available_from === null || $this->available_from->lessThanOrEqualTo($now))
            && ($this->available_until === null || $this->available_until->greaterThan($now));
    }
}
