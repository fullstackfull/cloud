<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * Stop installing from a profile.
 *
 * Deactivated, never deleted: a machine records the profile it was built
 * with, and "what is this server running?" is the first question asked when a
 * rebuild goes wrong. The renderer refuses an inactive profile at render
 * time, so a build queued before the withdrawal stops using it too.
 */
final readonly class WithdrawOsInstallProfile
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    public function execute(OsInstallProfile $profile, User $operator): OsInstallProfile
    {
        return $this->record->execute(
            act: function () use ($profile): OsInstallProfile {
                $profile->forceFill(['is_active' => false])->save();

                return $profile->refresh();
            },
            describe: fn (OsInstallProfile $withdrawn): AuditedAct => new AuditedAct(
                action: AuditAction::OsInstallProfileWithdrawn,
                subject: $withdrawn,
                context: [
                    'slug' => $withdrawn->slug,
                    'operator' => (string) $operator->getKey(),
                ],
            ),
        );
    }
}
