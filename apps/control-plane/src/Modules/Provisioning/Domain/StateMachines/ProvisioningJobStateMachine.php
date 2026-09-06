<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\StateMachines;

use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Shared\Domain\Contracts\AbstractStateMachine;

/**
 * The job lifecycle, as the engine is allowed to move it.
 *
 * Two entries carry the weight of the whole module:
 *
 *  - running → queued exists, and is how a retry is scheduled. The job goes
 *    back to the pool with a next_attempt_at, rather than a worker looping on
 *    a resource it may already have created.
 *  - running → needs_review exists, and is where a timeout lands. There is no
 *    needs_review → queued: only a person may put a job that may already have
 *    built something back into the pool, because only a person can go and
 *    look.
 *
 * @extends AbstractStateMachine<ProvisioningJobStatus>
 */
final class ProvisioningJobStateMachine extends AbstractStateMachine
{
    public function subject(): string
    {
        return 'ProvisioningJob';
    }

    /**
     * @return array<string, list<ProvisioningJobStatus>>
     */
    public function transitions(): array
    {
        return [
            ProvisioningJobStatus::Queued->value => [
                ProvisioningJobStatus::Running,
                ProvisioningJobStatus::Cancelled,
                // Adopting a resource the provider already built settles the
                // job it belongs to before a worker ever picks it up, which is
                // the only thing that stops the worker building a second one.
                ProvisioningJobStatus::Succeeded,
            ],

            ProvisioningJobStatus::Running->value => [
                ProvisioningJobStatus::Succeeded,
                ProvisioningJobStatus::Failed,
                ProvisioningJobStatus::NeedsReview,
                // Scheduling the next attempt.
                ProvisioningJobStatus::Queued,
            ],

            ProvisioningJobStatus::Failed->value => [
                // An operator may put a job that provably built nothing back
                // into the pool.
                ProvisioningJobStatus::Queued,
                ProvisioningJobStatus::NeedsReview,
                // Adoption of a resource found at the provider settles the job
                // the build it belongs to should have settled.
                ProvisioningJobStatus::Succeeded,
            ],

            ProvisioningJobStatus::NeedsReview->value => [
                // Every route out of review is a decision someone made: adopt
                // the orphan, give up, or — having checked the provider —
                // queue it again by hand.
                ProvisioningJobStatus::Succeeded,
                ProvisioningJobStatus::Failed,
                ProvisioningJobStatus::Cancelled,
                ProvisioningJobStatus::Queued,
            ],

            ProvisioningJobStatus::Succeeded->value => [],
            ProvisioningJobStatus::Cancelled->value => [],
        ];
    }
}
