<?php

declare(strict_types=1);

namespace Tests\Unit\Provisioning;

use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\StateMachines\ProvisioningJobStateMachine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProvisioningJobStateMachineTest extends TestCase
{
    private ProvisioningJobStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->machine = new ProvisioningJobStateMachine;
    }

    #[Test]
    public function a_retry_puts_the_job_back_in_the_pool(): void
    {
        // The worker does not loop on a job it may already have half-built; it
        // returns the job to the queue with a wait attached.
        $this->assertTrue($this->machine->canTransition(ProvisioningJobStatus::Running, ProvisioningJobStatus::Queued));
    }

    #[Test]
    public function a_job_in_review_is_never_requeued_by_the_engine(): void
    {
        /*
         * The engine has no route out of review at all: every transition from
         * needs_review is one an operator makes. That is the point — a job in
         * review may already have built something, and only a person can go
         * and look before anything else happens to it.
         */
        $this->assertTrue($this->machine->canTransition(ProvisioningJobStatus::NeedsReview, ProvisioningJobStatus::Succeeded));
        $this->assertTrue($this->machine->canTransition(ProvisioningJobStatus::NeedsReview, ProvisioningJobStatus::Cancelled));
    }

    #[Test]
    public function a_succeeded_job_is_finished_with(): void
    {
        $this->assertSame([], $this->machine->reachableFrom(ProvisioningJobStatus::Succeeded));

        // Re-running a job that already built a machine is how the second one
        // appears.
        $this->assertFalse($this->machine->canTransition(ProvisioningJobStatus::Succeeded, ProvisioningJobStatus::Queued));
        $this->assertFalse($this->machine->canTransition(ProvisioningJobStatus::Succeeded, ProvisioningJobStatus::Running));
    }

    #[Test]
    public function a_queued_job_may_be_settled_by_adopting_a_resource_that_already_exists(): void
    {
        // Otherwise the worker would build a second machine alongside the one
        // an operator just found.
        $this->assertTrue($this->machine->canTransition(ProvisioningJobStatus::Queued, ProvisioningJobStatus::Succeeded));
    }

    #[Test]
    public function a_cancelled_job_stays_cancelled(): void
    {
        $this->assertSame([], $this->machine->reachableFrom(ProvisioningJobStatus::Cancelled));
    }
}
