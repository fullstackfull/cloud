<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Contracts;

use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Exceptions\ProvisioningFailedException;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;

/**
 * Everything the engine is allowed to ask of the thing that does the work.
 *
 * The interface is the module boundary. Nothing above this line knows that
 * Proxmox, cPanel or an IPMI controller exist, and nothing below it knows
 * about retries, backoff or compensation. That separation is what lets a new
 * platform be added by writing one class and registering it, rather than by
 * editing the engine.
 *
 * Three rules bind every implementation:
 *
 *  - failures are reported as a classified {@see ProvisioningResult} or a
 *    {@see ProvisioningFailedException}, never as an SDK exception, and never
 *    with a credential in the message;
 *  - the remote job id is written with {@see ProvisioningJob::recordRemoteJobId()}
 *    the moment the provider hands it over — not on return. A handler that
 *    holds the id in a local variable while it polls for completion has made
 *    a timeout unrecoverable;
 *  - execute() must be safe to call twice for the same job. The engine will
 *    not do so deliberately, but a worker that is killed after the provider
 *    accepted the work leaves exactly that situation behind.
 */
interface ProvisioningHandler
{
    /**
     * The kind of work this handler performs. It is the registry key, so it
     * must be stable.
     */
    public function kind(): ProvisioningJobKind;

    /**
     * @throws ProvisioningFailedException
     */
    public function execute(ProvisioningJob $job): ProvisioningResult;
}
