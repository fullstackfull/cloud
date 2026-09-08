<?php

declare(strict_types=1);

namespace Tests\Unit\Domains;

use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The transitions that must never happen, and the questions the product asks
 * of a state.
 */
final class DomainStateTest extends TestCase
{
    #[Test]
    public function a_name_the_platform_lost_cannot_quietly_become_one_it_holds(): void
    {
        /*
         * The expensive bug this prevents: a reconciliation or an operator
         * action writing `active` over a domain that was deleted or
         * transferred away. The customer's list would show a domain they do
         * not own, and every renewal after it would be spending money on
         * somebody else's name.
         */
        $this->assertFalse(DomainState::Deleted->canBecome(DomainState::Active));
        $this->assertFalse(DomainState::TransferredAway->canBecome(DomainState::Active));
        $this->assertFalse(DomainState::Failed->canBecome(DomainState::Active));
    }

    #[Test]
    public function admitting_ignorance_is_always_allowed(): void
    {
        foreach (DomainState::cases() as $state) {
            $this->assertTrue(
                $state->canBecome(DomainState::NeedsReview),
                sprintf('%s should be able to reach needs_review.', $state->value),
            );
            $this->assertTrue(
                $state->canBecome(DomainState::Indeterminate),
                sprintf('%s should be able to reach indeterminate.', $state->value),
            );
        }
    }

    #[Test]
    public function an_unanswered_registration_can_be_resolved_either_way(): void
    {
        // Which is the entire purpose of the state: reconciliation looks, and
        // then says whether the platform holds the name or never did.
        $this->assertTrue(DomainState::Indeterminate->canBecome(DomainState::Active));
        $this->assertTrue(DomainState::Indeterminate->canBecome(DomainState::Failed));
    }

    #[Test]
    public function an_expired_domain_can_be_renewed_back_to_active(): void
    {
        // The ordinary happy path for somebody who paid late.
        $this->assertTrue(DomainState::Expired->canBecome(DomainState::Active));
        $this->assertTrue(DomainState::Grace->canBecome(DomainState::Active));
    }

    #[Test]
    public function an_unanswered_registration_is_not_shown_as_a_domain_the_customer_owns(): void
    {
        $this->assertFalse(DomainState::Indeterminate->isHeld());
        $this->assertFalse(DomainState::RegistrationPending->isHeld());

        $this->assertTrue(DomainState::Active->isHeld());
        // Held, because the name is still theirs to recover and still belongs
        // on their list.
        $this->assertTrue(DomainState::Grace->isHeld());
        $this->assertTrue(DomainState::Redemption->isHeld());
    }

    #[Test]
    public function anything_that_might_exist_at_the_registrar_says_so(): void
    {
        /*
         * Asked before the platform does something that assumes a name is
         * free — including registering it. Only two states are certain the
         * registrar has nothing.
         */
        foreach (DomainState::cases() as $state) {
            $expected = ! in_array($state, [DomainState::Deleted, DomainState::Failed], strict: true);

            $this->assertSame($expected, $state->mayExistAtRegistrar(), $state->value);
        }
    }

    #[Test]
    public function a_redeemed_domain_is_not_offered_a_renewal(): void
    {
        /*
         * Renewing out of redemption is not a thing registries allow: it is a
         * different operation at a different price, and offering "renew" there
         * would quote a number the registry refuses.
         */
        $this->assertFalse(DomainState::Redemption->isRenewable());

        $this->assertTrue(DomainState::Active->isRenewable());
        $this->assertTrue(DomainState::Expired->isRenewable());
        $this->assertTrue(DomainState::Grace->isRenewable());
    }

    #[Test]
    public function settings_cannot_be_changed_while_an_acquisition_is_in_flight(): void
    {
        // A nameserver change against a registrar record that may not exist.
        $this->assertFalse(DomainState::RegistrationPending->isManageable());
        $this->assertFalse(DomainState::TransferPending->isManageable());
        $this->assertFalse(DomainState::Indeterminate->isManageable());

        $this->assertTrue(DomainState::Active->isManageable());
    }
}
