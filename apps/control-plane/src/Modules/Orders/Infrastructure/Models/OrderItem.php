<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * One purchased line, snapshotted at the moment of sale.
 *
 * The name, price and resources are copied rather than referenced so that a
 * later catalogue change cannot silently rewrite what a customer bought.
 */
class OrderItem extends Model
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
            'resources_snapshot' => 'array',
            'quantity' => 'integer',
            'unit_recurring_minor' => 'integer',
            'unit_setup_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function total(): Money
    {
        return Money::ofMinor($this->total_minor, $this->order->currency);
    }
}
