<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DomainQuoteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * A price this platform will stand behind for a few minutes.
 *
 * The customer is handed an id. Checkout re-reads this row and uses the amount
 * stored here — no price travels through the browser, because a premium name
 * can be a hundred times the ordinary price for its TLD and a checkout that
 * accepted a submitted amount would let somebody buy the expensive name at the
 * cheap price.
 *
 * Quotes expire because availability and premium pricing do. A registry can
 * sell the name to somebody else between the search and the checkout, and an
 * hour-old quote is a promise about a world that has moved on.
 *
 * @property string $id
 * @property string $customer_id
 * @property string $name
 * @property string $tld
 * @property DomainOperationKind $operation
 * @property int $term_years
 * @property bool $premium
 * @property string $currency
 * @property int $price_minor
 * @property ?int $cost_minor
 * @property ?string $provider_reference
 * @property string $provider
 * @property CarbonImmutable $expires_at
 * @property ?CarbonImmutable $consumed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class DomainQuote extends Model
{
    /** @use HasFactory<DomainQuoteFactory> */
    use HasFactory, HasUlids;

    protected $table = 'domain_quotes';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operation' => DomainOperationKind::class,
            'premium' => 'boolean',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The total for the whole term, not the price of one year. */
    public function total(): Money
    {
        return Money::ofMinor($this->price_minor, $this->currency);
    }

    public function cost(): ?Money
    {
        return $this->cost_minor === null
            ? null
            : Money::ofMinor($this->cost_minor, $this->currency);
    }

    /**
     * Whether this quote may still be turned into an order.
     *
     * Spent once. A quote that could be redeemed twice would be two
     * registrations at one agreed price, which matters most for exactly the
     * premium names where the price is worth gaming.
     */
    public function isRedeemable(?CarbonImmutable $now = null): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isAfter($now ?? CarbonImmutable::now());
    }
}
