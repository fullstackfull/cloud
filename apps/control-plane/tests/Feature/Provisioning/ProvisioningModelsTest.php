<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningAttempt;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;

final class ProvisioningModelsTest extends ProvisioningTestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_service_owns_its_jobs_and_a_job_owns_its_attempts(): void
    {
        $service = Service::factory()->create();
        $job = ProvisioningJob::factory()->create(['service_id' => $service->id]);
        ProvisioningAttempt::factory()->count(2)->sequence(
            ['attempt_number' => 1],
            ['attempt_number' => 2],
        )->create(['provisioning_job_id' => $job->id]);

        $this->assertTrue($service->jobs()->get()->contains($job));
        $this->assertSame($service->id, $job->service()->first()?->id);

        // The attempt log is not called attempts(): that is the counter
        // column, and a relation of the same name would shadow it.
        $this->assertSame(2, $job->attemptRecords()->count());
        $this->assertSame(0, $job->attempts);
    }

    #[Test]
    public function every_column_that_carries_meaning_is_cast_to_it(): void
    {
        $service = Service::factory()->active()->create();
        $job = ProvisioningJob::factory()->kind(ProvisioningJobKind::DestroyVps)->create();
        $drift = ResourceDrift::factory()->create();

        $this->assertInstanceOf(ServiceStatus::class, $service->fresh()?->status);
        // jsonb does not preserve key order, so the assertion is about the
        // decoded values rather than the shape of the stored document.
        $this->assertEqualsCanonicalizing(
            ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 80],
            $service->fresh()?->resources,
        );

        $this->assertInstanceOf(ProvisioningJobKind::class, $job->fresh()?->kind);
        $this->assertInstanceOf(ProvisioningJobStatus::class, $job->fresh()?->status);
        $this->assertSame(300, $job->fresh()?->timeout_seconds);

        $this->assertInstanceOf(DriftKind::class, $drift->fresh()?->kind);
    }

    #[Test]
    public function an_attempt_has_no_updated_at_because_it_is_a_fact_about_a_moment(): void
    {
        $attempt = ProvisioningAttempt::factory()->create();

        // A fact that appears to have been edited later is a fact nobody
        // trusts in an incident review.
        $this->assertNull(ProvisioningAttempt::UPDATED_AT);
        $this->assertNotNull($attempt->created_at);
    }

    #[Test]
    public function the_deadline_is_derived_from_the_attempt_that_is_running(): void
    {
        $job = ProvisioningJob::factory()->running(startedSecondsAgo: 60)->create(['timeout_seconds' => 900]);

        $this->assertNotNull($job->deadline());
        $this->assertEqualsWithDelta(840, now()->diffInSeconds($job->deadline()), 2);

        // A job nobody has started has no deadline to miss.
        $this->assertNull(ProvisioningJob::factory()->create()->deadline());
    }

    #[Test]
    public function recording_the_remote_job_id_twice_is_harmless(): void
    {
        $job = ProvisioningJob::factory()->create();

        $job->recordRemoteJobId('UPID:node:0001');
        $job->recordRemoteJobId('UPID:node:0001');

        // A handler that is retried after a crash calls this again; it must
        // converge rather than churn the row.
        $this->assertSame('UPID:node:0001', $job->fresh()?->remote_job_id);
        $this->assertFalse($job->isDirty());
    }
}
