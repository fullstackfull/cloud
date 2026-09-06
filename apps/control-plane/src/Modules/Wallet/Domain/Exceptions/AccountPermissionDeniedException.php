<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Raised when the caller is a member of the acting account but their role
 * inside it does not carry the permission the endpoint needs.
 *
 * Deliberately not Laravel's AuthorizationException, for the framework reason
 * documented at length on Billing's namesake: Handler::render runs
 * prepareException before the render callbacks, which rewrites every
 * AuthorizationException into an AccessDeniedHttpException, so the stable
 * `auth.forbidden` code a client branches on never reaches the wire. A
 * DomainException is not rewritten on the way through.
 *
 * The permission itself is not put in the context. Telling a caller who has
 * just been refused exactly which grant to ask for is a detail for the
 * account's owner and the audit log, not for the response body.
 */
final class AccountPermissionDeniedException extends DomainException
{
    public static function make(): self
    {
        return new self('You are not permitted to perform this action.');
    }

    public function errorCode(): string
    {
        return 'auth.forbidden';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
