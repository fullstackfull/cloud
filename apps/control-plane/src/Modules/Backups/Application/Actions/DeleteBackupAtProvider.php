<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Application\Actions;

use Lynomia\Modules\Backups\Domain\Contracts\BackupProvider;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupProviderException;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Asks the provider to remove an archive, and only calls it gone once a
 * listing agrees.
 *
 * ---------------------------------------------------------------------------
 * Why acceptance is not confirmation
 * ---------------------------------------------------------------------------
 *
 * `deleteBackup()` returning without throwing means the provider took the
 * instruction. It does not mean the archive is gone: a prune can be queued
 * behind a running verification, a datastore can be read-only, a chunk store
 * can be mid-garbage-collection. A row moved to `Deleted` on the strength of
 * an accepted call tells the customer their data is destroyed — the strongest
 * claim this module makes — on the same evidence that would let it claim a
 * backup succeeded because a job was enqueued, which this module has always
 * refused to do.
 *
 * So the archive is looked for afterwards. Absent from the listing, it is
 * gone; still present, the row stays `Deleting` and is asked again later.
 *
 * ---------------------------------------------------------------------------
 * The Timeout Rule
 * ---------------------------------------------------------------------------
 *
 * A delete that times out is indeterminate, not failed. The provider may have
 * removed the archive, may be removing it, may never have received the
 * request. Re-issuing is *safe* here in a way it is not for a create — removing
 * something twice removes it once — so the row stays `Deleting` and is
 * retried, bounded. What is not safe is calling it either gone or kept, and
 * neither is written.
 *
 * A row that exhausts `backups.deletion_attempts` goes to `NeedsReview`. An
 * archive that will not delete is a datastore filling up, and that is a
 * person's problem rather than a retry's.
 */
final readonly class DeleteBackupAtProvider
{
    public function __construct(
        private BackupProviderFactory $providers,
        private SecretRedactor $redactor,
    ) {}

    public function execute(Backup $backup): Backup
    {
        if (! in_array($backup->state, [BackupState::DeleteRequested, BackupState::Deleting], true)) {
            return $backup;
        }

        $cluster = $backup->cluster()->first();

        if ($cluster === null || $backup->archive_id === null) {
            /*
             * No cluster to ask, or no archive identifier to name. Neither is
             * recoverable by retrying and both leave an archive that may still
             * exist somewhere, which is exactly what needs-review is for.
             */
            $backup->transitionTo(BackupState::NeedsReview, [
                'failure_reason' => $cluster === null
                    ? 'the cluster this backup was taken on no longer exists in the platform'
                    : 'this backup has no archive identifier, so the provider cannot be told what to remove',
            ]);

            return $backup->refresh();
        }

        $provider = $this->providers->for($cluster);

        if ($backup->state === BackupState::DeleteRequested) {
            $backup->transitionTo(BackupState::Deleting, []);
            $backup->refresh();
        }

        $backup->forceFill(['deletion_attempts' => $backup->deletion_attempts + 1])->save();

        try {
            $provider->deleteBackup($backup->node_name, $backup->datastore, (string) $backup->archive_id);
        } catch (BackupProviderException $e) {
            return $this->afterAFailedAttempt($backup, $e);
        }

        return $this->confirmAbsence($backup, $provider);
    }

    /**
     * Looks for the archive, and stamps the deletion only if it is not there.
     */
    private function confirmAbsence(Backup $backup, BackupProvider $provider): Backup
    {
        $machine = $backup->virtualMachine()->first();
        $providerId = $machine?->provider_id;

        if ($providerId === null) {
            /*
             * The machine's provider id is how a datastore listing is scoped,
             * and without it the platform cannot look. A destroyed VM whose
             * backups outlive it is the ordinary case here — the row is left
             * `Deleting` for the inventory reconciler, which lists by
             * datastore rather than by machine.
             */
            return $backup->refresh();
        }

        try {
            $remaining = $provider->listBackups($backup->node_name, $backup->datastore, (string) $providerId);
        } catch (BackupProviderException) {
            // Not knowing is the normal condition of a poller. The row stays
            // where it is and is asked again.
            return $backup->refresh();
        }

        foreach ($remaining as $archive) {
            if ($archive->archiveId === $backup->archive_id) {
                // Still there. Accepted is not deleted.
                return $this->afterAnUnconfirmedAttempt($backup);
            }
        }

        $backup->transitionTo(BackupState::Deleted, ['provider_deleted_at' => now()]);

        return $backup->refresh();
    }

    private function afterAFailedAttempt(Backup $backup, BackupProviderException $e): Backup
    {
        if ($backup->deletion_attempts >= $this->ceiling()) {
            $backup->transitionTo(BackupState::NeedsReview, [
                'failure_reason' => $this->redactor->redactString($e->getMessage()),
            ]);
        }

        return $backup->refresh();
    }

    private function afterAnUnconfirmedAttempt(Backup $backup): Backup
    {
        if ($backup->deletion_attempts >= $this->ceiling()) {
            $backup->transitionTo(BackupState::NeedsReview, [
                'failure_reason' => 'the provider accepted the deletion and the archive is still listed',
            ]);
        }

        return $backup->refresh();
    }

    private function ceiling(): int
    {
        return max(1, (int) config('backups.deletion_attempts', 5));
    }
}
