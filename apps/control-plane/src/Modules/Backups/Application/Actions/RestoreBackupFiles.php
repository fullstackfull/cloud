<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Application\Actions;

use Lynomia\Modules\Backups\Domain\Contracts\FileLevelBackupProvider;
use Lynomia\Modules\Backups\Domain\Enums\BackupFileKind;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Enums\FileRestoreState;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupFileRefusedException;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupProviderException;
use Lynomia\Modules\Backups\Domain\ValueObjects\BackupPath;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Backups\Infrastructure\Models\BackupFileRestore;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Put named files back into the machine they were backed up from.
 *
 * Less than a whole-machine restore and not less dangerous for the files
 * it names: what the machine holds at those paths is replaced. So it clears
 * the same bar — the hostname typed back, the backup complete and from this
 * machine, the service active, nothing else restoring into the machine —
 * and adds one of its own: every path is looked up in the archive first,
 * and a symlink is refused by name. A restore that followed a link would
 * write wherever the guest had pointed it.
 *
 * The Timeout Rule as everywhere: a provider that does not answer may be
 * restoring right now, so the row goes to needs_review and is never retried.
 */
final readonly class RestoreBackupFiles
{
    public function __construct(
        private FileLevelSupport $support,
        private SecretRedactor $redactor,
    ) {}

    /**
     * @param  list<BackupPath>  $paths
     *
     * @throws BackupFileRefusedException
     */
    public function execute(
        Backup $backup,
        VirtualMachine $machine,
        array $paths,
        string $confirmation,
        ?string $userId = null,
    ): BackupFileRestore {
        if (! hash_equals($machine->hostname, $confirmation)) {
            throw BackupFileRefusedException::confirmationMismatch();
        }

        $max = max(1, (int) config('backups.file_restore_max_paths', 50));

        if ($paths === [] || count($paths) > $max) {
            throw BackupFileRefusedException::tooManyPaths($max);
        }

        $this->assertRestorable($backup, $machine);

        $provider = $this->support->provider($backup);

        $paths = $this->unique($paths);
        $this->assertEachIsAFileOrDirectory($backup, $provider, $paths);

        $restore = BackupFileRestore::query()->create([
            'backup_id' => $backup->getKey(),
            'customer_id' => $backup->customer_id,
            'service_id' => $backup->service_id,
            'virtual_machine_id' => $machine->getKey(),
            'state' => FileRestoreState::Requested,
            'node_name' => $backup->node_name,
            'paths' => array_map(static fn (BackupPath $p): string => $p->value, $paths),
            'path_count' => count($paths),
            'requested_by_user_id' => $userId,
            'started_at' => now(),
        ]);

        try {
            $operation = $provider->startFileRestore(
                $backup->node_name,
                (string) $machine->provider_id,
                $backup->datastore,
                (string) $backup->archive_id,
                $paths,
            );
        } catch (BackupProviderException $e) {
            $restore->forceFill([
                'state' => $e->isIndeterminate() ? FileRestoreState::NeedsReview : FileRestoreState::Failed,
                'failure_reason' => $this->redactor->redactString($e->getMessage()),
                'finished_at' => $e->isIndeterminate() ? null : now(),
            ])->save();

            return $restore->refresh();
        }

        $restore->forceFill([
            'state' => FileRestoreState::Running,
            'provider_task_id' => $operation->taskId,
        ])->save();

        return $restore->refresh();
    }

    /**
     * @throws BackupFileRefusedException
     */
    private function assertRestorable(Backup $backup, VirtualMachine $machine): void
    {
        if ($backup->virtual_machine_id !== null
            && (string) $backup->virtual_machine_id !== (string) $machine->getKey()) {
            throw BackupFileRefusedException::notAvailable((string) $backup->getKey(), 'it was taken from a different machine');
        }

        $service = $machine->service()->first();

        if ($service === null || $service->status !== ServiceStatus::Active) {
            throw BackupFileRefusedException::notAvailable((string) $backup->getKey(), 'the service is not active');
        }

        $wholeMachine = Backup::query()
            ->where('virtual_machine_id', $machine->getKey())
            ->where('state', BackupState::Restoring->value)
            ->exists();

        // In flight, or in review: a restore nobody has confirmed the end of
        // may still be writing, and a second over it cannot be reasoned
        // about afterwards. A person settles the first (see the runbook).
        $files = BackupFileRestore::query()
            ->where('virtual_machine_id', $machine->getKey())
            ->whereIn('state', [FileRestoreState::Requested->value, FileRestoreState::Running->value, FileRestoreState::NeedsReview->value])
            ->exists();

        if ($wholeMachine || $files) {
            throw BackupFileRefusedException::alreadyRestoring((string) $machine->getKey());
        }
    }

    /**
     * @param  list<BackupPath>  $paths
     *
     * @throws BackupFileRefusedException
     */
    private function assertEachIsAFileOrDirectory(Backup $backup, FileLevelBackupProvider $provider, array $paths): void
    {
        /** @var array<string, array<string, BackupFileKind>> $listings parent path => name => kind */
        $listings = [];

        foreach ($paths as $path) {
            if ($path->isRoot()) {
                // The whole archive is a whole-machine restore, which has its
                // own confirmation and its own consequences.
                throw BackupFileRefusedException::badPath('the root of the archive is restored as the whole machine');
            }

            $parent = $path->parent()->value;

            if (! array_key_exists($parent, $listings)) {
                $listing = $provider->listFiles($backup->node_name, $backup->datastore, (string) $backup->archive_id, $path->parent());
                $listings[$parent] = [];

                foreach ($listing->entries as $entry) {
                    $listings[$parent][$entry->path->value] = $entry->kind;
                }
            }

            $kind = $listings[$parent][$path->value] ?? null;

            if ($kind === null) {
                throw BackupFileRefusedException::notFound($path->value);
            }

            if ($kind === BackupFileKind::Symlink) {
                throw BackupFileRefusedException::symlink($path->value);
            }

            if (! $kind->isRestorable()) {
                throw BackupFileRefusedException::notAFile($path->value);
            }
        }
    }

    /**
     * @param  list<BackupPath>  $paths
     * @return list<BackupPath>
     */
    private function unique(array $paths): array
    {
        $seen = [];

        foreach ($paths as $path) {
            $seen[$path->value] = $path;
        }

        return array_values($seen);
    }
}
