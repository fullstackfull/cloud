<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\DeploymentPlan;

/**
 * @mixin DeploymentPlan
 */
final class DeploymentPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $approval = $this->standingApproval();

        return [
            'id' => $this->id,
            'server_id' => $this->managed_server_id,
            'fingerprint' => $this->fingerprint,
            'changes' => $this->changes,
            'unchanged' => $this->unchanged,
            'blockers' => $this->blockers,
            'risk' => $this->risk->value,
            'required_safety_class' => $this->required_safety_class->value,
            'requires_reboot' => $this->requires_reboot,
            'is_destructive' => $this->is_destructive,
            'is_applicable' => $this->is_applicable,
            'approval' => $approval === null ? null : [
                'id' => $approval->id,
                'approved_fingerprint' => $approval->approved_fingerprint,
                'approved_at' => $approval->approved_at->toIso8601String(),
                'approved_by' => $approval->approved_by,
                'reason' => $approval->reason,
            ],
            'planned_by' => $this->planned_by,
            'planned_at' => $this->created_at?->toIso8601String(),
            'refreshed_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
