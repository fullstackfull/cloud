<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Application\Actions;

use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupProviderException;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
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
 */
final readonly class ReconcileBackup
{
    public function __construct(
        private BackupProviderFactory $providers,
        private SecretRedactor $redactor,
    ) {}

    public function execute(Backup $backup): Backup
    {
        if (! $backup->isAwaitingProvider()) {
            return $backup;
        }

        $cluster = $backup->cluster()->first();

        if ($cluster === null) {
            // The cluster row is gone. Nothing can be asked, and the archive
            // may still exist on a datastore nobody is now looking at.
            $backup->transitionTo(BackupState::NeedsReview, [
                'failure_reason' => 'the cluster this backup was taken on no longer exists in the platform',
            ]);

            return $backup->refresh();
        }

        $provider = $this->providers->for($cluster);

        try {
            $state = $provider->taskState($backup->node_name, (string) $backup->provider_task_id);
        } catch (BackupProviderException $e) {
            if ($e->isIndeterminate()) {
                // Not knowing is the normal condition of a poller. Record that
                // we asked, and ask again later.
                $backup->forceFill(['last_polled_at' => now(), 'poll_count' => $backup->poll_count + 1])->save();

                return $this->giveUpIfOverdue($backup);
            }

            // The provider answered and said something is wrong with the task
            // itself — most often that it has never heard of it, which after a
            // node reboot means the task is gone and its outcome with it.
            $backup->transitionTo(BackupState::NeedsReview, [
                'failure_reason' => $this->redactor->redactString($e->getMessage()),
            ]);

            return $backup->refresh();
        }

        $backup->forceFill(['last_polled_at' => now(), 'poll_count' => $backup->poll_count + 1])->save();

        if ($state->isRunning()) {
            return $this->giveUpIfOverdue($backup);
        }

        if ($state->hasFailed()) {
            $backup->transitionTo($this->failureStateFor($backup), [
                'failure_reason' => $this->redactor->redactString(
                    $state->exitStatus ?? 'the provider reported the task as failed',
                ),
                'finished_at' => now(),
            ]);

            return $backup->refresh();
        }

        $backup->transitionTo($this->successStateFor($backup), [
            'finished_at' => now(),
            'archive_id' => $state->archiveId ?? $backup->archive_id,
            'size_bytes' => $state->sizeBytes ?? $backup->size_bytes,
            ...$this->verificationAttributesFor($backup),
        ]);

        return $backup->refresh();
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
    private function giveUpIfOverdue(Backup $backup): Backup
    {
        $limit = max(1, (int) config('backups.max_poll_hours', 12));

        $startedAt = $backup->started_at ?? $backup->created_at;

        if ($startedAt->addHours($limit)->isFuture()) {
            return $backup;
        }

        $backup->transitionTo(BackupState::NeedsReview, [
            'failure_reason' => sprintf(
                'the provider task was still unfinished after %d hours; the platform has stopped tracking it',
                $limit,
            ),
        ]);

        return $backup->refresh();
    }
}
