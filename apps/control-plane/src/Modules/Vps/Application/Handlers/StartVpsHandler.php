<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Application\Handlers;

use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Vps\Domain\Enums\PowerAction;

/**
 * Powers a machine on.
 */
final class StartVpsHandler extends VpsPowerHandler
{
    public function kind(): ProvisioningJobKind
    {
        return ProvisioningJobKind::Start;
    }

    protected function permittedActions(): array
    {
        return [PowerAction::Start];
    }
}
