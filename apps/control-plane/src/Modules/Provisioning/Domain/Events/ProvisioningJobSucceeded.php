<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Events;

use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;

/**
 * A unit of provisioning work completed.
 *
 * Carries identifiers rather than models so that a queued listener
 * deserialises to the facts the event was raised with, and cannot mutate the
 * job through the event.
 *
 * `adopted` distinguishes a job the engine completed from one an operator
 * settled by attaching a resource the provider had already built. Downstream,
 * a welcome email is appropriate for the first and embarrassing for the
 * second.
 *
 * @immutable
 */
final readonly class ProvisioningJobSucceeded
{
    public function __construct(
        public string $provisioningJobId,
        public ProvisioningJobKind $kind,
        public ?string $serviceId,
        public ?string $remoteJobId,
        public bool $adopted = false,
    ) {}
}
