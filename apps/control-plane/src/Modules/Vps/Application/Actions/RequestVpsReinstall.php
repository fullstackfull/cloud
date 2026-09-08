<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordAuditEntry;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Provisioning\Application\Actions\CreateProvisioningJob;
use Lynomia\Modules\Provisioning\Application\DTOs\ProvisioningJobRequest;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Vps\Domain\Enums\ReinstallState;
use Lynomia\Modules\Vps\Domain\Exceptions\ReinstallConfirmationMismatchException;
use Lynomia\Modules\Vps\Domain\Services\VpsOperationGuard;
use Lynomia\Modules\Vps\Infrastructure\Models\VmReinstall;

/**
 * Ask the platform to wipe a machine and lay it down again.
 *
 * This is the most destructive thing on the customer surface, so it is also
 * the most heavily gated, and every gate is here rather than in a controller —
 * an operator tool or a support script reaching this action gets the same
 * refusals a customer does.
 *
 *  - The confirmation must name the machine. See
 *    {@see ReinstallConfirmationMismatchException} for why it is a hostname
 *    and not a flag.
 *  - The service must be active, and the machine must exist at the
 *    hypervisor. A rebuild of a machine that is still being built is a race
 *    with the build.
 *  - Nothing else may be in flight for the service.
 *  - The idempotency key is required, and a replay returns the first job
 *    rather than wiping the disk a second time. That is the whole point of
 *    step one: a client that retries after a dropped response must not
 *    reinstall twice.
 *
 * The template, when one is named, is resolved against what is actually
 * installable on this machine's own cluster. An id from the request body is
 * otherwise a way to ask for an image staged for somebody else's hardware.
 */
final readonly class RequestVpsReinstall
{
    public function __construct(
        private CreateProvisioningJob $createJob,
        private VpsOperationGuard $guard,
        private RecordAuditEntry $audit,
    ) {}

    /**
     * @param  string  $confirmation  must equal the machine's hostname
     * @param  list<string>  $sshKeys
     */
    public function execute(
        VirtualMachine $machine,
        string $confirmation,
        string $idempotencyKey,
        ?VmTemplate $template = null,
        array $sshKeys = [],
    ): ProvisioningJob {
        /*
         * Compared before anything else, and compared exactly — no trimming
         * of internal whitespace, no case folding. A hostname is
         * case-insensitive in DNS but the confirmation is not a lookup, it is
         * a proof that a person read the screen.
         */
        if (! hash_equals($machine->hostname, $confirmation)) {
            throw ReinstallConfirmationMismatchException::make();
        }

        $key = VpsIdempotencyKey::for($machine, 'reinstall', $idempotencyKey);

        $replay = ProvisioningJob::query()->where('idempotency_key', $key)->first();

        if ($replay !== null) {
            return $replay;
        }

        $this->guard->assertOperable($machine);
        $this->guard->assertNothingInFlight($machine);

        $job = $this->createJob->execute(new ProvisioningJobRequest(
            kind: ProvisioningJobKind::ReinstallVps,
            idempotencyKey: $key,
            provider: (string) ($machine->cluster()->first()?->driver->value ?? 'unknown'),
            serviceId: (string) $machine->service_id,
            customerId: $machine->service()->first()?->customer_id,
            payload: [
                'virtual_machine_id' => (string) $machine->getKey(),
                'hostname' => $machine->hostname,
                'template_id' => $template?->getKey(),
                'template_reference' => $template?->provider_reference,
                'os_family' => $template !== null ? $template->os_family->value : $machine->os_family,
                'ssh_keys' => $sshKeys,
            ],
            /*
             * One attempt, deliberately, against the engine's default of
             * three. Every other kind of job is safe to retry because it
             * either built nothing or built the thing it was asked for; a
             * reinstall that failed halfway has already destroyed the disk,
             * and a second automatic pass is a second destruction of whatever
             * the first one managed to lay down. A failed reinstall is a
             * person's decision.
             */
            maxAttempts: 1,
        ));

        /*
         * The operation record is written before the job is dispatched, and
         * that order matters. A customer who has just typed their hostname to
         * confirm a destructive act must see something happening; a record
         * created by the worker would appear only once a worker picked the job
         * up, which on a busy queue is a screen that says nothing for a minute
         * after the most frightening button on the platform.
         *
         * It also means a job that never reaches a worker leaves evidence that
         * a reinstall was asked for, rather than nothing at all.
         */
        $operation = VmReinstall::query()->create([
            'virtual_machine_id' => $machine->getKey(),
            'service_id' => $machine->service_id,
            'customer_id' => $machine->service()->first()?->customer_id,
            'provisioning_job_id' => $job->getKey(),
            'state' => ReinstallState::Requested,
            'state_changed_at' => now(),
            'template_id' => $template?->getKey(),
            'template_reference' => $template?->provider_reference,
            'provider_resource_id' => $machine->provider_id,
            'provider_node' => $machine->node()->first()?->provider_name,
        ]);

        /*
         * Recorded before the work is queued, and recorded on the request
         * rather than on the outcome. "Who asked for this machine to be
         * wiped, and when" is the question after a customer says they did not
         * — and it has to be answerable whether or not the rebuild then
         * succeeded.
         */
        $this->audit->execute(
            action: AuditAction::VpsReinstallRequested,
            subject: $machine,
            customerId: $machine->service()->first()?->customer_id,
            context: [
                'virtual_machine_id' => (string) $machine->getKey(),
                'hostname' => $machine->hostname,
                'provisioning_job_id' => (string) $job->getKey(),
                'reinstall_id' => (string) $operation->getKey(),
                'template_id' => $template?->getKey(),
            ],
        );

        if ($job->wasRecentlyCreated) {
            $operation->advanceTo(ReinstallState::Queued);

            RunProvisioningJob::dispatch((string) $job->getKey());
        }

        return $job;
    }
}
