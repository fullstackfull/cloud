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
            $this->assertTheAddressWasNotJustMailed($customer, $address);

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

    /**
     * An address this account mailed an invitation to moments ago is not
     * mailed again by withdrawing the offer and inviting it afresh.
     *
     * ResendInvitation holds an open offer to its cooldown. This is the other
     * road to the same inbox, and without it the wait was worth nothing:
     * withdraw, invite, withdraw, invite — a new row, a new clock and a new
     * mail each time, until the hourly budget ran out. So the clock is
     * the address's within this account, read from every earlier offer to it
     * rather than from the one on screen.
     *
     * Per account, deliberately. A wait shared across accounts would answer
     * one customer's invitation with a refusal that means "somebody else
     * invited this address a moment ago" — exactly the fact about another
     * account an invitation must never disclose. The price is that several
     * accounts each have a wait of their own.
     *
     * Only offers that have given up the address's one live slot — accepted,
     * declined or withdrawn — are compared. One that still holds it, open or
     * expired without being closed, makes the insert below fail on the
     * partial unique index with an answer of its own, and the wait is not
     * what stands in the way.
     *
     * The earlier offers are locked, not just read. Withdrawing an offer
     * closes it under that row's lock. Read without a lock while a withdrawal
     * is still in flight, the offer would look open and be left out of the
     * comparison; the insert would then wait on the partial unique index for
     * the withdrawal to commit, and succeed — two mails to the address inside
     * one wait. Locked, the read waits for the withdrawal instead and gets
     * the row as it committed it: closed, and carrying its latest
     * `last_sent_at`, a resend's included.
     */
    private function assertTheAddressWasNotJustMailed(Customer $customer, string $address): void
    {
        $again = CustomerInvitation::query()
            ->where('customer_id', $customer->getKey())
            ->whereRaw('lower(email) = ?', [$address])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->reject(static fn (CustomerInvitation $offer): bool => $offer->accepted_at === null
                && $offer->declined_at === null
                && $offer->revoked_at === null)
            ->map(static fn (CustomerInvitation $offer): CarbonImmutable => $offer->mailableAgainAt())
            ->max();

        if ($again instanceof CarbonImmutable && CarbonImmutable::now()->lt($again)) {
            throw MembershipRefusedException::becauseTheAddressWasMailedTooRecently($again);
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
