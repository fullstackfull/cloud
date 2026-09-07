<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Raised when the caller is a member of the acting account but their role
 * inside it does not carry the permission the endpoint needs.
 *
 * Deliberately *not* Laravel's AuthorizationException, and the reason is a
 * framework detail worth stating rather than rediscovering. The central
 * renderer in bootstrap/app.php has an arm that turns an AuthorizationException
 * into `auth.forbidden`, but that arm never runs: Handler::render calls
 * prepareException *before* the render callbacks, and prepareException rewrites
 * every AuthorizationException into an AccessDeniedHttpException. The generic
 * `http.403` arm catches it instead, and the stable code the API documents —
 * the one a client branches on — never reaches the wire.
 *
 * A DomainException is not rewritten on the way through, so raising one here
 * produces the documented body no matter which way that framework detail is
 * eventually resolved. The status and the message are identical either way;
 * only the machine-readable code differs, and only that code is worth a class.
 *
 * The permission itself is not put in the context. It would tell a caller who
 * has just been refused exactly which grant to go and ask for, which is a
 * detail for the account's owner and the audit log rather than for the
 * response body.
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
