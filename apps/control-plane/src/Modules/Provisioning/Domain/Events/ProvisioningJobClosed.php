<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Events;

use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;

/**
 * A job in review was closed, without running, because its service has ended
 * (CloseAJobWhoseServiceEnded).
 *
 * Raised inside the close's transaction, after the job is written `cancelled`
 * and before it commits, so a listener that must happen with the close - the
 * return of a paid change the job was delivering - commits or fails with it:
 * a close whose return failed is refused, rather than taking the last pointer
 * to the money off the review list.
 *
 * @immutable
 */
final readonly class ProvisioningJobClosed
{
    public function __construct(
        public string $provisioningJobId,
        public ProvisioningJobKind $kind,
        public ?string $serviceId,
    ) {}
}
