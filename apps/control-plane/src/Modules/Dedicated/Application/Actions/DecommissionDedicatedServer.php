<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DecommissionRefusedException;
use Lynomia\Modules\Dedicated\Domain\StateMachines\DedicatedServerStateMachine;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Provisioning\Application\Actions\TransitionService;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * Taking a physical machine back from a customer.
 *
 * The end of the dedicated lifecycle, and the one place where this platform
 * deliberately stops short of automating something. A VPS termination destroys
 * the machine and quarantines its address, all of it in software. A physical
 * server cannot be finished that way: the disks in it hold the customer's data
 * until somebody erases them, and no API call the platform can make proves
 * that happened.
 *
 * So decommissioning is two acts by two decisions, and the state machine
 * already said so before anything could travel it — `active → maintenance`,
 * then `maintenance → available`, commented as "the path a decommissioned
 * service takes once the disks have been erased".
 *
 *  1. **Here.** The service ends, the machine leaves the customer, and it goes
 *     to `maintenance` rather than back to stock. It is out of the fleet's
 *     available pool, so nothing can sell it, and it is still traceable to the
 *     account that was on it.
 *  2. **{@see ReturnDedicatedServerToStock}.** An operator states that the
 *     disks have been erased, and the machine becomes sellable again.
 *
 * Collapsing the two would mean a machine returning to stock with the last
 * customer's data on it, sold to the next one. That is not a race or an edge
 * case; it is what would happen every single time.
 */
final readonly class DecommissionDedicatedServer
{
    public function __construct(
        private DedicatedServerStateMachine $states,
        private TransitionService $transitionService,
    ) {}

    /**
     * @throws DecommissionRefusedException
     */
    public function execute(Service $service, bool $force = false): DedicatedServer
    {
        if ($service->status === ServiceStatus::Terminated) {
            throw DecommissionRefusedException::becauseItIsAlreadyOver((string) $service->getKey());
        }

        $server = DedicatedServer::query()->where('service_id', $service->getKey())->first();

        if ($server === null) {
            throw DecommissionRefusedException::becauseThereIsNoServer((string) $service->getKey());
        }

        if (! $force) {
            $this->assertTheRetentionWindowHasElapsed($service);
        }

        return DB::transaction(function () use ($service, $server): DedicatedServer {
            /** @var DedicatedServer $locked */
            $locked = DedicatedServer::query()->lockForUpdate()->findOrFail($server->getKey());

            $this->states->assertCanTransition($locked->status, DedicatedServerStatus::Maintenance);

            $locked->forceFill([
                'status' => DedicatedServerStatus::Maintenance,
                /*
                 * Unassigned here rather than when it returns to stock. From
                 * this moment the customer is not paying for it and must not
                 * see it, and the audit trail — not this column — is what
                 * answers "who was on this machine in March".
                 */
                'customer_id' => null,
                'service_id' => null,
                'reserved_until' => null,
                'reserved_by_order_id' => null,
            ])->save();

            $this->transitionService->execute($service, ServiceStatus::Terminated);

            return $locked;
        });
    }

    /**
     * @throws DecommissionRefusedException
     */
    private function assertTheRetentionWindowHasElapsed(Service $service): void
    {
        if ($service->status !== ServiceStatus::Suspended) {
            throw DecommissionRefusedException::becauseItIsStillInService(
                (string) $service->getKey(),
                $service->status,
            );
        }

        $days = max(0, (int) config('provisioning.termination.suspended_retention_days', 30));

        $releasesAt = $service->suspended_at?->addDays($days);

        if ($releasesAt === null || $releasesAt->isFuture()) {
            throw DecommissionRefusedException::becauseTheRetentionWindowIsOpen(
                (string) $service->getKey(),
                $releasesAt?->toIso8601String() ?? 'unknown',
            );
        }
    }
}
