<?php

declare(strict_types=1);

namespace Lynomia\Modules\ApiKeys\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\ApiKeys\Domain\Enums\ApiTokenStatus;
use Lynomia\Modules\ApiKeys\Infrastructure\Models\PersonalAccessToken;

/**
 * What a customer may see of one of their own API tokens.
 *
 * Absent on purpose, and each for its own reason:
 *
 *  - token: the SHA-256 digest the platform authenticates against. It is not
 *    the plaintext and cannot be replayed as one, but it is the stored half of
 *    a credential, it is the value an offline dictionary attack would be run
 *    against, and it is the column an attacker who could write to this table
 *    would target. A customer has no use for it. The plaintext appears exactly
 *    once, from IssuedApiTokenResource, and never from here.
 *  - customer_id: the caller already knows which account they are acting for —
 *    the middleware decided it — and the id buys them nothing but a shape to
 *    probe other endpoints with.
 *  - tokenable_id / tokenable_type: the internal polymorphic wiring to the
 *    users table. It is the caller's own id, so it leaks nothing, but it
 *    publishes a storage detail that would then be a compatibility promise.
 *
 * `last_used_at` and `last_used_ip` *are* here, including on revoked tokens.
 * They are the customer's own operational record, and "which token did this,
 * and from where" is the question the whole revoke-rather-than-delete design
 * exists to keep answerable.
 *
 * @mixin PersonalAccessToken
 */
final class ApiTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => ApiTokenStatus::of($this->resource)->value,

            // Always ["*"] today. Serialised anyway so that a client written
            // now keeps working the day tokens can be issued narrower.
            'abilities' => $this->abilities ?? ['*'],

            // Null means "from anywhere", which is what an empty list means to
            // the model too; normalised so a client never has to treat [] and
            // null as different answers.
            'allowed_ip_ranges' => $this->allowed_ip_ranges === [] ? null : $this->allowed_ip_ranges,
            'rate_limit_per_minute' => $this->rate_limit_per_minute,

            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'last_used_ip' => $this->last_used_ip,
            'expires_at' => $this->expires_at?->toIso8601String(),

            // The revocation record. Kept on the row rather than removed with
            // it, so the audit trail survives the credential.
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoked_reason' => $this->revoked_reason,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
