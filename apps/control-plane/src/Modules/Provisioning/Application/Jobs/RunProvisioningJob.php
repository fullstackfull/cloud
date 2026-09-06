<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Provisioning\Application\Actions\CompensateFailedJob;
use Lynomia\Modules\Provisioning\Application\Actions\TransitionService;
use Lynomia\Modules\Provisioning\Domain\Contracts\HandlerRegistry;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobFailed;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobNeedsReview;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobSucceeded;
use Lynomia\Modules\Provisioning\Domain\Exceptions\HandlerNotRegisteredException;
use Lynomia\Modules\Provisioning\Domain\Exceptions\ProvisioningFailedException;
use Lynomia\Modules\Provisioning\Domain\StateMachines\ProvisioningJobStateMachine;
use Lynomia\Modules\Provisioning\Domain\StateMachines\ServiceStateMachine;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningAttempt;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Throwable;

/**
 * The engine: one attempt at one job, and everything that has to be true if
 * the worker dies in the middle of it.
 *
 * The ordering below is the design, and each step is where it is because the
 * obvious alternative has a known failure mode.
 *
 *  1. Claim the job by moving queued → running under a row lock, in a
 *     transaction that commits BEFORE the provider is called. Two workers
 *     handed the same message — which queues do, routinely — serialise on the
 *     lock, and the loser finds a running job and leaves it alone. The
 *     transaction must not span the provider call: a lock held across a
 *     five-minute build blocks the operator trying to read the row, and worse,
 *     everything written during the call would be rolled back by the failure
 *     it exists to record.
 *
 *  2. Record the attempt row before the call, not after. An attempt that only
 *     appears on return is an attempt that never appears for the call that
 *     killed the worker — precisely the one somebody will need to explain.
 *
 *  3. Persist the provider's job id the moment it is known. Handlers do this
 *     themselves through recordRemoteJobId(); this class writes it again from
 *     the result as a backstop. Without that id a timeout is unrecoverable:
 *     the platform cannot ask "did my request succeed?", cannot tell whether
 *     the resource exists, and every recovery becomes a guess that risks
 *     creating a second one.
 *
 *  4. Classify the failure, and let the classification decide. transient and
 *     capacity are retried with backoff. permanent goes straight to failed —
 *     retrying a request the provider will reject just as firmly next time
 *     only delays the moment somebody looks. timeout is NEVER retried
 *     automatically: it means the platform stopped waiting, not that the
 *     provider stopped working, and the resource may well exist. Retrying is
 *     how a customer ends up with two servers, one of them unbilled,
 *     unmanaged, and holding an address the next customer is about to be
 *     given. A timeout goes to needs_review, and a person checks for an orphan
 *     before anything else happens.
 *
 *  5. Exhausting the attempts lands in needs_review, not failed. "Failed"
 *     invites the reflex to retry it; a job that has burned three attempts has
 *     something wrong with it that a fourth will not fix.
 *
 *  6. Compensate, and only once the job has stopped. Between attempts the
 *     reservations are exactly what the next attempt will use — releasing an
 *     address mid-retry hands the customer's address to somebody else while
 *     their build is still in progress.
 *
 * If this class itself dies — the database goes away, the worker is killed —
 * the job stays running and no other worker may claim it. That is deliberate,
 * and DetectStaleJobs is the other half of it: after the job's own timeout has
 * passed, the sweeper moves it to review and quarantines what it held.
 */
