<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerMember;

/**
 * One colleague, as their colleagues may see them.
 *
 * Name and email are here because a team screen without them names nobody. The
 * rest of the user row is not: whether they have two-factor on, when they last
 * signed in, how many sessions they hold. Those are their own security
 * questions and belong on their own security page, not on a list any member of
 * the account can read.
 *
 * `invited_by` is a name rather than a user id, for the same reason — and
 * because "who let them in" is the question, not "which row".
 *
 * @mixin CustomerMember
 */
final class TeamMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CustomerMember $member */
        $member = $this->resource;

        return [
            'id' => (string) $member->getKey(),
            'name' => $member->user?->name,
            'email' => $member->user?->email,
            'role' => $member->role->value,
            'invited_by' => $member->inviter?->name,
            'invited_at' => $member->invited_at?->toIso8601String(),
            'joined_at' => $member->accepted_at?->toIso8601String(),
        ];
    }
}
