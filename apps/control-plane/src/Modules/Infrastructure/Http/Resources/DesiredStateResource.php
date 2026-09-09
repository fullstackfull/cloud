<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\DesiredState;

/**
 * @mixin DesiredState
 */
final class DesiredStateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'server_id' => $this->managed_server_id,
            'profile' => $this->profile?->key,
            'profile_name' => $this->profile?->name,
            'overrides' => (object) ($this->overrides ?? []),
            'assigned_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
