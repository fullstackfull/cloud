<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Infrastructure\Models;

use Database\Factories\TaxRuleFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Lynomia\Modules\Billing\Domain\ValueObjects\TaxRate;

/**
 * A rate that applies to a jurisdiction over a period of time.
 *
 * Rules are never edited to change a rate: a rate change closes the old rule
 * with an effective_until and opens a new one. That is what lets an invoice
 * issued last year still resolve the rate it was issued under.
 *
 * @property string $id
 * @property string $name
 * @property string $country
 * @property string|null $state
 * @property string $rate exact decimal string such as "0.150000"
 * @property bool $is_inclusive
 * @property bool $is_active
 */
class TaxRule extends Model
{
    /** @use HasFactory<TaxRuleFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // `rate` stays a string for the same reason a TaxRate holds one:
            // 0.15 as a double is 0.1499999999999999944…, which is off by a
            // fils on a large enough invoice.
            'is_inclusive' => 'boolean',
            'is_active' => 'boolean',
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
        ];
    }

    public function taxRate(): TaxRate
    {
        return TaxRate::of($this->rate, $this->name, $this->is_inclusive);
    }

    /**
     * A rule without a state applies to the whole country, and is the fallback
     * when no state-level rule matches.
     */
    public function isCountryWide(): bool
    {
        return $this->state === null;
    }

    public function isEffectiveAt(DateTimeInterface $at): bool
    {
        return $this->is_active
            && $this->effective_from->lessThanOrEqualTo($at)
            && ($this->effective_until === null || $this->effective_until->greaterThan($at));
    }

    /**
     * Rules in force at a given instant.
     *
     * effective_until is exclusive: a rule that ends at midnight does not apply
     * to the invoice issued at midnight, which is the one the successor rule
     * starting at that instant covers. Exactly one of the pair matches.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeEffectiveAt(Builder $query, DateTimeInterface $at): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('effective_from', '<=', $at)
            ->where(fn (Builder $inner): Builder => $inner
                ->whereNull('effective_until')
                ->orWhere('effective_until', '>', $at));
    }
}
