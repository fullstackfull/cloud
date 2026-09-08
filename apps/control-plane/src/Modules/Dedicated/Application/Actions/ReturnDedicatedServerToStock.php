<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Application\Actions;

use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DecommissionRefusedException;
use Lynomia\Modules\Dedicated\Domain\StateMachines\DedicatedServerStateMachine;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;

/**
 * A machine goes back on the shelf, because a person says its disks are empty.
 *
 * The second half of a decommission, deliberately separate: the platform
 * cannot verify that a physical disk was erased, and the cost of assuming it
 * was is the next customer receiving the last one's data. What this records is
 * somebody's word for it — which is why the caller is required to say what
 * they did, and why that ends up in the audit trail beside their name.
 *
 * It refuses a machine that is still assigned. A server with a customer on it
 * has not been decommissioned, whatever its status column says, and returning
 * it to stock would offer a running customer's machine for sale.
 */
final readonly class ReturnDedicatedServerToStock
{
    public function __construct(
        private DedicatedServerStateMachine $states,
    ) {}

    /**
     * @throws DecommissionRefusedException
     */
    public function execute(DedicatedServer $server): DedicatedServer
    {
        if ($server->service_id !== null || $server->customer_id !== null) {
            throw DecommissionRefusedException::becauseItIsStillSomebodys((string) $server->getKey());
        }

        $this->states->assertCanTransition($server->status, DedicatedServerStatus::Available);

        $server->forceFill([
            'status' => DedicatedServerStatus::Available,
            // The install profile the last customer chose goes with them: the
            // next one picks their own, and a stale profile on a stock machine
            // is a default nobody selected.
            'os_install_profile_id' => null,
        ])->save();

        return $server;
    }
}
