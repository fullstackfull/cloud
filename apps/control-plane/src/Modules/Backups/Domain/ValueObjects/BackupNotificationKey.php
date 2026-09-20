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
     * Scoped to the provider task and not only to the backup row, because a
     * customer can restore the same backup again next month and that is a
     * second event they are owed a word about.
     *
     * A restore whose provider call never returned a handle has none to scope
     * to, and needs none: it lands in `NeedsReview`, which nothing transitions
     * out of, so that row can produce this message at most once ever.
     */
    public static function restore(string $backupId, ?string $restoreTaskId, string $outcome): string
    {
        $task = trim((string) $restoreTaskId);

        return sprintf('restore:%s:%s:%s', $backupId, $task === '' ? 'no-task' : $task, $outcome);
    }

    /**
     * A backup's own outcome. One row, one creation task, one of these.
     *
     * Deliberately not scoped to `provider_task_id`: a verification overwrites
     * that column, so a key built from it would not be stable for the life of
     * the row.
     */
    public static function backup(string $backupId, string $outcome): string
    {
        return sprintf('backup:%s:%s', $backupId, $outcome);
    }

    /**
     * The platform cannot account for this backup at all.
     *
     * Scoped to the row and nothing else, and that is the canonical identity
     * rather than a convenient one. Two facts from the model make it so:
     * `NeedsReview` is terminal, and every backup run creates its own row, so
     * one row reaches this outcome at most once in its life.
     *
     * Scoping it to the provider task would be weaker, not stronger. A
     * verification overwrites `provider_task_id`, so it is not stable for the
     * life of the row — and the route that matters most here, a start call
     * that never answered, has no task id at all. That is what indeterminate
     * means.
     */
    public static function needsReview(string $backupId): string
    {
        return self::backup($backupId, 'needs_review');
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
