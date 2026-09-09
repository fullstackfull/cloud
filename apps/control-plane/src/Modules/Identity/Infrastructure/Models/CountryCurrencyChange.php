<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Identity\Domain\Enums\CountryCurrencyChangeState;

/**
 * @property string $id
 * @property string $customer_id
 * @property CountryCurrencyChangeState $state
 * @property ?string $from_country
 * @property ?string $to_country
 * @property string $from_currency
 * @property string $to_currency
 * @property string $reason
 * @property array{facts: array<string, mixed>, blockers: list<string>, warnings: list<string>} $impact
 * @property ?string $requested_by_user_id
 * @property ?string $decided_by_user_id
 * @property ?string $decision_note
 * @property CarbonImmutable $analysed_at
 * @property ?CarbonImmutable $decided_at
 * @property ?CarbonImmutable $scheduled_for
 * @property ?CarbonImmutable $applied_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class CountryCurrencyChange extends Model
{
    use HasUlids;

    protected $table = 'customer_country_currency_changes';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => CountryCurrencyChangeState::class,
            'impact' => 'array',
            'analysed_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'scheduled_for' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function changesCountry(): bool
    {
        return $this->from_country !== $this->to_country;
    }

    public function changesCurrency(): bool
    {
        return $this->from_currency !== $this->to_currency;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('state', array_map(
            static fn (CountryCurrencyChangeState $s): string => $s->value,
            array_values(array_filter(CountryCurrencyChangeState::cases(), static fn (CountryCurrencyChangeState $s): bool => $s->isOpen())),
        ));
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
