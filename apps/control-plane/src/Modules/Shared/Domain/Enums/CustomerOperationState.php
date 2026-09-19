<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Domain\Enums;

/**
 * What a customer is told about a piece of work the platform is doing.
 *
 * Seven words, and every asynchronous thing in the portal maps onto exactly
 * one of them. The point is not tidiness: each product's engine has its own
 * execution states — a provisioning job knows `running`, a domain operation
 * knows `awaiting_registry`, a backup knows `restoring` — and a client that
 * received those directly would have to know all of them, would grow a branch
 * every time one was added, and would eventually guess wrong about a state it
 * had never seen.
 *
 * The three that must never be collapsed, and the reason each exists:
 *
 *  - `NeedsReview` is not `Failed`. The work stopped and a person at Lynomia
 *    has to look at it. Telling a customer it failed invites them to do it
 *    again, on a machine that may be half-built.
 *
 *  - `Indeterminate` is not `Failed` and is not `Succeeded`. The platform
 *    asked something outside itself — a registrar, a controller — and never
 *    heard back. The registration may have happened. The reboot may have
 *    happened. Presenting either guess as fact is how a customer buys a name
 *    twice or pulls the plug on a machine that already stopped.
 *
 *  - `Cancelled` is not `Failed`. Nothing went wrong; somebody changed
 *    their mind.
 *
 * This is the Timeout Rule as a type. Every mapping into this enum is written
 * by hand, in the module that owns the source state, precisely so that no
 * default case can quietly turn "we do not know" into "it worked".
 */
enum CustomerOperationState: string
{
    /** Accepted and waiting its turn. Nothing has been attempted yet. */
    case Queued = 'queued';

    /** Being worked on now. */
    case Processing = 'processing';

    /** Finished, and it did what was asked. */
    case Succeeded = 'succeeded';

    /** Finished, and it did not. The reason is a customer-safe code. */
    case Failed = 'failed';

    /** Stopped, and waiting on a person at Lynomia rather than on the customer. */
    case NeedsReview = 'needs_review';

    /** The outside world never answered. The result is genuinely unknown. */
    case Indeterminate = 'indeterminate';

    /** Called off before it ran. */
    case Cancelled = 'cancelled';

    /**
     * Whether the platform expects this to change on its own.
     *
     * The client polls exactly while this is false, which is why the method
     * lives on the enum rather than in a list of strings in the browser: a
     * state added here without an answer will not compile.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Queued, self::Processing => false,
            self::Succeeded, self::Failed, self::NeedsReview, self::Indeterminate, self::Cancelled => true,
        };
    }

    /**
     * Whether this state is waiting on somebody rather than on the platform.
     *
     * Drives the attention list and the "ask support about this" path. A
     * failure a customer can act on themselves is not the same as work nobody
     * is looking at.
     */
    public function needsAttention(): bool
    {
        return match ($this) {
            self::Failed, self::NeedsReview, self::Indeterminate => true,
            self::Queued, self::Processing, self::Succeeded, self::Cancelled => false,
        };
    }

    /**
     * What the customer may safely do next.
     *
     * The server decides this, not the screen. A button that offers a retry is
     * only ever drawn from `SafeToRetry` — so an indeterminate registrar
     * operation cannot grow a retry button by somebody adding one to a
     * component, because the contract never says it may.
     */
    public function retryAdvice(): RetryAdvice
    {
        return match ($this) {
            // Still ours to finish. Asking again would only queue a duplicate.
            self::Queued, self::Processing => RetryAdvice::Wait,

            // Nothing to retry.
            self::Succeeded => RetryAdvice::NotRetryable,

            // A clean failure: the platform knows it did not happen, so doing
            // it again is safe.
            self::Failed => RetryAdvice::SafeToRetry,

            // Somebody is already looking. A second attempt lands on top of
            // whatever they are in the middle of.
            self::NeedsReview => RetryAdvice::SupportRequired,

            /*
             * The whole reason this enum exists. The action may have taken
             * effect. Repeating it is the one thing a customer must not do,
             * and support is the way out.
             */
            self::Indeterminate => RetryAdvice::SupportRequired,

            // The customer stopped it; they can start it again.
            self::Cancelled => RetryAdvice::SafeToRetry,
        };
    }
}
