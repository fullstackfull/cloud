<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedReinstallState;
use Lynomia\Modules\Dedicated\Domain\StateMachines\DedicatedReinstallStateMachine;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Casts\RedactedJsonCast;

/**
 * One customer's request to have a physical machine rebuilt.
 *
 * The state moves only through {@see advanceTo()}, which asks the state
 * machine first. The value of this record is that an operator can trust its
 * history when deciding whether somebody's data is gone, and a handler able to
 * write the column directly could mark an erased machine `failed` — which
 * reads as "nothing happened".
 *
 * @property string $id
 * @property string $dedicated_server_id
 * @property ?string $service_id
 * @property ?string $customer_id
 * @property string $provisioning_job_id
 * @property DedicatedReinstallState $state
 * @property ?string $os_install_profile_id
 * @property ?string $os_install_profile_slug
 * @property ?string $pxe_boot_authorisation_id
 * @property ?string $bmc_endpoint_id
 * @property ?string $bmc_protocol
 * @property ?string $power_operation
 * @property array<string, mixed> $preserved
 * @property ?string $failure_code
 * @property ?string $failure_message
 * @property ?CarbonImmutable $state_changed_at
 * @property ?CarbonImmutable $destructive_started_at
 * @property ?CarbonImmutable $completed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class DedicatedReinstall extends Model
{
    protected $table = 'dedicated_reinstalls';

    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => DedicatedReinstallState::class,
            'preserved' => RedactedJsonCast::class,
            'state_changed_at' => 'immutable_datetime',
            'destructive_started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<DedicatedServer, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(DedicatedServer::class, 'dedicated_server_id');
    }

    /**
     * @return BelongsTo<ProvisioningJob, $this>
     */
    public function provisioningJob(): BelongsTo
    {
        return $this->belongsTo(ProvisioningJob::class, 'provisioning_job_id');
    }

    /**
     * @return BelongsTo<PxeBootAuthorisation, $this>
     */
    public function authorisation(): BelongsTo
    {
        return $this->belongsTo(PxeBootAuthorisation::class, 'pxe_boot_authorisation_id');
    }

    /**
     * Whether the installer has been started on this machine.
     *
     * The authority, because it is a fact about what happened rather than
     * about where the operation stopped: the stamp is written when the power
     * cycle is issued, so a rebuild that failed afterwards still answers true
     * while one that failed before it answers false.
     */
    public function destroyedData(): bool
    {
        return $this->destructive_started_at !== null;
    }

    public function advanceTo(DedicatedReinstallState $state): self
    {
        (new DedicatedReinstallStateMachine)->assertCanTransition($this->state, $state);

        $this->state = $state;
        $this->state_changed_at = CarbonImmutable::now();

        if ($state->impliesDestroyedData() && $this->destructive_started_at === null) {
            $this->destructive_started_at = CarbonImmutable::now();
        }

        if ($state === DedicatedReinstallState::Completed) {
            $this->completed_at = CarbonImmutable::now();
        }

        $this->save();

        return $this;
    }

    public function recordFailure(DedicatedReinstallState $state, string $code, string $message): self
    {
        $this->failure_code = $code;
        $this->failure_message = $message;

        return $this->advanceTo($state);
    }
}
