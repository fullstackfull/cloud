<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Application\Handlers;

use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Vps\Domain\Enums\PowerAction;

/**
 * Restarts a machine by asking the guest.
 *
 * The hard counterpart — the provider's resetVm() — is deliberately not
 * reachable from any kind this module dispatches. A customer who needs a reset
 * asks for `stop` and then `start`, which is two deliberate requests rather
 * than one word that quietly means "and lose whatever was in flight".
 */
final class RestartVpsHandler extends VpsPowerHandler
{
    public function kind(): ProvisioningJobKind
    {
        return ProvisioningJobKind::Restart;
    }

    protected function permittedActions(): array
    {
        return [PowerAction::Reboot];
    }
}
