<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;

/**
 * One backup, as a customer may see it.
 *
 * Deliberately absent: the datastore's name, the node it ran on, the provider's
 * task identifier and the archive identifier. Every one of those names a piece
 * of the platform's infrastructure — which PBS host, which hypervisor, which
 * of a customer's neighbours share it — and a customer needs none of them to
 * know whether their data is safe. An operator who needs them reads the row.
 *
 * `verified` is three-valued and stays that way in the payload. Null means the
 * datastore has never verified this archive, which is not the same as having
 * verified it and failed; a client that folded them together would tell a
 * customer their backup is broken when nobody has looked at it yet.
 *
 * @mixin Backup
 */
final class BackupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_id' => $this->service_id,
            'state' => $this->state->value,
            'trigger' => $this->trigger->value,
            'mode' => $this->mode->value,

            // What a customer actually asks: is it done, can I restore from
            // it, and does somebody need to look at it.
            'is_in_flight' => $this->state->isInFlight(),
            'is_restorable' => $this->state->isRestorable(),
            'needs_attention' => $this->state->needsAttention(),

            'size_bytes' => $this->size_bytes,
            'verified' => $this->verified,
            'verified_at' => $this->verified_at?->toIso8601String(),

            'retention_days' => $this->retention_days,
            'expires_at' => $this->expires_at?->toIso8601String(),

            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),

            /*
             * The provider's own words, and the one field here that could
             * carry something it should not. It is redacted twice before it
             * reaches this row — once by the adapter, once by the action — and
             * it is published because a customer whose backup failed for lack
             * of disk space is owed a better answer than "it failed".
             */
            'failure_reason' => $this->failure_reason,
        ];
    }
}
