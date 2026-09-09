<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\DTOs;

use Lynomia\Modules\Infrastructure\Domain\Enums\DeploymentKind;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;

/**
 * Everything a deployment controller is given, and nothing else.
 *
 * No free text reaches the controller. The host is the machine's registered
 * name, the playbook and roles come from the catalogue in source, and the
 * extra variables are the validated overrides. There is no field a screen
 * could use to hand a playbook an argument the catalogue did not declare.
 */
final readonly class DeploymentOrder
{
    /**
     * @param  list<string>  $roles
     * @param  array<string, array<string, string>>  $configuration  Component key => validated overrides.
     * @param  array<string, string|null>  $verifications  Component key => verification spec.
     */
    public function __construct(
        public string $jobId,
        public DeploymentKind $kind,
        public string $host,
        public string $managementAddress,
        public DeploymentEnvironment $environment,
        public string $playbook,
        public array $roles,
        public array $configuration,
        public array $verifications,
        public int $timeoutSeconds,
    ) {}
}
