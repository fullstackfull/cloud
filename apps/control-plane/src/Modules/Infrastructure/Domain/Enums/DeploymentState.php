<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Enums;

/**
 * A deployment's progress through plan, approval, apply and verify.
 *
 * The vocabulary is borrowed from the platform's provisioning jobs rather than
 * reinvented, including the two states that matter most:
 *
 *   Indeterminate — we asked and never learned the answer. Under the Timeout
 *   Rule this is never retried automatically, on a machine as on a provider.
 *
 *   NeedsReview — a person has to decide something before this moves.
 *
 * Approved is separate from Queued because approving is a decision and queuing
 * is a consequence, and collapsing them loses the moment somebody could have
 * said no.
 */
enum DeploymentState: string
{
    case Requested = 'requested';
    case Preflight = 'preflight';
    case Planning = 'planning';
    case AwaitingApproval = 'awaiting_approval';
    case Queued = 'queued';
    case Applying = 'applying';
    case Verifying = 'verifying';
    case Completed = 'completed';
    case Failed = 'failed';
    case Indeterminate = 'indeterminate';
    case NeedsReview = 'needs_review';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    /** Waiting on a person rather than on a machine. */
    public function waitsForSomebody(): bool
    {
        return $this === self::AwaitingApproval
            || $this === self::NeedsReview
            || $this === self::Indeterminate;
    }

    /**
     * May a deployment in this state be started?
     *
     * Only Queued. Applying is deliberately excluded so that a redelivered
     * message cannot begin a second run against the same machine — the same
     * guard the domain module uses for a redelivered registration.
     */
    public function mayBeStarted(): bool
    {
        return $this === self::Queued;
    }
}
