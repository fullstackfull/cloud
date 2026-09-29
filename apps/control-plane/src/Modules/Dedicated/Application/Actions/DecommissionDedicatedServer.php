<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DecommissionRefusedException;
use Lynomia\Modules\Dedicated\Domain\StateMachines\DedicatedServerStateMachine;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Provisioning\Application\Actions\EndAnUnbuiltService;
use Lynomia\Modules\Provisioning\Application\Actions\TransitionService;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\Exceptions\ABuildMayExistException;
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
 *     disks have been erased, and the machine becomes sellable again — or
 *     {@see RetireDedicatedServer}, when it is leaving the fleet instead.
 *
 * Collapsing the two would mean a machine returning to stock with the last
 * customer's data on it, sold to the next one. That is not a race or an edge
 * case; it is what would happen every single time.
 *
 * **The addresses follow the same two steps.** Every address the machine was
 * wearing is released here, in the same transaction as the machine leaving
 * the customer: the assignment is stamped, the customer's list no longer
 * shows it, and its PTR is marked for withdrawal, so the departing customer
 * stops controlling the reverse DNS of an address the platform will one day
 * hand to somebody else. Before this none of that happened: the assignments
 * stayed live for ever, one public address leaked per lifecycle, and the
 * customer who had left could go on publishing PTRs on it.
 *
 * But the address is *held*, not put on the quarantine clock. The operating
 * system on the disks still has it configured and the machine is still in the
 * rack; a clock started now could run out before anybody erased it, and the
 * next customer would be handed an address a racked machine answers on. The
 * clock starts in step two, when a person says the disks are empty.
 *
 * **A service with no server was refused, and now ends when nothing was
 * built (F-19).** A dedicated order whose chassis could not be reserved has no
 * server to take back, and refusing it meant the purchase could never end.
 * It ends here with no machine touched — unless EvidenceOfABuild finds a
 * build that may have reserved one, which is refused with
 * `provisioning.build_may_exist` whatever `force` says.
 */
final readonly class DecommissionDedicatedServer
{
    public function __construct(
        private DedicatedServerStateMachine $states,
        private TransitionService $transitionService,
        private IpAllocator $addresses,
        private EndAnUnbuiltService $unbuilt,
    ) {}

    /**
     * @return DedicatedServer|null the machine now in maintenance, or null when the service
     *                              had no server and nothing was ever built for it
     *
     * @throws DecommissionRefusedException
     * @throws ABuildMayExistException
     */
    public function execute(Service $service, bool $force = false): ?DedicatedServer
    {
        if ($service->status === ServiceStatus::Terminated) {
            throw DecommissionRefusedException::becauseItIsAlreadyOver((string) $service->getKey());
        }

        /*
         * One chassis per call. A service that somehow holds two is ended with
         * the first, and the other stays assigned with its addresses — which
         * is why the release below is keyed by this machine and not by the
         * service. Keyed by the service, it would take the addresses off a
         * machine that is still racked, still assigned and still answering.
         */
        $server = DedicatedServer::query()->where('service_id', $service->getKey())->first();

        if ($server === null) {
            // No machine to take back — if and only if none was ever reserved.
            $this->unbuilt->execute($service);

            return null;
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

            $this->addresses->holdAssignmentsOf($locked, ReleaseReason::ServiceTerminated);

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
