<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobClosed;
use Lynomia\Modules\Provisioning\Domain\Exceptions\CloseRefusedException;
use Lynomia\Modules\Provisioning\Domain\StateMachines\ProvisioningJobStateMachine;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * Take a job off the review list because the service it worked for has
 * ended, without running it.
 *
 * A resize, a package change or a power change stopped in review on a service
 * that has since ended (`terminated`) had no way out (X9-1, the re-audit
 * after round eight): a retry is refused once the service has ended
 * (RetryProvisioningJob - running a job for a service that is over), and an
 * adoption is refused for a job that builds nothing (AdoptOrphanResource).
 * It stayed in review for ever, and so did the ProvisioningJobsAwaitingReview
 * alert, which counts jobs in `needs_review`.
 *
 * Closing moves the job `needs_review -> cancelled`, an edge the state
 * machine already has for a person giving up, stamps finished_at and records
 * on the job's result who closed it and when (CLOSED); the controller audits
 * it with the evidence, in the same transaction. Nothing is asked of a
 * provider. The close raises ProvisioningJobClosed in that transaction, and
 * that is how money moves: a resize or package change delivering a paid plan
 * change (queued under its invoice's key) that is closed has its payment
 * returned to the wallet, with the close and in its transaction
 * (ReturnAPaidChangeWhoseDeliveryStopped, which asks
 * ReturnAnUpgradeTheEndPrevented - nothing, when the service's end already
 * returned it). A close whose return fails is refused with it, so the last
 * pointer to the money is not taken off the list while the money stays. It
 * used to move no money at all, and a paid change closed here was kept (R10-M,
 * the final audit). The locks: the job, then the invoice and the wallet
 * (WhatAnInvoiceStillHolds, the lock order). Nothing else is written: no
 * reservation or commitment is touched - what the service held is for the
 * service's end to give back, and closing does not check that it did. The address reaper
 * (Ipam's ReapExpiredReservations) releases the reservations of a
 * `cancelled` job; no kind a close accepts reserves an address (the only
 * handlers that use the address allocator are the VPS create, the VPS
 * destroy and the dedicated build), so a close gives it nothing to release.
 *
 * It refuses (CloseRefusedException, 409):
 *
 *  - a job not in `needs_review`;
 *  - a job of any kind but CLOSABLE_KINDS. A build or a destroy in review may
 *    have left a resource at the provider, and closing it would take the only
 *    pointer to it off the list: a build is adopted or retried. A rebuild is
 *    settled by its operation's own verdict. The WordPress kinds are not
 *    accepted: a close says nothing about the sites they act on;
 *  - a job whose service has not ended, or that has no service: what it was
 *    doing still matters, and a retry is the way out.
 */
final readonly class CloseAJobWhoseServiceEnded
{
    /** Where on the job's result the close is recorded. */
    public const string CLOSED = 'closed_after_the_service_ended';

    /**
     * The kinds a close accepts: each changes a resource that already exists
     * and makes or removes none.
     *
     * @var list<ProvisioningJobKind>
     */
    public const array CLOSABLE_KINDS = [
        ProvisioningJobKind::Start,
        ProvisioningJobKind::Stop,
        ProvisioningJobKind::Restart,
        ProvisioningJobKind::Resize,
        ProvisioningJobKind::ChangeHostingPackage,
    ];

    public function __construct(
        private ProvisioningJobStateMachine $jobStates,
    ) {}

    /**
     * Whether a close of this job would be accepted now: what the review list
     * publishes as `closable`, and what execute() asks again under the lock.
     */
    public static function accepts(ProvisioningJob $job, ?Service $service): bool
    {
        return $job->status === ProvisioningJobStatus::NeedsReview
            && in_array($job->kind, self::CLOSABLE_KINDS, true)
            && $service?->status === ServiceStatus::Terminated;
    }

    /**
     * @throws CloseRefusedException
     */
    public function execute(ProvisioningJob $job, string $closedBy): ProvisioningJob
    {
        return DB::transaction(function () use ($job, $closedBy): ProvisioningJob {
            /** @var ProvisioningJob $locked */
            $locked = ProvisioningJob::query()->lockForUpdate()->findOrFail($job->getKey());

            if ($locked->status !== ProvisioningJobStatus::NeedsReview) {
                throw CloseRefusedException::becauseTheJobIsNotInReview((string) $locked->getKey(), $locked->status);
            }

            if (! in_array($locked->kind, self::CLOSABLE_KINDS, true)) {
                throw CloseRefusedException::becauseOfItsKind((string) $locked->getKey(), $locked->kind);
            }

            $service = $locked->service_id === null ? null : Service::query()->find($locked->service_id);

            if ($service?->status !== ServiceStatus::Terminated) {
                throw CloseRefusedException::becauseTheServiceHasNotEnded((string) $locked->getKey(), $locked->service_id);
            }

            $this->jobStates->assertCanTransition($locked->status, ProvisioningJobStatus::Cancelled);

            $locked->status = ProvisioningJobStatus::Cancelled;
            $locked->finished_at = now();
            // The error is left where it is, as a retry leaves it: it is what
            // the job was stopped for.
            $locked->result = [
                ...($locked->result ?? []),
                self::CLOSED => ['by' => $closedBy, 'at' => now()->toIso8601String(), 'service_status' => $service->status->value],
            ];
            $locked->save();

            // Inside the transaction: what must happen with the close commits
            // or fails with it (the class docblock).
            event(new ProvisioningJobClosed(
                provisioningJobId: (string) $locked->getKey(),
                kind: $locked->kind,
                serviceId: $locked->service_id,
            ));

            return $locked;
        });
    }
}
