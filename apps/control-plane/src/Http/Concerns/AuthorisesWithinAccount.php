<?php

declare(strict_types=1);

namespace Lynomia\Http\Concerns;

use Illuminate\Http\Request;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Shared\Domain\Exceptions\AccountPermissionRequiredException;

/**
 * Permission check inside the customer account the request is acting for.
 *
 * Membership and capability are different questions. ResolveActingCustomer has
 * already answered the first - this caller belongs to this account - and this
 * answers the second: a Member may read the invoices, an Owner may pay them,
 * and a machine that is nobody's to reinstall stays nobody's to reinstall.
 *
 * The role is read from the membership rather than from the platform-wide RBAC
 * roles, because they answer different questions too: platform roles say what a
 * member of staff may do to the platform, and this says what a person may do
 * inside one customer's account.
 *
 * Called before any lookup, so a caller without permission gets the same answer
 * whether or not the id in the path names a real row.
 */
trait AuthorisesWithinAccount
{
    abstract protected function acting(): ActingCustomer;

    protected function authoriseWithinAccount(Request $request, string $permission): void
    {
        $user = $request->user();
        $role = $user instanceof User ? $user->roleWithin($this->acting()->id()) : null;

        if ($role === null || ! $role->can($permission)) {
            throw AccountPermissionRequiredException::forPermission($permission);
        }
    }
}
