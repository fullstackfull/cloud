<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\Enums;

/**
 * Where one backup actually is.
 *
 * The whole point of this enum is that none of these states can be reached by
 * hoping. A row is `Requested` because a row was written; `Running` because a
 * provider returned a task identifier; `Succeeded` because that task reported
 * OK; `Verified` because a verification task reported OK. A queue job being
 * accepted moves nothing — a customer told their backup succeeded, on the
 * strength of a job that was merely enqueued, finds out otherwise on the day
 * they need it.
 *
 * The three deletion states are late additions and they exist for one reason:
 * a backup is not gone because the platform asked for it to go.
 * `DeleteRequested` is somebody's decision, `Deleting` is a request the
 * provider has accepted, and `Deleted` is a subsequent listing of the
 * datastore that no longer contains the archive. Collapsing them would let the
 * platform tell a customer their data is destroyed while it is still sitting
 * on a datastore they are being billed for — or, worse, tell them it is still
 * there when it is not.
 *
 * There is no `Indeterminate` here, and that is a convention rather than an
 * omission: a deletion whose outcome cannot be established goes to
 * `NeedsReview`, which is what this module has always called that situation
 * and is the state an operator's screen already knows how to show.
 *
 * `NeedsReview` is not decoration. When a vzdump
 * request times out there is no task identifier to poll and no way to know
 * whether the provider started one: the backup may exist and be occupying
 * datastore space, or may not exist at all. Recording that as `Failed` invites
 * a retry that runs a second backup of the same machine, and recording it as
 * `Running` invites a poller to chase a task id that was never issued. It is
 * its own state so an operator can go and look, which is the only thing that
 * actually resolves it.
 */
enum BackupState: string
{
    case Requested = 'requested';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Verifying = 'verifying';
    case Verified = 'verified';
    case Restoring = 'restoring';
    case Restored = 'restored';
    case NeedsReview = 'needs_review';

    /** Somebody has asked for this archive to go; nothing has been sent yet. */
    case DeleteRequested = 'delete_requested';

    /** The provider has been asked and has not yet been seen to have done it. */
    case Deleting = 'deleting';

    /** A listing of the datastore no longer contains the archive. */
    case Deleted = 'deleted';

    /**
     * Whether the platform is waiting on the provider for this row.
     */
    public function isInFlight(): bool
    {
        return in_array($this, [self::Requested, self::Running, self::Verifying, self::Restoring], true);
    }

    /**
     * Whether a restore could be attempted from this backup.
     *
     * Verified is the honest bar and Succeeded is the practical one. A backup
     * that completed but has never been verified may still be unreadable — a
     * deduplicating datastore shares chunks between backups, so one corrupt
     * chunk can be shared by many — and the platform says so rather than
     * refusing, because a customer facing a lost machine would rather try an
     * unverified backup than be told no.
     */
    /**
     * Whether the archive is there and usable.
     *
     * What a customer means by "I have a backup", and what the retention
     * counter counts. A backup on its way out is not one of them: it is still
     * occupying space, but nobody should be told they can restore from it.
     */
    public function isAvailable(): bool
    {
        return in_array($this, [self::Succeeded, self::Verifying, self::Verified, self::Restored], true);
    }

    /** Whether this row is somewhere on the way to being removed. */
    public function isBeingDeleted(): bool
    {
        return in_array($this, [self::DeleteRequested, self::Deleting], true);
    }

    public function isRestorable(): bool
    {
        return in_array($this, [self::Succeeded, self::Verified, self::Restored], true);
    }

    /**
     * Whether this row is finished and nothing further will happen to it on
     * its own.
     */
    public function isSettled(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Verified, self::Restored, self::Deleted], true);
    }

    /**
     * Whether a person has to look at this.
     */
    public function needsAttention(): bool
    {
        return $this === self::NeedsReview;
    }

    /**
     * The states this one may become.
     *
     * Written as data rather than as scattered ifs so that the whole lifecycle
     * can be read in one place, and so that an illegal transition is a refusal
     * rather than a row that quietly went backwards.
     *
     * `Verified` back to `Verifying` is allowed: verification is re-run on a
     * schedule, and a datastore that reports a previously good backup as
     * corrupt is exactly the signal worth having.
     *
     * Nothing transitions out of `Failed`. A failed backup is not repaired; a
     * new one is taken, as a new row, so the failure stays visible in history.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Requested => [self::Running, self::Succeeded, self::Failed, self::NeedsReview],
            self::Running => [self::Succeeded, self::Failed, self::NeedsReview],
            self::Succeeded => [self::Verifying, self::Restoring, self::Failed, self::DeleteRequested],
            self::Verifying => [self::Verified, self::Failed, self::NeedsReview],
            self::Verified => [self::Verifying, self::Restoring, self::DeleteRequested],
            self::Restoring => [self::Restored, self::Succeeded, self::NeedsReview],
            self::Restored => [self::Verifying, self::Restoring, self::DeleteRequested],

            /*
             * A requested deletion can still be called off — a customer
             * changing their mind before the sweep picks it up returns the row
             * to being a backup. Once the provider has been asked there is no
             * way back: `Deleting` leads only to gone, or to a person.
             */
            self::DeleteRequested => [self::Deleting, self::Succeeded, self::NeedsReview],
            self::Deleting => [self::Deleted, self::NeedsReview],

            /*
             * Nothing leaves Deleted. The archive is not there; a customer who
             * wants one again takes a new backup, as a new row, so that the
             * deletion stays visible in the history.
             */
            self::Deleted => [],

            self::Failed, self::NeedsReview => [],
        };
    }

    public function canBecome(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}
