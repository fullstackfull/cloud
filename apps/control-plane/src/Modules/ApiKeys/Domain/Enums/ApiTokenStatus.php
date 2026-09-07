<?php

declare(strict_types=1);

namespace Lynomia\Modules\ApiKeys\Domain\Enums;

use Lynomia\Modules\ApiKeys\Infrastructure\Models\PersonalAccessToken;

/**
 * What a token is currently good for, as one word a client can branch on.
 *
 * Derived rather than stored: `revoked_at` and `expires_at` are the facts, and
 * a status column beside them would be a second answer to the same question
 * that drifts the first time a token expires without anything writing to the
 * row.
 */
enum ApiTokenStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public static function of(PersonalAccessToken $token): self
    {
        /*
         * Revocation is checked first. A token that was revoked and has since
         * passed its expiry is still, for the customer reading the list, the
         * one somebody turned off — and that is the fact the audit trail is
         * kept for.
         */
        return match (true) {
            $token->isRevoked() => self::Revoked,
            $token->isExpired() => self::Expired,
            default => self::Active,
        };
    }
}
