<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Application\Handlers;

use Lynomia\Modules\Compute\Application\Actions\ReserveNodeCapacity;
use Lynomia\Modules\Compute\Domain\Contracts\ComputeProvider;
use Lynomia\Modules\Compute\Domain\DTOs\CloudInitConfig;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\PlacementRequest;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteVmState;
use Lynomia\Modules\Compute\Domain\Enums\CpuArchitecture;
use Lynomia\Modules\Compute\Domain\Enums\OsFamily;
use Lynomia\Modules\Compute\Domain\Enums\StorageClass;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Domain\Exceptions\NoCapacityAvailableException;
use Lynomia\Modules\Compute\Domain\Exceptions\NodeCapacityExceededException;
use Lynomia\Modules\Compute\Domain\Services\NodeScheduler;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Domain\Exceptions\IpPoolExhaustedException;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Provisioning\Domain\Contracts\ProvisioningHandler;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ReservedProviderIdentity;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Builds a virtual machine: place it, reserve its address, create it, record it.
 *
 * The order is the design. Everything that can fail cheaply happens before
 * anything that cannot be undone:
 *
 *  1. **Place** — choosing a node is pure computation and costs nothing to redo.
 *  2. **Reserve the machine's identity, and look under it** — the hypervisor
 *     id this build asks for is written onto the job, and the provider is
 *     asked whether a machine already exists under it, before anything is
 *     reserved: a machine found there ends the attempt with nothing of its
 *     own to undo. See "One identity per build" below.
 *  3. **Reserve capacity** — a database row, released by compensation.
 *  4. **Reserve an address** — a database row, released or quarantined by
 *     compensation depending on how the job ended.
 *  5. **Create the machine** — the first irreversible step, and the only one.
 *  6. **Commit the address and record the machine** — bookkeeping that follows
 *     a resource that already exists.
 *
 * Doing step 5 before steps 3 and 4 would mean a machine with no address and no
 * accounting, which is worse than no machine at all: it is invisible to billing,
 * to monitoring and to the destroy path.
 *
 * Every failure is classified, because the engine's retry and compensation
 * behaviour depends entirely on that classification — and getting it wrong is
 * how a customer ends up with two servers.
 *
 * ---------------------------------------------------------------------------
 * One identity per build (F-15)
 * ---------------------------------------------------------------------------
 *
 * The id used to be a fresh `random_int` on every attempt, written down
 * nowhere until the provider answered. A create whose answer was lost — the
 * cluster accepted it, the request was abandoned — therefore left the job with
 * no task id and no provider reference, and `RetryProvisioningJob`'s
 * "something was built" refusal had nothing to read. The engine never retries
 * a timeout, but an operator could, and the retry drew a new id and built a
 * second machine beside the first: one billed, one orphaned and holding a
 * customer's address.
 *
 * Now every attempt of one job asks for one identity, reserved on the job row
 * before the call (`ProvisioningJob::reserveProviderIdentity()`), and every
 * attempt first asks the hypervisor what is already there under it, on every
 * node a create under it was ever sent to. What it finds decides the attempt:
 *
 *  - **Nothing** — build.
 *  - **A machine named as this job called it** — this build's own, left by an
 *    attempt whose answer was lost. Nothing new is built; the attempt
 *    settles for review carrying the machine as its provider reference, so
 *    the retry refusal holds from then on and the way out is adoption.
 *  - **A machine named otherwise** — somebody else's. `vps.create_identity_taken`
 *    with reason `named_otherwise`: the one finding that licenses an operator
 *    to move this job to a new identity (`RepointReservedIdentity`).
 *  - **A machine whose ownership cannot be established** — reported with no
 *    name, which is what Proxmox does while `qmcreate` is still running, or
 *    named as called but shaped otherwise. `vps.create_identity_taken` with
 *    reason `unnamed` or `shape_differs`, settled for review, and NOT a
 *    licence to repoint: "named nothing" read as "not ours" is exactly the
 *    mismatch that would let the job build around its own half-built machine.
 *
 * What is claimed as "ours" is claimed on the name, compared exactly against
 * every name a create under this identity sent. The residual is a machine
 * whose name was changed at the hypervisor after this job built it: it reads
 * as a stranger. Tags or the config lock could establish ownership positively
 * and were not taken up; that is a recorded design choice, not an
 * impossibility.
 */
