<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Events;

use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;

/**
 * A worker has claimed a job and is about to call the provider.
 *
 * The one moment in a build that moves no status on the service: an ordered
 * service is already `provisioning` while its job waits in the queue, so the
 * difference between "asked for" and "being built" is visible only on the job.
 * The order it was bought on distinguishes the two (`queued_for_provisioning`
 * and `provisioning`), and this is what tells it the second has begun.
 *
 * Raised once per claimed attempt, after the claim has committed. A retried
 * build is claimed again and says so again; a listener converges on that.
 *
 * @immutable
 */
final readonly class ProvisioningJobStarted
{
    public function __construct(
        public string $provisioningJobId,
        public ProvisioningJobKind $kind,
        public ?string $serviceId,
        public int $attempt,
    ) {}
}
