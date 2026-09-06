<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Infrastructure\Models;

use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Domain\Enums\PaymentMethodKind;

/**
 * A customer's stored instrument, as a provider reference plus the few display
 * details a receipt needs.
 *
 * No part of a card ever reaches this table. Brand, last four and expiry are
 * what the provider hands back after tokenisation and are the most that may be
 * stored; the pan, cvv and any full token stay at the provider, which is what
 * keeps this database out of PCI scope.
 *
 * Soft-deleted rather than removed: a deleted card is still referenced by the
 * payment attempts that used it, and an operator investigating a chargeback
 * needs to see which instrument was charged.
 *
 * @property string $id
 * @property string $customer_id
 * @property string $provider
 * @property string $provider_reference
 * @property PaymentMethodKind $kind
 * @property bool $is_default
 */
class PaymentMethod extends Model
{
    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PaymentMethodKind::class,
            'is_default' => 'boolean',
            'expiry_month' => 'integer',
            'expiry_year' => 'integer',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Whether the card's own expiry date has passed.
     *
     * Compared at month granularity because a card is valid through the last
     * day of its expiry month, not up to the first.
     */
    public function isExpired(): bool
    {
        if ($this->expiry_year === null || $this->expiry_month === null) {
            return false;
        }

        $now = now();

        return $this->expiry_year < (int) $now->year
            || ($this->expiry_year === (int) $now->year && $this->expiry_month < (int) $now->month);
    }

    public function label(): string
    {
        return $this->last_four === null
            ? ucfirst($this->kind->value)
            : sprintf('%s ····%s', $this->brand ?? ucfirst($this->kind->value), $this->last_four);
    }
}
