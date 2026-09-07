<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Application\Handlers;

use Lynomia\Modules\Compute\Application\Actions\ReserveNodeCapacity;
use Lynomia\Modules\Compute\Domain\DTOs\CloudInitConfig;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\PlacementRequest;
use Lynomia\Modules\Compute\Domain\Enums\OsFamily;
use Lynomia\Modules\Compute\Domain\Enums\StorageClass;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Domain\Exceptions\NoCapacityAvailableException;
use Lynomia\Modules\Compute\Domain\Exceptions\NodeCapacityExceededException;
use Lynomia\Modules\Compute\Domain\Services\NodeScheduler;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Domain\Exceptions\IpPoolExhaustedException;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Provisioning\Domain\Contracts\ProvisioningHandler;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Builds a virtual machine: place it, reserve its address, create it, record it.
 *
 * The order is the design. Everything that can fail cheaply happens before
 * anything that cannot be undone:
 *
 *  1. **Place** — choosing a node is pure computation and costs nothing to redo.
 *  2. **Reserve capacity** — a database row, released by compensation.
 *  3. **Reserve an address** — a database row, released or quarantined by
 *     compensation depending on how the job ended.
 *  4. **Create the machine** — the first irreversible step, and the only one.
 *  5. **Commit the address and record the machine** — bookkeeping that follows
 *     a resource that already exists.
 *
 * Doing step 4 before steps 2 and 3 would mean a machine with no address and no
 * accounting, which is worse than no machine at all: it is invisible to billing,
 * to monitoring and to the destroy path.
 *
 * Every failure is classified, because the engine's retry and compensation
 * behaviour depends entirely on that classification — and getting it wrong is
 * how a customer ends up with two servers.
 */
