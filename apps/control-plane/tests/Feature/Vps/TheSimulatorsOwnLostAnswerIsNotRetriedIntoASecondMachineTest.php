<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\Concerns\DrivesVpsCreatesThroughTheOperatorPath;
use Tests\TestCase;

/**
 * The double-build, rehearsed against the controlled hypervisor's own marker
 * rather than a test double's switch (F-24).
 *
 * F-15's tests lose the answer with a switch on a wrapping double, because
 * they need one attempt's answer lost and the next one's delivered. That left
 * the simulator itself where the audit found it: its only unanswered create
 * threw before the machine existed, so against the simulator alone "no
 * answer" meant "nothing built" and a retry into a second machine could not
 * happen in any test. Here the double's switch is off and it only counts what
 * it is sent; the lost answer is the simulator's, selected by the hostname,
 * and the production path from the worker to the operator's retry is the
 * same one F-15's tests drive.
 *
 * Against the old simulator the first assertion is already red — the create
 * answered, and the job succeeded — which is the premise this file refuses to
 * carry on without.
 */
final class TheSimulatorsOwnLostAnswerIsNotRetriedIntoASecondMachineTest extends TestCase
{
    use DrivesVpsCreatesThroughTheOperatorPath;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->setUpTheCluster();

        // The double only counts; the simulator loses the answer.
        $this->hypervisor->loseTheAnswerToCreates = false;
    }

    #[Test]
    public function an_operator_retry_of_a_create_the_simulator_built_and_went_quiet_on_builds_no_second_machine(): void
    {
        $job = $this->createJob([
            'hostname' => FakeComputeProvider::failingHostname('web-01', FakeComputeProvider::BUILT_UNANSWERED_MARKER),
        ]);

        $this->runWorker($job);

        $this->assertSame(
            ProvisioningJobStatus::NeedsReview,
            $job->refresh()->status,
            'The create answered, so the simulator never lost the answer and nothing below tests anything.',
        );
        $this->assertCount(1, $this->hypervisor->everyMachine(), 'The simulator went quiet without building the machine.');

        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $this->assertCount(1, $this->hypervisor->everyMachine(), 'The retry built a second machine beside the first.');

        // Counted at the door too: the simulator puts a second create under
        // the same id on top of the first, so the fleet alone cannot see one.
        $this->assertCount(1, $this->hypervisor->creates, 'The retry sent a second create.');
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->refresh()->status);
    }
}
