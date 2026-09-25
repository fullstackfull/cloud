<?php

declare(strict_types=1);

namespace Lynomia\Support\Lifecycle;

use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Dedicated\Application\Actions\DecommissionDedicatedServer;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\SharedHosting\Application\Actions\EndHostingService;
use Lynomia\Modules\Vps\Application\Actions\TerminateVpsService;
use RuntimeException;

/**
 * Ends one service, whatever kind of thing it is.
 *
 * Lives outside the modules because it is wiring: the sweep must not know that
 * a VPS is destroyed by a provisioning job, a hosting account by a control
 * panel call and a physical server by a person with a screwdriver, and none of
 * those three modules may learn about the others.
 *
 * ---------------------------------------------------------------------------
 * The two doors, and the one table (F-19)
 * ---------------------------------------------------------------------------
 *
 * Two things end a service in production: the retention sweep
 * (EndExpiredServices) and the operator's `DELETE /api/admin/services/{service}`.
 * Both come through here, and the per-kind actions below have no production
 * caller outside this class — tests call them directly, which is why that
 * sentence is scoped to production. Before F-19 the operator's route kept its
 * own dispatch table, `dedicated … else VPS`, and sent every hosting service
 * down the VPS path, where it was refused for having no virtual machine.
 *
 * authorityOver() sits beside execute() and is matched on the same kinds, so a
 * kind added to one and not the other fails on the first request rather than
 * ending on a guess. It is what the operator's route asks before it acts, and
 * it is asked whatever `force` says: `force` decides the retention window and
 * nothing else. For shared hosting it is both `service.terminate` and
 * `hosting_account.manage`, because the service route reaches
 * TerminateHostingAccount — F-18's action layer — without passing F-18's
 * controller gate on the hosting-account route, and must never be the weaker
 * of the two doors to the same account.
 *
 * ---------------------------------------------------------------------------
 * A dedicated server is not finished here, and that is the point
 * ---------------------------------------------------------------------------
 *
 * The physical case ends at `maintenance`: the machine leaves the customer and
 * stays out of the sellable pool until an operator states that its disks have
 * been erased. Nothing automated may complete that second act, because no call
 * this platform can make proves a disk was wiped, and the cost of assuming it
 * was is the next customer receiving the last one's data. So the sweep can
 * start a decommission and can never finish one.
 *
 * ---------------------------------------------------------------------------
 * A service nothing was built for ends too
 * ---------------------------------------------------------------------------
 *
 * Each kind's action ends a service that has no resource row when its build
 * history shows nothing could exist at a provider, and refuses it
 * (`provisioning.build_may_exist`) when something may. So a purchase whose
 * build failed can end, and give back what it held. See EvidenceOfABuild.
 *
 * An unknown kind raises, from both methods. Guessing would mean sending a
 * physical server down the path that destroys a virtual machine, and the
 * failure mode of guessing wrong here is somebody's data.
 */
final readonly class EndOfService
{
    public function __construct(
        private TerminateVpsService $terminateVps,
        private EndHostingService $endHosting,
        private DecommissionDedicatedServer $decommission,
    ) {}

    /**
     * Every permission an operator must hold to end a service of this kind.
     *
     * @return list<Permission>
     */
    public static function authorityOver(string $kind): array
    {
        return match ($kind) {
            ProductKind::Dedicated->value, ProductKind::Vps->value => [Permission::ServiceTerminate],
            /*
             * Both keys, forced or not. The hosting-account route demands the
             * second one only for an account that is not already waiting out
             * its window; this route asks for it always, which is never less.
             */
            ProductKind::SharedHosting->value => [Permission::ServiceTerminate, Permission::HostingAccountManage],
            default => throw self::noPathFor($kind),
        };
    }

    /**
     * @param  bool  $force  Skip the retention window. Only ever an operator acting on an
     *                       explicit request; the sweep never sets it.
     */
    public function execute(Service $service, bool $force = false): HowTheServiceEnded
    {
        return match ($service->kind) {
            ProductKind::Dedicated->value => $this->endDedicated($service, $force),
            ProductKind::SharedHosting->value => $this->endHosting($service, $force),
            ProductKind::Vps->value => $this->endVps($service, $force),
            default => throw self::noPathFor($service->kind),
        };
    }

    private function endVps(Service $service, bool $force): HowTheServiceEnded
    {
        $job = $this->terminateVps->execute($service, force: $force);

        return $job === null ? HowTheServiceEnded::nothingWasBuilt() : HowTheServiceEnded::destroyQueued($job);
    }

    private function endHosting(Service $service, bool $force): HowTheServiceEnded
    {
        $account = $this->endHosting->execute($service, force: $force);

        return $account === null ? HowTheServiceEnded::nothingWasBuilt() : HowTheServiceEnded::accountTerminated($account);
    }

    private function endDedicated(Service $service, bool $force): HowTheServiceEnded
    {
        $server = $this->decommission->execute($service, force: $force);

        return $server === null ? HowTheServiceEnded::nothingWasBuilt() : HowTheServiceEnded::decommissioned($server);
    }

    private static function noPathFor(string $kind): RuntimeException
    {
        return new RuntimeException(sprintf(
            'No termination path for a service of kind "%s". Ending it by guesswork is how the wrong thing gets destroyed.',
            $kind,
        ));
    }
}
