<?php

declare(strict_types=1);

namespace Lynomia\Modules\Estate\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * A person agreeing to one exact plan.
 *
 * It records the fingerprint rather than pointing at the plan and trusting it
 * to stay still, so "Alice approved this" survives the plan being regenerated
 * — as a record that Alice approved something else.
 */
class DeploymentApproval extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approved_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<DeploymentPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(DeploymentPlan::class, 'deployment_plan_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Still valid for the plan as it stands now? */
    public function coversCurrentPlan(): bool
    {
        return $this->revoked_at === null
            && $this->plan !== null
            && $this->approved_fingerprint === $this->plan->fingerprint;
    }
}
