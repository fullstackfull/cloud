<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Application\Handlers;

use Lynomia\Modules\Compute\Domain\DTOs\ResizeVmRequest;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Domain\Contracts\ProvisioningHandler;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Makes a machine the size the customer now pays for.
 *
 * Queued by a plan change, which is the only thing that creates this kind of
 * work. That matters for what this handler is allowed to assume: the money has
 * already moved, so refusing here leaves a customer paying for a plan their
 * server is not running — which is why every failure is either retryable or
 * loudly recorded, and none of them quietly succeeds.
 *
 * ---------------------------------------------------------------------------
 * Growth only, checked twice
 * ---------------------------------------------------------------------------
 *
 * A disk that shrinks truncates a filesystem. The plan-change quote refuses
 * such a downgrade before a customer can choose it, and this handler refuses
 * it again — because a job payload is not a request: it may have been written
 * by an older version of the quote, or by an operator tool, or by a replay of
 * something that was legal when it was queued. Two checks, and the second one
 * is the one that runs next to the provider call.
 *
 * ---------------------------------------------------------------------------
 * The row moves after the provider does
 * ---------------------------------------------------------------------------
 *
 * The machine's recorded shape is written only once the hypervisor has
 * confirmed it, and this is the opposite order from the power handler. A power
 * state that is briefly wrong is a display problem; a shape that is wrong is
 * capacity accounting that has drifted — the node believes it has memory it
 * does not, and the next customer placed on it is placed on a lie.
 */
final readonly class ResizeVpsHandler implements ProvisioningHandler
{
    public function __construct(
        private ComputeProviderFactory $computeProviders,
        private SecretRedactor $redactor,
    ) {}

    public function kind(): ProvisioningJobKind
    {
        return ProvisioningJobKind::Resize;
    }

    public function execute(ProvisioningJob $job): ProvisioningResult
    {
        /** @var array<string, mixed> $payload */
        $payload = $job->payload;

        $machineId = (string) ($payload['virtual_machine_id'] ?? '');
        $machine = $machineId === '' ? null : VirtualMachine::query()->find($machineId);

        if ($machine === null) {
            return ProvisioningResult::failed(
                FailureClass::Permanent,
                'vps.unknown_machine',
                'The job names a virtual machine that no longer exists.',
                metadata: ['virtual_machine_id' => $machineId],
            );
        }

        $node = $machine->node()->first();
        $cluster = $machine->cluster()->first();

        if (! $machine->existsRemotely() || $node === null || $cluster === null) {
            return ProvisioningResult::failed(
                FailureClass::Permanent,
                'vps.not_provisioned',
                'The machine has no confirmed provider id, node or cluster, so there is nothing to resize.',
                metadata: ['virtual_machine_id' => $machineId],
            );
        }

        $targetVcpu = $this->intOrNull($payload['vcpu'] ?? null);
        $targetMemory = $this->intOrNull($payload['memory_mib'] ?? null);
        $targetDisk = $this->intOrNull($payload['disk_gib'] ?? null);

        if ($targetDisk !== null && $targetDisk < $machine->disk_gib) {
            return ProvisioningResult::failed(
                FailureClass::Permanent,
                'vps.disk_shrink_refused',
                'A resize may not reduce a disk: the bytes past the new end would be gone.',
                metadata: [
                    'virtual_machine_id' => $machineId,
                    'current_disk_gib' => $machine->disk_gib,
                    'requested_disk_gib' => $targetDisk,
                ],
            );
        }

        /*
         * The disk field is a growth, not a size. The provider contract says
         * so and the Proxmox adapter's "+" prefix depends on it: an absolute
         * value smaller than the current one silently truncates, and one
         * larger would be applied twice on a retry.
         */
        $diskGrowth = $targetDisk === null || $targetDisk === $machine->disk_gib
            ? null
            : $targetDisk - $machine->disk_gib;

        $request = new ResizeVmRequest(
            vcpu: $targetVcpu === $machine->vcpu ? null : $targetVcpu,
            memoryMib: $targetMemory === $machine->memory_mib ? null : $targetMemory,
            diskGib: $diskGrowth,
        );

        if ($request->isEmpty()) {
            /*
             * The machine is already the shape the plan sells — a retry after
             * a successful resize, or a plan change that only moved the price.
             * Answered as a success: the state the caller wanted is the state
             * that exists.
             */
            return ProvisioningResult::succeeded(
                metadata: ['virtual_machine_id' => $machineId, 'already_correct' => true],
            );
        }

        try {
            $provider = $this->computeProviders->for($cluster);

            $operation = $provider->resizeVm($node->provider_name, (string) $machine->provider_id, $request);
        } catch (ComputeProviderException $e) {
            /*
             * Indeterminate becomes a TIMEOUT, which the engine escalates and
             * never retries. A resize the platform stopped waiting for may
             * have grown the disk; repeating it would grow it again, and the
             * customer would be billed for one upgrade and given two.
             */
            return ProvisioningResult::failed(
                $e->isIndeterminate() ? FailureClass::Timeout : FailureClass::Transient,
                $e->errorCode(),
                $e->getMessage(),
                metadata: $this->redactor->redact($e->context()),
            );
        }

        // Read back rather than assumed. This is the step that makes the
        // difference between "we asked" and "the machine is that size".
        $state = $provider->getVm($node->provider_name, (string) $machine->provider_id);

        if ($state === null) {
            return ProvisioningResult::failed(
                FailureClass::Permanent,
                'vps.resize_unverified',
                'The machine could not be read back after the resize.',
                metadata: ['virtual_machine_id' => $machineId],
            );
        }

        $machine->vcpu = $state->vcpu ?? $targetVcpu ?? $machine->vcpu;
        $machine->memory_mib = $state->memoryMib ?? $targetMemory ?? $machine->memory_mib;
        $machine->disk_gib = $state->diskGib ?? $targetDisk ?? $machine->disk_gib;
        $machine->save();

        return ProvisioningResult::succeeded(
            remoteJobId: $operation->taskId,
            providerReference: $operation->providerId,
            metadata: [
                'virtual_machine_id' => $machineId,
                'vcpu' => $machine->vcpu,
                'memory_mib' => $machine->memory_mib,
                'disk_gib' => $machine->disk_gib,
            ],
        );
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
