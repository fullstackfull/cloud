<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Infrastructure\Deployment;

use Lynomia\Modules\Infrastructure\Domain\Contracts\DeploymentController;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\DeploymentRefused;

/**
 * Which bridge this build uses, decided once from configuration.
 *
 * `fake` outside production only; `ansible` anywhere the tree is present and
 * CI is not. There is no third driver and no way to add one from a request.
 */
final readonly class DeploymentControllerFactory
{
    public function __construct(
        private string $driver,
        private string $environment,
        private ?string $iacPath,
        private bool $ci,
    ) {}

    public function driver(): string
    {
        return $this->driver;
    }

    public function make(): DeploymentController
    {
        return match ($this->driver) {
            'fake' => new FakeDeploymentController($this->environment),
            'ansible' => new AnsibleDeploymentController($this->iacPath, $this->ci),
            default => throw DeploymentRefused::controllerRefused(sprintf('There is no %s deployment controller.', $this->driver)),
        };
    }
}
