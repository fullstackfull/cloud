<?php

declare(strict_types=1);

namespace Tests\Unit\Orders;

use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Domain\StateMachines\OrderStateMachine;
use Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OrderStateMachineTest extends TestCase
{
    private OrderStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->machine = new OrderStateMachine;
    }

    #[Test]
    public function the_happy_path_is_reachable_end_to_end(): void
    {
        $path = [
            OrderStatus::Draft,
            OrderStatus::PendingPayment,
            OrderStatus::Paid,
            OrderStatus::QueuedForProvisioning,
            OrderStatus::Provisioning,
            OrderStatus::Active,
        ];

        for ($i = 0; $i < count($path) - 1; $i++) {
            $this->assertTrue(
                $this->machine->canTransition($path[$i], $path[$i + 1]),
                "{$path[$i]->value} → {$path[$i + 1]->value} must be allowed.",
            );
        }
    }

    #[Test]
    public function a_zero_total_order_may_skip_the_payment_stage(): void
    {
        // An order fully covered by wallet credit or a 100% coupon has nothing
        // to charge, and must not sit in PendingPayment forever.
        $this->assertTrue($this->machine->canTransition(OrderStatus::Draft, OrderStatus::Paid));
    }

    #[Test]
    public function an_unpaid_order_can_never_reach_provisioning(): void
    {
        // The single most important rule in the machine: a service is only ever
        // built after money is confirmed server-side.
        foreach ([OrderStatus::Draft, OrderStatus::PendingPayment, OrderStatus::PaymentFailed] as $unpaid) {
            $this->assertFalse(
                $this->machine->canTransition($unpaid, OrderStatus::QueuedForProvisioning),
                "{$unpaid->value} must not reach provisioning.",
            );
            $this->assertFalse($this->machine->canTransition($unpaid, OrderStatus::Provisioning));
            $this->assertFalse($this->machine->canTransition($unpaid, OrderStatus::Active));
        }
    }

    /**
     * @return iterable<string, array{OrderStatus}>
     */
    public static function terminalStates(): iterable
    {
        yield 'cancelled' => [OrderStatus::Cancelled];
        yield 'refunded' => [OrderStatus::Refunded];
        yield 'terminated' => [OrderStatus::Terminated];
    }

    #[Test]
    #[DataProvider('terminalStates')]
    public function a_terminal_state_has_no_way_out(OrderStatus $terminal): void
    {
        $this->assertTrue($terminal->isTerminal());
        $this->assertSame([], $this->machine->reachableFrom($terminal));

        foreach (OrderStatus::cases() as $target) {
            if ($target === $terminal) {
                continue;
            }

            $this->assertFalse(
                $this->machine->canTransition($terminal, $target),
                "{$terminal->value} must not become {$target->value}.",
            );
        }
    }

    #[Test]
    public function a_refunded_order_cannot_be_resurrected_as_active(): void
    {
        // Reactivating a refunded order would give a customer a running service
        // they have been paid back for.
        $this->assertFalse($this->machine->canTransition(OrderStatus::Refunded, OrderStatus::Active));
        $this->assertFalse($this->machine->canTransition(OrderStatus::Refunded, OrderStatus::QueuedForProvisioning));
    }

    #[Test]
    public function a_failed_provisioning_can_be_retried_or_refunded_but_not_silently_activated(): void
    {
        $this->assertTrue($this->machine->canTransition(OrderStatus::ProvisioningFailed, OrderStatus::QueuedForProvisioning));
        $this->assertTrue($this->machine->canTransition(OrderStatus::ProvisioningFailed, OrderStatus::Refunded));
        $this->assertTrue($this->machine->canTransition(OrderStatus::ProvisioningFailed, OrderStatus::ManualReview));

        // Marking a failed provisioning ACTIVE without going through review or
        // a retry would report a service the customer does not have.
        $this->assertFalse($this->machine->canTransition(OrderStatus::ProvisioningFailed, OrderStatus::Active));
    }

    #[Test]
    public function a_suspended_order_can_be_restored_or_terminated(): void
    {
        $this->assertTrue($this->machine->canTransition(OrderStatus::Suspended, OrderStatus::Active));
        $this->assertTrue($this->machine->canTransition(OrderStatus::Suspended, OrderStatus::Terminated));
    }

    #[Test]
    public function a_forbidden_transition_throws_rather_than_silently_doing_nothing(): void
    {
        $this->expectException(IllegalStateTransitionException::class);

        $this->machine->assertCanTransition(OrderStatus::Draft, OrderStatus::Active);
    }

    #[Test]
    public function the_illegal_transition_error_names_both_states(): void
    {
        try {
            $this->machine->assertCanTransition(OrderStatus::Draft, OrderStatus::Active);
            $this->fail('Expected an IllegalStateTransitionException.');
        } catch (IllegalStateTransitionException $e) {
            $this->assertSame('state.illegal_transition', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
            $this->assertSame(['subject' => 'Order', 'from' => 'draft', 'to' => 'active'], $e->context());
        }
    }

    #[Test]
    public function re_entering_the_same_state_is_allowed_so_that_retries_are_safe(): void
    {
        // A redelivered webhook or a retried job must converge, not throw.
        foreach (OrderStatus::cases() as $status) {
            $this->assertTrue($this->machine->canTransition($status, $status));
        }
    }

    #[Test]
    public function every_status_appears_in_the_transition_table(): void
    {
        // A status missing from the table would be a dead end that silently
        // rejects every transition.
        $table = $this->machine->transitions();

        foreach (OrderStatus::cases() as $status) {
            $this->assertArrayHasKey($status->value, $table, "{$status->value} is missing from the table.");
        }
    }

    #[Test]
    public function every_non_terminal_status_is_reachable_from_somewhere(): void
    {
        $reachable = [];
        foreach ($this->machine->transitions() as $targets) {
            foreach ($targets as $target) {
                $reachable[$target->value] = true;
            }
        }

        foreach (OrderStatus::cases() as $status) {
            if ($status === OrderStatus::Draft) {
                continue; // The entry point.
            }

            $this->assertArrayHasKey(
                $status->value,
                $reachable,
                "{$status->value} can never be reached, so it is dead code.",
            );
        }
    }

    #[Test]
    public function paid_states_are_exactly_those_after_payment_capture(): void
    {
        $paid = array_filter(OrderStatus::cases(), static fn (OrderStatus $s): bool => $s->isPaid());

        $this->assertEqualsCanonicalizing(
            [
                OrderStatus::Paid, OrderStatus::QueuedForProvisioning, OrderStatus::Provisioning,
                OrderStatus::ProvisioningFailed, OrderStatus::ManualReview, OrderStatus::Active,
                OrderStatus::Suspended,
            ],
            array_values($paid),
        );

        // Refunded is deliberately not "paid": the money has gone back.
        $this->assertFalse(OrderStatus::Refunded->isPaid());
    }
}
