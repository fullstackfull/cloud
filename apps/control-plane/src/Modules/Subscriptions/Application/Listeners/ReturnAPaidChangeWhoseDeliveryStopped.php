<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Listeners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Provisioning\Application\Actions\CloseAJobWhoseServiceEnded;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobClosed;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobFailed;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobNeedsReview;
use Lynomia\Modules\Provisioning\Domain\StateMachines\ProvisioningJobStateMachine;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Subscriptions\Application\Actions\ReturnAnUpgradeTheEndPrevented;
use Lynomia\Modules\Subscriptions\Application\Actions\WindUpAnEndedSubscription;
use Throwable;

/**
 * A paid plan change whose delivering job stops after its service has ended
 * goes back to the wallet then, not never.
 *
 * The end of a service asks about its paid changes once, when it arrives
 * ({@see WindUpAnEndedSubscription}): a change whose resize or package change
 * has already stopped - in review, failed - is returned there
 * (ReturnAnUpgradeTheEndPrevented). A job still queued or running then may
 * still stop afterwards, and nothing asked again: it failed or went to review
 * on a service that had ended, and the payment stayed with the platform. So
 * does a job an operator closes (CloseAJobWhoseServiceEnded), which used to
 * take the last pointer to the money off the review list and move none of it
 * (R10-M, the final audit).
 *
 * So each stop of a job queued under a paid change's key
 * (`plan-change:<subscription>:<plan>:invoice:<id>`, QueuePlanChangeAtProvider)
 * asks ReturnAnUpgradeTheEndPrevented for that invoice. It decides - on a
 * service that has not ended nothing is returned, and the payment is held for
 * an operator (docs/billing.md) - and it is idempotent with the wind-up and
 * with itself: what the invoice still holds is read under the invoice's lock.
 *
 *  - Closed ({@see ProvisioningJobClosed}): synchronous, inside the close's
 *    transaction. The close holds the job's lock and this takes the invoice's
 *    and the wallet's after it (WhatAnInvoiceStillHolds, the lock order). A
 *    return that fails fails the close: the job stays in review, closable,
 *    and the money is not left without a pointer.
 *  - Failed or in review ({@see ProvisioningJobFailed},
 *    {@see ProvisioningJobNeedsReview}): after the caller's transaction
 *    commits, if there is one, in a transaction of its own, so it holds none
 *    of the caller's locks. It never throws: it is raised where a worker or a
 *    sweep has already written the job's outcome, and its failure must not
 *    become theirs. A failure is logged, and the job is left where the close
 *    asks again: a job in review is closable as it is, and a failed one on
 *    an ended service is sent to review (sendAFailedJobToReview()), where it
 *    is closable too (docs/runbooks/provisioning-stuck.md §6).
 */
final readonly class ReturnAPaidChangeWhoseDeliveryStopped
{
    public function __construct(
        private ReturnAnUpgradeTheEndPrevented $returnIt,
        private ProvisioningJobStateMachine $jobStates,
    ) {}

    public function handle(ProvisioningJobClosed|ProvisioningJobFailed|ProvisioningJobNeedsReview $event): void
    {
        if ($event->kind !== ProvisioningJobKind::Resize && $event->kind !== ProvisioningJobKind::ChangeHostingPackage) {
            return;
        }

        $invoiceId = self::invoiceItDelivers($event->provisioningJobId);

        if ($invoiceId === null) {
            // A change that owed nothing (`...:change:<id>`): nothing was paid.
            return;
        }

        if ($event instanceof ProvisioningJobClosed) {
            $this->returnIt->execute($invoiceId);

            return;
        }

        DB::afterCommit(function () use ($invoiceId, $event): void {
            try {
                DB::transaction(fn (): int => $this->returnIt->execute($invoiceId));
            } catch (Throwable $e) {
                $sentToReview = $this->sendAFailedJobToReview($event->provisioningJobId, $e);

                Log::warning('A paid plan change\'s job stopped and returning its payment to the wallet failed; closing the job asks again (docs/runbooks/provisioning-stuck.md §6).', [
                    'provisioning_job_id' => $event->provisioningJobId,
                    'invoice_id' => $invoiceId,
                    'sent_to_review' => $sentToReview,
                    'reason' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * A failed job whose return failed goes to review (`failed ->
     * needs_review`, an edge the state machine has), on a service that has
     * ended, so it is on the review list, closable, and its close asks for
     * the return again inside the close's transaction. A job already in
     * review is closable as it is. It used to be only logged, and a failed
     * job left nothing on any list pointing at the money (reservation 3 of
     * round ten M). Nothing is sent to review on a live service: nothing is
     * returned there, and the payment is held for an operator as it was.
     */
    private function sendAFailedJobToReview(string $provisioningJobId, Throwable $why): bool
    {
        try {
            return DB::transaction(function () use ($provisioningJobId, $why): bool {
                /** @var ProvisioningJob|null $job */
                $job = ProvisioningJob::query()->lockForUpdate()->find($provisioningJobId);

                if ($job === null
                    || $job->status !== ProvisioningJobStatus::Failed
                    || $job->service_id === null
                    || Service::query()->find($job->service_id)?->status !== ServiceStatus::Terminated
                    || ! $this->jobStates->canTransition($job->status, ProvisioningJobStatus::NeedsReview)) {
                    return false;
                }

                $job->status = ProvisioningJobStatus::NeedsReview;
                $job->last_error = sprintf('%s (returning the paid plan change it delivered also failed: %s; close the job to ask again)', (string) $job->last_error, mb_substr($why->getMessage(), 0, 300));
                $job->save();

                return true;
            });
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The proration invoice a job delivers, read off its key; null for a job
     * queued under anything else. The key shapes are
     * QueuePlanChangeAtProvider's, the same NotifyOnProvisioningOutcome
     * reads to tell a paid change's failure from an unpaid one's.
     */
    private static function invoiceItDelivers(string $provisioningJobId): ?string
    {
        $key = ProvisioningJob::query()->whereKey($provisioningJobId)->value('idempotency_key');

        if (! is_string($key) || preg_match('/\Aplan-change:[^:]+:[^:]+:invoice:([0-9A-Za-z]{26})\z/', $key, $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
