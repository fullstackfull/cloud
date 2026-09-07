<?php

declare(strict_types=1);

namespace Tests\Unit\Dedicated;

use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\StateMachines\DedicatedServerStateMachine;
use Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The transition table is the module's whole statement of what may follow
 * what, so it is asserted rather than read.
 */
final class DedicatedServerStateMachineTest extends TestCase
{
    private DedicatedServerStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->machine = new DedicatedServerStateMachine;
    }

    #[Test]
    public function a_machine_follows_the_happy_path_from_stock_to_delivered(): void
    {
        $this->assertTrue($this->machine->canTransition(DedicatedServerStatus::Available, DedicatedServerStatus::Reserved));
        $this->assertTrue($this->machine->canTransition(DedicatedServerStatus::Reserved, DedicatedServerStatus::Provisioning));
        $this->assertTrue($this->machine->canTransition(DedicatedServerStatus::Provisioning, DedicatedServerStatus::Active));
    }

    #[Test]
    public function maintenance_is_reachable_from_active_and_back_again(): void
    {
        $this->assertTrue($this->machine->canTransition(DedicatedServerStatus::Active, DedicatedServerStatus::Maintenance));
        $this->assertTrue($this->machine->canTransition(DedicatedServerStatus::Maintenance, DedicatedServerStatus::Active));
    }

    #[Test]
    public function retired_is_terminal_and_nothing_leaves_it(): void
    {
        // The whole reason the row survives decommissioning. A retired machine
        // that could be marked failed could then be cleared back to available
        // and sold again, and the serial's history would be overwritten by the
        // next customer's.
        $this->assertSame([], $this->machine->reachableFrom(DedicatedServerStatus::Retired));

        foreach (DedicatedServerStatus::cases() as $status) {
            if ($status === DedicatedServerStatus::Retired) {
                continue;
            }

            $this->assertFalse(
                $this->machine->canTransition(DedicatedServerStatus::Retired, $status),
                sprintf('A retired server must not be able to become "%s".', $status->value),
            );
        }
    }

    #[Test]
    public function anything_that_is_not_retired_can_fail_and_can_be_retired(): void
    {
        foreach (DedicatedServerStatus::cases() as $status) {
            if ($status === DedicatedServerStatus::Retired || $status === DedicatedServerStatus::Failed) {
                continue;
            }

            $this->assertTrue(
                $this->machine->canTransition($status, DedicatedServerStatus::Failed),
                sprintf('Hardware can fail while "%s".', $status->value),
            );
        }

        foreach (DedicatedServerStatus::cases() as $status) {
            if ($status === DedicatedServerStatus::Retired) {
                continue;
            }

            $this->assertTrue($this->machine->canTransition($status, DedicatedServerStatus::Retired));
        }
    }

    #[Test]
    public function a_failure_is_cleared_only_back_to_stock_or_to_retirement(): void
    {
        $this->assertTrue($this->machine->canTransition(DedicatedServerStatus::Failed, DedicatedServerStatus::Available));
        $this->assertTrue($this->machine->canTransition(DedicatedServerStatus::Failed, DedicatedServerStatus::Retired));

        // A failed machine is not handed straight to a customer: an operator
        // clears it to stock first, which is where the repair decision is made.
        $this->assertFalse($this->machine->canTransition(DedicatedServerStatus::Failed, DedicatedServerStatus::Reserved));
        $this->assertFalse($this->machine->canTransition(DedicatedServerStatus::Failed, DedicatedServerStatus::Active));
    }

    #[Test]
    public function a_hold_can_be_released_so_a_cancelled_order_does_not_shrink_inventory(): void
    {
        // Without this, every cancellation would permanently remove a machine
        // from stock — and invisibly, since it would look sold with nobody to
        // bill.
        $this->assertTrue($this->machine->canTransition(DedicatedServerStatus::Reserved, DedicatedServerStatus::Available));
    }

    #[Test]
    public function a_used_machine_cannot_go_straight_back_into_stock(): void
    {
        /*
         * active → available is refused because the disks still hold a
         * customer's data. The route back to stock is through maintenance,
         * which is the step that forces somebody to erase them.
         */
        $this->assertFalse($this->machine->canTransition(DedicatedServerStatus::Active, DedicatedServerStatus::Available));
        $this->assertTrue($this->machine->canTransition(DedicatedServerStatus::Maintenance, DedicatedServerStatus::Available));
    }

    #[Test]
    public function an_unfinished_install_does_not_return_the_machine_to_stock(): void
    {
        // A machine abandoned mid-install is half-partitioned and possibly
        // still running an installer. It goes to failed and waits for a person.
        $this->assertFalse($this->machine->canTransition(DedicatedServerStatus::Provisioning, DedicatedServerStatus::Available));
        $this->assertTrue($this->machine->canTransition(DedicatedServerStatus::Provisioning, DedicatedServerStatus::Failed));
    }

    #[Test]
    public function a_forbidden_transition_throws_rather_than_silently_doing_nothing(): void
    {
        $this->expectException(IllegalStateTransitionException::class);

        $this->machine->assertCanTransition(DedicatedServerStatus::Retired, DedicatedServerStatus::Available);
    }

    #[Test]
    public function a_network_install_is_legal_in_exactly_two_states(): void
    {
        /*
         * Building a machine nobody has taken delivery of, and rebuilding one
         * at its owner's request. Nothing else, and the sweep is over the
         * whole enum so that a status added later has to be considered here
         * rather than inheriting an answer.
         *
         * `active` is the one that matters: PXE on a running customer server
         * is that server erased. A rebuild reaches `reinstalling` only by way
         * of a typed serial number, which is why the second door exists at all.
         */
        $allowed = [DedicatedServerStatus::Provisioning, DedicatedServerStatus::Reinstalling];

        foreach (DedicatedServerStatus::cases() as $status) {
            $this->assertSame(
                in_array($status, $allowed, strict: true),
                $status->permitsNetworkInstall(),
                sprintf('"%s" permits a network install and should not.', $status->value),
            );
        }

        $this->assertFalse(DedicatedServerStatus::Active->permitsNetworkInstall());
    }

    #[Test]
    public function a_rebuild_starts_from_active_and_never_ends_in_stock(): void
    {
        // The customer's own machine, so the route in is from active — and the
        // route out never goes straight to available, for the same reason an
        // unfinished install does not: the disks may be half written.
        $this->assertTrue($this->machine->canTransition(DedicatedServerStatus::Active, DedicatedServerStatus::Reinstalling));

        $this->assertFalse($this->machine->canTransition(DedicatedServerStatus::Reinstalling, DedicatedServerStatus::Available));
        $this->assertTrue($this->machine->canTransition(DedicatedServerStatus::Reinstalling, DedicatedServerStatus::Active));
        $this->assertTrue($this->machine->canTransition(DedicatedServerStatus::Reinstalling, DedicatedServerStatus::Maintenance));
        $this->assertTrue($this->machine->canTransition(DedicatedServerStatus::Reinstalling, DedicatedServerStatus::Failed));
    }

    #[Test]
    public function a_machine_being_rebuilt_is_not_sellable(): void
    {
        // It belongs to somebody. Counting it as stock would sell one machine
        // twice.
        $this->assertFalse(DedicatedServerStatus::Reinstalling->isAllocatable());
        $this->assertTrue(DedicatedServerStatus::Reinstalling->isCustomerHeld());
    }

    #[Test]
    public function only_an_available_machine_may_be_reserved(): void
    {
        foreach (DedicatedServerStatus::cases() as $status) {
            $this->assertSame($status === DedicatedServerStatus::Available, $status->isAllocatable());
        }
    }
}
