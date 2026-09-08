<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Services;

use Illuminate\Database\Eloquent\Builder;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedOperationRefusedException;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;

/**
 * The two questions asked before anything is done to a customer's machine.
 *
 * It lives in Domain rather than in a controller because a queue worker, a
 * console command and an operator surface all have to reach the same verdict.
 * A guard that only exists on the HTTP path is a guard the next caller skips —
 * and on physical hardware the thing the next caller skips is the check that
 * stops a customer power cycling a host in the middle of a firmware flash.
 *
 * Neither method decides *whose* machine this is. That is settled before the
 * row is ever in hand, by fetching it through the acting customer's own
 * relation; a guard that took an id and checked ownership afterwards would
 * already have read another tenant's row.
 *
 * The mirror of this class exists in the Vps module. It is written out again
 * rather than shared, because the questions only look alike: a virtual machine
 * is asked whether its hypervisor has confirmed it, and a physical one is
 * asked whether an operator currently has it open on a bench.
 */
final class DedicatedOperationGuard
{
    /**
     * A machine that is installed, running, and nobody else's problem right
     * now.
     *
     * `active` and only `active`. Every other status is a machine that is in
     * the platform's or an operator's hands — see
     * {@see DedicatedOperationRefusedException} for what each one costs — and
     * "not active" is deliberately refused rather than queued: an operation
     * accepted now and carried out later is an operation whose preconditions
     * were true at a moment nobody recorded.
     *
     * @throws DedicatedOperationRefusedException
     */
    public function assertInService(DedicatedServer $server): void
    {
        if ($server->status !== DedicatedServerStatus::Active) {
            throw DedicatedOperationRefusedException::becauseServerIsNotInService(
                (string) $server->getKey(),
                $server->status,
            );
        }
    }

    /**
     * Nothing the platform started is still running against this machine.
     *
     * Two predicates, because a dedicated server can be named by a job in two
     * ways and missing either one lets a second operation through:
     *
     *  - the job's payload names the machine, which is how every job this
     *    module creates identifies its target;
     *  - the job names the service the machine fulfils, which is how a
     *    build, a suspend or a termination created elsewhere identifies it.
     *
     * A machine with no service row is matched by the first predicate alone
     * rather than by `service_id = null`, which would match every job in the
     * platform that has no service.
     *
     * @throws DedicatedOperationRefusedException
     */
    public function assertNothingInFlight(DedicatedServer $server): void
    {
        $serverId = (string) $server->getKey();
        $serviceId = $server->service_id;

        $live = ProvisioningJob::query()
            ->whereIn('status', [
                ProvisioningJobStatus::Queued->value,
                ProvisioningJobStatus::Running->value,
            ])
            ->where(static function (Builder $query) use ($serverId, $serviceId): void {
                $query->where('payload->dedicated_server_id', $serverId);

                if ($serviceId !== null) {
                    $query->orWhere('service_id', $serviceId);
                }
            })
            ->first();

        if ($live !== null) {
            throw DedicatedOperationRefusedException::becauseWorkIsAlreadyInFlight($serverId, $live->kind);
        }
    }

    /**
     * The platform is not in the middle of erasing this machine.
     *
     * Narrower than {@see self::assertNothingInFlight()} on purpose, and the
     * narrowness is the point. A power request is how a customer recovers a
     * host that has stopped listening, so refusing one because *any* job
     * touches the service — a billing suspend, a reconciliation sweep, a job
     * stuck in `running` since last week — would take their only recovery tool
     * away for reasons that have nothing to do with the chassis.
     *
     * A reinstall is the one exception. Its status is deliberately still
     * `active` while it waits and while it runs, because moving a delivered
     * machine out of service is the handler's decision and not an HTTP
     * request's — so the `active`-only check in
     * {@see self::assertInService()} lets a `cycle` straight through to a
     * machine whose disks are being partitioned. Resetting a host mid-install
     * is how a customer ends up with an unbootable machine and no operating
     * system on either side of the interruption.
     *
     * @throws DedicatedOperationRefusedException
     */
    public function assertNoReinstallInFlight(DedicatedServer $server): void
    {
        $serverId = (string) $server->getKey();
        $serviceId = $server->service_id;

        $live = ProvisioningJob::query()
            ->where('kind', ProvisioningJobKind::ReinstallDedicated->value)
            ->whereIn('status', [
                ProvisioningJobStatus::Queued->value,
                ProvisioningJobStatus::Running->value,
            ])
            ->where(static function (Builder $query) use ($serverId, $serviceId): void {
                $query->where('payload->dedicated_server_id', $serverId);

                // Matched by the machine first and by the service only as
                // well, never by the service alone: `service_id = null` would
                // match every serviceless job in the platform.
                if ($serviceId !== null) {
                    $query->orWhere('service_id', $serviceId);
                }
            })
            ->first();

        if ($live !== null) {
            throw DedicatedOperationRefusedException::becauseWorkIsAlreadyInFlight($serverId, $live->kind);
        }
    }
}
