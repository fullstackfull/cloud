<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Enums;

/**
 * Where a unit of provisioning work stands.
 *
 * `needs_review` is the state that makes this engine safe to run unattended:
 * work that cannot be retried without risking a duplicate resource stops here
 * and waits for a person, instead of being quietly marked failed and forgotten.
 */
enum ProvisioningJobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case NeedsReview = 'needs_review';
    case Cancelled = 'cancelled';

    /**
     * Whether the engine is finished with this job.
     *
     * `needs_review` counts: no worker will pick it up again without a human
     * deciding what should happen.
     */
    public function isSettled(): bool
    {
        return match ($this) {
            self::Queued, self::Running => false,
            self::Succeeded, self::Failed, self::NeedsReview, self::Cancelled => true,
        };
    }

    /** Whether a worker may claim this job. */
    public function isClaimable(): bool
    {
        return $this === self::Queued;
    }

    /** Whether an operator has to decide something before this job moves. */
    public function needsAttention(): bool
    {
        return $this === self::NeedsReview;
    }
}
