<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Events;

use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;

/**
 * A job has stopped and needs a person.
 *
 * This is the event that must never be routed to a dashboard nobody watches.
 * A job in review is usually one that timed out, which means a resource may
 * exist at the provider that the platform does not know about: unbilled,
 * unmanaged, and holding an address the next customer is about to be given.
 *
 * @immutable
 */
final readonly class ProvisioningJobNeedsReview
{
    public function __construct(
        public string $provisioningJobId,
        public ProvisioningJobKind $kind,
        public ?string $serviceId,
        public ?string $remoteJobId,
        public FailureClass $failureClass,
        public string $reason,
    ) {}
}
