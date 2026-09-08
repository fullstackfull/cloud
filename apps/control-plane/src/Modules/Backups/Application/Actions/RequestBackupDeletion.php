<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupDeletionRefusedException;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * Records that a backup is to go, and refuses when it must not.
 *
 * **This action asks nothing of the provider.** It writes a decision, and the
 * sweep acts on it after a grace period. That separation is the point: a
 * deletion is irreversible and a mis-click is common, so the hour between
 * asking and acting has saved somebody's only copy more than once — and a
 * customer who changes their mind can call it off while the row is still
 * `DeleteRequested`.
 *
 * Every refusal below is a way the archive is not the platform's to destroy.
 */
final readonly class RequestBackupDeletion
{
    /** Why a deletion was asked for. Recorded, because it changes what an
     *  operator does about one that will not complete. */
    public const string BY_CUSTOMER = 'customer';

    public const string BY_RETENTION = 'retention';

    public const string BY_OPERATOR = 'operator';

    public function __construct(
        private ResolveBackupPolicy $policy,
    ) {}

    public function execute(Backup $backup, string $reason, ?User $actor = null): Backup
    {
        return DB::transaction(function () use ($backup, $reason, $actor): Backup {
            /** @var Backup $locked */
            $locked = Backup::query()->whereKey($backup->getKey())->lockForUpdate()->firstOrFail();

            $this->assertDeletable($locked, $reason);

            $locked->transitionTo(BackupState::DeleteRequested, [
                'deletion_requested_at' => now(),
                'deletion_requested_by_user_id' => $actor?->getKey(),
                'deletion_reason' => $reason,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Calls off a deletion nobody has acted on yet.
     *
     * Only from `DeleteRequested`: once the provider has been asked there is
     * nothing to cancel, and pretending otherwise would leave a row saying
     * `succeeded` for an archive that is being removed.
     */
    public function cancel(Backup $backup): Backup
    {
        return DB::transaction(function () use ($backup): Backup {
            /** @var Backup $locked */
            $locked = Backup::query()->whereKey($backup->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->state !== BackupState::DeleteRequested) {
                throw BackupDeletionRefusedException::becauseItIsNotAvailable(
                    (string) $locked->getKey(),
                    $locked->state,
                );
            }

            $locked->transitionTo(BackupState::Succeeded, [
                'deletion_requested_at' => null,
                'deletion_requested_by_user_id' => null,
                'deletion_reason' => null,
            ]);

            return $locked->refresh();
        });
    }

    private function assertDeletable(Backup $backup, string $reason): void
    {
        $id = (string) $backup->getKey();

        if ($backup->state->isBeingDeleted()) {
            throw BackupDeletionRefusedException::becauseItIsAlreadyGoing($id);
        }

        // A restore reads the archive it restores from. Removing it mid-restore
        // leaves a machine half-written from a source that is no longer there.
        if ($backup->state === BackupState::Restoring) {
            throw BackupDeletionRefusedException::becauseARestoreIsRunning($id);
        }

        if ($backup->state->isInFlight()) {
            throw BackupDeletionRefusedException::becauseItIsStillBeingWritten($id, $backup->state);
        }

        if (! $backup->state->isAvailable()) {
            throw BackupDeletionRefusedException::becauseItIsNotAvailable($id, $backup->state);
        }

        /*
         * A hold placed by the termination path, which keeps a departing
         * customer's last backups through the retention window. It outranks
         * both a customer's request and the retention sweep: the whole point
         * of the window is that somebody who cancelled by mistake still has
         * something to restore.
         */
        if ($backup->protected_until !== null && $backup->protected_until->isFuture()) {
            throw BackupDeletionRefusedException::becauseItIsProtected(
                $id,
                $backup->protected_until->toIso8601String(),
            );
        }

        if ($reason !== self::BY_CUSTOMER) {
            return;
        }

        /** @var Service|null $service */
        $service = Service::query()->whereKey($backup->service_id)->first();

        if (! $this->policy->execute($service)->customerMayDelete) {
            throw BackupDeletionRefusedException::becauseThePlanForbidsIt($id);
        }
    }
}
