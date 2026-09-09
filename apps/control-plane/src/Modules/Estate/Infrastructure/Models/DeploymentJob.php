<?php

declare(strict_types=1);

namespace Lynomia\Modules\Estate\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Estate\Domain\Enums\DeploymentState;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * One attempt to make a machine match its desired state.
 *
 * Carries the two states the platform treats as different from failure, for the
 * same reason everywhere else does: Indeterminate means the controller was
 * asked and never answered, so nobody knows whether the playbook ran, and the
 * Timeout Rule says that is never retried automatically. NeedsReview means a
 * person has to decide something.
 *
 * A partial unique index allows one in-flight job per machine. Two playbooks
 * writing the same /etc concurrently is not a race worth surviving.
 *
 * @property DeploymentState $state
 * @property string $kind
 * @property string $idempotency_key
 * @property list<array{name: string, state: string, detail?: string}> $steps
 */
class DeploymentJob extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => DeploymentState::class,
            'steps' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ManagedServer, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(ManagedServer::class, 'managed_server_id');
    }

    /**
     * @return BelongsTo<DeploymentPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(DeploymentPlan::class, 'deployment_plan_id');
    }

    /**
     * @return BelongsTo<DeploymentApproval, $this>
     */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(DeploymentApproval::class, 'deployment_approval_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
