<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Infrastructure;

use Lynomia\Modules\Provisioning\Domain\Contracts\DestructiveOperationLedger;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Vps\Infrastructure\Models\VmReinstall;

/**
 * What a virtual machine's rebuild has already cost the customer.
 *
 * The timestamp is the authority rather than the state, for the same reason it
 * is inside the operation itself: a reinstall that failed after the disk was
 * handed to the hypervisor reads `failed`, and the disk is still gone.
 */
final readonly class VpsReinstallLedger implements DestructiveOperationLedger
{
    public function destructionState(ProvisioningJob $job): ?string
    {
        if ($job->kind !== ProvisioningJobKind::ReinstallVps) {
            return null;
        }

        $operation = VmReinstall::query()
            ->where('provisioning_job_id', $job->getKey())
            ->orderByDesc('created_at')
            ->first();

        return $operation !== null && $operation->destroyedData() ? $operation->state->value : null;
    }
}
