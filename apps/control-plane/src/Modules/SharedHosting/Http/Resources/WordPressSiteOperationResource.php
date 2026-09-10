<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Http\Responses\CustomerFailureReason;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSiteOperation;

/**
 * @mixin WordPressSiteOperation
 */
final class WordPressSiteOperationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->wordpress_site_id,
            'target_site_id' => $this->target_site_id,
            'kind' => $this->kind->value,
            'state' => $this->state->value,
            'is_in_flight' => $this->state->isInFlight(),
            'needs_attention' => $this->state->needsAttention(),
            'scope' => $this->scope?->value,
            'impact' => $this->impact,
            'failure_reason' => CustomerFailureReason::describe($this->failure_reason, 'wordpress.operation_failed'),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
