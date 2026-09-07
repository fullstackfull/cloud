<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Payments\Domain\Exceptions\AccountPermissionRequiredException;

/**
 * The caller's authority *inside* the account they are acting for.
 *
 * Membership alone is not permission to spend: the roles carry explicit
 * permissions, and someone added to an account to watch its services
 * (`member`) has no business starting a payment against it or reading what it
 * has been charged.
 *
 * Deliberately the first thing every method calls. Run after a lookup, its 403
 * would tell an unauthorised caller which ids exist — the check's answer must
 * depend on the caller's role and never on whether the id was real.
 */
trait AuthorisesWithinAccount
{
    abstract protected function acting(): ActingCustomer;

    /**
     * @throws AccountPermissionRequiredException
     */
    protected function authoriseWithinAccount(Request $request, string $permission): void
    {
        $user = $request->user();
        $role = $user instanceof User ? $user->roleWithin($this->acting()->id()) : null;

        if ($role === null || ! $role->can($permission)) {
            throw AccountPermissionRequiredException::forPermission($permission);
        }
    }
}
