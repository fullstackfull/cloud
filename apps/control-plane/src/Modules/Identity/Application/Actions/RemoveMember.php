<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\ApiKeys\Infrastructure\Models\PersonalAccessToken;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Domain\Exceptions\MembershipRefusedException;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerMember;

/**
 * Takes somebody out of an account.
 *
 * **Removal revokes their keys to it.** A personal access token scoped to this
 * customer outlives the membership that justified it, and `ResolveActingCustomer`
 * re-checks membership on every request precisely because of that — so the
 * token would already stop working. It is deleted anyway: a credential that is
 * refused on use is still a credential somebody holds, and a former colleague's
 * laptop should not be carrying one. Tokens the person holds for *other*
 * accounts are untouched.
 *
 * The owner cannot be removed. Not a permission question — there is no role
 * that may do it — because an account without an owner has nobody who can pay
 * for it or hand it on.
 */
final readonly class RemoveMember
{
    public function execute(CustomerMember $member): void
    {
        DB::transaction(function () use ($member): void {
            /** @var CustomerMember $locked */
            $locked = CustomerMember::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->role === CustomerRole::Owner) {
                throw MembershipRefusedException::becauseTheOwnerCannotBeRemoved();
            }

            PersonalAccessToken::query()
                ->where('tokenable_id', $locked->user_id)
                ->where('customer_id', $locked->customer_id)
                ->delete();

            $locked->delete();
        });
    }
}
