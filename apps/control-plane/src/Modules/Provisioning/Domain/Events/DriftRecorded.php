<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Events;

use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftSeverity;

/**
 * A disagreement between the platform and a provider was seen for the first
 * time.
 *
 * Raised only on the first sighting. A reconciler that runs every half hour
 * would otherwise alert forty-eight times a day about one unresolved orphan,
 * which is how an alert channel stops being read.
 *
 * @immutable
 */
final readonly class DriftRecorded
{
    public function __construct(
        public string $driftId,
        public string $provider,
        public string $resourceType,
        public DriftKind $kind,
        public DriftSeverity $severity,
        public ?string $serviceId,
        public ?string $providerReference,
    ) {}
}
