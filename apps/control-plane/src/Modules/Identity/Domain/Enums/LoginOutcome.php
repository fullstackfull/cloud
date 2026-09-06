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

    public function isFailure(): bool
    {
        return match ($this) {
            self::FailedCredentials, self::FailedTwoFactor, self::Locked, self::UnverifiedEmail => true,
            default => false,
        };
    }
}
