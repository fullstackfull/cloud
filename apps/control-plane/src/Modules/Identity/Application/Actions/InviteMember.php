<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Application\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Application\DTOs\IssuedInvitation;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Domain\Exceptions\MembershipRefusedException;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerInvitation;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerMember;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * Offers somebody a place in a customer account.
 *
 * **Nothing here confirms whether the address has a Lynomia login.** The
 * invitation is written against the address, not against a user, and the mail
 * goes to the address either way. A caller who invites `ceo@rival.example` and
 * one who invites an address nobody has ever used get identical responses, so
 * the endpoint cannot be turned into an oracle for "does this person bank
 * with Lynomia". The one exception is an address that is already a member of
 * *this* account, which the inviter can see on the same screen anyway.
 *
 * **The token is minted here and never stored.** Thirty-two bytes from the
 * CSPRNG, hex-encoded; the row keeps only its SHA-256. What comes back is the
 * one copy, for the mail to carry, and it is deliberately not part of the API
 * response — see {@see IssuedInvitation}.
 *
 * **Owner is not invitable.** An account has one owner and ownership moves by
 * transfer, which names both sides of the handover in a single act.
 */
final readonly class InviteMember
{
    public function execute(
        Customer $customer,
        string $email,
        CustomerRole $role,
        ?User $invitedBy = null,
    ): IssuedInvitation {
        if ($role === CustomerRole::Owner) {
            throw MembershipRefusedException::becauseOwnershipIsTransferredNotGranted();
        }

        $address = mb_strtolower(trim($email));
        $token = bin2hex(random_bytes(32));

        return DB::transaction(function () use ($customer, $address, $role, $invitedBy, $token): IssuedInvitation {
            $this->assertNotAlreadyAMember($customer, $address);
            $this->assertThereIsRoomForOneMore($customer);

            try {
                /** @var CustomerInvitation $invitation */
                $invitation = CustomerInvitation::query()->create([
                    'customer_id' => $customer->getKey(),
                    'email' => $address,
                    'role' => $role,
                    'token_hash' => CustomerInvitation::hashOf($token),
                    'invited_by_user_id' => $invitedBy?->getKey(),
                    'expires_at' => CarbonImmutable::now()->addDays($this->ttlInDays()),
                    'last_sent_at' => CarbonImmutable::now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                /*
                 * The partial index refused a second live offer to the same
                 * address. Two people pressing Invite at once is the ordinary
                 * cause, and the honest answer is that one of them already
                 * exists — not a second token that would make the first
                 * revocation a lie.
                 */
                throw MembershipRefusedException::becauseAnOfferIsAlreadyOpen();
            }

            return new IssuedInvitation($invitation, $token);
        });
    }

    /**
     * A membership is a user and this is an address, so the check is a join
     * rather than a lookup: the address may belong to a login that is already
     * in this account under a different capitalisation.
     */
    private function assertNotAlreadyAMember(Customer $customer, string $address): void
    {
        $already = CustomerMember::query()
            ->where('customer_id', $customer->getKey())
            ->whereIn('user_id', User::query()->whereRaw('lower(email) = ?', [$address])->select('id'))
            ->exists();

        if ($already) {
            throw MembershipRefusedException::becauseTheyAreAlreadyAMember();
        }
    }

    /**
     * Members and open offers count together, because an offer is a member the
     * account has already agreed to have.
     */
    private function assertThereIsRoomForOneMore(Customer $customer): void
    {
        $members = CustomerMember::query()->where('customer_id', $customer->getKey())->count();
        $offers = CustomerInvitation::query()->where('customer_id', $customer->getKey())->open()->count();

        if ($members + $offers >= $this->ceiling()) {
            throw MembershipRefusedException::becauseTheAccountIsFull($this->ceiling());
        }
    }

    private function ttlInDays(): int
    {
        return max(1, (int) config('teams.invitation_ttl_days', 14));
    }

    private function ceiling(): int
    {
        return max(1, (int) config('teams.max_members', 25));
    }
}
