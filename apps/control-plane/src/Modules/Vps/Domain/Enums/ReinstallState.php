<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Domain\Enums;

use Lynomia\Modules\Vps\Infrastructure\Models\VmReinstall;

/**
 * Where a reinstall has got to.
 *
 * ---------------------------------------------------------------------------
 * Why this is not the provisioning job's status
 * ---------------------------------------------------------------------------
 *
 * The engine knows four things about any job: queued, running, succeeded,
 * failed — plus needs_review. That vocabulary is right for the engine and
 * useless to the person whose server is being rebuilt, because every minute of
 * a reinstall is "running" and the difference between the minutes is the whole
 * question. Has the disk been destroyed yet? Is the machine being configured,
 * or is the platform waiting to see whether it came back?
 *
 * The distinction is not cosmetic. It decides what an operator may do: a
 * reinstall that failed in `preparing` destroyed nothing and can simply be
 * asked for again, and one that failed in `configuring` has already replaced
 * the disk. Collapsing both into "failed" is how somebody restores from a
 * backup they did not need to.
 *
 * ---------------------------------------------------------------------------
 * The three ways it can end badly, kept separate
 * ---------------------------------------------------------------------------
 *
 *  - **failed** — the platform knows what happened and knows it is over.
 *  - **needs_review** — the platform knows what happened and a person has to
 *    decide, because the machine is in a state no automatic step should act
 *    on.
 *  - **indeterminate** — the platform stopped waiting and does not know what
 *    the hypervisor did. Never retried automatically: a second reinstall
 *    against a machine that may be mid-rebuild destroys whatever the first one
 *    had laid down.
 */
enum ReinstallState: string
{
    /** Accepted, confirmed, and recorded. Nothing has been asked of anyone. */
    case Requested = 'requested';

    /** Handed to the queue. Still nothing at the hypervisor. */
    case Queued = 'queued';

    /** Gathering what must survive: address, hostname, shape, image. */
    case Preparing = 'preparing';

    /** The disk is being replaced. Past this line the old data is gone. */
    case Reinstalling = 'reinstalling';

    /** Disk laid down; identity, network and keys being written back. */
    case Configuring = 'configuring';

    /** Asking the hypervisor whether what came back is what was asked for. */
    case Verifying = 'verifying';

    case Completed = 'completed';

    case Failed = 'failed';

    case NeedsReview = 'needs_review';

    case Indeterminate = 'indeterminate';

    /**
     * Whether being in this state, by itself, means the disk is already gone.
     *
     * The question an operator asks first, and the reason the states before
     * the destructive call are enumerated separately rather than lumped into
     * "running".
     *
     * `failed` deliberately answers false, and it is the interesting case: a
     * reinstall can fail before the disk was touched — no image staged, no
     * address to give back — and it can fail after. The state alone cannot
     * tell those apart, so it does not pretend to. The operation record can,
     * because it stamps the moment it entered a destructive state, and
     * {@see VmReinstall::destroyedData()}
     * is what callers should ask.
     */
    public function impliesDestroyedData(): bool
    {
        return match ($this) {
            self::Requested, self::Queued, self::Preparing, self::Failed => false,
            // Indeterminate counts as destroyed: the platform does not know,
            // and the safe assumption about a disk it may have replaced is
            // that it did.
            self::Reinstalling, self::Configuring, self::Verifying,
            self::Completed, self::NeedsReview, self::Indeterminate => true,
        };
    }

    /**
     * Whether this operation is over, whatever its outcome.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::NeedsReview, self::Indeterminate => true,
            default => false,
        };
    }

    /**
     * Whether a person has to look before anything else touches this machine.
     */
    public function needsAttention(): bool
    {
        return $this === self::NeedsReview || $this === self::Indeterminate;
    }

    /**
     * Whether the machine is expected to be usable while in this state.
     *
     * Only used to decide what the portal says. A machine mid-reinstall is not
     * broken, but telling a customer it is running while its disk is being
     * replaced is worse than saying nothing.
     */
    public function isInFlight(): bool
    {
        return ! $this->isTerminal();
    }
}
