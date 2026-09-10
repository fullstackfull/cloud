<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Application\Actions;

use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Application\Actions\CreateProvisioningJob;
use Lynomia\Modules\Provisioning\Application\DTOs\ProvisioningJobRequest;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Vps\Domain\Enums\PowerAction;
use Lynomia\Modules\Vps\Domain\Services\VpsOperationGuard;

/**
 * Ask the platform to change a machine's power state.
 *
 * An action rather than controller code, so a queue worker, a console command
 * and an operator surface can request the same thing without going through
 * HTTP — and so the refusals below cannot be skipped by whichever caller comes
 * next.
 *
 * Nothing here talks to a hypervisor. The work is recorded as a provisioning
 * job and executed by the engine, which owns claiming, attempt accounting,
 * failure classification and — the part a controller could never get right —
 * the rule that a timed-out operation is never retried automatically.
 *
 * The order of the four steps is the design:
 *
 *  1. **Replay first.** A repeated idempotency key returns the job that
 *     already exists, before any guard runs. Without this, a client retrying
 *     after a dropped response would be told its own in-flight job is a
 *     conflict — a 409 for doing exactly what a retry is supposed to do.
 *  2. **Then the guards.** Not active, not provisioned, or something already
 *     running: refused, not queued.
 *  3. **Then the insert**, which is where idempotency is actually decided —
 *     by a unique constraint, in one statement, not by the read in step 1.
 *  4. **Dispatch only what was inserted.** Dispatching for a job that already
 *     existed is how a job that is mid-flight acquires a second worker.
 */
final readonly class RequestVpsPowerChange
{
    public function __construct(
        private CreateProvisioningJob $createJob,
        private VpsOperationGuard $guard,
    ) {}

    public function execute(
        VirtualMachine $machine,
        PowerAction $action,
        string $idempotencyKey,
        ?string $requestedByUserId = null,
    ): ProvisioningJob {
        $key = VpsIdempotencyKey::for($machine, $action->value, $idempotencyKey);

        $replay = ProvisioningJob::query()->where('idempotency_key', $key)->first();

        if ($replay !== null) {
            return $replay;
        }

        $this->guard->assertOperable($machine);
        $this->guard->assertNothingInFlight($machine);

        $job = $this->createJob->execute(new ProvisioningJobRequest(
            kind: $action->jobKind(),
            idempotencyKey: $key,
            /*
             * The cluster's driver, read from the machine's own cluster row
             * rather than from a global default. A platform mid-migration runs
             * two clusters on two hypervisors, and the job has to record which
             * one this machine is actually on.
             */
            provider: (string) ($machine->cluster()->first()?->driver->value ?? 'unknown'),
            serviceId: (string) $machine->service_id,
            customerId: $machine->service()->first()?->customer_id,
            requestedByUserId: $requestedByUserId,
            payload: [
                'virtual_machine_id' => (string) $machine->getKey(),
                /*
                 * The distinction the kind cannot carry. `stop` and `shutdown`
                 * are both ProvisioningJobKind::Stop, and pulling the plug on
                 * a customer who asked the guest politely is a data-loss bug,
                 * so the handler reads this field and never the kind.
                 */
                'power_action' => $action->value,
                'graceful' => $action->isGraceful(),
            ],
            /*
             * One attempt for a graceful request, the engine's default for the
             * rest. A guest that ignored an ACPI shutdown will ignore the next
             * two as well, and three attempts only delay the moment the
             * customer is told it did not work — while leaving them believing
             * the machine is on its way down.
             */
            maxAttempts: $action->isGraceful() ? 1 : null,
        ));

        if ($job->wasRecentlyCreated) {
            RunProvisioningJob::dispatch((string) $job->getKey());
        }

        return $job;
    }
}
