<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Domain\Exceptions\MembershipRefusedException;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerMember;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * Hands an account to somebody else.
 *
 * One act, both sides. The outgoing owner becomes an administrator and the
 * incoming one becomes the owner, inside a single transaction with both rows
 * locked — which is the only arrangement where the account is never for an
 * instant ownerless and never for an instant owned twice. Doing it as two role
 * changes would leave both of those states reachable by a crash.
 *
 * **The successor must already be an accepted member.** Ownership is not a way
 * to join an account: they are invited, they accept, and only then can they be
 * handed the keys. An invitation that could confer ownership would make a
 * mis-typed address the end of somebody's business.
 *
 * The outgoing owner keeps administrator rather than being removed, because
 * the person handing over is usually still there — and an owner who wants to
 * leave entirely can be removed afterwards, by the new owner, deliberately.
 */
final readonly class TransferOwnership
{
    /**
     * @param  User  $actor  the person asking; must be the current owner
     * @param  User  $successor  the member who will own the account
     */
    public function execute(Customer $customer, User $actor, User $successor): CustomerMember
    {
        return DB::transaction(function () use ($customer, $actor, $successor): CustomerMember {
            $current = $this->acceptedMembership($customer, $actor);

            if ($current === null || $current->role !== CustomerRole::Owner) {
                throw MembershipRefusedException::becauseOnlyTheOwnerMayTransfer();
            }

            $incoming = $this->acceptedMembership($customer, $successor);

            if ($incoming === null) {
                throw MembershipRefusedException::becauseTheyAreNotAMember();
            }

            if ($incoming->is($current)) {
                throw MembershipRefusedException::becauseTheyAreAlreadyTheOwner();
            }

            $current->forceFill(['role' => CustomerRole::Administrator])->save();
            $incoming->forceFill(['role' => CustomerRole::Owner])->save();

            return $incoming;
        });
    }

    private function acceptedMembership(Customer $customer, User $user): ?CustomerMember
    {
        /** @var CustomerMember|null $membership */
        $membership = CustomerMember::query()
            ->where('customer_id', $customer->getKey())
            ->where('user_id', $user->getKey())
            ->whereNotNull('accepted_at')
            ->lockForUpdate()
            ->first();

        return $membership;
    }
}
