<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobNeedsReview;
use Lynomia\Modules\Provisioning\Domain\StateMachines\ProvisioningJobStateMachine;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Throwable;

/**
 * Finds jobs nobody is coming back for.
 *
 * A worker that is killed mid-call leaves a job marked running for ever: the
 * process that would have settled it is gone, and no other worker may claim a
 * running job. Without this sweeper those jobs are invisible failures, and the
 * addresses they reserved are held by a job that will never finish.
 *
 * Everything it finds is classified as a timeout, which is the honest
 * classification and also the safe one. The platform stopped waiting; what the
 * provider did is unknown. So these jobs go to needs_review, never back into
 * the queue, and their reservations are quarantined rather than released — the
 * same rule the engine applies to a timeout it observed itself, for the same
 * reason.
 *
 * It also writes the job's finding, `result.error`, and that is not
 * bookkeeping. The finding is what the screen and the actions downstream of
 * it read as "what the last attempt found", and before this sweep wrote one
 * the finding on a swept job was whatever an EARLIER attempt had written. F-15
 * measured what that costs: an attempt that had found a stranger's machine at
 * its reserved VPS identity left `vps.create_identity_taken` behind; instead
 * of the repoint that finding licensed, the operator had the stranger removed
 * at the hypervisor and retried; the retry built under the SAME identity and
 * its worker died; this sweep moved the job to review without touching
 * `result` — and the old finding, still about the identity the job held,
 * licensed a repoint off the identity the job's own machine now sat under,
 * and the next retry built a second machine. The sweep's own code, stamped
 * with the attempt it belongs to, replaces whatever was there.
 * (`RepointReservedIdentity` also refuses a finding from any attempt but the
 * last, independently; either half alone closes that door. A build under a
 * NEW identity after a repoint is not this door: the old finding is about the
 * identity the job was moved off, and the repoint refuses it on that alone.)
 */
final readonly class DetectStaleJobs
{
    /**
     * What a swept job's finding says: no attempt answered, not what an
     * earlier attempt found.
     */
    public const string ERROR_CODE = 'provisioning.worker_never_settled';

    public function __construct(
        private ProvisioningJobStateMachine $jobStates,
        private CompensateFailedJob $compensate,
        private SecretRedactor $redactor,
    ) {}

    /**
     * @param  int  $limit  Bounds one sweep, so a backlog is worked through over several
     *                      runs rather than in one transaction-heavy burst.
     * @return int The number of jobs moved to review.
     */
    public function execute(int $limit = 100): int
    {
        /** @var list<string> $ids */
        $ids = ProvisioningJob::query()
            ->stale()
            ->orderBy('started_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $moved = 0;

        foreach ($ids as $id) {
            $job = $this->moveToReview($id);

            if ($job === null) {
                continue;
            }

            $moved++;

            /*
             * Compensation runs after the status is committed, not inside the
             * lock. The releaser reaches into another module's tables, and a
             * slow or failing IPAM must not hold a row lock on a job that an
             * operator is trying to read.
             *
             * It is also allowed to fail without taking the sweep with it.
             * This action is the last line of defence for a worker that never
             * came back: one unreachable IPAM must not stop the jobs queued
             * behind the failing one being swept. And the failing one is
             * already committed to review, so no later sweep will find it
             * again — if the failure were not written onto the job, nothing
             * anywhere would say its addresses are still out of the pool.
             */
            try {
                $this->compensate->execute($job, FailureClass::Timeout, $this->reasonFor($job));
            } catch (Throwable $e) {
                $job->last_error = sprintf(
                    '%s (compensation also failed: %s)',
                    $this->reasonFor($job),
                    // Another module's exception message, whose contents this
                    // one does not control.
                    $this->redactor->redactString($e->getMessage()),
                );
                $job->save();
            }

            event(new ProvisioningJobNeedsReview(
                provisioningJobId: (string) $job->getKey(),
                kind: $job->kind,
                serviceId: $job->service_id,
                remoteJobId: $job->remote_job_id,
                failureClass: FailureClass::Timeout,
                reason: (string) $job->last_error,
            ));
        }

        return $moved;
    }

    private function moveToReview(string $id): ?ProvisioningJob
    {
        return DB::transaction(function () use ($id): ?ProvisioningJob {
            /** @var ProvisioningJob|null $locked */
            $locked = ProvisioningJob::query()->lockForUpdate()->find($id);

            if ($locked === null) {
                return null;
            }

            /*
             * Re-checked under the lock. Between the sweep's read and this
             * write the worker may have finished — a job that completed one
             * second before the sweeper looked at it must not be dragged into
             * review, and its resources must not be quarantined.
             */
            $deadline = $locked->deadline();

            if ($locked->status !== ProvisioningJobStatus::Running || $deadline === null || $deadline->isFuture()) {
                return null;
            }

            $this->jobStates->assertCanTransition($locked->status, ProvisioningJobStatus::NeedsReview);

            $locked->status = ProvisioningJobStatus::NeedsReview;
            $locked->failure_class = FailureClass::Timeout;
            $locked->last_error = $this->reasonFor($locked);
            $locked->finished_at = now();
            $locked->next_attempt_at = null;
            $locked->result = [
                ...($locked->result ?? []),
                'error' => [
                    'code' => self::ERROR_CODE,
                    'class' => FailureClass::Timeout->value,
                    'attempt' => $locked->attempts,
                ],
            ];
            $locked->save();

            return $locked;
        });
    }

    private function reasonFor(ProvisioningJob $job): string
    {
        return sprintf(
            'No worker settled this job within its %d second timeout; the resource may exist at the provider.',
            $job->timeout_seconds,
        );
    }
}
