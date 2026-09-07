<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Contracts\HandlerRegistry;
use Lynomia\Modules\Provisioning\Domain\Contracts\ProvisioningHandler;
use Lynomia\Modules\Provisioning\Domain\Contracts\ResourceReservationReleaser;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobFailed;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobNeedsReview;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobSucceeded;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Handlers\FakeProvisioningHandler;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningAttempt;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Provisioning\Infrastructure\Registries\ProvisioningHandlerRegistry;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * The engine, tested as the failures rather than as the happy path.
 *
 * Every assertion here exists because the alternative behaviour produces a
 * customer with two servers, an address handed to two people, or a failure
 * nobody is told about.
 */
final class RunProvisioningJobTest extends ProvisioningTestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_successful_run_settles_the_job_and_activates_the_service(): void
    {
        Event::fake([ProvisioningJobSucceeded::class]);

        $service = Service::factory()->create();
        $job = $this->queueJob(['outcome' => 'succeed'], ['service_id' => $service->id]);

        $this->runWorker($job->id);

        $job = $job->fresh();
        $this->assertNotNull($job);
        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->status);
        $this->assertSame(1, $job->attempts);
        $this->assertNotNull($job->remote_job_id);
        $this->assertNotNull($job->finished_at);
        $this->assertNull($job->failure_class);

        $this->assertSame(ServiceStatus::Active, $service->fresh()?->status);
        $this->assertNotNull($service->fresh()?->activated_at);

        Event::assertDispatched(ProvisioningJobSucceeded::class);
    }

    #[Test]
    public function the_remote_job_id_is_persisted_before_the_handler_returns(): void
    {
        $observer = $this->observingHandler();
        $this->handlers->register($observer);

        $job = $this->queueJob();

        $this->runWorker($job->id);

        /*
         * Read from the database at the moment the handler was called, not
         * from the model afterwards. This is the invariant the whole module
         * rests on: if the worker is killed on the next line, the platform
         * must still be able to ask the provider what happened to that id.
         * Without it a timeout is unrecoverable and every recovery is a guess
         * that risks building a second machine.
         */
        $this->assertSame('remote-job-777', $observer->remoteJobIdInDatabase);
    }

    #[Test]
    public function the_attempt_is_recorded_before_the_handler_is_called(): void
    {
        $observer = $this->observingHandler();
        $this->handlers->register($observer);

        $job = $this->queueJob();

        $this->runWorker($job->id);

        // An attempt written only on return is an attempt that never exists
        // for the call that killed the worker — precisely the one somebody
        // will need in order to explain what happened.
        $this->assertSame(1, $observer->attemptRowsAtCallTime);
        $this->assertSame(ProvisioningJobStatus::Running->value, $observer->jobStatusAtCallTime);
    }

    #[Test]
    public function a_transient_failure_is_retried_with_backoff(): void
    {
        $job = $this->queueJob(['outcome' => 'transient']);

        $this->runWorker($job->id);

        $job = $job->fresh();
        $this->assertNotNull($job);
        $this->assertSame(ProvisioningJobStatus::Queued, $job->status);
        $this->assertSame(FailureClass::Transient, $job->failure_class);
        $this->assertSame(1, $job->attempts);
        $this->assertNotNull($job->next_attempt_at);
        $this->assertEqualsWithDelta(30, now()->diffInSeconds($job->next_attempt_at), 2);

        Queue::assertPushed(RunProvisioningJob::class, 1);

        // The second wait is longer than the first: a provider that is still
        // refusing connections after thirty seconds is not going to be fixed
        // by asking again thirty seconds later.
        $this->runWorker($job->id);

        $job = $job->fresh();
        $this->assertNotNull($job);
        $this->assertSame(2, $job->attempts);
        $this->assertEqualsWithDelta(120, now()->diffInSeconds($job->next_attempt_at), 2);
    }

    #[Test]
    public function a_capacity_failure_is_retried_because_room_may_appear(): void
    {
        $job = $this->queueJob(['outcome' => 'capacity']);

        $this->runWorker($job->id);

        $job = $job->fresh();
        $this->assertNotNull($job);
        $this->assertSame(ProvisioningJobStatus::Queued, $job->status);
        $this->assertSame(FailureClass::Capacity, $job->failure_class);
        Queue::assertPushed(RunProvisioningJob::class, 1);
    }

    #[Test]
    public function a_permanent_failure_is_never_retried(): void
    {
        Event::fake([ProvisioningJobFailed::class]);

        $service = Service::factory()->create();
        $job = $this->queueJob(['outcome' => 'permanent'], ['service_id' => $service->id]);

        $this->runWorker($job->id);

        $job = $job->fresh();
        $this->assertNotNull($job);
        // Retrying a request the provider will reject just as firmly next time
        // only delays the moment somebody looks at it.
        $this->assertSame(ProvisioningJobStatus::Failed, $job->status);
        $this->assertSame(FailureClass::Permanent, $job->failure_class);
        $this->assertSame(1, $job->attempts);
        $this->assertNull($job->next_attempt_at);

        Queue::assertNotPushed(RunProvisioningJob::class);
        $this->assertSame(ServiceStatus::Failed, $service->fresh()?->status);
        Event::assertDispatched(ProvisioningJobFailed::class);
    }

    #[Test]
    public function a_timeout_never_auto_retries_and_lands_in_needs_review(): void
    {
        Event::fake([ProvisioningJobNeedsReview::class]);

        $job = $this->queueJob(['outcome' => 'timeout']);

        $this->runWorker($job->id);

        $job = $job->fresh();
        $this->assertNotNull($job);

        /*
         * The single most important assertion in the module. A timeout means
         * the platform stopped waiting, not that the provider stopped working:
         * the machine may exist, running, with the customer's address on it.
         * Retrying is how that customer ends up with two servers, one of them
         * unbilled and unmanaged. It goes to a person, who looks first.
         */
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->status);
        $this->assertSame(FailureClass::Timeout, $job->failure_class);
        $this->assertSame(1, $job->attempts);
        $this->assertTrue($job->hasAttemptsRemaining(), 'The job still had attempts left and was still not retried.');
        $this->assertNull($job->next_attempt_at);

        Queue::assertNotPushed(RunProvisioningJob::class);
        Event::assertDispatched(ProvisioningJobNeedsReview::class);
    }

    #[Test]
    public function exhausted_attempts_land_in_needs_review_rather_than_silently_failing(): void
    {
        $job = $this->queueJob(['outcome' => 'transient'], ['max_attempts' => 3]);

        $this->runWorker($job->id);
        $this->runWorker($job->id);
        $this->runWorker($job->id);

        $job = $job->fresh();
        $this->assertNotNull($job);
        $this->assertSame(3, $job->attempts);

        // Not "failed": a job that has burned every attempt on faults the
        // engine thought were temporary has something wrong with it that a
        // fourth attempt will not fix, and "failed" invites a fourth attempt.
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->status);
        $this->assertSame(FailureClass::Transient, $job->failure_class);
        $this->assertFalse($job->hasAttemptsRemaining());

        // Two retries were scheduled, not three: the last failure stopped.
        Queue::assertPushed(RunProvisioningJob::class, 2);
    }

    #[Test]
    public function every_attempt_is_recorded_with_its_error(): void
    {
        $job = $this->queueJob(['outcome' => 'transient'], ['max_attempts' => 3]);

        $this->runWorker($job->id);
        $this->runWorker($job->id);
        $this->runWorker($job->id);

        $attempts = ProvisioningAttempt::query()
            ->where('provisioning_job_id', $job->id)
            ->orderBy('attempt_number')
            ->get();

        $this->assertCount(3, $attempts);
        $this->assertSame([1, 2, 3], $attempts->pluck('attempt_number')->all());

        foreach ($attempts as $attempt) {
            $this->assertSame(ProvisioningJobStatus::Failed, $attempt->status);
            $this->assertSame('fake.transient', $attempt->error_code);
            $this->assertNotNull($attempt->error_message);
            $this->assertNotNull($attempt->duration_ms);
        }
    }

    #[Test]
    public function compensation_releases_on_a_permanent_failure(): void
    {
        $job = $this->queueJob(['outcome' => 'permanent']);

        $this->runWorker($job->id);

        // Nothing was built, so the address goes straight back into the pool.
        $this->assertSame(['release'], $this->releaser->actions());
        $this->assertTrue($this->releaser->released($job));

        $this->assertSame('released', $job->fresh()?->result['compensation']['action'] ?? null);
    }

    #[Test]
    public function compensation_quarantines_on_a_timeout_and_never_releases(): void
    {
        $job = $this->queueJob(['outcome' => 'timeout']);

        $this->runWorker($job->id);

        /*
         * The machine may exist with that address configured on it. Releasing
         * hands a live address to the next customer, and the resulting
         * duplicate-IP incident is invisible until two customers are broken.
         */
        $this->assertSame(['quarantine'], $this->releaser->actions());
        $this->assertTrue($this->releaser->quarantined($job));
        $this->assertFalse($this->releaser->released($job));

        $this->assertSame('quarantined', $job->fresh()?->result['compensation']['action'] ?? null);
    }

    #[Test]
    public function nothing_is_compensated_between_two_attempts_of_the_same_build(): void
    {
        $job = $this->queueJob(['outcome' => 'transient']);

        $this->runWorker($job->id);

        // The reservations are what the next attempt will use. Handing them
        // back mid-retry gives the customer's address to somebody else while
        // their build is still going.
        $this->assertSame([], $this->releaser->actions());
    }

    #[Test]
    public function an_unclassified_failure_after_the_provider_accepted_the_work_is_treated_as_a_timeout(): void
    {
        $job = $this->queueJob(['outcome' => 'unclassified']);

        $this->runWorker($job->id);

        $job = $job->fresh();
        $this->assertNotNull($job);

        // The provider has our request and gave us an id for it. Whatever
        // broke afterwards, "nothing was built" is no longer a safe
        // assumption, so the safe classification is the cautious one.
        $this->assertNotNull($job->remote_job_id);
        $this->assertSame(FailureClass::Timeout, $job->failure_class);
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->status);
        $this->assertSame(['quarantine'], $this->releaser->actions());
    }

    #[Test]
    public function an_unclassified_failure_before_the_provider_accepted_anything_is_transient(): void
    {
        $job = $this->queueJob(['outcome' => 'unclassified', 'announce_remote_job_id' => false]);

        $this->runWorker($job->id);

        $job = $job->fresh();
        $this->assertNotNull($job);
        $this->assertNull($job->remote_job_id);
        $this->assertSame(FailureClass::Transient, $job->failure_class);
        $this->assertSame(ProvisioningJobStatus::Queued, $job->status);
    }

    #[Test]
    public function a_job_that_is_already_running_is_left_alone(): void
    {
        $job = ProvisioningJob::factory()->running()->create();

        $this->runWorker($job->id);

        // Another worker owns it. Executing anyway is how two workers call the
        // provider for the same job.
        $this->assertSame(ProvisioningJobStatus::Running, $job->fresh()?->status);
        $this->assertSame(1, $job->fresh()?->attempts);
        $this->assertSame(0, ProvisioningAttempt::query()->count());
    }

    #[Test]
    public function a_settled_job_is_never_run_again(): void
    {
        $job = ProvisioningJob::factory()->status(ProvisioningJobStatus::Succeeded)->create();

        $this->runWorker($job->id);

        $this->assertSame(0, ProvisioningAttempt::query()->count());
        $this->assertSame(0, $job->fresh()?->attempts);
    }

    #[Test]
    public function an_unwired_kind_fails_permanently_instead_of_retrying_forever(): void
    {
        $job = $this->queueJob([], ['kind' => ProvisioningJobKind::ReinstallVps]);

        // A deployment where nothing handles this kind of work. Rebuild the
        // registry with only one kind wired up.
        $registry = new ProvisioningHandlerRegistry($this->app);
        $registry->register(new FakeProvisioningHandler(ProvisioningJobKind::CreateVps));
        $this->app->instance(HandlerRegistry::class, $registry);

        $this->runWorker($job->id);

        $job = $job->fresh();
        $this->assertNotNull($job);

        // A wiring fault is not a provider fault: retrying it every thirty
        // seconds until the attempts run out only delays the moment somebody
        // notices.
        $this->assertSame(ProvisioningJobStatus::Failed, $job->status);
        $this->assertSame(FailureClass::Permanent, $job->failure_class);
        $this->assertSame('provisioning.handler_not_registered', $job->result['error']['code'] ?? null);
        Queue::assertNotPushed(RunProvisioningJob::class);
    }

    #[Test]
    public function a_stored_provider_response_carries_no_secrets(): void
    {
        $job = $this->queueJob(['outcome' => 'succeed']);

        $this->runWorker($job->id);

        $storedResult = (string) DB::table('provisioning_jobs')->where('id', $job->id)->value('result');
        $storedAttempt = (string) DB::table('provisioning_attempts')->where('provisioning_job_id', $job->id)->value('response_metadata');

        foreach ([$storedResult, $storedAttempt] as $stored) {
            // The fake provider answers with a Proxmox-shaped ticket and a
            // bearer token every time, precisely so that this path is
            // exercised by every test rather than by one that remembers to.
            $this->assertStringNotContainsString('c2VjcmV0LXRpY2tldC12YWx1ZQ', $stored);
            $this->assertStringNotContainsString('fake-token-abcdef0123456789', $stored);
            $this->assertStringContainsString('[redacted]', $stored);
        }

        // And the harmless parts survive: redaction must not cost the operator
        // the context they need.
        $this->assertSame('fake-node-01', $job->fresh()?->result['response']['node'] ?? null);
    }

    #[Test]
    public function a_credential_in_a_handler_reported_failure_never_reaches_the_error_columns(): void
    {
        /*
         * A handler that RETURNS a classified failure — rather than throwing
         * one — is the ordinary case for "the provider said no", and its
         * message is whatever the SDK handed back: very often the request that
         * carried the credential. last_error and provisioning_attempts.
         * error_message are plain text columns read by everyone with support
         * access, so the engine has to redact on the way in rather than trust
         * every adapter that will ever be written.
         */
        $this->handlers->register(new class implements ProvisioningHandler
        {
            public function kind(): ProvisioningJobKind
            {
                return ProvisioningJobKind::CreateVps;
            }

            public function execute(ProvisioningJob $job): ProvisioningResult
            {
                return ProvisioningResult::failed(
                    failureClass: FailureClass::Permanent,
                    errorCode: 'provider.rejected',
                    errorMessage: 'POST /nodes/pve/qemu failed; sent Authorization: Bearer leaked-token-abcdef0123456789 and password=hunter2-correct-horse',
                );
            }
        });

        $job = $this->queueJob();

        $this->runWorker($job->id);

        $storedError = (string) DB::table('provisioning_jobs')->where('id', $job->id)->value('last_error');
        $storedAttempt = (string) DB::table('provisioning_attempts')->where('provisioning_job_id', $job->id)->value('error_message');

        foreach ([$storedError, $storedAttempt] as $stored) {
            $this->assertStringNotContainsString('leaked-token-abcdef0123456789', $stored);
            $this->assertStringNotContainsString('hunter2-correct-horse', $stored);
            $this->assertStringContainsString('[redacted]', $stored);
        }

        // The operator still gets the part that tells them what broke.
        $this->assertStringContainsString('/nodes/pve/qemu', $storedError);
    }

    #[Test]
    public function a_credential_in_a_failing_releaser_never_reaches_the_error_column(): void
    {
        $this->app->instance(ResourceReservationReleaser::class, new class implements ResourceReservationReleaser
        {
            public function release(ProvisioningJob $job): int
            {
                throw new RuntimeException('IPAM refused: GET /ipam/release with Authorization: Bearer ipam-token-abcdef0123456789');
            }

            public function quarantine(ProvisioningJob $job, string $reason): int
            {
                throw new RuntimeException('IPAM refused: GET /ipam/release with Authorization: Bearer ipam-token-abcdef0123456789');
            }
        });

        $job = $this->queueJob(['outcome' => 'permanent']);

        $this->runWorker($job->id);

        // The releaser reaches into another module, whose exception messages
        // this one does not control. Appending them raw is the same leak by a
        // different door.
        $storedError = (string) DB::table('provisioning_jobs')->where('id', $job->id)->value('last_error');

        $this->assertStringContainsString('compensation also failed', $storedError);
        $this->assertStringNotContainsString('ipam-token-abcdef0123456789', $storedError);
    }

    #[Test]
    public function a_compensation_that_fails_escalates_the_job_to_review(): void
    {
        $this->app->instance(ResourceReservationReleaser::class, new class implements ResourceReservationReleaser
        {
            public function release(ProvisioningJob $job): int
            {
                throw new RuntimeException('IPAM is unreachable');
            }

            public function quarantine(ProvisioningJob $job, string $reason): int
            {
                throw new RuntimeException('IPAM is unreachable');
            }
        });

        $job = $this->queueJob(['outcome' => 'permanent']);

        $this->runWorker($job->id);

        $job = $job->fresh();
        $this->assertNotNull($job);

        // Resources may still be held by a job that has stopped, which is
        // exactly what a person needs to be told about — rather than a job
        // that looks tidily failed while an address stays out of the pool.
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->status);
        $this->assertStringContainsString('compensation also failed', (string) $job->last_error);
    }

    #[Test]
    public function the_worker_runs_on_the_provisioning_queue(): void
    {
        // Provisioning work is slow, expensive and rate-limited by real
        // hardware; it must not share a queue with password-reset emails.
        $this->assertSame('provisioning', (new RunProvisioningJob('01JQ0000000000000000000000'))->queue);
    }

    /**
     * A handler that records what the database looked like at the moment it
     * was called, which is the only way to assert on ordering the engine
     * promises rather than on the state it leaves behind.
     */
    private function observingHandler(): ProvisioningHandler
    {
        return new class implements ProvisioningHandler
        {
            public ?string $remoteJobIdInDatabase = null;

            public ?string $jobStatusAtCallTime = null;

            public int $attemptRowsAtCallTime = 0;

            public function kind(): ProvisioningJobKind
            {
                return ProvisioningJobKind::CreateVps;
            }

            public function execute(ProvisioningJob $job): ProvisioningResult
            {
                $job->recordRemoteJobId('remote-job-777');

                $row = DB::table('provisioning_jobs')->where('id', $job->getKey())->first();

                $this->remoteJobIdInDatabase = $row?->remote_job_id;
                $this->jobStatusAtCallTime = $row?->status;
                $this->attemptRowsAtCallTime = DB::table('provisioning_attempts')
                    ->where('provisioning_job_id', $job->getKey())
                    ->count();

                return ProvisioningResult::succeeded('remote-job-777', 'vm-101');
            }
        };
    }

    /**
     * @param  array<string, mixed>  $fake
     * @param  array<string, mixed>  $attributes
     */
    private function queueJob(array $fake = ['outcome' => 'succeed'], array $attributes = []): ProvisioningJob
    {
        return ProvisioningJob::factory()->create([
            'payload' => ['hostname' => 'vps-01', 'fake' => $fake],
            ...$attributes,
        ]);
    }
}
