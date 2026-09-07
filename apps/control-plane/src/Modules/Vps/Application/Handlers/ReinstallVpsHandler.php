<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Application\Handlers;

use Illuminate\Database\Eloquent\Builder;
use Lynomia\Modules\Compute\Domain\DTOs\CloudInitConfig;
use Lynomia\Modules\Compute\Domain\DTOs\ReinstallVmRequest;
use Lynomia\Modules\Compute\Domain\Enums\PowerState;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Provisioning\Domain\Contracts\ProvisioningHandler;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\Vps\Domain\Enums\ReinstallState;
use Lynomia\Modules\Vps\Infrastructure\Models\VmReinstall;
use Lynomia\Modules\Vps\Infrastructure\Queries\PrimaryMachineAddress;

/**
 * Replaces a customer's disk, and keeps everything else.
 *
 * ---------------------------------------------------------------------------
 * What a reinstall is not
 * ---------------------------------------------------------------------------
 *
 * It is not a create. Nothing here places a machine, reserves capacity or
 * allocates an address, and that is the design rather than an omission: every
 * one of those would produce a second thing where there should be one. A
 * placement would move the machine, a capacity reservation would double-count
 * it on its own node, and an allocation would hand the customer a second
 * address while the first stayed assigned to the same machine.
 *
 * The list of what survives is therefore explicit, and each entry is a failure
 * that would otherwise be invisible until somebody complained:
 *
 *  - **The provider id.** The machine is rebuilt in place. A clone-and-swap
 *    would leave the platform's row pointing at the old machine and the new
 *    one billed to nobody.
 *  - **The service mapping.** No row is created and none is repointed, so the
 *    subscription that pays for this machine still names it afterwards.
 *  - **The address assignment.** Untouched in IPAM and written back into the
 *    guest through cloud-init. Releasing and re-reserving would give the
 *    customer a new address — and would give their old one to somebody else
 *    while their DNS still pointed at it.
 *  - **The MAC.** A consequence of not touching the machine's NIC, which the
 *    provider contract requires of every adapter. Licences and firewall rules
 *    are keyed on it.
 *  - **The shape.** vCPU, memory and disk are read from the machine's own row
 *    and passed back unchanged. A reinstall that resized would change what the
 *    customer uses without changing what they pay.
 *  - **The hostname.** Unless the customer is renaming, which they are not:
 *    the confirmation they typed *was* the hostname.
 *  - **The backup relationship.** Backups are keyed on the service and the
 *    machine, both of which are the same afterwards, so yesterday's backup is
 *    still restorable onto this machine. That is deliberate and worth stating:
 *    a customer who rebuilds the wrong server needs the backup to still be
 *    attached to it.
 *
 * What does not survive, and cannot: the disk, the SSH host keys, and anything
 * the customer installed. That is what they asked for.
 *
 * ---------------------------------------------------------------------------
 * The states, and why a timeout does not retry
 * ---------------------------------------------------------------------------
 *
 * Each phase is recorded on the operation before it is attempted, so a worker
 * that dies leaves behind a row saying what it was doing rather than a job
 * that was "running". The distinction that matters is `preparing` versus
 * everything after it: a failure while preparing destroyed nothing, and the
 * customer still has the machine they had.
 *
 * A provider call that ends indeterminately — the platform stopped waiting —
 * ends the operation in `indeterminate` and the job as a TIMEOUT, which the
 * engine escalates to review and never retries. This is the single most
 * important line in the file. A retried reinstall lands on a machine that may
 * be mid-rebuild and destroys whatever the first attempt had laid down; and
 * because a timed-out job sits in `needs_review`, VpsOperationGuard refuses
 * every further operation on the service until a person has looked.
 */
