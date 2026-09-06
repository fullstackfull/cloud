<?php

declare(strict_types=1);

namespace Tests\Unit\Subscriptions;

use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException;
use Lynomia\Modules\Subscriptions\Domain\StateMachines\SubscriptionStateMachine;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SubscriptionStateMachineTest extends TestCase
{
    private SubscriptionStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->machine = new SubscriptionStateMachine;
    }

    #[Test]
    public function the_dunning_path_runs_from_active_through_past_due_and_suspended_to_terminated(): void
    {
        $this->assertTrue($this->machine->canTransition(SubscriptionStatus::Active, SubscriptionStatus::PastDue));
        $this->assertTrue($this->machine->canTransition(SubscriptionStatus::PastDue, SubscriptionStatus::Suspended));
        $this->assertTrue($this->machine->canTransition(SubscriptionStatus::Suspended, SubscriptionStatus::Terminated));
    }

    #[Test]
    public function termination_is_always_preceded_by_suspension(): void
    {
        // Termination destroys data. Reaching it without the customer first
        // seeing their service go off would leave nobody a window to notice.
        $this->assertFalse($this->machine->canTransition(SubscriptionStatus::Active, SubscriptionStatus::Terminated));
        $this->assertFalse($this->machine->canTransition(SubscriptionStatus::PastDue, SubscriptionStatus::Terminated));
    }

    #[Test]
    public function a_payment_recovers_a_subscription_from_past_due_or_suspended(): void
    {
        $this->assertTrue($this->machine->canTransition(SubscriptionStatus::PastDue, SubscriptionStatus::Active));
        $this->assertTrue($this->machine->canTransition(SubscriptionStatus::Suspended, SubscriptionStatus::Active));
    }

    #[Test]
    public function cancelled_and_terminated_are_terminal(): void
    {
        $this->assertSame([], $this->machine->reachableFrom(SubscriptionStatus::Cancelled));
        $this->assertSame([], $this->machine->reachableFrom(SubscriptionStatus::Terminated));

        $this->expectException(IllegalStateTransitionException::class);

        $this->machine->assertCanTransition(SubscriptionStatus::Terminated, SubscriptionStatus::Active);
    }

    #[Test]
    public function re_entering_the_same_state_is_a_permitted_no_op(): void
    {
        // Retried jobs and redelivered webhooks depend on this converging
        // rather than throwing.
        $this->assertTrue($this->machine->canTransition(SubscriptionStatus::PastDue, SubscriptionStatus::PastDue));
        $this->assertTrue($this->machine->canTransition(SubscriptionStatus::Cancelled, SubscriptionStatus::Cancelled));
    }

    #[Test]
    public function an_illegal_transition_names_the_subject_and_both_states(): void
    {
        try {
            $this->machine->assertCanTransition(SubscriptionStatus::Cancelled, SubscriptionStatus::Active);
            $this->fail('A cancelled subscription must not be reactivatable.');
        } catch (IllegalStateTransitionException $e) {
            $this->assertSame('state.illegal_transition', $e->errorCode());
            $this->assertSame(
                ['subject' => 'Subscription', 'from' => 'cancelled', 'to' => 'active'],
                $e->context(),
            );
        }
    }
}
