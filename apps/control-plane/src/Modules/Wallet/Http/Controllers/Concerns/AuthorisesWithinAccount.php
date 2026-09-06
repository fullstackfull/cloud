<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Wallet\Domain\Exceptions\AccountPermissionDeniedException;

/**
 * The caller's authority *inside* the account they are acting for.
 *
 * Membership alone is not permission to see the money. A wallet balance and
 * its ledger are the account's financial history — every top-up, every invoice
 * it settled, every adjustment an operator made — so they sit behind the same
 * `billing.view` grant as the invoices, and a `member` added to watch the
 * servers does not get them.
 *
 * Deliberately the first thing every method calls, before any lookup.
 *
 * Duplicated from the Billing and Payments modules rather than imported: a
 * controller trait is part of a module's HTTP surface, and one module reaching
 * into another's Http namespace couples the two surfaces together for fifteen
 * lines.
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