final readonly class ReinstallVpsHandler implements ProvisioningHandler
{
    public function __construct(
        private ComputeProviderFactory $computeProviders,
        private SecretRedactor $redactor,
    ) {}

    public function kind(): ProvisioningJobKind
    {
        return ProvisioningJobKind::ReinstallVps;
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

        /*
         * Found rather than created where possible. The request action writes
         * this row when the customer confirms, so the operation exists before
         * the queue does; a job created some other way — an operator tool, a
         * replayed fixture — gets one here instead of the handler having no
         * record to write to.
         */
        $operation = $this->operationFor($job, $machine);

        if ($operation->state->isTerminal()) {
            /*
             * A second delivery of a job that already finished. Answered as a
             * success without touching the machine: the alternative is
             * reinstalling a customer's server because a queue redelivered a
             * message.
             */
            return ProvisioningResult::succeeded(
                remoteJobId: $operation->provider_task_id,
                providerReference: $operation->provider_resource_id,
                metadata: ['reinstall_state' => $operation->state->value, 'replayed' => true],
            );
        }

        $operation->advanceTo(ReinstallState::Preparing);

        $node = $machine->node()->first();
        $cluster = $machine->cluster()->first();

        if (! $machine->existsRemotely() || $node === null || $cluster === null) {
            return $this->refuse(
                $operation,
                ReinstallState::Failed,
                FailureClass::Permanent,
                'vps.not_provisioned',
                'The machine has no confirmed provider id, node or cluster, so there is nothing to rebuild.',
            );
        }

        $storage = $machine->storage_name;

        if ($storage === null || $storage === '') {
            /*
             * Refused rather than guessed. The storages on a node are
             * different tiers, so choosing one would silently move the
             * customer's server onto hardware they did not buy — and would do
             * it during an operation they already know is destructive, which
             * is the worst possible moment to also change something they did
             * not ask about.
             */
            return $this->refuse(
                $operation,
                ReinstallState::Failed,
                FailureClass::Permanent,
                'vps.reinstall_storage_unknown',
                'The platform does not know which storage this disk lives on, so it will not create a replacement somewhere else.',
            );
        }

        $template = $this->templateFor($payload, $machine, $cluster);

        if ($template === null) {
            return $this->refuse(
                $operation,
                ReinstallState::Failed,
                FailureClass::Permanent,
                'vps.reinstall_image_unavailable',
                'No installable image was named for this reinstall, and the machine names none that is staged on its cluster.',
            );
        }

        $assignment = PrimaryMachineAddress::for($machine);
        $address = $assignment?->ipAddress;
        $subnet = $address?->subnet;

        if ($address === null || $subnet === null) {
            /*
             * A machine with no live address is a machine whose network the
             * platform cannot restore. Rebuilding it would produce a guest
             * with no route out and no way for the customer to reach it —
             * indistinguishable, from their side, from a reinstall that
             * destroyed their server.
             */
            return $this->refuse(
                $operation,
                ReinstallState::Failed,
                FailureClass::Permanent,
                'vps.reinstall_address_missing',
                'The machine has no live address assignment, so a rebuilt guest could not be given its network back.',
            );
        }

        $preserved = [
            'provider_id' => (string) $machine->provider_id,
            'node' => $node->provider_name,
            'hostname' => $machine->hostname,
            'service_id' => (string) $machine->service_id,
            'vcpu' => $machine->vcpu,
            'memory_mib' => $machine->memory_mib,
            'disk_gib' => $machine->disk_gib,
            'storage_name' => $storage,
            'primary_address' => $address->address,
            'ip_assignment_id' => (string) $assignment->getKey(),
        ];

        $operation->forceFill([
            'preserved' => $preserved,
            'provider_node' => $node->provider_name,
            'provider_resource_id' => (string) $machine->provider_id,
            'template_id' => $template->getKey(),
            'template_reference' => $template->provider_reference,
        ])->save();

        /*
         * Past this line the customer's data is at risk, so the state is
         * written first. A worker killed between this and the provider's
         * answer leaves a row that says "reinstalling", which is what makes
         * the difference between "your disk is intact" and "a person needs to
         * look" recoverable from the database alone.
         */
        $operation->advanceTo(ReinstallState::Reinstalling);

        try {
            $provider = $this->computeProviders->for($cluster);

            $operationResult = $provider->reinstallVm(
                $node->provider_name,
                (string) $machine->provider_id,
                new ReinstallVmRequest(
                    templateReference: (string) $template->provider_reference,
                    storageName: $storage,
                    diskGib: $machine->disk_gib,
                    hostname: $machine->hostname,
                    osFamily: $template->os_family,
                    cloudInit: new CloudInitConfig(
                        sshKeys: array_values(array_filter((array) ($payload['ssh_keys'] ?? []), 'is_string')),
                        // The same address, restated. IPAM is not touched: the
                        // assignment that was live before the rebuild is the
                        // one the guest comes back with.
                        ipConfig: sprintf(
                            'ip=%s/%d,gw=%s',
                            $address->address,
                            $subnet->prefix_length,
                            $subnet->gateway,
                        ),
                    ),
                ),
            );
        } catch (ComputeProviderException $e) {
            if ($e->isIndeterminate()) {
                /*
                 * The one case that must never become a retry. Everything the
                 * platform knows about what it was doing is written down —
                 * the task, the machine, the node, the service — so an
                 * operator can ask the hypervisor what actually happened
                 * instead of asking the customer.
                 */
                $operation->recordFailure(
                    ReinstallState::Indeterminate,
                    $e->errorCode(),
                    $e->getMessage(),
                );

                return ProvisioningResult::failed(
                    FailureClass::Timeout,
                    $e->errorCode(),
                    $e->getMessage(),
                    metadata: $this->redactor->redact([
                        ...$e->context(),
                        'reinstall_id' => (string) $operation->getKey(),
                        'provider_resource_id' => (string) $machine->provider_id,
                        'provider_node' => $node->provider_name,
                        'service_id' => (string) $machine->service_id,
                    ]),
                );
            }

            /*
             * The provider refused and said so. Which state that leaves the
             * machine in depends on how far the adapter got, and the adapter
             * is the only thing that knows — so this is `needs_review` rather
             * than `failed`: the disk may be detached, the import may have
             * half run, and nothing automatic should touch it again.
             */
            $operation->recordFailure(ReinstallState::NeedsReview, $e->errorCode(), $e->getMessage());

            return ProvisioningResult::failed(
                FailureClass::Permanent,
                $e->errorCode(),
                $e->getMessage(),
                metadata: $this->redactor->redact([
                    ...$e->context(),
                    'reinstall_id' => (string) $operation->getKey(),
                ]),
            );
        }

        // Written the moment it exists, not on return: a worker that dies
        // between here and the end must leave the task id behind.
        $job->recordRemoteJobId($operationResult->taskId);

        $operation->forceFill(['provider_task_id' => $operationResult->taskId])->save();

        $operation->advanceTo(ReinstallState::Configuring);

        /*
         * The image on the machine changed, so the platform's record of what
         * is installed changes with it. Nothing else about the row is
         * rewritten — the id, the node, the shape and the hostname are the
         * same machine they were.
         */
        $machine->template_id = $template->getKey();
        $machine->os_family = $template->os_family->value;
        $machine->os_version = null;
        $machine->power_state = PowerState::Running;
        $machine->save();

        $operation->advanceTo(ReinstallState::Verifying);

        $verdict = $this->verify($operation, $machine, $node, $cluster);

        if ($verdict !== null) {
            return $verdict;
        }

        $operation->advanceTo(ReinstallState::Completed);

        return ProvisioningResult::succeeded(
            remoteJobId: $operationResult->taskId,
            providerReference: $operationResult->providerId,
            metadata: [
                'reinstall_id' => (string) $operation->getKey(),
                'template_reference' => (string) $template->provider_reference,
                'node' => $node->provider_name,
                'virtual_machine_id' => (string) $machine->getKey(),
            ],
        );
    }

    /**
     * Asks the hypervisor whether the machine that came back is the machine
     * that went in.
     *
     * Only the identity is checked, deliberately. Whether the guest has
     * finished booting is not this handler's question — a fresh cloud-init run
     * takes longer than any sensible provisioning timeout — but whether the
     * machine still exists under the same id is, because the failure it would
     * catch is an adapter that rebuilt by replacing.
     *
     * @return ProvisioningResult|null a result when verification failed, null when it passed
     */
    private function verify(
        VmReinstall $operation,
        VirtualMachine $machine,
        ComputeNode $node,
        ComputeCluster $cluster,
    ): ?ProvisioningResult {
        try {
            $state = $this->computeProviders->for($cluster)
                ->getVm($node->provider_name, (string) $machine->provider_id);
        } catch (ComputeProviderException $e) {
            /*
             * The rebuild itself succeeded; only the confirmation failed. Left
             * for a person rather than called a failure, because telling a
             * customer their reinstall failed when the machine is probably
             * running is its own kind of wrong.
             */
            $operation->recordFailure(ReinstallState::NeedsReview, $e->errorCode(), $e->getMessage());

            return ProvisioningResult::failed(
                FailureClass::Permanent,
                'vps.reinstall_unverified',
                'The machine was rebuilt and the hypervisor could not be asked to confirm it.',
                metadata: $this->redactor->redact($e->context()),
            );
        }

        if ($state === null) {
            $operation->recordFailure(
                ReinstallState::NeedsReview,
                'vps.reinstall_machine_missing',
                'The hypervisor no longer has a machine under this id after the rebuild.',
            );

            return ProvisioningResult::failed(
                FailureClass::Permanent,
                'vps.reinstall_machine_missing',
                'The hypervisor no longer has a machine under this id after the rebuild.',
                metadata: [
                    'reinstall_id' => (string) $operation->getKey(),
                    'provider_resource_id' => (string) $machine->provider_id,
                ],
            );
        }

        return null;
    }

    /**
     * The image to lay down.
     *
     * The request's template when it named one — the controller has already
     * checked it is installable on this machine's own cluster — and otherwise
     * the machine's current template, which is "the same again". A machine
     * whose template row has since been retired names nothing installable, and
     * that is refused rather than approximated with whatever else is staged.
     *
     * @param  array<string, mixed>  $payload
     */
    private function templateFor(array $payload, VirtualMachine $machine, ComputeCluster $cluster): ?VmTemplate
    {
        $requested = isset($payload['template_id']) ? (string) $payload['template_id'] : null;

        /** @var VmTemplate|null $template */
        $template = VmTemplate::query()
            /*
             * The same three constraints the controller applies to a
             * customer-supplied template id, restated here because a job's
             * payload is not a request: it may have been written minutes ago
             * by an endpoint, or by an operator tool that checked nothing.
             * `installable` excludes a retired image and one never staged at a
             * provider.
             */
            ->installable()
            ->where(function (Builder $builder) use ($cluster): void {
                // A template staged fleet-wide is usable anywhere; one staged
                // on another cluster is an image that does not exist on this
                // machine's hardware.
                $builder->whereNull('cluster_id')->orWhere('cluster_id', $cluster->getKey());
            })
            ->whereKey($requested ?? $machine->template_id ?? '')
            ->first();

        if ($template === null || $template->provider_reference === null) {
            return null;
        }

        return $template;
    }

    /**
     * The operation record for this job, created if the request path did not.
     */
    private function operationFor(ProvisioningJob $job, VirtualMachine $machine): VmReinstall
    {
        $existing = VmReinstall::query()
            ->where('provisioning_job_id', $job->getKey())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        /** @var VmReinstall $created */
        $created = VmReinstall::query()->create([
            'virtual_machine_id' => $machine->getKey(),
            'service_id' => $job->service_id,
            'customer_id' => $job->customer_id,
            'provisioning_job_id' => $job->getKey(),
            'state' => ReinstallState::Queued,
            'state_changed_at' => now(),
        ]);

        return $created;
    }

    /**
     * Ends the operation before anything was destroyed.
     */
    private function refuse(
        VmReinstall $operation,
        ReinstallState $state,
        FailureClass $class,
        string $code,
        string $message,
    ): ProvisioningResult {
        $operation->recordFailure($state, $code, $message);

        return ProvisioningResult::failed($class, $code, $message, metadata: [
            'reinstall_id' => (string) $operation->getKey(),
        ]);
    }
}
