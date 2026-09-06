<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\StateMachines\InvoiceStateMachine;
use Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InvoiceStateMachineTest extends TestCase
{
    private InvoiceStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->machine = new InvoiceStateMachine;
    }

    #[Test]
    public function a_draft_may_be_issued_or_discarded_and_nothing_else(): void
    {
        $this->assertTrue($this->machine->canTransition(InvoiceStatus::Draft, InvoiceStatus::Open));
        $this->assertTrue($this->machine->canTransition(InvoiceStatus::Draft, InvoiceStatus::Void));

        // A draft has not been issued, so there is nothing to pay or write off.
        $this->assertFalse($this->machine->canTransition(InvoiceStatus::Draft, InvoiceStatus::Paid));
        $this->assertFalse($this->machine->canTransition(InvoiceStatus::Draft, InvoiceStatus::Uncollectible));
        $this->assertFalse($this->machine->canTransition(InvoiceStatus::Draft, InvoiceStatus::Refunded));
    }

    #[Test]
    public function an_open_invoice_may_be_paid_voided_or_written_off(): void
    {
        $this->assertSame(
            [InvoiceStatus::Paid, InvoiceStatus::Void, InvoiceStatus::Uncollectible],
            $this->machine->reachableFrom(InvoiceStatus::Open),
        );
    }

    #[Test]
    public function a_paid_invoice_may_only_become_refunded(): void
    {
        $this->assertSame(
            [InvoiceStatus::Refunded],
            $this->machine->reachableFrom(InvoiceStatus::Paid),
        );
    }

    #[Test]
    public function a_paid_invoice_can_never_return_to_open(): void
    {
        /*
         * The single most important edge in this table. A paid invoice has been
         * receipted and may already have been filed for tax; making it
         * collectible again would have the platform demanding money it has
         * already taken. Undoing a payment is a refund, which moves money and
         * leaves its own record.
         */
        $this->assertFalse($this->machine->canTransition(InvoiceStatus::Paid, InvoiceStatus::Open));

        $this->expectException(IllegalStateTransitionException::class);
        $this->machine->assertCanTransition(InvoiceStatus::Paid, InvoiceStatus::Open);
    }

    #[Test]
    public function a_paid_invoice_cannot_be_voided(): void
    {
        $this->assertFalse($this->machine->canTransition(InvoiceStatus::Paid, InvoiceStatus::Void));
    }

    #[Test]
    public function void_and_refunded_are_terminal(): void
    {
        $this->assertSame([], $this->machine->reachableFrom(InvoiceStatus::Void));
        $this->assertSame([], $this->machine->reachableFrom(InvoiceStatus::Refunded));

        foreach (InvoiceStatus::cases() as $status) {
            if ($status === InvoiceStatus::Void) {
                continue;
            }

            $this->assertFalse(
                $this->machine->canTransition(InvoiceStatus::Void, $status),
                sprintf('A void invoice must not reach %s.', $status->value),
            );
        }
    }

    #[Test]
    public function a_written_off_invoice_may_still_be_paid_or_voided(): void
    {
        // Collections and goodwill both happen after dunning has given up.
        $this->assertTrue($this->machine->canTransition(InvoiceStatus::Uncollectible, InvoiceStatus::Paid));
        $this->assertTrue($this->machine->canTransition(InvoiceStatus::Uncollectible, InvoiceStatus::Void));
        $this->assertFalse($this->machine->canTransition(InvoiceStatus::Uncollectible, InvoiceStatus::Open));
    }

    #[Test]
    public function re_entering_the_same_state_is_a_permitted_no_op(): void
    {
        foreach (InvoiceStatus::cases() as $status) {
            $this->assertTrue($this->machine->canTransition($status, $status));
        }
    }

    #[Test]
    public function an_illegal_transition_names_the_subject_and_carries_a_stable_code(): void
    {
        try {
            $this->machine->assertCanTransition(InvoiceStatus::Refunded, InvoiceStatus::Paid);
            $this->fail('Expected the refunded invoice to refuse a transition.');
        } catch (IllegalStateTransitionException $e) {
            $this->assertSame('state.illegal_transition', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
            $this->assertSame('Invoice', $e->context()['subject']);
            $this->assertSame('refunded', $e->context()['from']);
            $this->assertSame('paid', $e->context()['to']);
        }
    }

    #[Test]
    public function every_status_is_present_in_the_table(): void
    {
        // A status missing from the table would silently behave as terminal.
        foreach (InvoiceStatus::cases() as $status) {
            $this->assertArrayHasKey($status->value, $this->machine->transitions());
        }
    }
}
