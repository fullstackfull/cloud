<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Lynomia\Modules\Billing\Domain\Exceptions\AccountPermissionDeniedException;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * The caller's authority *inside* the account they are acting for.
 *
 * Membership alone is not permission to see the money: the roles carry
 * explicit permissions, and someone added to an account to watch its services
 * (`member`) has no business reading its invoices or ending its subscriptions.
 *
 * Deliberately the first thing every method calls. Run after a lookup, its 403
 * would tell an unauthorised caller which ids exist — the check's answer must
 * depend on the caller's role and never on whether the id was real.
 */
trait AuthorisesWithinAccount
{
    abstract protected function acting(): ActingCustomer;

    /**
     * @throws AccountPermissionDeniedException
     */
    protected function authoriseWithinAccount(Request $request, string $permission): void
    {
        $user = $request->user();
        $role = $user instanceof User ? $user->roleWithin($this->acting()->id()) : null;

        if ($role === null || ! $role->can($permission)) {
            throw AccountPermissionDeniedException::make();
        }
    }
}
