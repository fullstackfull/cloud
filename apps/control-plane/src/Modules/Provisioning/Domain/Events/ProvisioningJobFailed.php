<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Events;

use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;

/**
 * A unit of provisioning work failed and will not be retried automatically.
 *
 * Raised for failures the engine considers finished with. A failure that is
 * about to be retried raises nothing: telling a customer their server failed
 * thirty seconds before it succeeds is worse than saying nothing.
 *
 * @immutable
 */
final readonly class ProvisioningJobFailed
{
    public function __construct(
        public string $provisioningJobId,
        public ProvisioningJobKind $kind,
        public ?string $serviceId,
        public FailureClass $failureClass,
        public string $errorCode,
    ) {}
}
