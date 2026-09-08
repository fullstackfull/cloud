<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Casts\RedactedJsonCast;
use Lynomia\Modules\Vps\Domain\Enums\ReinstallState;
use Lynomia\Modules\Vps\Domain\StateMachines\ReinstallStateMachine;

/**
 * One customer's request to have their disk replaced, and where it got to.
 *
 * The state is only ever moved through {@see advanceTo()}, which asks the
 * state machine first. Writing the column directly would let a handler mark a
 * failed reinstall completed, and the whole value of this record is that its
 * history is one somebody can trust when deciding whether a customer's data is
 * gone.
 *
 * @property string $id
 * @property string $virtual_machine_id
 * @property ?string $service_id
 * @property ?string $customer_id
 * @property string $provisioning_job_id
 * @property ReinstallState $state
 * @property ?string $template_id
 * @property ?string $template_reference
 * @property ?string $provider_task_id
 * @property ?string $provider_resource_id
 * @property ?string $provider_node
 * @property array<string, mixed> $preserved
 * @property ?string $failure_code
 * @property ?string $failure_message
 * @property ?CarbonImmutable $state_changed_at
 * @property ?CarbonImmutable $destroyed_at
 * @property ?CarbonImmutable $completed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class VmReinstall extends Model
{
    use HasUlids;

    protected $table = 'vm_reinstalls';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => ReinstallState::class,
            /*
             * Redacted on write like every other column that can carry a
             * provider payload. What is preserved here is the machine's
             * address and image reference, not a credential — but the cast is
             * applied by column type across this codebase rather than by the
             * author's judgement about each one, because the judgement is what
             * fails.
             */
            'preserved' => RedactedJsonCast::class,
            'state_changed_at' => 'immutable_datetime',
            'destroyed_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<VirtualMachine, $this>
     */
    public function virtualMachine(): BelongsTo
    {
        return $this->belongsTo(VirtualMachine::class, 'virtual_machine_id');
    }

    /**
     * @return BelongsTo<ProvisioningJob, $this>
     */
    public function provisioningJob(): BelongsTo
    {
        return $this->belongsTo(ProvisioningJob::class, 'provisioning_job_id');
    }

    /**
     * Move to the next state, or refuse.
     *
     * The timestamps are stamped here rather than by the caller so that
     * "when did this stop being recoverable" is recorded by the same code that
     * decides it has.
     */
    public function advanceTo(ReinstallState $state): self
    {
        (new ReinstallStateMachine)->assertCanTransition($this->state, $state);

        $this->state = $state;
        $this->state_changed_at = CarbonImmutable::now();

        if ($state->impliesDestroyedData() && $this->destroyed_at === null) {
            $this->destroyed_at = CarbonImmutable::now();
        }

        if ($state === ReinstallState::Completed) {
            $this->completed_at = CarbonImmutable::now();
        }

        $this->save();

        return $this;
    }

    /**
     * Whether this operation has already destroyed the customer's data.
     *
     * The authority, because it is a fact about what happened rather than
     * about where the operation stopped: the timestamp is stamped on entering
     * the destructive phase, so a reinstall that failed afterwards still
     * answers true while one that failed before it answers false.
     */
    public function destroyedData(): bool
    {
        return $this->destroyed_at !== null;
    }

    /**
     * Record why it ended badly, alongside the state that says how badly.
     */
    public function recordFailure(ReinstallState $state, string $code, string $message): self
    {
        $this->failure_code = $code;
        $this->failure_message = $message;

        return $this->advanceTo($state);
    }
}
