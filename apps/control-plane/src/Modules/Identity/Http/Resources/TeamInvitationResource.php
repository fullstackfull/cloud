<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerInvitation;

/**
 * An outstanding offer, as the account that made it may see it.
 *
 * **No token, and no field that could ever carry one.** The row stores a hash
 * and this class does not read it; an invitation that could be listed with its
 * token would let anybody who can see the team screen join as anybody who has
 * been invited. The only copy of a token is in the mail.
 *
 * @mixin CustomerInvitation
 */
final class TeamInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CustomerInvitation $invitation */
        $invitation = $this->resource;

        return [
            'id' => (string) $invitation->getKey(),
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'status' => $invitation->status()->value,
            'invited_by' => $invitation->invitedBy?->name,
            'invited_at' => $invitation->created_at->toIso8601String(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'sent_count' => $invitation->sent_count,
            'last_sent_at' => $invitation->last_sent_at?->toIso8601String(),
        ];
    }
}
