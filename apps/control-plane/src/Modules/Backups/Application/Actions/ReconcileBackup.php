<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Application\Actions;

use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupProviderException;
use Lynomia\Modules\Backups\Domain\ValueObjects\BackupNotificationKey;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Ask the provider what became of a task, and move the row to match.
 *
 * This is the only thing in the platform that can write `Succeeded`, and it
 * writes it for exactly one reason: a provider task reported OK. Everything
 * else the platform knows — that a job was enqueued, that a request was
 * accepted, that time has passed — is not evidence that a backup exists.
 *
 * ---------------------------------------------------------------------------
 * Giving up is a decision, not a failure
 * ---------------------------------------------------------------------------
 *
 * A task that is still running after `backups.max_poll_hours` is not marked
 * failed. The provider may well still be working; a 400 GiB machine onto a
 * cold datastore takes as long as it takes. What has failed is the platform's
 * ability to keep track, so the row goes to `NeedsReview` — which puts it in
 * front of a person, and is the only outcome that resolves the question of
 * whether the archive is there.
 *
 * A poll that itself times out changes nothing at all. The row is left where
 * it is and asked again later: not knowing what a task is doing is the normal
 * condition of a poller, and turning one unanswered read into a state change
 * would let a slow datastore mark good backups as needing review.
 *
 * ---------------------------------------------------------------------------
 * Which task, and why that was the whole defect
 * ---------------------------------------------------------------------------
 *
 * One row can be waiting on three different things over its life — the backup
 * that created it, a verification that reads it back, a restore that writes it
 * over a machine — and they are three different provider tasks. This used to
 * poll `provider_task_id` for all of them.
 *
 * For a verification that is right, because {@see VerifyStoredArchives}
 * deliberately writes the verification's handle into both `provider_task_id`
 * and `verification_task_id`: the first is "whatever this row is waiting on
 * now", the second is the durable record.
 *
 * A restore cannot do that, and {@see RestoreServiceBackup} says why: writing
 * over `provider_task_id` would erase the identifier of the backup itself —
 * the one thing that finds the archive when a restore goes wrong, which is
 * exactly when it is needed — and `(provider, provider_task_id)` is unique, so
 * the write could be rejected outright. The restore's handle therefore lives
 * in `restore_task_id`, and nothing ever read it.
 *
 * The consequence was not a subtle one. A row in `Restoring` was polled with
 * the identifier of its own creation task, which had finished successfully
 * hours or days earlier, so the first sweep after a restore started read "OK"
 * and wrote `Restored`. A customer was told their machine was back while its
 * disks were still being written — and would have been told the same thing if
 * the restore had failed.
 *
 * ---------------------------------------------------------------------------
 * Telling the customer
 * ---------------------------------------------------------------------------
 *
 * This is the only place that settles a backup or a restore, so it is the only
 * honest place to announce one. Four of the platform's declared notification
 * types — BackupCompleted, BackupFailed, RestoreCompleted, RestoreFailed —
 * were written, translated into both languages, and raised by nothing.
 *
 * They are raised from the transition rather than from a controller or a
 * browser poll, so a customer hears about a backup that finished at 3am. Once,
 * because the key is built from the row and the outcome and never from a
 * clock: this sweep runs every few minutes for the life of the platform, and a
 * `Restored` row that somehow got asked about again must not produce a second
 * message.
 *
 * `NeedsReview` announces nothing. There is no truthful notification type for
 * it in this vocabulary — `FileRestoreNeedsReview` exists, its whole-machine
 * counterpart does not — and reporting "we do not know whether your restore
 * ran" as either completed or failed would be the exact class of lie this
 * module is arranged to prevent. It stays an operator's row until somebody
 * decides what the customer should be told.
 */
