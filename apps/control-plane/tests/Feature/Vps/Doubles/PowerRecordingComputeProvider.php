<?php

declare(strict_types=1);

namespace Tests\Feature\Vps\Doubles;

use Lynomia\Modules\Compute\Domain\Contracts\ComputeProvider;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteTaskState;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteVmState;
use Lynomia\Modules\Compute\Domain\DTOs\ResizeVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\VmOperation;
use Lynomia\Modules\Compute\Domain\Enums\RemoteTaskStatus;
use Lynomia\Modules\Compute\Domain\Enums\SuspensionPolicy;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;

/**
 * A hypervisor that records what it was asked and builds nothing.
 *
 * The fake provider cannot serve here: it refuses any machine it did not
 * itself create, and these tests start from a machine the factory wrote
 * straight into the database. What is under test is which method the handler
 * chooses, which is exactly what this records.
 */
final class PowerRecordingComputeProvider implements ComputeProvider
{
    /** @var list<string> */
    public array $calls = [];

    public ?ComputeProviderException $failWith = null;

    public function name(): string
    {
        return 'recording';
    }

    public function createVirtualMachine(CreateVmRequest $request): VmOperation
    {
        return $this->record('createVirtualMachine', $request->nodeName, (string) $request->vmId);
    }

    public function startVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->record('startVm', $nodeName, $providerId);
    }

    public function stopVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->record('stopVm', $nodeName, $providerId);
    }

    public function shutdownVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->record('shutdownVm', $nodeName, $providerId);
    }

    public function rebootVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->record('rebootVm', $nodeName, $providerId);
    }

    public function resetVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->record('resetVm', $nodeName, $providerId);
    }

    public function resizeVm(string $nodeName, string $providerId, ResizeVmRequest $request): VmOperation
    {
        return $this->record('resizeVm', $nodeName, $providerId);
    }

    public function destroyVm(string $nodeName, string $providerId, bool $purge = true): VmOperation
    {
        return $this->record('destroyVm', $nodeName, $providerId);
    }

    public function suspendVm(string $nodeName, string $providerId, SuspensionPolicy $policy): VmOperation
    {
        // Recorded like every other call, so a test asserting that a code path
        // does NOT suspend a machine has something to assert against.
        $this->calls[] = 'suspendVm';

        return new VmOperation('UPID:suspend', $nodeName, $providerId, 'suspend_vm');
    }

    public function liftSuspension(string $nodeName, string $providerId): VmOperation
    {
        $this->calls[] = 'liftSuspension';

        return new VmOperation('UPID:unsuspend', $nodeName, $providerId, 'lift_suspension');
    }

    public function getVm(string $nodeName, string $providerId): ?RemoteVmState
    {
        return null;
    }

    public function listVms(string $nodeName): array
    {
        return [];
    }

    public function getTask(string $nodeName, string $taskId): RemoteTaskState
    {
        return new RemoteTaskState(
            $taskId,
            $nodeName,
            RemoteTaskStatus::Succeeded,
        );
    }

    public function listNodes(): array
    {
        return [];
    }

    private function record(string $method, string $nodeName, string $providerId): VmOperation
    {
        $this->calls[] = $method;

        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        return new VmOperation(
            taskId: 'UPID:'.$nodeName.':'.$method.':'.$providerId,
            nodeName: $nodeName,
            providerId: $providerId,
            operation: $method,
        );
    }
}
