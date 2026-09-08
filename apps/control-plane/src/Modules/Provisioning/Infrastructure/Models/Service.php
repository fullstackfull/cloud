<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;

/**
 * The generic thing a customer bought.
 *
 * Billing, support and the customer portal operate against this row and never
 * against a provider-specific resource, which is what lets a service move from
 * one hypervisor to another without touching a single invoice.
 *
 * The status column is written only through TransitionService, so that every
 * change is checked against the state machine.
 *
 * @property string $id
 * @property string $customer_id
 * @property ?string $order_id
 * @property ?string $order_item_id
 * @property ?string $subscription_id
 * @property ?string $plan_id
 * @property string $kind
 * @property ?string $label
 * @property ServiceStatus $status
 * @property array<string, mixed> $resources
 * @property ?CarbonImmutable $activated_at
 * @property ?CarbonImmutable $suspended_at
 * @property ?CarbonImmutable $retention_ends_at
 * @property ?CarbonImmutable $retention_warned_at
 * @property ?string $ended_reason
 * @property ?CarbonImmutable $terminated_at
 */
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ServiceStatus::class,
            'resources' => 'array',
            'activated_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'retention_ends_at' => 'immutable_datetime',
            'retention_warned_at' => 'immutable_datetime',
            'terminated_at' => 'immutable_datetime',
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
     * @return HasMany<ProvisioningJob, $this>
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(ProvisioningJob::class);
    }

    public function isUsable(): bool
    {
        return $this->status->isUsable();
    }

    /**
     * Whether this service is still standing on infrastructure someone is
     * paying for, which is what a reaper and a capacity report both need.
     */
    public function holdsResources(): bool
    {
        return $this->status->holdsResources();
    }
}
