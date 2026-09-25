<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DecommissionRefusedException;
use Lynomia\Modules\Dedicated\Domain\StateMachines\DedicatedServerStateMachine;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;

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
 *
 * The same word starts the quarantine clock on the addresses the machine was
 * holding. {@see DecommissionDedicatedServer} released them but held them,
 * because until the disks were erased the machine in the rack still had them
 * configured. Once a person says the disks are empty, the address is no
 * longer answering anywhere and its ordinary quarantine window can begin.
 *
 * Behind the row lock, like {@see RetireDedicatedServer}, so the two doors out
 * of maintenance are decided one at a time: whichever commits second is
 * checked against the state the first one wrote.
 */
final readonly class ReturnDedicatedServerToStock
{
    public function __construct(
        private DedicatedServerStateMachine $states,
        private IpAllocator $addresses,
    ) {}

    /**
     * @throws DecommissionRefusedException
     */
    public function execute(DedicatedServer $server): DedicatedServer
    {
        return DB::transaction(function () use ($server): DedicatedServer {
            /** @var DedicatedServer $locked */
            $locked = DedicatedServer::query()->lockForUpdate()->findOrFail($server->getKey());

            if ($locked->service_id !== null || $locked->customer_id !== null) {
                throw DecommissionRefusedException::becauseItIsStillSomebodys((string) $locked->getKey());
            }

            $this->states->assertCanTransition($locked->status, DedicatedServerStatus::Available);

            $locked->forceFill([
                'status' => DedicatedServerStatus::Available,
                // The install profile the last customer chose goes with them: the
                // next one picks their own, and a stale profile on a stock machine
                // is a default nobody selected.
                'os_install_profile_id' => null,
            ])->save();

            $this->addresses->startHeldQuarantines($locked);

            return $locked;
        });
    }
}
