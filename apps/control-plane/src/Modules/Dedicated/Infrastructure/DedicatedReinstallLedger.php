<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Infrastructure;

use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedReinstall;
use Lynomia\Modules\Provisioning\Domain\Contracts\DestructiveOperationLedger;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;

/**
 * What a physical rebuild has already cost the customer.
 *
 * `destructive_started_at` is stamped as the installer is armed and retracted
 * only where a controller answered and refused to boot the machine — so a
 * stamp that is still there means the machine may be erasing itself right now.
 */
final readonly class DedicatedReinstallLedger implements DestructiveOperationLedger
{
    public function destructionState(ProvisioningJob $job): ?string
    {
        if ($job->kind !== ProvisioningJobKind::ReinstallDedicated) {
            return null;
        }

        $operation = DedicatedReinstall::query()
            ->where('provisioning_job_id', $job->getKey())
            ->orderByDesc('created_at')
            ->first();

        return $operation !== null && $operation->destructive_started_at !== null
            ? $operation->state->value
            : null;
    }
}
