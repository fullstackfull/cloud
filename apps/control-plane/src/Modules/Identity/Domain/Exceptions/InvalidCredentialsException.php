<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

final class InvalidCredentialsException extends DomainException
{
    public static function make(): self
    {
        // Deliberately identical whether the address is unknown or the password
        // is wrong: distinguishing them turns the login form into an account
        // enumeration oracle.
        return new self('These credentials do not match our records.');
    }

    public function errorCode(): string
    {
        return 'auth.invalid_credentials';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
