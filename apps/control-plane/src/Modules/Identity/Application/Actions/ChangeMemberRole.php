<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Domain\Exceptions\MembershipRefusedException;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerMember;

/**
 * Moves a member between roles.
 *
 * Two refusals, and both are about the owner. Nobody may be *given* the owner
 * role, because an account has one owner and a second one is how an account
 * ends up with none. And the owner may not change their own role, because
 * stepping down without naming a successor leaves an account whose invoices
 * nobody can pay and whose members nobody can manage — the platform would then
 * have to guess a new owner or send somebody to the database.
 *
 * A downgrade takes effect on the next request, not eventually: the role is
 * read from this row on every request by `User::roleWithin()`, and nothing
 * caches it.
 */
final readonly class ChangeMemberRole
{
    public function execute(CustomerMember $member, CustomerRole $role): CustomerMember
    {
        if ($role === CustomerRole::Owner) {
            throw MembershipRefusedException::becauseOwnershipIsTransferredNotGranted();
        }

        return DB::transaction(function () use ($member, $role): CustomerMember {
            /** @var CustomerMember $locked */
            $locked = CustomerMember::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->role === CustomerRole::Owner) {
                throw MembershipRefusedException::becauseTheOwnerCannotChangeTheirOwnRole();
            }

            $locked->forceFill(['role' => $role])->save();

            return $locked;
        });
    }
}
