<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A change to who belongs to an account that the platform will not make.
 *
 * Two kinds of refusal live here and they are deliberately not distinguished
 * in the response body beyond their code: the ones that protect the account
 * from being left without an owner, and the ones that protect a person from
 * being admitted to an account that was not offered to them.
 *
 * What is *not* here is any refusal that would reveal whether an email address
 * already has a Lynomia login. Inviting an address that is already a customer
 * elsewhere and inviting an address nobody has ever seen produce the same
 * answer, because the difference is exactly the fact an attacker is fishing
 * for. The one address the platform will confirm is the caller's own.
 */
final class MembershipRefusedException extends DomainException
{
    private string $errorCode = 'membership.refused';

    private int $status = 409;

    public static function becauseTheyAreAlreadyAMember(): self
    {
        return (new self('That person is already a member of this account.'))
            ->as('membership.already_a_member');
    }

    public static function becauseAnOfferIsAlreadyOpen(): self
    {
        return (new self('An invitation to that address is already open. Revoke it before sending another.'))
            ->as('membership.invitation_already_open');
    }

    public static function becauseOwnershipIsTransferredNotGranted(): self
    {
        return (new self('An account has one owner. Transfer ownership rather than granting the role.'))
            ->as('membership.owner_is_not_assignable');
    }

    public static function becauseTheOwnerCannotBeRemoved(): self
    {
        return (new self('The owner of an account cannot be removed. Transfer ownership first.'))
            ->as('membership.owner_cannot_be_removed');
    }

    public static function becauseTheOwnerCannotChangeTheirOwnRole(): self
    {
        return (new self('The owner cannot step down by changing their own role. Transfer ownership instead.'))
            ->as('membership.owner_cannot_demote_themselves');
    }

    public static function becauseOnlyTheOwnerMayTransfer(): self
    {
        return (new self('Only the owner of an account may hand it to somebody else.'))
            ->as('membership.only_the_owner_may_transfer')->withStatus(403);
    }

    public static function becauseTheyAreNotAMember(): self
    {
        return (new self('That person is not an accepted member of this account.'))
            ->as('membership.not_a_member')->withStatus(404);
    }

    public static function becauseTheOfferIsNotOpen(): self
    {
        return (new self('This invitation is no longer open.'))
            ->as('membership.invitation_not_open');
    }

    public static function becauseTheOfferWasMadeToSomebodyElse(): self
    {
        return (new self('This invitation was made to a different address.'))
            ->as('membership.invitation_not_yours')->withStatus(403);
    }

    public static function becauseTheTransferWasNotConfirmed(): self
    {
        return (new self('Type the account id to confirm handing this account to somebody else.'))
            ->as('membership.transfer_not_confirmed')->withStatus(422);
    }

    public static function becauseTheyAreAlreadyTheOwner(): self
    {
        return (new self('That person already owns this account.'))
            ->as('membership.already_the_owner');
    }

    public static function becauseTheAccountIsFull(int $ceiling): self
    {
        return (new self('This account has reached its limit of members and open invitations.'))
            ->as('membership.account_is_full')->withContext(['limit' => $ceiling]);
    }

    public static function becauseTheAddressIsNotVerified(): self
    {
        return (new self('Verify your own email address before joining an account.'))
            ->as('membership.address_not_verified')->withStatus(403);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    private function as(string $code): self
    {
        $this->errorCode = $code;

        return $this;
    }

    private function withStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }
}