final class RunProvisioningJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string QUEUE = 'provisioning';

    /**
     * The engine owns retrying, not the queue.
     *
     * A queue-level retry would re-execute a job whose provider call may have
     * landed, with none of the classification, backoff or attempt accounting
     * that makes retrying safe. One try per message; the engine schedules the
     * next one when it decides there should be one.
     */
    public int $tries = 1;

    public function __construct(
        public readonly string $provisioningJobId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(
        HandlerRegistry $handlers,
        CompensateFailedJob $compensate,
        TransitionService $transitionService,
        ServiceStateMachine $serviceStates,
        ProvisioningJobStateMachine $jobStates,
        SecretRedactor $redactor,
    ): void {
        $job = $this->claim($jobStates);

        if ($job === null) {
            // Already running, already settled, or gone. All three are cases
            // where doing nothing is the only safe action.
            return;
        }

        $this->syncService($job, ServiceStatus::Provisioning, $transitionService, $serviceStates, onlyForCreates: true);

        $attempt = ProvisioningAttempt::create([
            'provisioning_job_id' => $job->getKey(),
            'attempt_number' => $job->attempts,
            'status' => ProvisioningJobStatus::Running,
            'remote_job_id' => $job->remote_job_id,
            'created_at' => now(),
        ]);

        $startedAt = microtime(true);

        try {
            $result = $handlers->get($job->kind)->execute($job);
        } catch (HandlerNotRegisteredException $e) {
            // A wiring fault, not a provider fault. Classified permanent so it
            // surfaces now rather than after three pointless retries.
            $result = ProvisioningResult::failed(FailureClass::Permanent, $e->errorCode(), $e->getMessage());
        } catch (ProvisioningFailedException $e) {
            $result = ProvisioningResult::failed($e->failureClass(), $e->errorCode(), $e->getMessage());
        } catch (Throwable $e) {
            $result = ProvisioningResult::failed(
                $this->classifyUnknown($job),
                'provisioning.unclassified',
                $e->getMessage(),
            );
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        /*
         * One choke point for the failure message, covering every way a
         * failure can arrive: an SDK exception quoting the request that
         * carried the credential, and equally a handler that RETURNS a
         * classified failure whose message is the provider's raw response.
         * Redacting only in the catch blocks left the returned case — the
         * ordinary "the provider said no" — writing a bearer token straight
         * into last_error and provisioning_attempts.error_message, which are
         * plain text columns read by everyone with support access.
         */
        $result = $this->redactMessage($result, $redactor);

        // Backstop for a handler that reported the id only on return. It is
        // late — a handler that waits until it returns has already lost the
        // race it matters in — but late is better than never.
        if ($result->remoteJobId !== null) {
            $job->recordRemoteJobId($result->remoteJobId);
        }

        if ($result->successful) {
            $this->succeed($job, $attempt, $result, $durationMs, $jobStates, $transitionService, $serviceStates);

            return;
        }

        $this->fail($job, $attempt, $result, $durationMs, $jobStates, $compensate, $transitionService, $serviceStates, $redactor);
    }

    /**
     * Take ownership of the job, or decline.
     *
     * The lock, the re-read and the status check are one atomic step. A worker
     * that checked the status and then wrote would be racing every other
     * worker holding a copy of the same message, and both would call the
     * provider.
     */
    private function claim(ProvisioningJobStateMachine $jobStates): ?ProvisioningJob
    {
        return DB::transaction(function () use ($jobStates): ?ProvisioningJob {
            /** @var ProvisioningJob|null $locked */
            $locked = ProvisioningJob::query()->lockForUpdate()->find($this->provisioningJobId);

            if ($locked === null || ! $locked->status->isClaimable()) {
                return null;
            }

            $jobStates->assertCanTransition($locked->status, ProvisioningJobStatus::Running);

            $locked->status = ProvisioningJobStatus::Running;
            $locked->attempts++;
            // Reset per attempt: the timeout bounds this call, not the whole
            // history of the job, or a job on its third attempt would be
            // declared stale the moment it started.
            $locked->started_at = now();
            $locked->finished_at = null;
            $locked->next_attempt_at = null;
            $locked->save();

            return $locked;
        });
    }

    private function succeed(
        ProvisioningJob $job,
        ProvisioningAttempt $attempt,
        ProvisioningResult $result,
        int $durationMs,
        ProvisioningJobStateMachine $jobStates,
        TransitionService $transitionService,
        ServiceStateMachine $serviceStates,
    ): void {
        $this->finishAttempt($attempt, ProvisioningJobStatus::Succeeded, $result, $durationMs, $job->remote_job_id);

        $jobStates->assertCanTransition($job->status, ProvisioningJobStatus::Succeeded);

        $job->status = ProvisioningJobStatus::Succeeded;
        $job->failure_class = null;
        $job->last_error = null;
        $job->finished_at = now();
        $job->next_attempt_at = null;
        $job->result = $this->mergedResult($job, $result);
        $job->save();

        $this->syncService($job, $job->kind->serviceStatusOnSuccess(), $transitionService, $serviceStates);

        event(new ProvisioningJobSucceeded(
            provisioningJobId: (string) $job->getKey(),
            kind: $job->kind,
            serviceId: $job->service_id,
            remoteJobId: $job->remote_job_id,
        ));
    }

    private function fail(
        ProvisioningJob $job,
        ProvisioningAttempt $attempt,
        ProvisioningResult $result,
        int $durationMs,
        ProvisioningJobStateMachine $jobStates,
        CompensateFailedJob $compensate,
        TransitionService $transitionService,
        ServiceStateMachine $serviceStates,
        SecretRedactor $redactor,
    ): void {
        $failureClass = $this->reclassifyIfDeadlineExceeded(
            $job,
            $result->failureClass ?? FailureClass::Transient,
            $durationMs,
        );

        $this->finishAttempt($attempt, ProvisioningJobStatus::Failed, $result, $durationMs, $job->remote_job_id);

        if ($failureClass->isAutomaticallyRetryable() && $job->hasAttemptsRemaining()) {
            $this->scheduleRetry($job, $result, $failureClass, $jobStates);

            return;
        }

        $status = $this->terminalStatusFor($job, $failureClass);

        $jobStates->assertCanTransition($job->status, $status);

        $job->status = $status;
        $job->failure_class = $failureClass;
        $job->last_error = $result->errorMessage;
        $job->finished_at = now();
        $job->next_attempt_at = null;
        $job->result = $this->mergedResult($job, $result);
        $job->save();

        try {
            $compensate->execute($job, $failureClass);
        } catch (Throwable $e) {
            /*
             * Compensation reaches into another module. If it fails, resources
             * may still be held by a job that has stopped — which is exactly
             * what a person needs to be told about, so the job is escalated
             * rather than left looking tidily failed.
             */
            $status = ProvisioningJobStatus::NeedsReview;
            $job->status = $status;
            $job->last_error = sprintf(
                '%s (compensation also failed: %s)',
                (string) $job->last_error,
                // Another module's exception message, whose contents this one
                // does not control.
                $redactor->redactString($e->getMessage()),
            );
            $job->save();
        }

        if ($job->kind->failsServiceOnFailure()) {
            $this->syncService($job, ServiceStatus::Failed, $transitionService, $serviceStates);
        }

        if ($status === ProvisioningJobStatus::NeedsReview) {
            event(new ProvisioningJobNeedsReview(
                provisioningJobId: (string) $job->getKey(),
                kind: $job->kind,
                serviceId: $job->service_id,
                remoteJobId: $job->remote_job_id,
                failureClass: $failureClass,
                reason: (string) $job->last_error,
            ));

            return;
        }

        event(new ProvisioningJobFailed(
            provisioningJobId: (string) $job->getKey(),
            kind: $job->kind,
            serviceId: $job->service_id,
            failureClass: $failureClass,
            errorCode: (string) $result->errorCode,
        ));
    }

    private function scheduleRetry(
        ProvisioningJob $job,
        ProvisioningResult $result,
        FailureClass $failureClass,
        ProvisioningJobStateMachine $jobStates,
    ): void {
        $delay = $this->backoffSeconds($job->attempts);

        $jobStates->assertCanTransition($job->status, ProvisioningJobStatus::Queued);

        $job->status = ProvisioningJobStatus::Queued;
        $job->failure_class = $failureClass;
        $job->last_error = $result->errorMessage;
        // Recorded as well as delayed on the queue: the delay lives in a
        // message that a flushed queue would lose, while this column is what
        // lets a sweeper find the job again.
        $job->next_attempt_at = now()->addSeconds($delay);
        $job->finished_at = null;
        $job->result = $this->mergedResult($job, $result);
        $job->save();

        /*
         * Nothing is compensated here. The addresses and capacity this attempt
         * reserved are what the next attempt will use; handing them back
         * between two attempts of the same build would give the customer's
         * address to somebody else halfway through.
         */
        self::dispatch($job->getKey())->delay(now()->addSeconds($delay));
    }

    /**
     * Where a job that will not be retried comes to rest.
     */
    private function terminalStatusFor(ProvisioningJob $job, FailureClass $failureClass): ProvisioningJobStatus
    {
        // A timeout always needs a person: something may exist at the provider
        // and only a person can go and look.
        if ($failureClass->requiresReview()) {
            return ProvisioningJobStatus::NeedsReview;
        }

        /*
         * Attempts exhausted. This is not a silent failure: a job that has
         * burned every attempt on faults the engine considered temporary has
         * something wrong with it that a fourth attempt will not fix, and
         * marking it "failed" invites exactly that fourth attempt.
         */
        if (! $job->hasAttemptsRemaining() && $failureClass->isAutomaticallyRetryable()) {
            return ProvisioningJobStatus::NeedsReview;
        }

        return ProvisioningJobStatus::Failed;
    }

    /**
     * What an exception the engine does not recognise means.
     *
     * If the provider had already accepted the work — we hold its job id —
     * then "nothing was built" is not a safe assumption, whatever broke
     * afterwards. Treating it as a timeout quarantines the reservations and
     * asks for a person, which is the only classification that cannot produce
     * a second server.
     */
    private function classifyUnknown(ProvisioningJob $job): FailureClass
    {
        return $job->remote_job_id !== null ? FailureClass::Timeout : FailureClass::Transient;
    }

    /**
     * A call that outran the deadline is a timeout, whatever it says it is.
     *
     * The handler may report a connection error that arrived twenty minutes
     * late; by then the platform had already stopped waiting, and the provider
     * may have finished the work in the meantime.
     */
    private function reclassifyIfDeadlineExceeded(ProvisioningJob $job, FailureClass $failureClass, int $durationMs): FailureClass
    {
        if ($failureClass === FailureClass::Timeout) {
            return $failureClass;
        }

        return $durationMs >= $job->timeout_seconds * 1000 ? FailureClass::Timeout : $failureClass;
    }

    /**
     * Strip credentials from a failure message before it is persisted.
     *
     * A handler is asked never to put one there, but "asked" is not a control:
     * the message usually comes from an SDK, and the one adapter that forgets
     * is the one that quotes the request headers.
     */
    private function redactMessage(ProvisioningResult $result, SecretRedactor $redactor): ProvisioningResult
    {
        if ($result->successful || $result->errorMessage === null) {
            return $result;
        }

        return ProvisioningResult::failed(
            failureClass: $result->failureClass ?? FailureClass::Transient,
            errorCode: (string) $result->errorCode,
            errorMessage: $redactor->redactString($result->errorMessage),
            remoteJobId: $result->remoteJobId,
            providerReference: $result->providerReference,
            metadata: $result->metadata,
        );
    }

    private function backoffSeconds(int $attempt): int
    {
        /** @var list<int> $backoff */
        $backoff = config('provisioning.retry.backoff_seconds', [30, 120, 600]);

        if ($backoff === []) {
            return 60;
        }

        // Past the end of the table the last wait repeats, rather than the
        // first one coming round again.
        return (int) ($backoff[$attempt - 1] ?? $backoff[count($backoff) - 1]);
    }

    private function finishAttempt(
        ProvisioningAttempt $attempt,
        ProvisioningJobStatus $status,
        ProvisioningResult $result,
        int $durationMs,
        ?string $remoteJobId,
    ): void {
        $attempt->status = $status;
        $attempt->remote_job_id = $result->remoteJobId ?? $remoteJobId;
        $attempt->error_code = $result->errorCode === null ? null : mb_substr($result->errorCode, 0, 64);
        $attempt->error_message = $result->errorMessage;
        $attempt->response_metadata = $result->metadata === [] ? null : $result->metadata;
        $attempt->duration_ms = $durationMs;
        $attempt->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function mergedResult(ProvisioningJob $job, ProvisioningResult $result): array
    {
        $existing = $job->result ?? [];

        if ($result->providerReference !== null) {
            $existing['provider_reference'] = $result->providerReference;
        }

        $existing['remote_job_id'] = $result->remoteJobId ?? $job->remote_job_id;
        $existing['response'] = $result->metadata;

        if ($result->isFailure()) {
            $existing['error'] = ['code' => $result->errorCode, 'class' => $result->failureClass?->value];
        }

        return $existing;
    }

    /**
     * Move the service to where this job leaves it.
     *
     * Guarded with canTransition rather than asserted: a service that is not
     * in the state this job expected is a mismatch for an operator to explain,
     * not a reason to turn a provider call that actually succeeded into a
     * failure.
     */
    private function syncService(
        ProvisioningJob $job,
        ?ServiceStatus $target,
        TransitionService $transitionService,
        ServiceStateMachine $serviceStates,
        bool $onlyForCreates = false,
    ): void {
        if ($target === null || ($onlyForCreates && ! $job->kind->createsResource())) {
            return;
        }

        $service = $job->service()->first();

        if ($service === null || ! $serviceStates->canTransition($service->status, $target)) {
            return;
        }

        $transitionService->execute($service, $target);
    }
}
