<?php

declare(strict_types=1);

namespace Lynomia\Modules\ApiKeys\Application\Actions;

use Lynomia\Modules\ApiKeys\Infrastructure\Models\PersonalAccessToken;

/**
 * Turns a token off by recording that it was turned off.
 *
 * Not a delete. "Which token made this call?" has to stay answerable after the
 * token is gone — `last_used_at` and `last_used_ip` on a revoked row are how an
 * incident is reconstructed, and a DELETE takes the evidence with the
 * credential. The row stops authenticating the moment `revoked_at` is set,
 * because ApiTokenServiceProvider refuses any token that is not `isUsable()`.
 *
 * Repeating a revocation is safe and keeps the first reason. A retry after a
 * timeout must not overwrite "leaked in a public gist" with a second call's
 * generic wording, and the moment the credential actually stopped working is
 * the first `revoked_at`, not the last.
 */
final class RevokeApiToken
{
    /**
     * The widest reason `revoked_reason` will accept without being mangled.
     *
     * PersonalAccessToken::revoke() cuts with `substr()`, which counts bytes.
     * The column counts characters, so the two disagree the moment a reason is
     * not ASCII — see safeReason().
     */
    private const int REASON_BYTES = 128;

    public function execute(PersonalAccessToken $token, string $reason): PersonalAccessToken
    {
        if ($token->isRevoked()) {
            return $token;
        }

        $token->revoke(self::safeReason($reason));

        return $token;
    }

    /**
     * A reason the model's byte-counting truncation cannot corrupt.
     *
     * `PersonalAccessToken::revoke()` applies `substr($reason, 0, 128)`. A
     * 43-character Chinese reason is 129 bytes, so that cut lands mid-character
     * and produces a string that is no longer valid UTF-8 — Postgres refuses it
     * ("invalid byte sequence for encoding UTF8") and the request dies with a
     * 500. The token is then *not* revoked, which is the worst possible outcome
     * for this endpoint in particular: the customer typed a reason in their own
     * language because a credential had just leaked, and the platform answered
     * by leaving it live.
     *
     * `mb_strcut` is the byte-bounded cut that respects character boundaries,
     * so what reaches the model is already short enough for its `substr` to be
     * a no-op.
     *
     * This belongs one line higher, in the model: `mb_substr($reason, 0, 128)`
     * would keep all 128 characters the column can actually hold, where this
     * over-truncates a Chinese reason to 42. Infrastructure is owned elsewhere;
     * when that changes, this guard becomes redundant rather than wrong.
     */
    private static function safeReason(string $reason): string
    {
        if (strlen($reason) <= self::REASON_BYTES) {
            return $reason;
        }

        return mb_strcut($reason, 0, self::REASON_BYTES, 'UTF-8');
    }
}
