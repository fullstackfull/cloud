<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Raised when a password check succeeded but the account has a second factor.
 *
 * Carries a short-lived, single-use challenge token rather than establishing a
 * session, so that possession of the password alone never yields authenticated
 * state — not even briefly.
 */
final class TwoFactorRequiredException extends DomainException
{
    public static function withChallenge(string $token, int $expiresInSeconds): self
    {
        $exception = new self('A two-factor code is required to complete sign-in.');

        return $exception->withContext([
            'challenge_token' => $token,
            'expires_in' => $expiresInSeconds,
        ]);
    }

    public function errorCode(): string
    {
        return 'auth.two_factor_required';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
