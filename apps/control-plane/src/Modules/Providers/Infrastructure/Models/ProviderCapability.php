<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Providers\Domain\Enums\CapabilityState;

/**
 * Whether one provider instance can do one specific thing.
 *
 * The row exists because "OpenSRS supports transfers" is not a fact about this
 * account. Reseller tiers differ, sandboxes implement a subset, and a TLD
 * entitlement is per-contract. So the platform asks the account and records
 * what it said, and the default until it has asked is Unknown rather than a
 * guess from the vendor's name.
 *
 * @property string $capability
 * @property CapabilityState $state
 */
class ProviderCapability extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => CapabilityState::class,
            'observed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ProviderInstance, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderInstance::class, 'provider_instance_id');
    }
}