final readonly class CreateVpsHandler implements ProvisioningHandler
{
    public function __construct(
        private NodeScheduler $scheduler,
        private ReserveNodeCapacity $reserveCapacity,
        private IpAllocator $ipAllocator,
        /*
         * Resolved per cluster rather than injected as "the" provider. The
         * driver is a property of the cluster the machine lands on, and a
         * platform with two clusters on different hypervisors — during a
         * migration, say — has no single correct default.
         */
        private ComputeProviderFactory $computeProviders,
        private SecretRedactor $redactor,
    ) {}

    public function kind(): ProvisioningJobKind
    {
        return ProvisioningJobKind::CreateVps;
    }

    public function execute(ProvisioningJob $job): ProvisioningResult
    {
        /** @var array<string, mixed> $payload */
        $payload = $job->payload;

        /*
         * The customer the job names has to be a customer that exists.
         *
         * IpAllocator::assertScopeMayServe() — the one check that stops a
         * management address being written onto a machine that runs customer
         * code — is skipped entirely when it is handed a null customer. Passing
         * null because the row could not be loaded therefore does not fail the
         * build, it disarms the guard; and Customer is soft-deleted, so a
         * cancelled customer's job takes that route silently. If the job names
         * a customer, that customer is either loaded or the job is refused.
         */
        $customer = null;

        if ($job->customer_id !== null) {
            $customer = Customer::query()->find($job->customer_id);

            if ($customer === null) {
                return ProvisioningResult::failed(
                    FailureClass::Permanent,
                    'provisioning.unknown_customer',
                    sprintf('No customer exists with the id "%s".', (string) $job->customer_id),
                    metadata: ['customer_id' => (string) $job->customer_id],
                );
            }
        }

        $resources = new VmResources(
            vcpu: (int) ($payload['vcpu'] ?? 1),
            memoryMib: (int) ($payload['memory_mib'] ?? 1024),
            diskGib: (int) ($payload['disk_gib'] ?? 10),
        );

        try {
            $decision = $this->scheduler->place(new PlacementRequest(
                clusterId: (string) $payload['cluster_id'],
                resources: $resources,
                customerId: $job->customer_id,
                storageClass: StorageClass::tryFrom((string) ($payload['storage_class'] ?? '')) ?? StorageClass::Nvme,
            ));
        } catch (NoCapacityAvailableException $e) {
            /*
             * Classified as capacity, not permanent. A full cluster is a
             * condition that resolves — a machine is destroyed, a node comes
             * back from maintenance, an operator adds hardware — so the job
             * waits and retries rather than refunding a customer who would
             * happily have waited an hour.
             */
            return ProvisioningResult::failed(
                FailureClass::Capacity,
                $e->errorCode(),
                $e->getMessage(),
                metadata: $this->redactor->redact($e->context()),
            );
        }

        $node = $decision->node();

        // Capacity first, keyed on the job so a retry commits once. Without the
        // key a retried job would commit a second machine's worth of capacity
        // that release can never give back — there is only one machine to
        // destroy.
        try {
            $this->reserveCapacity->execute(
                node: $node,
                resources: $resources,
                customerId: $job->customer_id,
                // Forwarded, never omitted. The scheduler's exclusion ran
                // before any lock and against machines that had already been
                // written — and this handler writes its virtual_machines row
                // only after the hypervisor has answered, so the window is the
                // whole length of the provider call. Re-counting under the
                // node's row lock is the only thing that stops three
                // concurrent orders from one customer landing on one node. A
                // null here is the scheduler's deliberate waiver and has to
                // travel as such.
                antiAffinityLimit: $decision->antiAffinityLimit,
                storageId: $decision->storageId ?? null,
                reservationKey: $job->idempotency_key,
                serviceId: $job->service_id,
            );
        } catch (NodeCapacityExceededException $e) {
            /*
             * The loser of a race the lock exists to expose: another order for
             * this customer took the last slot the anti-affinity limit allowed,
             * or filled the node, between scoring and this commitment. Capacity
             * rather than transient, and for the same reason placement uses it
             * — the next attempt scores the fleet again and lands somewhere
             * else. Nothing has been built.
             */
            return ProvisioningResult::failed(
                FailureClass::Capacity,
                $e->errorCode(),
                $e->getMessage(),
                metadata: $this->redactor->redact($e->context()),
            );
        }

        try {
            $reservations = $this->ipAllocator->reserve(
                scope: IpPool::query()->findOrFail((string) $payload['ip_pool_id']),
                provisioningJobId: (string) $job->getKey(),
                customer: $customer,
                count: (int) ($payload['ipv4_count'] ?? 1),
            );
        } catch (IpPoolExhaustedException $e) {
            // Also capacity: addresses are freed by quarantine expiry and by
            // terminations, so this resolves without anyone refunding anyone.
            return ProvisioningResult::failed(
                FailureClass::Capacity,
                $e->errorCode(),
                $e->getMessage(),
                metadata: $this->redactor->redact($e->context()),
            );
        }

        $primary = $reservations[0];
        $address = $primary->ipAddress()->firstOrFail();

        /*
         * Which layer-2 segment the machine is plugged into, taken from the
         * network the address actually belongs to.
         *
         * IPAM models this explicitly — a network carries the bridge, the VLAN
         * id and whether customers may be attached to it — and none of it
         * reaches the hypervisor unless it is passed here. Falling back to the
         * DTO's default bridge would put every customer machine, whatever
         * subnet it was allocated from, untagged on one bridge: on this
         * platform's own inventory that bridge is the one carried by the
         * node's management interface, so an untagged NIC lands on the native
         * VLAN beside the hypervisor and BMC management interfaces — the
         * lateral movement IpPoolScope::isCustomerAllocatable() exists to
         * close, bypassed one layer lower — and every tenant shares one
         * broadcast domain regardless of the VLAN their subnet names.
         *
         * A subnet with no network, an inactive or management network, or a
         * network with no bridge recorded is refused rather than guessed at:
         * the platform cannot say where the machine would be plugged in, and a
         * guess is exactly what is dangerous here.
         */
        $network = $address->subnet->network()->first();

        if ($network === null || ! $network->acceptsCustomerAttachments() || ($network->bridge ?? '') === '') {
            return ProvisioningResult::failed(
                FailureClass::Permanent,
                'vps.network_not_attachable',
                sprintf(
                    'The subnet holding %s names no customer-attachable network with a bridge, so there is nowhere to attach this machine.',
                    $address->address,
                ),
                metadata: [
                    'subnet_id' => (string) $address->subnet_id,
                    'network_id' => $network?->getKey(),
                ],
            );
        }

        try {
            $provider = $this->computeProviders->for($node->cluster()->firstOrFail());

            $operation = $provider->createVirtualMachine(new CreateVmRequest(
                nodeName: $node->provider_name,
                vmId: (int) ($payload['vm_id'] ?? random_int(10000, 99999)),
                hostname: (string) ($payload['hostname'] ?? 'vps-'.strtolower((string) $job->getKey())),
                vcpu: $resources->vcpu,
                memoryMib: $resources->memoryMib,
                diskGib: $resources->diskGib,
                storageName: $decision->storageName,
                templateReference: isset($payload['template_reference']) ? (string) $payload['template_reference'] : null,
                osFamily: OsFamily::tryFrom((string) ($payload['os_family'] ?? '')) ?? OsFamily::Debian,
                networkBridge: $network->bridge,
                vlanTag: $network->vlan_id,
                cloudInit: new CloudInitConfig(
                    sshKeys: array_values(array_filter((array) ($payload['ssh_keys'] ?? []), 'is_string')),
                    ipConfig: sprintf('ip=%s/%d,gw=%s', $address->address, $address->subnet->prefix_length, $address->subnet->gateway),
                ),
            ));
        } catch (ComputeProviderException $e) {
            /*
             * The adapter's own verdict on whether the request may still be in
             * flight is what decides the failure class, and it is the only
             * thing that can know: it saw the transport. A refusal the cluster
             * spoke out loud is transient — nothing was built, retry it. A
             * request that stopped being waited for is a TIMEOUT, which the
             * engine neither retries nor releases resources for, because the
             * machine may exist and retrying would build a second one.
             */
            return ProvisioningResult::failed(
                $e->isIndeterminate() ? FailureClass::Timeout : FailureClass::Transient,
                $e->errorCode(),
                $e->getMessage(),
                metadata: $this->redactor->redact($e->context()),
            );
        }

        $vm = VirtualMachine::query()->create([
            'service_id' => $job->service_id,
            'cluster_id' => $node->cluster_id,
            'node_id' => $node->getKey(),
            'provider_id' => $operation->providerId,
            // Recorded rather than forgotten: a reinstall has to create the
            // replacement disk on the storage this one is on, and its only
            // alternative is to guess at a tier the customer did not buy.
            'storage_name' => $decision->storageName,
            'hostname' => (string) ($payload['hostname'] ?? 'vps-'.strtolower((string) $job->getKey())),
            'vcpu' => $resources->vcpu,
            'memory_mib' => $resources->memoryMib,
            'disk_gib' => $resources->diskGib,
            'power_state' => 'running',
            'os_family' => (string) ($payload['os_family'] ?? 'debian'),
        ]);

        // The address is committed only now, against a machine that exists.
        // Committing earlier would leave an assignment pointing at nothing if
        // creation failed.
        foreach ($reservations as $reservation) {
            $this->ipAllocator->commit(
                reservation: $reservation,
                serviceId: $job->service_id,
                assignable: $vm,
            );
        }

        return ProvisioningResult::succeeded(
            remoteJobId: $operation->taskId,
            providerReference: $operation->providerId,
            metadata: [
                'node' => $node->provider_name,
                'placement_score' => $decision->score(),
                'primary_ipv4' => $address->address,
                'virtual_machine_id' => $vm->getKey(),
            ],
        );
    }
}
