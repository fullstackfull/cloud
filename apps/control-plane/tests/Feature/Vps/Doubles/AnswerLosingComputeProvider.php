<?php

declare(strict_types=1);

namespace Tests\Feature\Vps\Doubles;

use Closure;
use Lynomia\Modules\Compute\Domain\Contracts\ComputeProvider;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\ReinstallVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteConsoleEndpoint;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteTaskState;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteVmState;
use Lynomia\Modules\Compute\Domain\DTOs\ResizeVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\VmOperation;
use Lynomia\Modules\Compute\Domain\Enums\SuspensionPolicy;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use Throwable;

/**
 * The controlled hypervisor, with one thing added: it can build a machine and
 * then lose the answer.
 *
 * That is the case F-15 is about and the one the plain fake cannot produce on
 * its own terms — its timeout marker records nothing, so "the call timed out"
 * and "nothing exists" always coincide there. On a real cluster they do not:
 * `qmcreate` keeps running after the HTTP request that started it has been
 * abandoned. Everything else is delegated, so the fleet this wraps is the one
 * every assertion reads.
 *
 * It also records what it was asked, and can run a probe at the instant a
 * create is sent — which is how a test asks "had the platform written the
 * identity down BEFORE it called?" rather than "was it written by the end?".
 */
final class AnswerLosingComputeProvider implements ComputeProvider
{
    public bool $loseTheAnswerToCreates = true;

    /** @var list<CreateVmRequest> */
    public array $creates = [];

    /** @var ?Closure(CreateVmRequest): void */
    public ?Closure $atTheMomentOfCreate = null;

    /**
     * When set, thrown once the machine is built: the worker process dying
     * between the cluster accepting a create and anything being written down
     * about it. Not a provider exception, so nothing in the handler catches it.
     */
    public ?Throwable $dieAfterBuilding = null;

    /** When set, every read of a machine fails with this, as an unreachable node does. */
    public ?ComputeProviderException $failReadsWith = null;

    public function __construct(public readonly FakeComputeProvider $fleet = new FakeComputeProvider) {}

    public function name(): string
    {
        return $this->fleet->name();
    }

    public function createVirtualMachine(CreateVmRequest $request): VmOperation
    {
        $this->creates[] = $request;

        if ($this->atTheMomentOfCreate !== null) {
            ($this->atTheMomentOfCreate)($request);
        }

        $operation = $this->fleet->createVirtualMachine($request);

        if ($this->dieAfterBuilding !== null) {
            throw $this->dieAfterBuilding;
        }

        if (! $this->loseTheAnswerToCreates) {
            return $operation;
        }

        throw ComputeProviderException::requestFailed($this->name(), 'create_vm', [
            'node' => $request->nodeName,
            'vmid' => $request->vmId,
            'provider_message' => 'the answer to this create was lost after the cluster accepted it',
        ], indeterminate: true);
    }

    public function startVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->fleet->startVm($nodeName, $providerId);
    }

    public function stopVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->fleet->stopVm($nodeName, $providerId);
    }

    public function shutdownVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->fleet->shutdownVm($nodeName, $providerId);
    }

    public function rebootVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->fleet->rebootVm($nodeName, $providerId);
    }

    public function resetVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->fleet->resetVm($nodeName, $providerId);
    }

    public function resizeVm(string $nodeName, string $providerId, ResizeVmRequest $request): VmOperation
    {
        return $this->fleet->resizeVm($nodeName, $providerId, $request);
    }

    public function destroyVm(string $nodeName, string $providerId, bool $purge = true): VmOperation
    {
        return $this->fleet->destroyVm($nodeName, $providerId, $purge);
    }

    public function suspendVm(string $nodeName, string $providerId, SuspensionPolicy $policy): VmOperation
    {
        return $this->fleet->suspendVm($nodeName, $providerId, $policy);
    }

    public function liftSuspension(string $nodeName, string $providerId): VmOperation
    {
        return $this->fleet->liftSuspension($nodeName, $providerId);
    }

    public function reinstallVm(string $nodeName, string $providerId, ReinstallVmRequest $request): VmOperation
    {
        return $this->fleet->reinstallVm($nodeName, $providerId, $request);
    }

    public function consoleEndpoint(string $nodeName, string $providerId): RemoteConsoleEndpoint
    {
        return $this->fleet->consoleEndpoint($nodeName, $providerId);
    }

    public function getVm(string $nodeName, string $providerId): ?RemoteVmState
    {
        if ($this->failReadsWith !== null) {
            throw $this->failReadsWith;
        }

        return $this->fleet->getVm($nodeName, $providerId);
    }

    public function listVms(string $nodeName): array
    {
        return $this->fleet->listVms($nodeName);
    }

    public function getTask(string $nodeName, string $taskId): RemoteTaskState
    {
        return $this->fleet->getTask($nodeName, $taskId);
    }

    public function listNodes(): array
    {
        return $this->fleet->listNodes();
    }

    /**
     * Every machine on every node this fleet knows, which is what "one
     * machine, not two" is counted against.
     *
     * @return list<RemoteVmState>
     */
    public function everyMachine(): array
    {
        $machines = [];

        foreach ($this->fleet->listNodes() as $node) {
            array_push($machines, ...$this->fleet->listVms($node->name));
        }

        return $machines;
    }
}
