<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\DeploymentJob;

/**
 * @mixin DeploymentJob
 */
final class DeploymentJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'server_id' => $this->managed_server_id,
            'server_name' => $this->whenLoaded('server', fn (): ?string => $this->server?->name),
            'kind' => $this->kind,
            'state' => $this->state->value,
            'waits_for_somebody' => $this->state->waitsForSomebody(),
            'plan_id' => $this->deployment_plan_id,
            'approval_id' => $this->deployment_approval_id,
            'steps' => $this->steps ?? [],
            'failure_class' => $this->failure_class,
            'failure_detail' => $this->failure_detail,
            'requested_by' => $this->requested_by,
            'requested_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
