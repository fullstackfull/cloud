<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Contracts;

use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;

/**
 * The seam through which the engine can ask "has this job already destroyed
 * something?" without knowing what the job destroys.
 *
 * Only one caller needs the answer, and it is the one that matters: an
 * operator pressing retry. Every other refusal in the platform can afford to
 * be wrong once and be corrected; this one cannot, because the second attempt
 * is what destroys the data the first attempt was halfway through replacing.
 *
 * The engine deliberately does not know what a reinstall is. It knows a job
 * kind can be destructive, and that the module which owns the operation keeps
 * a record of the moment its subject stopped being intact.
 */
interface DestructiveOperationLedger
{
    /**
     * The operation's state when this job has already destroyed data, and null
     * when it has not — including when this ledger knows nothing about the job.
     *
     * Null is therefore "no evidence of destruction from me", never "safe".
     * Safety is the caller's conclusion from asking every ledger.
     */
    public function destructionState(ProvisioningJob $job): ?string;
}
