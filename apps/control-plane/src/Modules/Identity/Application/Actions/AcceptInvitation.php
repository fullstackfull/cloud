<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Application\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Domain\Exceptions\MembershipRefusedException;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerInvitation;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerMember;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * Takes up an offer of membership.
 *
 * Four things have to be true, and they are checked in an order chosen so that
 * the failures do not leak more than they must:
 *
 *  1. **The token names a live offer.** Looked up by hash, under a row lock, so
 *     two clicks on the same link cannot both create a membership.
 *  2. **The offer was made to this person.** The invitation's address is
 *     compared against the accepting user's own, case-insensitively. A
 *     forwarded invitation admits nobody: the mail is a notification, the
 *     address is the credential.
 *  3. **That address has been verified.** Otherwise anybody could sign up
 *     claiming an address they do not control and walk into the account it was
 *     invited to — the invitation would be doing the work an email
 *     verification is supposed to do.
 *  4. **The membership does not already exist.** Somebody invited twice, or
 *     invited to an account they were separately added to, joins once.
 *
 * The offer is stamped rather than deleted, and the membership it produced is
 * recorded on it, so "how did this person get in" has an answer months later.
 */
final readonly class AcceptInvitation
{
    public function execute(string $token, User $user): CustomerMember
    {
        return DB::transaction(function () use ($token, $user): CustomerMember {
            /** @var CustomerInvitation|null $invitation */
            $invitation = CustomerInvitation::forToken($token)->lockForUpdate()->first();

            if ($invitation === null || ! $invitation->isOpen()) {
                throw MembershipRefusedException::becauseTheOfferIsNotOpen();
            }

            if (! hash_equals($invitation->email, mb_strtolower((string) $user->email))) {
                throw MembershipRefusedException::becauseTheOfferWasMadeToSomebodyElse();
            }

            if ($user->email_verified_at === null) {
                throw MembershipRefusedException::becauseTheAddressIsNotVerified();
            }

            $member = $this->admit($invitation, $user);

            $invitation->forceFill([
                'accepted_at' => CarbonImmutable::now(),
                'accepted_member_id' => $member->getKey(),
            ])->save();

            return $member;
        });
    }

    /**
     * The membership row, created or found.
     *
     * `accepted_at` is stamped here and not defaulted on the column, because
     * the same table holds memberships created by an operator adopting an
     * account, and those are accepted the moment they are made.
     */
    private function admit(CustomerInvitation $invitation, User $user): CustomerMember
    {
        try {
            /** @var CustomerMember $member */
            $member = CustomerMember::query()->create([
                'customer_id' => $invitation->customer_id,
                'user_id' => $user->getKey(),
                'role' => $invitation->role,
                'invited_at' => $invitation->created_at,
                'accepted_at' => CarbonImmutable::now(),
                'invited_by' => $invitation->invited_by_user_id,
            ]);

            return $member;
        } catch (UniqueConstraintViolationException) {
            /** @var CustomerMember $existing */
            $existing = CustomerMember::query()
                ->where('customer_id', $invitation->customer_id)
                ->where('user_id', $user->getKey())
                ->firstOrFail();

            return $existing;
        }
    }
}
