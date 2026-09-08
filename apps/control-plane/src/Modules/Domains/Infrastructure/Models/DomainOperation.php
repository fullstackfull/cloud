<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DomainOperationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationState;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * One attempt to acquire, keep, move or rescue a name — and what it cost.
 *
 * The money is recorded here at the moment it was agreed rather than derived
 * from today's price list, because a domain has many of these over its life
 * and a reconciliation months later has to answer what was charged, not what
 * would be charged now.
 *
 * @property string $id
 * @property ?string $domain_id
 * @property string $customer_id
 * @property string $name
 * @property DomainOperationKind $kind
 * @property DomainOperationState $state
 * @property int $term_years
 * @property string $currency
 * @property int $price_minor
 * @property ?int $cost_minor
 * @property ?string $order_id
 * @property ?string $invoice_id
 * @property string $idempotency_key
 * @property string $provider
 * @property ?string $provider_reference
 * @property int $attempts
 * @property ?CarbonImmutable $last_attempted_at
 * @property ?string $failure_code
 * @property ?string $failure_message
 * @property ?CarbonImmutable $completed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class DomainOperation extends Model
{
    /** @use HasFactory<DomainOperationFactory> */
    use HasFactory, HasUlids;

    protected $table = 'domain_operations';

    protected $guarded = [];

    /**
     * Never serialised, whatever a resource forgets to exclude.
     *
     * The authorisation code held here is a bearer credential for the whole
     * domain. It exists for the minutes between a transfer being paid for and
     * being sent, and it must not reach a payload, a log line or a debug dump
     * in between.
     *
     * @var list<string>
     */
    protected $hidden = ['authorisation_code'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DomainOperationKind::class,
            'authorisation_code' => 'encrypted',
            'state' => DomainOperationState::class,
            'last_attempted_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** What the customer was charged. */
    public function price(): Money
    {
        return Money::ofMinor($this->price_minor, $this->currency);
    }

    /**
     * What it cost the platform, where the provider says.
     *
     * Null rather than zero when unknown: a zero here would report the whole
     * selling price as margin in a report somebody prices a product from.
     */
    public function cost(): ?Money
    {
        return $this->cost_minor === null
            ? null
            : Money::ofMinor($this->cost_minor, $this->currency);
    }

    /**
     * Price less cost, or null when the cost is not known.
     *
     * Derived rather than stored, because both sides are stored: a third
     * column could disagree with the two it was computed from.
     */
    public function margin(): ?Money
    {
        $cost = $this->cost();

        return $cost === null ? null : $this->price()->minus($cost);
    }
}
