<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Enums;

use Lynomia\Modules\Shared\Domain\Enums\CustomerOperationState;

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

    /**
     * The same state, in the one vocabulary the customer surface speaks.
     *
     * Written here, once, because the portal used to be told three different
     * things about one job: this enum raw from the power and reinstall
     * receipts, a `scheduled`/`in_progress`/`completed`/`under_review` set
     * from the service event list, and the canonical seven words from the
     * operation endpoint. Three vocabularies for one concept is three chances
     * for a screen to render a word it has no translation for, and it is how a
     * customer reads "completed" in one place and "succeeded" in another about
     * the same reboot.
     *
     * No default arm, and that is the safety property: a status added to the
     * engine stops the build here rather than reaching a customer as whichever
     * of the seven words happened to be first.
     *
     * There is no mapping onto `indeterminate`. This engine always knows
     * whether it ran its own work — a job that lost contact with a provider is
     * parked as `needs_review` by the worker rather than guessed at — so the
     * unknown-result state belongs to the operations that genuinely have one,
     * which are the ones that call out to a registry or a controller.
     */
    public function customerState(): CustomerOperationState
    {
        return match ($this) {
            self::Queued => CustomerOperationState::Queued,
            self::Running => CustomerOperationState::Processing,
            self::Succeeded => CustomerOperationState::Succeeded,
            self::Failed => CustomerOperationState::Failed,
            self::NeedsReview => CustomerOperationState::NeedsReview,
            self::Cancelled => CustomerOperationState::Cancelled,
        };
    }
}