final readonly class CreateVpsHandler implements ProvisioningHandler
{
    /** A machine exists at the reserved identity and it is not established as this build's. */
    public const string IDENTITY_TAKEN = 'vps.create_identity_taken';

    /** A machine exists at the reserved identity and it is this build's own. */
    public const string FOUND_ITS_OWN_BUILD = 'vps.create_found_its_own_build';

    /** The job holds an identity reserved against a different cluster than its payload names. */
    public const string IDENTITY_RESERVED_ELSEWHERE = 'vps.create_identity_reserved_elsewhere';

    /** The hypervisor could not be asked what is at the reserved identity. */
    public const string IDENTITY_UNVERIFIABLE = 'vps.create_identity_unverifiable';

    /** The machine found is named, and not with any name this job called with. */
    public const string REASON_NAMED_OTHERWISE = 'named_otherwise';

    /** The machine found reports no name, so whose it is cannot be read off it. */
    public const string REASON_UNNAMED = 'unnamed';

    /** The machine found carries a name this job called with, and a shape it did not ask for. */
    public const string REASON_SHAPE_DIFFERS = 'shape_differs';

    /** The machine found carries a name this job called with and nothing that contradicts it. */
    public const string REASON_NAMED_AS_CALLED = 'named_as_called';

    /**
     * The range a derived id is drawn from: five digits, clear of the low ids
     * operators hand out by hand and of Proxmox's own reserved range below 100.
     */
    public const int FIRST_DERIVED_ID = 10000;

    public const int DERIVED_ID_SPAN = 90000;

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

        $templateReference = isset($payload['template_reference']) ? trim((string) $payload['template_reference']) : '';

        if ($templateReference === '') {
            /*
             * Refused before a node is reserved, and refused permanently.
             *
             * A machine built with no image is a machine with an empty disk:
             * it boots to a firmware prompt, answers nothing, and looks to the
             * customer exactly like hardware that does not work. Retrying
             * cannot add an image, so this is not transient — it is a
             * placement the catalogue never completed, and it needs an
             * operator to stage an image or name one on the plan.
             *
             * `ProvisionOrderedService` resolves the image at the purchase and
             * refuses to create a job without one, so reaching here means a
             * job written by something else. The guard stays because the cost
             * of the two disagreeing is a customer paying for an empty disk.
             */
            return ProvisioningResult::failed(
                FailureClass::Permanent,
                'vps.create_image_unavailable',
                'This build names no OS image, so there is nothing to install; the plan or the cluster needs one.',
                metadata: ['cluster_id' => (string) ($payload['cluster_id'] ?? '')],
            );
        }

        $clusterId = (string) ($payload['cluster_id'] ?? '');

        if ($job->reserved_cluster_id !== null && $job->reserved_cluster_id !== '' && $job->reserved_cluster_id !== $clusterId) {
            /*
             * Refused before anything is reserved. The identity this job holds
             * means something only in the cluster it was reserved against, and
             * an earlier attempt may have built under it THERE — looking for
             * that build in another cluster finds nothing and builds again,
             * which is the second machine the identity exists to prevent.
             *
             * Nothing on the platform changes a job's payload, so a payload
             * naming another cluster came from outside it; there is no route
             * from the operator's screen to put it back, and this attempt does
             * not guess which of the two is right.
             */
            return ProvisioningResult::failed(
                FailureClass::Permanent,
                self::IDENTITY_RESERVED_ELSEWHERE,
                sprintf(
                    'This build holds provider identity %s reserved on cluster %s, but its payload now names cluster %s; it will not build under an identity reserved elsewhere.',
                    (string) $job->reserved_provider_id,
                    $job->reserved_cluster_id,
                    $clusterId,
                ),
                metadata: [
                    'reserved_provider_id' => $job->reserved_provider_id,
                    'reserved_cluster_id' => $job->reserved_cluster_id,
                    'cluster_id' => $clusterId,
                ],
            );
        }

        /*
         * An identity this job already holds is looked under first, before
         * this attempt is even placed. When an earlier
         * attempt's build is there, this attempt must end without having
         * taken anything: the machine is configured with the earlier
         * attempt's address, and an address reserved now would be quarantined
         * by the settle for nothing — permanently, since a timeout's
         * quarantine waits for a person.
         */
        $held = $job->reservedProviderIdentity();

        if ($held !== null && $held->nodes !== []) {
            try {
                $found = $this->whatIsAlreadyThere(
                    $this->computeProviders->for(ComputeCluster::query()->findOrFail($held->clusterId)),
                    $held,
                    $held->nodes,
                );
            } catch (ComputeProviderException $e) {
                return $this->becauseTheHypervisorCannotBeAsked($held, $e);
            }

            if ($found !== null) {
                return $this->becauseSomethingIsAlreadyThere($found, $held, $resources);
            }
        }

        try {
            $decision = $this->scheduler->place(new PlacementRequest(
                clusterId: (string) $payload['cluster_id'],
                resources: $resources,
                customerId: $job->customer_id,
                storageClass: StorageClass::tryFrom((string) ($payload['storage_class'] ?? '')) ?? StorageClass::Nvme,
                // The image's architecture, not the default. A node of the
                // wrong architecture cannot run the image, and
                // NodeCapacityPolicy already refuses that pairing — it was
                // never being told which architecture to refuse.
                architecture: CpuArchitecture::tryFrom((string) ($payload['architecture'] ?? ''))
                    ?? CpuArchitecture::X86_64,
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

        $hostname = $this->hostnameFor($job, $payload);

        /*
         * Written down before the provider is asked anything, and committed —
         * this is a standalone statement, not part of any transaction the
         * engine holds — so a worker that dies during the call, or a call
         * whose answer is lost, leaves the identity behind for the next
         * attempt to look under. Placed before capacity and the address, so
         * that a machine found at the identity costs this attempt nothing.
         */
        $identity = $job->reserveProviderIdentity(
            providerId: (string) $this->vmIdFor($job, $payload),
            clusterId: (string) $node->cluster_id,
            nodeName: $node->provider_name,
            hostname: $hostname,
        );

        if ($identity === null) {
            return ProvisioningResult::failed(
                FailureClass::Permanent,
                self::IDENTITY_RESERVED_ELSEWHERE,
                sprintf(
                    'This build holds a provider identity reserved on a cluster other than %s; it will not build under an identity reserved elsewhere.',
                    (string) $node->cluster_id,
                ),
                metadata: ['cluster_id' => (string) $node->cluster_id],
            );
        }

        try {
            $provider = $this->computeProviders->for($node->cluster()->firstOrFail());

            // The nodes not already looked at above: on a first attempt, the
            // one this attempt was placed on; on a later one, a node no
            // earlier attempt was sent to, where a stranger may be sitting.
            $found = $this->whatIsAlreadyThere(
                $provider,
                $identity,
                array_values(array_diff($identity->nodes, $held !== null ? $held->nodes : [])),
            );
        } catch (ComputeProviderException $e) {
            return $this->becauseTheHypervisorCannotBeAsked($identity, $e);
        }

        if ($found !== null) {
            return $this->becauseSomethingIsAlreadyThere($found, $identity, $resources);
        }

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
            $operation = $provider->createVirtualMachine(new CreateVmRequest(
                nodeName: $node->provider_name,
                vmId: (int) $identity->providerId,
                hostname: $hostname,
                vcpu: $resources->vcpu,
                memoryMib: $resources->memoryMib,
                diskGib: $resources->diskGib,
                storageName: $decision->storageName,
                templateReference: $templateReference,
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
                metadata: [
                    ...$this->redactor->redact($e->context()),
                    // Which identity the lost answer was about, so the screen
                    // can say where to look without a query.
                    'reserved_provider_id' => $identity->providerId,
                ],
            );
        }

        /*
         * Written the moment the handle exists, with the node it belongs to,
         * rather than only on return. Two reasons, and the second is the one
         * that matters: a worker that dies between here and the end must leave
         * the handle behind, and the task poller needs to know which node to
         * ask about it — a UPID without a node is a handle nothing can ask
         * about.
         */
        $job->recordRemoteJobId($operation->taskId, $node->provider_name);

        $vm = VirtualMachine::query()->create([
            'service_id' => $job->service_id,
            'cluster_id' => $node->cluster_id,
            'node_id' => $node->getKey(),
            'provider_id' => $operation->providerId,
            // Recorded rather than forgotten: a reinstall has to create the
            // replacement disk on the storage this one is on, and its only
            // alternative is to guess at a tier the customer did not buy.
            'storage_name' => $decision->storageName,
            'hostname' => $hostname,
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

    /**
     * The hypervisor id this job asks for.
     *
     * In order: the identity the job already holds — so every attempt of one
     * build asks for the same id, which is the whole of F-15's repair; then
     * an id named on the payload, which is how a migration pins a specific id
     * for a job that has not yet reserved one; then an id derived from the
     * idempotency key, which is fixed for the life of the order item.
     *
     * The payload's id is consulted only while nothing is reserved. Once an
     * identity exists it is the job's, and the only thing that changes it is
     * `RepointReservedIdentity` — an operator action that refuses every case
     * in which a new identity could mean a second machine.
     *
     * A derived id can collide with a machine somebody else already has: over
     * a 90,000-id span the chance that some two of N machines share one is
     * about 5% at N = 100 and about 39% at N = 300, so at scale it is an
     * operational certainty rather than an edge case. That is why the create
     * looks before it builds, and why a stranger at the id is a finding an
     * operator can act on rather than a dead end.
     *
     * @param  array<string, mixed>  $payload
     */
    private function vmIdFor(ProvisioningJob $job, array $payload): int
    {
        if ($job->reserved_provider_id !== null && is_numeric($job->reserved_provider_id)) {
            return (int) $job->reserved_provider_id;
        }

        if (isset($payload['vm_id']) && is_numeric($payload['vm_id'])) {
            return (int) $payload['vm_id'];
        }

        return self::derivedId($job->idempotency_key);
    }

    /**
     * An id derived from a key, in the platform's five-digit range.
     *
     * Public so the repoint action draws its replacement ids from the same
     * range by the same rule, rather than keeping a second copy of it.
     */
    public static function derivedId(string $key): int
    {
        return self::FIRST_DERIVED_ID + (crc32($key) % self::DERIVED_ID_SPAN);
    }

    /**
     * The name this job asks the hypervisor to give the machine.
     *
     * Read from the payload and nowhere else. `provisioning_jobs.payload` has
     * exactly one writer in `src/` — the statement that creates the row — so
     * this is the same name on every attempt, which is what makes the name a
     * machine reports usable as evidence that it is this build's.
     *
     * @param  array<string, mixed>  $payload
     */
    private function hostnameFor(ProvisioningJob $job, array $payload): string
    {
        return (string) ($payload['hostname'] ?? 'vps-'.strtolower((string) $job->getKey()));
    }

    /**
     * What the hypervisor already has at this job's identity, on the nodes
     * given.
     *
     * Every node a create under the identity was sent to, between the two
     * calls in execute(), not only the one this attempt was placed on:
     * placement is recomputed per attempt, and the machine an earlier attempt
     * built is on the node THAT attempt chose.
     *
     * @param  list<string>  $nodes
     *
     * @throws ComputeProviderException
     */
    private function whatIsAlreadyThere(ComputeProvider $provider, ReservedProviderIdentity $identity, array $nodes): ?RemoteVmState
    {
        foreach ($nodes as $nodeName) {
            $machine = $provider->getVm($nodeName, $identity->providerId);

            if ($machine !== null) {
                return $machine;
            }
        }

        return null;
    }

    /**
     * Transient: nothing has been sent to build anything, so the engine may
     * try again — and the next attempt asks again before it builds. What it
     * must not do is build without having asked.
     */
    private function becauseTheHypervisorCannotBeAsked(ReservedProviderIdentity $identity, ComputeProviderException $e): ProvisioningResult
    {
        return ProvisioningResult::failed(
            FailureClass::Transient,
            self::IDENTITY_UNVERIFIABLE,
            sprintf(
                'The hypervisor could not be asked whether a machine already exists at provider identity %s, so nothing was built.',
                $identity->providerId,
            ),
            metadata: [
                ...$this->redactor->redact($e->context()),
                'reserved_provider_id' => $identity->providerId,
            ],
        );
    }

    /**
     * Settle an attempt that found a machine where it was about to build one.
     *
     * Never builds. What differs is what the finding licenses, and the rule
     * is that anything short of "this is somebody else's, by name" is treated
     * as possibly ours: a machine that is ours but called a stranger is one
     * the job may be moved away from and built again around, which is the
     * defect; a stranger called "possibly ours" costs a person a look.
     */
    private function becauseSomethingIsAlreadyThere(
        RemoteVmState $machine,
        ReservedProviderIdentity $identity,
        VmResources $resources,
    ): ProvisioningResult {
        $reason = $this->whoseItIs($machine, $identity, $resources);

        $metadata = [
            'reason' => $reason,
            'reserved_provider_id' => $identity->providerId,
            'node' => $machine->nodeName,
            'found_name' => $machine->name,
            'found_vcpu' => $machine->vcpu,
            'found_memory_mib' => $machine->memoryMib,
            'called_names' => $identity->hostnames,
        ];

        if ($reason === self::REASON_NAMED_AS_CALLED) {
            /*
             * This build's own machine, left by an attempt whose answer was
             * lost. Carried as the provider reference, so the retry refusal
             * holds from here on and the only way forward is adoption; and a
             * timeout, so the addresses the machine was configured with are
             * quarantined rather than handed to the next customer.
             */
            return ProvisioningResult::failed(
                FailureClass::Timeout,
                self::FOUND_ITS_OWN_BUILD,
                sprintf(
                    'An earlier attempt of this build already created machine %s on node %s, named "%s". Nothing new was built; adopt that machine rather than building another.',
                    $identity->providerId,
                    $machine->nodeName,
                    (string) $machine->name,
                ),
                providerReference: $identity->providerId,
                metadata: $metadata,
            );
        }

        if ($reason === self::REASON_NAMED_OTHERWISE) {
            /*
             * Somebody else's machine, by the one piece of evidence that
             * establishes it. Nothing of this build's exists at the id, so its
             * reservations are released like any refusal before a build, and
             * an operator may move the job to a new identity.
             */
            return ProvisioningResult::failed(
                FailureClass::Permanent,
                self::IDENTITY_TAKEN,
                sprintf(
                    'Provider identity %s is already used on node %s by a machine named "%s", which is not a name this build asked for. Nothing was built; repoint this job to a new identity.',
                    $identity->providerId,
                    $machine->nodeName,
                    (string) $machine->name,
                ),
                metadata: $metadata,
            );
        }

        /*
         * Ownership cannot be established either way. Settled for review as a
         * timeout — the machine may be this build's, configured with this
         * build's address — and deliberately not a licence to repoint.
         */
        return ProvisioningResult::failed(
            FailureClass::Timeout,
            self::IDENTITY_TAKEN,
            sprintf(
                'A machine exists at provider identity %s on node %s and it cannot be established whose it is (%s). Nothing was built; look at it before doing anything else.',
                $identity->providerId,
                $machine->nodeName,
                $reason === self::REASON_UNNAMED ? 'it reports no name' : 'it is named as this build called it but shaped otherwise',
            ),
            metadata: $metadata,
        );
    }

    /**
     * Whose the machine at the reserved identity is, as one of four reasons.
     *
     * A null is the absence of an observation, not an observation of absence
     * — and that cuts both ways. A machine with no name is NOT "named
     * otherwise": Proxmox omits the name while `qmcreate` is still writing the
     * config, which is precisely the window in which a retry arrives after a
     * lost answer. And a null vCPU or memory figure contradicts nothing.
     */
    private function whoseItIs(RemoteVmState $machine, ReservedProviderIdentity $identity, VmResources $resources): string
    {
        if ($machine->name === null || $machine->name === '') {
            return self::REASON_UNNAMED;
        }

        if (! $identity->calledWith($machine->name)) {
            return self::REASON_NAMED_OTHERWISE;
        }

        $shapeDiffers = ($machine->vcpu !== null && $machine->vcpu !== $resources->vcpu)
            || ($machine->memoryMib !== null && $machine->memoryMib !== $resources->memoryMib);

        return $shapeDiffers ? self::REASON_SHAPE_DIFFERS : self::REASON_NAMED_AS_CALLED;
    }
}
