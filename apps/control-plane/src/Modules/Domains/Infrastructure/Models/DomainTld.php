<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DomainTldFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * One namespace this platform sells, and what it charges for it.
 *
 * The catalogue for domains is separate from plans and prices on purpose: a
 * plan is a shape sold at a price, and a TLD is a namespace whose price
 * depends on which of four operations is being performed and sometimes on the
 * exact name. See the migration for the rest of that argument.
 *
 * @property string $id
 * @property string $tld
 * @property bool $enabled
 * @property string $provider
 * @property bool $allows_registration
 * @property bool $allows_transfer
 * @property bool $allows_renewal
 * @property bool $supports_premium
 * @property int $minimum_term_years
 * @property int $maximum_term_years
 * @property string $currency
 * @property int $registration_price_minor
 * @property int $renewal_price_minor
 * @property int $transfer_price_minor
 * @property ?int $redemption_price_minor
 * @property ?int $registration_cost_minor
 * @property ?int $renewal_cost_minor
 * @property ?int $transfer_cost_minor
 * @property ?int $redemption_cost_minor
 * @property ?int $grace_days
 * @property ?int $redemption_days
 * @property ?array<string, mixed> $registrant_requirements
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class DomainTld extends Model
{
    /** @use HasFactory<DomainTldFactory> */
    use HasFactory, HasUlids;

    protected $table = 'domain_tlds';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'allows_registration' => 'boolean',
            'allows_transfer' => 'boolean',
            'allows_renewal' => 'boolean',
            'supports_premium' => 'boolean',
            'registrant_requirements' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Whether this platform will sell this operation on this namespace today.
     *
     * Two questions in one, and both have to be yes: the namespace has to be
     * enabled commercially, and the operation has to be one this TLD permits.
     * A TLD whose reseller agreement lapsed is disabled with every capability
     * still true, and a TLD that no registry will transfer is enabled with
     * transfers off.
     */
    public function permits(DomainOperationKind $kind): bool
    {
        if (! $this->enabled) {
            return false;
        }

        return match ($kind) {
            DomainOperationKind::Register => $this->allows_registration,
            DomainOperationKind::Transfer => $this->allows_transfer,
            DomainOperationKind::Renew => $this->allows_renewal,
            // A redemption price that was never set means this platform does
            // not know what the registry charges, and quoting a guess would
            // be quoting a penalty somebody has to pay.
            DomainOperationKind::Redeem => $this->redemption_price_minor !== null,
        };
    }

    /**
     * The list price for one year of an operation.
     *
     * Per year, so the caller multiplies by the term. Null only for
     * redemption, and only when this platform has not been told the price.
     */
    public function priceFor(DomainOperationKind $kind): ?Money
    {
        $minor = match ($kind) {
            DomainOperationKind::Register => $this->registration_price_minor,
            DomainOperationKind::Renew => $this->renewal_price_minor,
            DomainOperationKind::Transfer => $this->transfer_price_minor,
            DomainOperationKind::Redeem => $this->redemption_price_minor,
        };

        return $minor === null ? null : Money::ofMinor($minor, $this->currency);
    }

    /**
     * What one year of an operation costs the platform, where it knows.
     */
    public function costFor(DomainOperationKind $kind): ?Money
    {
        $minor = match ($kind) {
            DomainOperationKind::Register => $this->registration_cost_minor,
            DomainOperationKind::Renew => $this->renewal_cost_minor,
            DomainOperationKind::Transfer => $this->transfer_cost_minor,
            DomainOperationKind::Redeem => $this->redemption_cost_minor,
        };

        return $minor === null ? null : Money::ofMinor($minor, $this->currency);
    }

    /**
     * Whether a term in years is one this namespace accepts.
     */
    public function permitsTerm(int $years): bool
    {
        return $years >= $this->minimum_term_years && $years <= $this->maximum_term_years;
    }
}
