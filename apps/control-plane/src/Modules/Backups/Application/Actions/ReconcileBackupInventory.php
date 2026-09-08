<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Application\Actions;

use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupProviderException;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Application\Actions\RecordDrift;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftSeverity;

/**
 * Compares what the platform believes about a datastore with what is on it.
 *
 * This is what `BackupProvider::listBackups()` is for, and until now nothing
 * called it — so the platform's belief that a customer had four restorable
 * backups rested entirely on its own record of having taken them.
 *
 * Four disagreements, and each is a different kind of bad day:
 *
 *  - **Missing at the provider.** The platform says a backup exists and the
 *    datastore does not have it. Critical: a customer will find out when they
 *    try to restore, which is the worst possible moment.
 *  - **A deletion that completed after all.** A row still marked `Deleting`
 *    whose archive is no longer listed. Not drift — the answer the platform
 *    was waiting for — so the row is settled rather than reported.
 *  - **Still there after being deleted.** A row marked `Deleted` whose archive
 *    is back, or never went. Billing-relevant: it is occupying space nobody is
 *    accounting for.
 *  - **Orphan at the provider.** An archive the platform never took. Recorded
 *    and never adopted: an archive whose provenance nobody knows must not be
 *    offered to a customer as their backup, and must certainly not be deleted
 *    by a sweep that does not know what it is.
 *
 * Read-only towards the provider. Nothing here deletes anything.
 */
final readonly class ReconcileBackupInventory
{
    private const string RESOURCE = 'backup';

    public function __construct(
        private BackupProviderFactory $providers,
        private RecordDrift $drift,
    ) {}

    /**
     * @return array{checked: int, drifts: int, settled: int}
     */
    public function execute(): array
    {
        $checked = 0;
        $drifts = 0;
        $settled = 0;

        foreach ($this->machinesWithBackups() as $machine) {
            $cluster = $machine->cluster()->first();

            if ($cluster === null || $machine->provider_id === null) {
                continue;
            }

            /** @var list<Backup> $rows */
            $rows = Backup::query()
                ->where('virtual_machine_id', $machine->getKey())
                ->whereNotNull('archive_id')
                ->get()
                ->all();

            if ($rows === []) {
                continue;
            }

            $datastores = array_unique(array_map(static fn (Backup $b): string => $b->datastore, $rows));

            foreach ($datastores as $datastore) {
                $nodeName = $rows[0]->node_name;

                try {
                    $listed = $this->providers->for($cluster)
                        ->listBackups($nodeName, $datastore, (string) $machine->provider_id);
                } catch (BackupProviderException) {
                    /*
                     * A datastore that will not answer is not a datastore that
                     * has lost anything. Recording drift on an unread listing
                     * would raise a critical alarm every time a provider was
                     * briefly unreachable, and teach an operator to ignore the
                     * one that matters.
                     */
                    continue;
                }

                $present = array_map(static fn (object $archive): string => (string) $archive->archiveId, $listed);
                $checked++;

                foreach ($rows as $row) {
                    if ($row->datastore !== $datastore) {
                        continue;
                    }

                    $isPresent = in_array((string) $row->archive_id, $present, strict: true);

                    if ($this->settle($row, $isPresent)) {
                        $settled++;

                        continue;
                    }

                    if ($this->reportRow($row, $isPresent, $datastore)) {
                        $drifts++;
                    }
                }

                $drifts += $this->reportOrphans($rows, $present, $datastore);
            }
        }

        return ['checked' => $checked, 'drifts' => $drifts, 'settled' => $settled];
    }

    /**
     * The one case where a listing answers a question rather than raising one:
     * a deletion the platform asked for and could not confirm has now been
     * confirmed by the archive's absence.
     */
    private function settle(Backup $row, bool $isPresent): bool
    {
        if ($row->state !== BackupState::Deleting || $isPresent) {
            return false;
        }

        $row->transitionTo(BackupState::Deleted, ['provider_deleted_at' => now()]);

        return true;
    }

    private function reportRow(Backup $row, bool $isPresent, string $datastore): bool
    {
        if ($row->state->isAvailable() && ! $isPresent) {
            $this->drift->execute(
                provider: $row->provider,
                resourceType: self::RESOURCE,
                kind: DriftKind::MissingAtProvider,
                providerReference: (string) $row->archive_id,
                serviceId: (string) $row->service_id,
                expected: ['state' => $row->state->value, 'datastore' => $datastore],
                observed: ['present' => false],
                // A customer finds out about this when they try to restore.
                severity: DriftSeverity::Critical,
            );

            return true;
        }

        if ($row->state === BackupState::Deleted && $isPresent) {
            $this->drift->execute(
                provider: $row->provider,
                resourceType: self::RESOURCE,
                kind: DriftKind::OrphanAtProvider,
                providerReference: (string) $row->archive_id,
                serviceId: (string) $row->service_id,
                expected: ['state' => BackupState::Deleted->value],
                observed: ['present' => true, 'datastore' => $datastore],
                severity: DriftSeverity::Warning,
            );

            return true;
        }

        return false;
    }

    /**
     * @param  list<Backup>  $rows
     * @param  list<string>  $present
     */
    private function reportOrphans(array $rows, array $present, string $datastore): int
    {
        $known = array_map(static fn (Backup $row): string => (string) $row->archive_id, $rows);
        $orphans = array_values(array_diff($present, $known));

        foreach ($orphans as $archiveId) {
            $this->drift->execute(
                provider: $rows[0]->provider,
                resourceType: self::RESOURCE,
                kind: DriftKind::OrphanAtProvider,
                providerReference: $archiveId,
                serviceId: (string) $rows[0]->service_id,
                expected: ['known_to_platform' => false],
                observed: ['present' => true, 'datastore' => $datastore],
                severity: DriftSeverity::Warning,
            );
        }

        return count($orphans);
    }

    /**
     * @return list<VirtualMachine>
     */
    private function machinesWithBackups(): array
    {
        /** @var list<VirtualMachine> $machines */
        $machines = VirtualMachine::query()
            ->whereNotNull('provider_id')
            ->whereIn('id', Backup::query()->whereNotNull('virtual_machine_id')->select('virtual_machine_id'))
            ->get()
            ->all();

        return $machines;
    }
}
