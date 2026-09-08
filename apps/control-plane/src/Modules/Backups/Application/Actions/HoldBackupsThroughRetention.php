<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Application\Actions;

use DateTimeInterface;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;

/**
 * Keeps a departing customer's backups until their retention window closes.
 *
 * `protected_until` was written into the schema by the deletion work and had
 * nobody to set it. This is who: the moment a service stops serving because
 * the account is leaving, its backups stop being subject to the ordinary
 * retention sweep — because the whole point of the window is that somebody who
 * cancelled by mistake, or who forgot to download something, still has it.
 *
 * The hold outranks the sweep and the customer both. It is deliberately not a
 * lock on deletion by an operator acting on an explicit request: "delete my
 * data now" is a thing a customer is entitled to ask for, and the override
 * exists for exactly that.
 *
 * Never shortens an existing hold. Two services ending a week apart must not
 * pull each other's backups forward.
 */
final readonly class HoldBackupsThroughRetention
{
    /**
     * @return int how many backups were put on hold
     */
    public function execute(string $serviceId, DateTimeInterface $until): int
    {
        $held = 0;

        Backup::query()
            ->where('service_id', $serviceId)
            ->whereIn('state', [
                BackupState::Succeeded->value,
                BackupState::Verified->value,
                BackupState::Restored->value,
            ])
            ->get()
            ->each(function (Backup $backup) use ($until, &$held): void {
                if ($backup->protected_until !== null && $backup->protected_until->greaterThanOrEqualTo($until)) {
                    return;
                }

                $backup->forceFill(['protected_until' => $until])->save();
                $held++;
            });

        return $held;
    }
}
