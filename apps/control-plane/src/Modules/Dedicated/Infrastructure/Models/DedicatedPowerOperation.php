<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedPowerAction;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerOperationOutcome;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;

/**
 * One power intent against one chassis.
 *
 * Written before the management controller is called and settled after it
 * answers, so the row is the platform's memory of a request it may not be able
 * to ask about again. A reset that timed out is the case this exists for: the
 * chassis may be coming back up, and the only safe answer to a repeat is the
 * one already recorded here.
 *
 * @property string $id
 * @property string $dedicated_server_id
 * @property ?string $customer_id
 * @property ?string $requested_by_user_id
 * @property DedicatedPowerAction $action
 * @property string $idempotency_key
 * @property PowerOperationOutcome $outcome
 * @property ?bool $accepted
 * @property ?PowerState $resulting_power_state
 * @property ?string $provider_operation
 * @property ?string $bmc_endpoint_id
 * @property ?BmcProtocol $bmc_protocol
 * @property ?string $provider_task_id
 * @property ?string $failure_code
 * @property CarbonImmutable $requested_at
 * @property ?CarbonImmutable $settled_at
 */
class DedicatedPowerOperation extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => DedicatedPowerAction::class,
            'outcome' => PowerOperationOutcome::class,
            'resulting_power_state' => PowerState::class,
            'bmc_protocol' => BmcProtocol::class,
            'accepted' => 'boolean',
            'requested_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<DedicatedServer, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(DedicatedServer::class, 'dedicated_server_id');
    }
}
