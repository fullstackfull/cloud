<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Lynomia\Modules\Provisioning\Application\Actions\AdoptOrphanResource;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobSucceeded;
use Lynomia\Modules\Provisioning\Domain\Exceptions\OrphanAdoptionRejectedException;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningAttempt;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;

/**
 * The other half of never retrying a timeout.
 *
 * A job in review usually means a machine may exist that the platform does not
 * know about. The recovery is for a person to go and look, and then to say
 * "that one is ours" — which is what adoption records. Re-running the build
 * instead leaves the first machine orphaned for ever and bills the customer
 * for the second.
 */
final class AdoptOrphanResourceTest extends ProvisioningTestCase
{
    use RefreshDatabase;

    private AdoptOrphanResource $adopt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adopt = app(AdoptOrphanResource::class);
    }

    #[Test]
    public function a_timed_out_job_adopts_the_machine_it_actually_built(): void
    {
        Event::fake([ProvisioningJobSucceeded::class]);

        $service = Service::factory()->provisioning()->create();
        $job = ProvisioningJob::factory()->failedWith(FailureClass::Timeout)->create([
            'service_id' => $service->id,
            'remote_job_id' => 'UPID:node:0001',
            'attempts' => 1,
        ]);

        $adopted = $this->adopt->execute($job, 'vm-501', evidence: ['found_by' => 'reconciler'], adoptedBy: 'ops@example.test');

        $this->assertSame(ProvisioningJobStatus::Succeeded, $adopted->status);
        $this->assertSame('vm-501', $adopted->result['provider_reference'] ?? null);
        $this->assertNull($adopted->failure_class);
        $this->assertNotNull($adopted->finished_at);

        // The service the customer bought is delivered, without a second
        // machine ever being created.
        $this->assertSame(ServiceStatus::Active, $service->fresh()?->status);

        Event::assertDispatched(
            ProvisioningJobSucceeded::class,
            fn (ProvisioningJobSucceeded $event): bool => $event->adopted === true,
        );
    }

    #[Test]
    public function adopting_the_orphan_of_a_real_timed_out_build_delivers_the_service(): void
    {
        /*
         * The whole recovery, end to end, instead of from a hand-placed
         * fixture. That distinction is the point of this test: the engine
         * marks the service failed when a create times out, because at that
         * moment nobody knows whether anything exists. Adoption is the answer
         * to that question, so the service has to be able to reach the state a
         * successful build would have left it in. If it cannot, the platform
         * has a machine it is billing for and a customer whose service reads
         * "failed" for ever.
         */
        $service = Service::factory()->create();
        $job = ProvisioningJob::factory()->create([
            'service_id' => $service->id,
            'payload' => ['hostname' => 'vps-01', 'fake' => ['outcome' => 'timeout']],
        ]);

        $this->runWorker($job->id);

        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->fresh()?->status);
        $this->assertSame(ServiceStatus::Failed, $service->fresh()?->status);

        // An operator looks at the provider, finds the machine, and says so.
        $adopted = $this->adopt->execute($job->fresh(), 'vm-501', adoptedBy: 'ops@example.test');

        $this->assertSame(ProvisioningJobStatus::Succeeded, $adopted->status);
        $this->assertSame(ServiceStatus::Active, $service->fresh()?->status);
        $this->assertNotNull($service->fresh()?->activated_at);
    }

    #[Test]
    public function the_adoption_is_recorded_as_an_attempt_with_its_evidence(): void
    {
        $job = ProvisioningJob::factory()->failedWith(FailureClass::Timeout)->create(['attempts' => 2]);

        $this->adopt->execute($job, 'vm-501', evidence: ['vmid' => 501], adoptedBy: 'ops@example.test');

        $attempt = ProvisioningAttempt::query()->where('provisioning_job_id', $job->id)->sole();

        // The history has to read as what happened: two failed calls and a
        // human, not a build that mysteriously succeeded on its own.
        $this->assertSame(3, $attempt->attempt_number);
        $this->assertSame(ProvisioningJobStatus::Succeeded, $attempt->status);
        $this->assertTrue($attempt->response_metadata['adopted'] ?? false);
        $this->assertSame('ops@example.test', $attempt->response_metadata['adopted_by'] ?? null);
    }

    #[Test]
    public function the_failure_that_produced_the_orphan_is_not_erased(): void
    {
        $job = ProvisioningJob::factory()->failedWith(FailureClass::Timeout)->create([
            'last_error' => 'the provider did not answer within the deadline',
        ]);

        $adopted = $this->adopt->execute($job, 'vm-501', adoptedBy: 'ops@example.test');

        // An adopted job must stay distinguishable from a clean build; the
        // adoption record and the original error are both part of the story.
        $this->assertSame('the provider did not answer within the deadline', $adopted->last_error);
        $this->assertSame('ops@example.test', $adopted->result['adoption']['adopted_by'] ?? null);
        $this->assertSame('needs_review', $adopted->result['adoption']['previous_status'] ?? null);
    }

    #[Test]
    public function adopting_a_queued_job_stops_a_worker_building_a_second_machine(): void
    {
        $service = Service::factory()->provisioning()->create();
        $job = ProvisioningJob::factory()->create(['service_id' => $service->id]);

        $this->adopt->execute($job, 'vm-501', remoteJobId: 'UPID:node:0001');

        // A worker picks the message up afterwards, as queues do.
        $this->runWorker($job->id);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->fresh()?->status);
        $this->assertSame('vm-501', $job->fresh()?->result['provider_reference'] ?? null);

        // One attempt: the adoption. The worker found a settled job and left
        // it alone rather than building the machine a second time.
        $this->assertSame(1, ProvisioningAttempt::query()->count());
    }

    #[Test]
    public function a_running_job_cannot_be_adopted(): void
    {
        $job = ProvisioningJob::factory()->running()->create();

        // A live attempt may be about to write its own reference over the one
        // being adopted.
        $this->expectException(OrphanAdoptionRejectedException::class);

        $this->adopt->execute($job, 'vm-501');
    }

    #[Test]
    public function a_job_that_already_succeeded_cannot_adopt_a_second_resource(): void
    {
        $job = ProvisioningJob::factory()->status(ProvisioningJobStatus::Succeeded)->create([
            'result' => ['provider_reference' => 'vm-400'],
        ]);

        try {
            $this->adopt->execute($job, 'vm-501');
            $this->fail('A settled job must not adopt a second resource.');
        } catch (OrphanAdoptionRejectedException $e) {
            $this->assertSame('provisioning.adoption_job_settled', $e->errorCode());
        }

        // Overwriting would have orphaned vm-400 for ever while making the
        // books look tidy.
        $this->assertSame('vm-400', $job->fresh()?->result['provider_reference'] ?? null);
    }

    #[Test]
    public function a_resource_another_job_already_claims_is_refused(): void
    {
        ProvisioningJob::factory()->status(ProvisioningJobStatus::Succeeded)->create([
            'result' => ['provider_reference' => 'vm-501'],
        ]);
        $job = ProvisioningJob::factory()->failedWith(FailureClass::Timeout)->create();

        try {
            $this->adopt->execute($job, 'vm-501');
            $this->fail('A resource that is already claimed must not be adopted twice.');
        } catch (OrphanAdoptionRejectedException $e) {
            // Two jobs pointing at one machine means the next termination
            // deletes a server somebody else is still paying for.
            $this->assertSame('provisioning.adoption_reference_claimed', $e->errorCode());
        }

        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->fresh()?->status);
    }

    #[Test]
    public function a_provider_job_id_another_job_already_holds_is_refused(): void
    {
        ProvisioningJob::factory()->status(ProvisioningJobStatus::Succeeded)->create([
            'remote_job_id' => 'UPID:node:0001',
        ]);
        $job = ProvisioningJob::factory()->failedWith(FailureClass::Timeout)->create();

        $this->expectException(OrphanAdoptionRejectedException::class);

        $this->adopt->execute($job, 'vm-501', remoteJobId: 'UPID:node:0001');
    }
}
