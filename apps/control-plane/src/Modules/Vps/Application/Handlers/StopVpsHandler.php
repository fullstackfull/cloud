<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Application\Handlers;

use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Vps\Domain\Enums\PowerAction;

/**
 * Brings a machine down — either by asking the guest or by cutting the power.
 *
 * Both live under one kind because the engine has one, and both are permitted
 * here for the same reason. Which of the two actually happens is decided by
 * the payload's `power_action`, never by the kind: the base handler routes
 * `shutdown` to the guest and `stop` to the plug, and there is no arm in that
 * match that lets one answer for the other.
 */
final class StopVpsHandler extends VpsPowerHandler
{
    public function kind(): ProvisioningJobKind
    {
        return ProvisioningJobKind::Stop;
    }

    protected function permittedActions(): array
    {
        return [PowerAction::Stop, PowerAction::Shutdown];
    }
}
