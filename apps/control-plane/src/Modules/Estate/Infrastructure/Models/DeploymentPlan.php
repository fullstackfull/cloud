<?php

declare(strict_types=1);

namespace Lynomia\Modules\Estate\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Estate\Domain\Enums\PlanRisk;
use Lynomia\Modules\Estate\Domain\Enums\SafetyClass;

/**
 * What a deployment would do, written down before anybody agrees to it.
 *
 * The fingerprint is the mechanism that makes approval mean something. It is
 * computed over the changes, and an approval records the fingerprint it
 * approved. Regenerate the plan — because the profile changed, or because the
 * machine drifted — and the fingerprint moves, and the old approval no longer
 * matches. Nobody has to remember to invalidate anything.
 *
 * @property list<array{action: string, target: string, risk: string, destructive: bool, detail?: string}> $changes
 * @property list<string> $unchanged
 * @property list<array{reason: string, detail: string}> $blockers
 * @property PlanRisk $risk
 * @property SafetyClass $required_safety_class
 * @property string $fingerprint
 */
class DeploymentPlan extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'unchanged' => 'array',
            'blockers' => 'array',
            'risk' => PlanRisk::class,
            'required_safety_class' => SafetyClass::class,
            'requires_reboot' => 'boolean',
            'requires_downtime' => 'boolean',
            'is_destructive' => 'boolean',
            'is_applicable' => 'boolean',
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
     * @return HasMany<DeploymentApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(DeploymentApproval::class);
    }

    /**
     * @return HasMany<DeploymentJob, $this>
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(DeploymentJob::class);
    }

    /**
     * The approval that is still good for this plan as it stands, if there is one.
     *
     * Both halves matter: not revoked, and approved against the fingerprint the
     * plan currently has. An approval whose fingerprint has moved on is a
     * record of a decision about a different plan.
     */
    public function standingApproval(): ?DeploymentApproval
    {
        return $this->approvals()
            ->whereNull('revoked_at')
            ->where('approved_fingerprint', $this->fingerprint)
            ->latest('approved_at')
            ->first();
    }

    public function isApproved(): bool
    {
        return $this->standingApproval() !== null;
    }

    /** Does this plan change anything at all? */
    public function isEmpty(): bool
    {
        return $this->changes === [];
    }
}
