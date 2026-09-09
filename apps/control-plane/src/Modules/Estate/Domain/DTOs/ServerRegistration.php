<?php

declare(strict_types=1);

namespace Lynomia\Modules\Estate\Domain\DTOs;

use Lynomia\Modules\Estate\Domain\Enums\EstateEnvironment;

/**
 * What somebody knows about a machine when they first write it down.
 *
 * Almost everything is optional, because almost everything is unknown at that
 * moment: a machine can be registered from a delivery note, from a rack
 * elevation, or from an operator who has been told its management address and
 * nothing else. What discovery later learns goes to server_facts rather than
 * over these, so the two never silently overwrite one another.
 *
 * There is deliberately no safety classification here. It is not something a
 * registration can express.
 */
final readonly class ServerRegistration
{
    public function __construct(
        public string $name,
        public EstateEnvironment $environment,
        public ?string $datacenterId = null,
        public ?string $rackId = null,
        public ?int $rackUnit = null,
        public ?int $heightUnits = null,
        public ?string $vendor = null,
        public ?string $model = null,
        public ?string $serial = null,
        public ?string $assetTag = null,
        public ?string $managementAddress = null,
        public ?int $managementPort = null,
        public ?string $bmcAddress = null,
        public ?int $bmcPort = null,
        public ?string $operatingSystem = null,
        public ?string $notes = null,
    ) {}
}
