<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DedicatedServerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentKind;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Domain\StateMachines\DedicatedServerStateMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * One physical machine, from the day it is racked to long after it is
 * switched off for the last time.
 *
 * Two things distinguish this row from a virtual machine's, and both shape
 * every action in the module:
 *
 *  - **it is stock.** The machine exists whether or not anybody has bought it,
 *    so `status` is inventory state, not a lifecycle derived from a service.
 *    Nothing assigns `status` directly: every change goes through
 *    {@see DedicatedServerStateMachine},
 *    which is what keeps a machine mid-install out of the pool an order draws
 *    from and keeps a retired serial out of it for ever;
 *
 *  - **it is never deleted.** `retired` is terminal and the row stays. A
 *    replacement machine gets its own row with its own history, because "what
 *    happened to my old server" is a question about the old server.
 *
 * `power_state` is what a BMC last reported and is refreshed by discovery.
 * `status` is what the platform intends, and discovery never writes it: a
 * machine an operator put into maintenance must not be returned to service by
 * a background job that noticed it answers again.
 *
 * @property string $id
 * @property string $datacenter_id
 * @property ?string $rack_id
 * @property string $manufacturer
 * @property string $model
 * @property string $serial
 * @property ?string $asset_tag
 * @property ?int $rack_unit
 * @property int $height_units
 * @property ?string $hardware_profile
 * @property ?string $os_install_profile_id
 * @property DedicatedServerStatus $status
 * @property PowerState $power_state
 * @property ?string $customer_id
 * @property ?string $service_id
 * @property ?CarbonImmutable $reserved_until
 * @property ?string $reserved_by_order_id
 * @property ?string $notes
 * @property ?CarbonImmutable $last_seen_at
 * @property ?CarbonImmutable $retired_at
 */
class DedicatedServer extends Model
{
    /** @use HasFactory<DedicatedServerFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * The state a row has before anything happens to it.
     *
     * This duplicates the column default on purpose. A default declared only
     * in the database applies during the INSERT and not to the model object
     * that create() hands back, so a caller that renders its own result reads
     * null for a column the table will happily report a value for one query
     * later. Declared here, every creation path starts in the same state,
     * including the ones written after this comment.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'power_state' => 'unknown',
        'status' => 'available',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DedicatedServerStatus::class,
            'power_state' => PowerState::class,
            'rack_unit' => 'integer',
            'height_units' => 'integer',
            'reserved_until' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Datacenter, $this>
     */
    public function datacenter(): BelongsTo
    {
        return $this->belongsTo(Datacenter::class);
    }

    /**
     * @return BelongsTo<Rack, $this>
     */
    public function rack(): BelongsTo
    {
        return $this->belongsTo(Rack::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function reservedByOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'reserved_by_order_id');
    }

    /**
     * @return HasMany<ServerComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(ServerComponent::class);
    }

    /**
     * @return HasMany<BmcEndpoint, $this>
     */
    public function bmcEndpoints(): HasMany
    {
        return $this->hasMany(BmcEndpoint::class);
    }

    /**
     * @return HasMany<PxeBootAuthorisation, $this>
     */
    public function pxeAuthorisations(): HasMany
    {
        return $this->hasMany(PxeBootAuthorisation::class);
    }

    /**
     * The best controller this machine has.
     *
     * "Best" is {@see BmcProtocol::preferenceRank()}:
     * Redfish over iLO over IPMI. A machine with both a Redfish and an IPMI
     * endpoint is reached over Redfish, which is how a fleet migrates off IPMI
     * one machine at a time without a single caller learning what protocol it
     * is speaking.
     */
    public function preferredBmcEndpoint(): ?BmcEndpoint
    {
        /** @var list<BmcEndpoint> $endpoints */
        $endpoints = $this->bmcEndpoints()->get()->all();

        usort(
            $endpoints,
            static fn (BmcEndpoint $a, BmcEndpoint $b): int => $a->protocol->preferenceRank() <=> $b->protocol->preferenceRank(),
        );

        return $endpoints[0] ?? null;
    }

    /**
     * The MAC address a network install should be authorised for.
     *
     * The NIC explicitly flagged for provisioning wins. Falling back to "the
     * first NIC" is a coin toss on machines whose onboard ports are enumerated
     * in firmware order rather than in the order they are cabled, so the
     * fallback is deliberately last and returns null rather than guessing when
     * no NIC reports an address at all — an authorisation with no MAC would be
     * an authorisation for every machine on the provisioning VLAN.
     */
    public function provisioningMacAddress(): ?string
    {
        $nics = $this->components()
            ->ofKind(ComponentKind::Nic)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($nics as $nic) {
            if ($nic->isProvisioningInterface() && $nic->macAddress() !== null) {
                return $nic->macAddress();
            }
        }

        foreach ($nics as $nic) {
            if ($nic->macAddress() !== null) {
                return $nic->macAddress();
            }
        }

        return null;
    }

    public function isRetired(): bool
    {
        return $this->status === DedicatedServerStatus::Retired;
    }

    /**
     * Whether the hold on this machine is still in force.
     *
     * A reservation with no expiry is held indefinitely and is treated as
     * live: an operator holding a machine for a named order should not lose it
     * to a reaper because nobody set a date.
     */
    public function hasLiveReservation(): bool
    {
        if ($this->status !== DedicatedServerStatus::Reserved) {
            return false;
        }

        return $this->reserved_until === null || $this->reserved_until->isFuture();
    }

    /**
     * Machines that may be handed to an order.
     *
     * The retired check is redundant against the status filter and is kept
     * anyway: it is the one mistake in this module whose cost is a customer
     * being sold a decommissioned machine, and a belt-and-braces predicate is
     * cheaper than that conversation.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAllocatable(Builder $query): Builder
    {
        return $query
            ->where('status', DedicatedServerStatus::Available->value)
            ->whereNull('retired_at');
    }
}
