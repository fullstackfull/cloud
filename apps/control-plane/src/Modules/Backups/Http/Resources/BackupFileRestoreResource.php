<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Backups\Infrastructure\Models\BackupFileRestore;

/**
 * @mixin BackupFileRestore
 */
final class BackupFileRestoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'backup_id' => $this->backup_id,
            'state' => $this->state->value,
            'is_in_flight' => $this->state->isInFlight(),
            'needs_attention' => $this->state->needsAttention(),
            'paths' => $this->paths,
            'path_count' => $this->path_count,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'failure_reason' => $this->failure_reason,
        ];
    }
}
