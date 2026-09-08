<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Application\Actions\CreateProvisioningJob;
use Lynomia\Modules\Provisioning\Application\DTOs\ProvisioningJobRequest;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Vps\Domain\Exceptions\TerminationRefusedException;

/**
 * Ending a service, and destroying the machine behind it.
 *
 * The retention window is enforced here rather than left to whatever asked for
 * the termination, which is the same decision shared hosting made and for the
 * same reason: most suspensions are billing disputes that end with the
 * customer paying, and a termination that ran the moment a service was
 * suspended would turn a late invoice into a lost customer and a destroyed
 * dataset. Every automated path has to pass this check.
 *
 * The only way past it is an explicit override that a person asks for — an
 * abuse case, or a customer who wants their data deleted today — and the
 * override travels with the request so it is recorded rather than inferred.
 *
 * ---------------------------------------------------------------------------
 * Queued, not done here
 * ---------------------------------------------------------------------------
 *
 * The work is a provider call and a set of releases, so it belongs to the
 * engine: it needs the attempt accounting, the timeout classification and the
 * quarantine-on-timeout behaviour that every other provider call gets. What
 * this action does is decide that it may happen, and record the decision as a
 * job.
 */
final readonly class TerminateVpsService
{
    public function __construct(
        private CreateProvisioningJob $createJob,
    ) {}

    /**
     * @param  bool  $force  Skip the retention window. Reserved for an operator acting on an
     *                       explicit request. Never set by an automated path.
     *
     * @throws TerminationRefusedException
     */
    public function execute(Service $service, bool $force = false, ?string $idempotencyKey = null): ProvisioningJob
    {
        if ($service->status === ServiceStatus::Terminated) {
            throw TerminationRefusedException::becauseItIsAlreadyOver((string) $service->getKey());
        }

        $machine = VirtualMachine::query()->where('service_id', $service->getKey())->first();

        if ($machine === null) {
            throw TerminationRefusedException::becauseThereIsNoMachine((string) $service->getKey());
        }

        if (! $force) {
            $this->assertTheRetentionWindowHasElapsed($service);
        }

        $key = $idempotencyKey ?? 'terminate:'.$service->getKey();

        $replay = ProvisioningJob::query()->where('idempotency_key', $key)->first();

        if ($replay !== null) {
            return $replay;
        }

        $job = $this->createJob->execute(new ProvisioningJobRequest(
            kind: ProvisioningJobKind::DestroyVps,
            idempotencyKey: $key,
            provider: (string) ($machine->cluster()->first()?->driver->value ?? 'unknown'),
            serviceId: (string) $service->getKey(),
            customerId: $service->customer_id,
            payload: [
                'virtual_machine_id' => (string) $machine->getKey(),
                'hostname' => $machine->hostname,
                'forced' => $force,
            ],
            /*
             * One attempt for the destructive part, like a rebuild. A destroy
             * that failed at the hypervisor is a machine that still exists,
             * and the engine's own transient retry — which this leaves in
             * place for a refusal — is enough; what must not happen
             * automatically is a second pass after a timeout, where the
             * platform does not know whether the machine is gone.
             */
            maxAttempts: 2,
        ));

        if ($job->wasRecentlyCreated) {
            RunProvisioningJob::dispatch((string) $job->getKey());
        }

        return $job;
    }

    /**
     * @throws TerminationRefusedException
     */
    private function assertTheRetentionWindowHasElapsed(Service $service): void
    {
        if ($service->status !== ServiceStatus::Suspended) {
            /*
             * An active service is one somebody is still paying for. The path
             * to termination runs through suspension, so that a customer who
             * pays late gets their machine back rather than a condolence
             * message.
             */
            throw TerminationRefusedException::becauseItIsStillInService(
                (string) $service->getKey(),
                $service->status,
            );
        }

        $days = max(0, (int) config('provisioning.termination.suspended_retention_days', 30));

        $suspendedAt = $service->suspended_at;

        $releasesAt = $suspendedAt === null
            // No timestamp means the platform cannot prove the window has
            // passed, and the safe reading of an unprovable window is that it
            // has not.
            ? CarbonImmutable::now()->addDays($days)
            : $suspendedAt->addDays($days);

        if ($releasesAt->isFuture()) {
            throw TerminationRefusedException::becauseTheRetentionWindowIsOpen(
                (string) $service->getKey(),
                $releasesAt->toIso8601String(),
            );
        }
    }
}
