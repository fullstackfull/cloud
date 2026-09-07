<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The caller is a member of the account but not one who may spend its money.
 *
 * Raised instead of Laravel's AuthorizationException because the framework
 * rewrites that into an AccessDeniedHttpException before the API's renderer
 * ever sees it, and the response then carries the generic `http.403` rather
 * than the `auth.forbidden` code the rest of the API documents. A
 * DomainException reaches the renderer intact.
 *
 * Deliberately says nothing about the resource. This is thrown before any
 * lookup, so it cannot depend on whether the id in the path names a real row —
 * a 403 that appears only for ids that exist is an enumeration oracle wearing
 * a different status code.
 *
 * The rule it enforces — which role inside a customer account may pay its
 * invoices — is not really the Payments module's to own. It lives here because
 * the Identity module is owned elsewhere, and the same class exists in Orders
 * for the same reason; when a shared guard exists both should give way to it,
 * and the error code must not change when they do.
 */
final class AccountPermissionRequiredException extends DomainException
{
    public static function forPermission(string $permission): self
    {
        $exception = new self('You are not permitted to perform this action.');

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
