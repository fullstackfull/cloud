<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\Contracts;

use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteNodeState;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteTaskState;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteVmState;
use Lynomia\Modules\Compute\Domain\DTOs\ResizeVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\VmOperation;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;

/**
 * Everything the platform is allowed to ask a hypervisor to do.
 *
 * Stated entirely in domain types: nothing above this line may accept an HTTP
 * response, catch a transport exception, or know that Proxmox exists. That
 * constraint is what makes adding a second hypervisor an additive change
 * rather than a rewrite of provisioning.
 *
 * Three rules bind every implementation:
 *
 *  - failures throw {@see ComputeProviderException}, never a client exception,
 *    and never with a credential in the message or the context;
 *  - every mutation returns a {@see VmOperation} carrying the provider's own
 *    task id, even when the provider happens to be synchronous. Building a
 *    machine takes minutes and the API answers in milliseconds, so the task id
 *    is the only thing that lets a worker which died mid-build find out what
 *    happened instead of building a second machine;
 *  - the read methods never mutate. getVm(), listVms(), getTask() and
 *    listNodes() are what reconciliation and inventory run on a schedule
 *    against the whole fleet, and a read path with a side effect there would
 *    be a fleet-wide side effect.
 */
interface ComputeProvider
{
    /**
     * The stable key this adapter is registered and persisted under. It is
     * written into compute_clusters.driver, so it must never change once a row
     * exists.
     */
    public function name(): string;

    /**
     * @throws ComputeProviderException
     */
    public function createVirtualMachine(CreateVmRequest $request): VmOperation;

    /**
     * @throws ComputeProviderException
     */
    public function startVm(string $nodeName, string $providerId): VmOperation;

    /**
     * Cut the power. Use only when the guest is unreachable — this is the
     * hypervisor equivalent of pulling the plug and can lose writes.
     *
     * @throws ComputeProviderException
     */
    public function stopVm(string $nodeName, string $providerId): VmOperation;

    /**
     * Ask the guest to shut itself down cleanly. Requires a guest agent or
     * ACPI support; the caller is responsible for deciding how long to wait
     * before escalating to stopVm().
     *
     * @throws ComputeProviderException
     */
    public function shutdownVm(string $nodeName, string $providerId): VmOperation;

    /**
     * @throws ComputeProviderException
     */
    public function rebootVm(string $nodeName, string $providerId): VmOperation;

    /**
     * The hard counterpart of rebootVm(), with the same data-loss risk as
     * stopVm().
     *
     * @throws ComputeProviderException
     */
    public function resetVm(string $nodeName, string $providerId): VmOperation;

    /**
     * @throws ComputeProviderException
     */
    public function resizeVm(string $nodeName, string $providerId, ResizeVmRequest $request): VmOperation;

    /**
     * @param  bool  $purge  Also remove the machine from backup jobs and HA
     *                       configuration, so nothing keeps referring to an id
     *                       that will be reissued to another customer.
     *
     * @throws ComputeProviderException
     */
    public function destroyVm(string $nodeName, string $providerId, bool $purge = true): VmOperation;

    /**
     * The hypervisor's view of one machine, or null when it does not have it.
     *
     * Null is a legitimate answer and specifically not an exception: a destroy
     * that is confirmed by absence, and a reconciliation pass finding a row
     * for a machine somebody deleted by hand, both depend on being able to ask
     * without handling a failure.
     *
     * @throws ComputeProviderException
     */
    public function getVm(string $nodeName, string $providerId): ?RemoteVmState;

    /**
     * @return list<RemoteVmState>
     *
     * @throws ComputeProviderException
     */
    public function listVms(string $nodeName): array;

    /**
     * @throws ComputeProviderException
     */
    public function getTask(string $nodeName, string $taskId): RemoteTaskState;

    /**
     * Every node in the cluster with its physical capacity.
     *
     * @return list<RemoteNodeState>
     *
     * @throws ComputeProviderException
     */
    public function listNodes(): array;
}
