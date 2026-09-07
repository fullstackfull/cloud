<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Enums;

/**
 * How one piece of provisioning work reads to the customer it was done for.
 *
 * The engine's own vocabulary is about who may touch the job next — queued
 * means a worker may claim it, needs_review means no worker ever will. That is
 * a statement about the platform's internals, and it is not what a customer is
 * asking. They are asking whether the thing they bought is being worked on,
 * whether it finished, and whether it is stuck.
 *
 * `queued` is published as `scheduled` for that reason: a job that failed on a
 * transient fault goes back to queued with a delay, so "queued" here covers
 * both the first attempt and a retry that is waiting its turn, and neither is
 * a queue position the customer can do anything with.
 */
enum CustomerProvisioningEventState: string
{
    /** Waiting to run, whether for the first time or after a retryable fault. */
    case Scheduled = 'scheduled';

    /** Running now. */
    case InProgress = 'in_progress';

    case Completed = 'completed';

    /** Stopped, and will not be retried on its own. */
    case Failed = 'failed';

    /** Stopped mid-flight and waiting on a person. */
    case UnderReview = 'under_review';

    case Cancelled = 'cancelled';

    public static function for(ProvisioningJobStatus $status): self
    {
        return match ($status) {
            ProvisioningJobStatus::Queued => self::Scheduled,
            ProvisioningJobStatus::Running => self::InProgress,
            ProvisioningJobStatus::Succeeded => self::Completed,
            ProvisioningJobStatus::Failed => self::Failed,
            ProvisioningJobStatus::NeedsReview => self::UnderReview,
            ProvisioningJobStatus::Cancelled => self::Cancelled,
        };
    }
}
