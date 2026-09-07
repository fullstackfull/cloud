<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Application\Actions;

use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupProviderException;
use Lynomia\Modules\Backups\Domain\Exceptions\RestoreRefusedException;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Put a backup back, over the machine it came from.
 *
 * The most destructive thing a customer can do to themselves through this API.
 * Everything the platform already knew how to do — the provider's startRestore,
 * the Restoring and Restored states, the poller that watches the task — was
 * built and had no caller, because the confirmation flow it needs did not
 * exist and shipping the endpoint without one would have been shipping the
 * dangerous half first.
 *
 * ---------------------------------------------------------------------------
 * The four refusals
 * ---------------------------------------------------------------------------
 *
 *  - **The confirmation must match the hostname exactly.** Not trimmed of
 *    internal whitespace, not case-folded. It is not a lookup; it is evidence
 *    that a person read the screen. Same rule as a reinstall, for the same
 *    reason.
 *
 *  - **The backup must have actually completed.** A row in Requested or
 *    Running names an archive that is still being written; restoring from it
 *    would write a half-copied disk over a working machine. `Failed` and
 *    `NeedsReview` are refused for the more obvious reason.
 *
 *  - **The machine must still be the one the backup came from.** The backup
 *    holds the machine id it was taken from, and a restore is aimed at a
 *    machine; if the two disagree, something upstream has confused two
 *    customers' servers and this is the last place to catch it.
 *
 *  - **Nothing else may be restoring.** Two concurrent restores over the same
 *    disks is the one outcome that cannot be reasoned about afterwards.
 *
 * ---------------------------------------------------------------------------
 * A timeout is not a failure
 * ---------------------------------------------------------------------------
 *
 * The same rule the rest of the platform follows, and it matters more here
 * than anywhere. A restore call that does not answer may be running right
 * now, writing to the customer's disks. Marking it failed invites a retry
 * that starts a second restore over a disk that a first restore is halfway
 * through. The row goes to NeedsReview and stops, and a person looks at the
 * datastore — the only thing that actually settles it.
 */
final readonly class RestoreServiceBackup
{
    public function __construct(
        private BackupProviderFactory $providers,
        private SecretRedactor $redactor,
    ) {}

    /**
     * @param  string  $confirmation  must equal the machine's hostname
     *
     * @throws RestoreRefusedException
     */
    public function execute(
        Backup $backup,
        VirtualMachine $machine,
        string $confirmation,
        ?string $restoredByUserId = null,
    ): Backup {
        if (! hash_equals($machine->hostname, $confirmation)) {
            throw RestoreRefusedException::confirmationMismatch();
        }

        $this->assertRestorable($backup, $machine);

        $cluster = $machine->cluster()->first();

        if ($cluster === null) {
            throw RestoreRefusedException::notRestorable(
                (string) $backup->getKey(),
                'the machine is not attached to a cluster',
            );
        }

        $archive = trim((string) $backup->archive_id);

        if ($archive === '') {
            /*
             * A backup the platform believes succeeded but for which it never
             * learned the archive's name. Restoring "" would ask the provider
             * for its idea of a default, which on a shared datastore is
             * somebody else's archive.
             */
            throw RestoreRefusedException::notRestorable(
                (string) $backup->getKey(),
                'the platform never recorded which archive this backup wrote',
            );
        }

        $provider = $this->providers->for($cluster);

        // Moved before the call, exactly as the backup path writes its row
        // first: if this process dies mid-request, the platform still knows a
        // restore was started and an operator can find it.
        $backup->transitionTo(BackupState::Restoring, [
            'restore_started_at' => now(),
            'restored_by_user_id' => $restoredByUserId,
            // Cleared: a previous attempt's reason has nothing to say about
            // this one, and leaving it makes a running restore look broken.
            'failure_reason' => null,
        ]);

        try {
            $operation = $provider->startRestore(
                nodeName: $backup->node_name,
                providerId: (string) $machine->provider_id,
                datastore: $backup->datastore,
                archiveId: $archive,
            );
        } catch (BackupProviderException $e) {
            $backup->transitionTo(
                // Indeterminate stops rather than fails: a restore that may be
                // running must never be retried automatically.
                $e->isIndeterminate() ? BackupState::NeedsReview : BackupState::Succeeded,
                [
                    'failure_reason' => $this->redactor->redactString($e->getMessage()),
                ],
            );

            return $backup->refresh();
        }

        /*
         * An update, not a transition: the row is already Restoring, and
         * Restoring is not a legal destination from itself — deliberately, so
         * that a re-entrant call cannot restart the clock on a restore that is
         * already running.
         *
         * The identifier goes in its own column. Overwriting provider_task_id
         * would erase the identifier of the backup itself — the one thing that
         * finds the archive if the restore goes wrong, which is exactly when
         * it is needed — and the table's unique index on (provider,
         * provider_task_id) could reject the write outright.
         */
        $backup->forceFill(['restore_task_id' => $operation->taskId])->save();

        return $backup->refresh();
    }

    /**
     * @throws RestoreRefusedException
     */
    private function assertRestorable(Backup $backup, VirtualMachine $machine): void
    {
        if ($backup->virtual_machine_id !== null
            && (string) $backup->virtual_machine_id !== (string) $machine->getKey()) {
            throw RestoreRefusedException::notRestorable(
                (string) $backup->getKey(),
                'it was taken from a different machine',
            );
        }

        if ($backup->state === BackupState::Restoring) {
            throw RestoreRefusedException::alreadyRestoring((string) $backup->getKey());
        }

        if (! in_array($backup->state, [BackupState::Succeeded, BackupState::Verified], true)) {
            throw RestoreRefusedException::notRestorable(
                (string) $backup->getKey(),
                sprintf('its state is %s, and only a completed backup can be restored', $backup->state->value),
            );
        }

        $inFlight = Backup::query()
            ->where('virtual_machine_id', $machine->getKey())
            ->where('state', BackupState::Restoring->value)
            ->exists();

        if ($inFlight) {
            throw RestoreRefusedException::alreadyRestoring((string) $backup->getKey());
        }

        $service = $machine->service()->first();

        if ($service === null || $service->status !== ServiceStatus::Active) {
            /*
             * The same bar every other destructive VPS operation clears. A
             * suspended service must not be restorable: it is the state a
             * non-paying customer is in, and a restore is real work on real
             * hardware.
             */
            throw RestoreRefusedException::notRestorable(
                (string) $backup->getKey(),
                'the service is not active',
            );
        }
    }
}
