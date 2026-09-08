<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\Enums;

/**
 * Where one attempt at a registry has got to.
 *
 * The distinction this enum exists for is `Failed` against `Indeterminate`,
 * and it is the same one the compute and DNS modules draw. Failed means the
 * registrar answered and said no — the name was taken, the contact set was
 * rejected, the term was not permitted — and the customer's money can go back.
 * Indeterminate means the registrar did not answer, which is not the same
 * thing at all: it may hold the name, it may have charged for it, and the only
 * safe next move is to go and look rather than to ask again.
 */
enum DomainOperationState: string
{
    /** Written down, money not yet settled. */
    case Requested = 'requested';

    /** Paid for and handed to the queue. */
    case Queued = 'queued';

    /** A worker is talking to the registrar right now. */
    case Running = 'running';

    /**
     * The registrar accepted it and has not finished.
     *
     * Transfers live here for days. So can a registration at a registry that
     * queues rather than answers. It is not a failure and it is not a success;
     * it is the operation the poller is watching.
     */
    case AwaitingRegistry = 'awaiting_registry';

    case Completed = 'completed';

    /** The registrar answered and refused. Recoverable by the customer. */
    case Failed = 'failed';

    /** Nobody knows. Never retried automatically. */
    case Indeterminate = 'indeterminate';

    /** A person has to decide, and the reason is on the row. */
    case NeedsReview = 'needs_review';

    /** Whether this attempt is still going somewhere on its own. */
    public function isInFlight(): bool
    {
        return match ($this) {
            self::Requested, self::Queued, self::Running, self::AwaitingRegistry => true,
            default => false,
        };
    }

    public function isFinished(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }

    public function needsAttention(): bool
    {
        return $this === self::Indeterminate || $this === self::NeedsReview;
    }

    /**
     * Whether the platform may spend the customer's money on this name again.
     *
     * Only after a refusal. An indeterminate attempt may already have bought
     * the name, and a second purchase is a second year nobody asked for.
     */
    public function permitsAnotherAttempt(): bool
    {
        return $this === self::Failed;
    }
}
