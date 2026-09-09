<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Contracts;

use Lynomia\Modules\Infrastructure\Domain\DTOs\DeploymentOrder;
use Lynomia\Modules\Infrastructure\Domain\DTOs\DeploymentOutcome;

/**
 * The bridge from the control plane to the machines: the one thing that
 * crosses into the management network.
 *
 * Two verbs. Apply runs the reviewed playbook for the order's profile against
 * the one host named; verify runs the same tree in check mode plus the
 * components' declared verifications and reports what it observed. A run that
 * outlives its deadline is reported indeterminate, never failed and never
 * succeeded.
 */
interface DeploymentController
{
    public function driver(): string;

    public function apply(DeploymentOrder $order): DeploymentOutcome;

    public function verify(DeploymentOrder $order): DeploymentOutcome;
}
