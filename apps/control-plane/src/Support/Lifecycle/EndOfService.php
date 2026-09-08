<?php

declare(strict_types=1);

namespace Lynomia\Support\Lifecycle;

use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Dedicated\Application\Actions\DecommissionDedicatedServer;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\SharedHosting\Application\Actions\TerminateHostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
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
 * An unknown kind raises. Guessing would mean sending a physical server down
 * the path that destroys a virtual machine, and the failure mode of guessing
 * wrong here is somebody's data.
 */
final readonly class EndOfService
{
    public function __construct(
        private TerminateVpsService $terminateVps,
        private TerminateHostingAccount $terminateHosting,
        private DecommissionDedicatedServer $decommission,
    ) {}

    /**
     * @param  bool  $force  Skip the retention window. Only ever an operator acting on an
     *                       explicit request; the sweep never sets it.
     * @return string what was done, for the audit entry and the log line
     */
    public function execute(Service $service, bool $force = false): string
    {
        return match ($service->kind) {
            ProductKind::Dedicated->value => $this->endDedicated($service, $force),
            ProductKind::SharedHosting->value => $this->endHosting($service, $force),
            ProductKind::Vps->value => $this->endVps($service, $force),
            default => throw new RuntimeException(sprintf(
                'No termination path for a service of kind "%s". Ending it by guesswork is how the wrong thing gets destroyed.',
                $service->kind,
            )),
        };
    }

    private function endVps(Service $service, bool $force): string
    {
        $job = $this->terminateVps->execute($service, force: $force);

        return 'queued provisioning job '.$job->getKey();
    }

    private function endHosting(Service $service, bool $force): string
    {
        $account = HostingAccount::query()->where('service_id', $service->getKey())->first();

        if ($account === null) {
            throw new RuntimeException(sprintf(
                'Service %s is shared hosting with no account behind it.',
                $service->getKey(),
            ));
        }

        $this->terminateHosting->execute($account, force: $force);

        return 'terminated hosting account '.$account->getKey();
    }

    private function endDedicated(Service $service, bool $force): string
    {
        $server = $this->decommission->execute($service, force: $force);

        // Deliberately not "returned to stock". It is in maintenance, holding
        // the customer's disks, until somebody says otherwise.
        return 'decommissioned dedicated server '.$server->getKey().' to maintenance';
    }
}
