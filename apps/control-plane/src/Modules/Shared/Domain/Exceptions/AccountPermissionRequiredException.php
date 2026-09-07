<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Domain\Exceptions;

/**
 * The caller is a member of the customer account but not one who may do this.
 *
 * Raised instead of Laravel's AuthorizationException because the framework
 * rewrites that into an AccessDeniedHttpException before the API's renderer
 * sees it, and the response then carries a generic `http.403` rather than the
 * `auth.forbidden` code the rest of the API documents. A DomainException
 * reaches the renderer intact.
 *
 * Deliberately says nothing about the resource. It is thrown before any lookup,
 * so it cannot depend on whether the id in the path names a real row - a 403
 * that appears only for ids that exist is an enumeration oracle wearing a
 * different status code.
 *
 * One class for the whole platform. Nine modules each grew their own copy of
 * this while their HTTP surfaces were written in parallel, under two different
 * names and with two different context shapes; which role inside an account may
 * spend its money is not any one module's rule, and nine copies of it is nine
 * places for the answer to drift.
 */
final class AccountPermissionRequiredException extends DomainException
{
    public static function forPermission(string $permission): self
    {
        $exception = new self('You are not permitted to perform this action.');

        /*
         * Naming the permission is safe and useful: it tells an integrator which
         * grant they are missing, which they could infer anyway from the
         * endpoint they called. It says nothing about the resource, or about
         * whether it exists.
         */
        return $exception->withContext(['required_permission' => $permission]);
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
