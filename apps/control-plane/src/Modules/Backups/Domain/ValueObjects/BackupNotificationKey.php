<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\ValueObjects;

/**
 * The key that decides whether a customer has already been told this.
 *
 * `NotifyCustomer` deduplicates on a unique index, so the key is the whole
 * mechanism: build it from a clock and every retry is a new message, build it
 * too coarsely and a real second event is swallowed. It lives here rather than
 * in the actions because three of them now raise these messages — the
 * reconciler, the restore request, and the inventory sweep — and a format
 * agreed by copy-and-paste is a format that drifts.
 *
 * Scalars in and a string out, so this stays in Domain with no Eloquent behind
 * it.
 */
final readonly class BackupNotificationKey
{
    /**
     * One restore attempt's outcome.
     *
     * Scoped to the attempt and not only to the backup row, because a
     * customer can restore the same backup again next month and that is a
     * second event they are owed a word about.
     *
     * The attempt is its provider task and when it started. The start is the
     * part every attempt has — `restore_started_at`, written by the transition
     * that begins it, fixed for the life of the attempt and not a clock read
     * at the moment of sending — so two attempts never share a key even when
     * one has no handle (its call never answered) or a provider hands out a
     * handle it has used before. The task alone used to be the scope, and a
     * second restore polled on the first one's stale handle produced the first
     * one's key, so its message was swallowed as a duplicate (F-09).
     */
    public static function restore(
        string $backupId,
        ?string $restoreTaskId,
        string $outcome,
        ?string $attemptStartedAt = null,
    ): string {
        $task = trim((string) $restoreTaskId);

        if ($task === '') {
            $task = 'no-task';
        }

        if ($attemptStartedAt !== null) {
            $task .= '@'.$attemptStartedAt;
        }

        return sprintf('restore:%s:%s:%s', $backupId, $task, $outcome);
    }

    /**
     * A backup's own outcome. One row, one creation task, one of these.
     *
     * Deliberately not scoped to `provider_task_id`. A verification used to
     * overwrite that column, so rows exist whose value is not the backup's
     * own task, and a key built from it would not be stable for them.
     */
    public static function backup(string $backupId, string $outcome): string
    {
        return sprintf('backup:%s:%s', $backupId, $outcome);
    }

    /**
     * The platform cannot account for this backup at all.
     *
     * Scoped to the row and nothing else, and that is the canonical identity
     * rather than a convenient one. Two facts from the model make it so: a
     * backup that went to review is never settled out of it
     * (`BackupState::afterReview()` has no answer for one), and every backup
     * run creates its own row, so one row reaches this outcome at most once in
     * its life.
     *
     * Scoping it to the provider task would be weaker, not stronger. A
     * verification used to overwrite `provider_task_id`, so it is not stable
     * on older rows — and the route that matters most here, a start call that
     * never answered, has no task id at all. That is what indeterminate
     * means.
     */
    public static function needsReview(string $backupId): string
    {
        return self::backup($backupId, 'needs_review');
    }

    /**
     * A verification of this archive the platform lost track of.
     *
     * Not the backup's own needs-review key: the backup succeeded, and a
     * verification that went to review can be settled by a person and a later
     * one lost again. Scoped to the verification task, which every
     * verification that reaches the poller has.
     */
    public static function verificationNeedsReview(string $backupId, ?string $verificationTaskId): string
    {
        $task = trim((string) $verificationTaskId);

        return self::backup($backupId, 'verification_needs_review:'.($task === '' ? 'no-task' : $task));
    }

    /**
     * The archive was read back and did not come back.
     *
     * Scoped to the row alone, and that is the only identity the two writers
     * share: a verification task has a handle, and a datastore that verifies on
     * its own schedule reports its verdict in a listing with no task anywhere.
     * Keying on either would let the same bad news arrive twice by two routes.
     */
    public static function verificationFailed(string $backupId): string
    {
        return self::backup($backupId, 'verification_failed');
    }
}
