<?php

declare(strict_types=1);

namespace Tests\Unit\Provisioning;

use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\StateMachines\ServiceStateMachine;
use Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ServiceStateMachineTest extends TestCase
{
    private ServiceStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->machine = new ServiceStateMachine;
    }

    #[Test]
    public function the_happy_path_is_reachable_end_to_end(): void
    {
        $path = [ServiceStatus::Pending, ServiceStatus::Provisioning, ServiceStatus::Active];

        for ($i = 0; $i < count($path) - 1; $i++) {
            $this->assertTrue(
                $this->machine->canTransition($path[$i], $path[$i + 1]),
                "{$path[$i]->value} → {$path[$i + 1]->value} must be allowed.",
            );
        }
    }

    #[Test]
    public function a_build_that_fails_lands_in_failed(): void
    {
        $this->assertTrue($this->machine->canTransition(ServiceStatus::Provisioning, ServiceStatus::Failed));
    }

    #[Test]
    public function suspension_is_reversible(): void
    {
        // Suspension is how non-payment is enforced, and paying restores the
        // service rather than rebuilding it.
        $this->assertTrue($this->machine->canTransition(ServiceStatus::Active, ServiceStatus::Suspended));
        $this->assertTrue($this->machine->canTransition(ServiceStatus::Suspended, ServiceStatus::Active));
    }

    #[Test]
    public function an_active_or_suspended_service_can_be_terminated(): void
    {
        $this->assertTrue($this->machine->canTransition(ServiceStatus::Active, ServiceStatus::Terminated));
        $this->assertTrue($this->machine->canTransition(ServiceStatus::Suspended, ServiceStatus::Terminated));
    }

    #[Test]
    public function termination_is_final(): void
    {
        // Anything else would mean a service the customer has stopped paying
        // for could come back, and a late webhook could resurrect a machine
        // that has already been wiped.
        foreach (ServiceStatus::cases() as $status) {
            if ($status === ServiceStatus::Terminated) {
                continue;
            }

            $this->assertFalse(
                $this->machine->canTransition(ServiceStatus::Terminated, $status),
                "terminated → {$status->value} must be impossible.",
            );
        }

        $this->assertSame([], $this->machine->reachableFrom(ServiceStatus::Terminated));
    }

    #[Test]
    public function a_service_can_never_jump_straight_to_active(): void
    {
        // Active means "the customer can use it", which is a claim only a
        // completed build is allowed to make.
        $this->assertFalse($this->machine->canTransition(ServiceStatus::Pending, ServiceStatus::Active));
        $this->assertFalse($this->machine->canTransition(ServiceStatus::Failed, ServiceStatus::Active));
    }

    #[Test]
    public function a_failed_build_is_retried_on_the_same_service(): void
    {
        // The same row goes round again so the customer's order, subscription
        // and reserved addresses stay attached to it.
        $this->assertTrue($this->machine->canTransition(ServiceStatus::Failed, ServiceStatus::Provisioning));
    }

    #[Test]
    public function re_entering_the_same_state_is_a_permitted_no_op(): void
    {
        // Retries and redelivered webhooks land here routinely; converging is
        // the point.
        foreach (ServiceStatus::cases() as $status) {
            $this->assertTrue($this->machine->canTransition($status, $status));
        }
    }

    #[Test]
    public function an_illegal_transition_throws_rather_than_being_ignored(): void
    {
        $this->expectException(IllegalStateTransitionException::class);

        $this->machine->assertCanTransition(ServiceStatus::Terminated, ServiceStatus::Active);
    }
}