final readonly class ReconcileBackup
{
    public function __construct(
        private BackupProviderFactory $providers,
        private SecretRedactor $redactor,
        private NotifyCustomer $notify,
    ) {}

    public function execute(Backup $backup): Backup
    {
        if (! $backup->isAwaitingProvider()) {
            return $backup;
        }

        /*
         * Read before anything moves. Which operation this row was waiting on
         * is what decides where a finished task lands and what the customer is
         * told, and the transition below overwrites it.
         */
        $operation = $backup->state;

        $taskId = $this->taskFor($backup);

        if ($taskId === null) {
            /*
             * A restore that was recorded and whose handle never arrived: the
             * process died between the `Restoring` transition and the write of
             * `restore_task_id`, which are deliberately two saves so that a
             * crash still leaves evidence a restore was started.
             *
             * Left alone rather than quarantined on sight, because a sweep
             * running at that exact moment would otherwise race a request that
             * is about to succeed. The overdue rule is what settles it: after
             * `max_poll_hours` the row goes to a person, which is the only
             * thing that can establish whether a restore is running.
             */
            return $this->giveUpIfOverdue($backup, $operation);
        }

        $cluster = $backup->cluster()->first();

        if ($cluster === null) {
            // The cluster row is gone. Nothing can be asked, and the archive
            // may still exist on a datastore nobody is now looking at.
            return $this->quarantine(
                $backup,
                $operation,
                'the cluster this backup was taken on no longer exists in the platform',
            );
        }

        $provider = $this->providers->for($cluster);

        try {
            $state = $provider->taskState($backup->node_name, $taskId);
        } catch (BackupProviderException $e) {
            if ($e->isIndeterminate()) {
                // Not knowing is the normal condition of a poller. Record that
                // we asked, and ask again later.
                $backup->forceFill(['last_polled_at' => now(), 'poll_count' => $backup->poll_count + 1])->save();

                return $this->giveUpIfOverdue($backup, $operation);
            }

            // The provider answered and said something is wrong with the task
            // itself — most often that it has never heard of it, which after a
            // node reboot means the task is gone and its outcome with it.
            return $this->quarantine($backup, $operation, $this->redactor->redactString($e->getMessage()));
        }

        $backup->forceFill(['last_polled_at' => now(), 'poll_count' => $backup->poll_count + 1])->save();

        if ($state->isRunning()) {
            return $this->giveUpIfOverdue($backup, $operation);
        }

        if ($state->hasFailed()) {
            $backup->transitionTo($this->failureStateFor($backup), [
                'failure_reason' => $this->redactor->redactString(
                    $state->exitStatus ?? 'the provider reported the task as failed',
                ),
                ...$this->completionAttributesFor($backup),
                ...$this->failedVerificationAttributesFor($backup),
            ]);

            return $this->announce($backup->refresh(), $operation);
        }

        $backup->transitionTo($this->successStateFor($backup), [
            ...$this->completionAttributesFor($backup),
            'archive_id' => $state->archiveId ?? $backup->archive_id,
            'size_bytes' => $state->sizeBytes ?? $backup->size_bytes,
            ...$this->verificationAttributesFor($backup),
            ...$this->restoreAttributesFor($backup),
        ]);

        return $this->announce($backup->refresh(), $operation);
    }

    /**
     * The handle of the task this row is actually waiting on.
     *
     * Null only for a restore whose provider call never returned one, which is
     * handled by the caller rather than here: there is no identifier to ask
     * about and guessing at another one is how this went wrong in the first
     * place.
     */
    private function taskFor(Backup $backup): ?string
    {
        if ($backup->state === BackupState::Restoring) {
            $restoreTask = trim((string) $backup->restore_task_id);

            return $restoreTask === '' ? null : $restoreTask;
        }

        /*
         * Everything else reads `provider_task_id`, including a verification:
         * VerifyStoredArchives writes the verification's handle there as well
         * as into its own column, precisely so that this line keeps working.
         */
        return (string) $backup->provider_task_id;
    }

    /**
     * When the archive itself was written, which only the backup decides.
     *
     * This used to be stamped for whatever operation had just finished. The
     * portal shows the column under the heading "Taken", so a verification —
     * which runs against every archive on a sweep — moved a customer's record
     * of when their backup was taken to today, and a restore did the same.
     *
     * A verification's outcome is `verified_at`, a restore's is `restored_at`,
     * and the backup's own is this. Three facts, three columns; the only one
     * that was ever a problem was the one they were all writing to.
     *
     * @return array<string, mixed>
     */
    private function completionAttributesFor(Backup $backup): array
    {
        return in_array($backup->state, [BackupState::Requested, BackupState::Running], true)
            ? ['finished_at' => now()]
            : [];
    }

    /**
     * When the restore actually finished, written only where that is true.
     *
     * The column existed, was cast on the model, had its own migration, and
     * was written by nothing. It is set on exactly one transition — a restore
     * task the provider reported as successful — and never on a pending one, a
     * failed one, an indeterminate one, or on a verification of the source
     * archive.
     *
     * It does not need protecting against being rewritten by a later sweep:
     * `Restored` is not in flight, so `isAwaitingProvider()` is false and this
     * action returns before it asks anything. The timestamp means "when this
     * restore finished", once.
     *
     * @return array<string, mixed>
     */
    private function restoreAttributesFor(Backup $backup): array
    {
        return $backup->state === BackupState::Restoring ? ['restored_at' => now()] : [];
    }

    /**
     * Tell the customer what became of the thing they asked for.
     *
     * Keyed on the row and the outcome, never on a clock, because that key is
     * the whole dedup mechanism: {@see NotifyCustomer} relies on a unique
     * index, and a key containing a timestamp would make every replay unique
     * and defeat it.
     *
     * A restore's key carries the restore task as well, because one backup row
     * can be restored more than once and the second attempt is a different
     * event the customer is owed a word about. A backup row has exactly one
     * creation task, so its outcome needs no such qualifier — and must not
     * borrow `provider_task_id`, which a later verification overwrites.
     */
    private function announce(Backup $backup, BackupState $operation): Backup
    {
        $landed = $backup->state;
        $id = (string) $backup->getKey();

        /*
         * Read as a pair: what the row was doing, and where it ended up. Three
         * operations and three endings each, and no ending is allowed to
         * borrow another operation's words — the defect this module keeps
         * producing is a true sentence about the wrong thing.
         *
         * A verification's endings are the asymmetric ones. Unreadable gets
         * its own message, because "your backup failed" would be false: the
         * backup ran and reported OK, and what is wrong is the data it left.
         * Readable gets none at all — a customer does not need telling that a
         * check they never asked for passed. And a verification that could not
         * be run reaches NeedsReview with `verified` still null, which is not
         * a verdict and must not be announced as one.
         *
         * A backup that reaches NeedsReview says nothing either, and the guard
         * below is load-bearing: without it, routing every quarantine through
         * here would start announcing BackupFailed for a backup nobody can
         * account for, which is a new false statement rather than a fixed one.
         * There is no BackupNeedsReview in this vocabulary to say it properly.
         */
        [$type, $key] = match (true) {
            $operation === BackupState::Restoring => [
                match ($landed) {
                    BackupState::Restored => NotificationType::RestoreCompleted,
                    BackupState::NeedsReview => NotificationType::RestoreNeedsReview,
                    default => NotificationType::RestoreFailed,
                },
                BackupNotificationKey::restore($id, $backup->restore_task_id, match ($landed) {
                    BackupState::Restored => 'restored',
                    BackupState::NeedsReview => 'needs_review',
                    default => 'failed',
                }),
            ],
            $operation === BackupState::Verifying && $backup->verified === false => [
                NotificationType::BackupVerificationFailed,
                BackupNotificationKey::verificationFailed($id),
            ],
            in_array($operation, [BackupState::Requested, BackupState::Running], true)
                && $landed !== BackupState::NeedsReview => [
                    $landed === BackupState::Succeeded
                        ? NotificationType::BackupCompleted
                        : NotificationType::BackupFailed,
                    BackupNotificationKey::backup($id, $landed === BackupState::Succeeded ? 'succeeded' : 'failed'),
                ],
            default => [null, null],
        };

        if ($type === null || $key === null) {
            return $backup;
        }

        /** @var ?Service $service */
        $service = $backup->service()->first();
        $label = $service?->label;

        $this->notify->execute(
            customerId: $backup->customer_id,
            type: $type,
            idempotencyKey: $key,
            subject: $backup,
            data: [
                'service' => is_string($label) && $label !== ''
                    ? $label
                    : (string) ($service?->getKey() ?? $backup->service_id),
            ],
            link: '/backups',
        );

        return $backup;
    }

    /**
     * Where a finished task lands, which depends on what it was doing.
     *
     * A verification task that reports OK makes the backup `Verified`; a
     * restore that reports OK makes it `Restored`. Collapsing all three into
     * `Succeeded` would lose the distinction a customer asked about — "did the
     * restore work?" is not answered by "the backup is fine".
     */
    private function successStateFor(Backup $backup): BackupState
    {
        return match ($backup->state) {
            BackupState::Verifying => BackupState::Verified,
            BackupState::Restoring => BackupState::Restored,
            default => BackupState::Succeeded,
        };
    }

    /**
     * A failed verification does not mean the backup is gone — it means it
     * could not be read, which is worse and must be visible as a failure.
     * A failed restore, on the other hand, leaves the backup itself intact:
     * the row goes back to Succeeded so the customer can try again, and the
     * reason is kept.
     */
    private function failureStateFor(Backup $backup): BackupState
    {
        return $backup->state === BackupState::Restoring
            ? BackupState::Succeeded
            : BackupState::Failed;
    }

    /**
     * The third value of `verified`, written where it belongs.
     *
     * The column carries three answers and they are not interchangeable: null
     * is "nobody has read this back", true is "it was read back", and false is
     * "it could not be". A failed verification used to leave null, which
     * collapsed the worst answer into the neutral one — an archive that was
     * checked and found unreadable looked exactly like one nobody had got
     * around to checking.
     *
     * Only a verification writes this. A failed backup or a failed restore
     * says nothing about whether the stored archive is readable, so neither
     * touches the column.
     *
     * @return array<string, mixed>
     */
    private function failedVerificationAttributesFor(Backup $backup): array
    {
        return $backup->state === BackupState::Verifying
            ? ['verified' => false, 'verified_at' => null]
            : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function verificationAttributesFor(Backup $backup): array
    {
        return $backup->state === BackupState::Verifying
            ? ['verified' => true, 'verified_at' => now()]
            : [];
    }

    /**
     * The row has been in flight longer than the platform is willing to track.
     */
    private function giveUpIfOverdue(Backup $backup, BackupState $operation): Backup
    {
        $limit = max(1, (int) config('backups.max_poll_hours', 12));

        $startedAt = $backup->started_at ?? $backup->created_at;

        if ($startedAt->addHours($limit)->isFuture()) {
            return $backup;
        }

        return $this->quarantine($backup, $operation, sprintf(
            'the provider task was still unfinished after %d hours; the platform has stopped tracking it',
            $limit,
        ));
    }

    /**
     * Hand the row to a person, and say so where it is somebody's problem.
     *
     * Every route to `NeedsReview` in this class goes through here, which is
     * the point: a restore reaching it is the most dangerous outcome the
     * module produces — the provider may be writing to the customer's disks
     * right now — and it used to be the quietest. A customer told nothing has
     * no reason not to press restore again.
     */
    private function quarantine(Backup $backup, BackupState $operation, string $reason): Backup
    {
        $backup->transitionTo(BackupState::NeedsReview, ['failure_reason' => $reason]);

        return $this->announce($backup->refresh(), $operation);
    }
}
