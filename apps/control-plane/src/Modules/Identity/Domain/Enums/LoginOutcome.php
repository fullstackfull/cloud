<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\Enums;

enum LoginOutcome: string
{
    case Success = 'success';
    case FailedCredentials = 'failed_credentials';
    case FailedTwoFactor = 'failed_two_factor';
    case Locked = 'locked';
    case UnverifiedEmail = 'unverified_email';
    case TokenIssued = 'token_issued';
    case LoggedOut = 'logged_out';
    case PasswordReset = 'password_reset';

    /*
     * A failed re-confirmation of the account password inside an already
     * authenticated session - disabling the second factor, changing the
     * password. It is deliberately distinct from FailedCredentials: the
     * customer reading their own history needs to be able to tell "somebody
     * tried to sign in as me" from "somebody already inside my session tried
     * to guess my password", because the two call for different responses.
     */
    case FailedPasswordConfirmation = 'failed_password_confirmation';

    public function isFailure(): bool
    {
        return match ($this) {
            self::FailedCredentials,
            self::FailedTwoFactor,
            self::FailedPasswordConfirmation,
            self::Locked,
            self::UnverifiedEmail => true,
            default => false,
        };
    }
}
