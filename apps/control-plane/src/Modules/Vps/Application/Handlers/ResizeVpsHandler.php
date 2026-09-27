<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Application\Handlers;

use Lynomia\Modules\Compute\Domain\DTOs\ResizeVmRequest;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Domain\Exceptions\NodeCapacityExceededException;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Domain\Contracts\ProvisioningHandler;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\Vps\Application\Services\MachineCommitment;

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
 * The machine is looked at before it is changed
 * ---------------------------------------------------------------------------
 *
 * The change asked of the hypervisor is worked out from the machine as the
 * hypervisor reports it just before, not from the row: F-15's "look before
 * you act", for a resize. A disk growth is a growth, not a size (the provider
 * contract, and the Proxmox adapter's "+"), so a growth worked out from the
 * row was applied twice whenever the row was behind the machine - an
 * indeterminate 40 -> 400 GiB growth that landed went to review, the
 * operator's retry grew it by 360 again, and the customer had 760 GiB and
 * paid for 400 (D7-2, round seven). Now a disk already at or above the
 * target is not grown, vCPU and memory already at the target are not sent,
 * and a machine already the target shape is a success whose row and
 * commitment are settled to what the hypervisor reported. A machine the
 * hypervisor reports no such machine for is not resized and nothing is
 * committed (`vps.machine_not_found`, transient); a read that fails is the
 * same, nothing having been asked. What is not reported (a figure the
 * adapter could not read) is taken from the row.
 *
 * ---------------------------------------------------------------------------
 * The row moves after the provider does
 * ---------------------------------------------------------------------------
 *
 * The machine's recorded shape is written only once the hypervisor has
 * confirmed it, and this is the opposite order from the power handler. A power
 * state that is briefly wrong is a display problem; a shape that is wrong is
 * a record that says the customer has what they do not.
 *
 * ---------------------------------------------------------------------------
 * The commitment moves before the machine does
 * ---------------------------------------------------------------------------
 *
 * Changing the row was all this used to do: the node's allocated_* figures,
 * its pool's committed_gib and the machine's capacity reservation went on
 * describing the machine as it was bought. A grown machine oversold its node
 * - the re-audit grew 4096 MiB to 65536 on a 131072 MiB node that still read
 * 4096, and placed an 80000 MiB machine beside it - and a destroy gave back
 * the old figures (D2). So, through RestateNodeCommitment, in the one lock
 * order:
 *
 *  - before the hypervisor is asked, the commitment is raised to cover the
 *    larger of what it holds (as locked) and the target, on the machine's
 *    node and pool. A growth the node or pool cannot hold is refused there -
 *    FailureClass Capacity, code `compute.node_capacity_exceeded` - and
 *    nothing is grown or committed. Only what grows is asked about: a shrink
 *    is never refused (NodeCapacityPolicy::assessGrowth());
 *  - once the hypervisor has confirmed and the machine row holds the shape
 *    read back, the commitment is set to the machine row as read under its
 *    locks, which is where a shrink gives its difference back;
 *  - a refusal the hypervisor gave (nothing changed) sets it back to the
 *    machine row, read the same way; an outcome that is unknown leaves it
 *    raised, because the machine may have grown and the job goes to a person;
 *  - a resize the hypervisor accepted whose machine then cannot be read back
 *    (`vps.resize_unverified`) is settled to what is known: the machine is
 *    either the shape read just before the resize or the shape the accepted
 *    change makes of it, so the commitment is set to the larger of the two,
 *    per dimension, and any excess the ceiling held above both is given
 *    back. It used to be left at the ceiling, and nothing settled it after
 *    (the failure is permanent). The row is not written - nothing confirmed
 *    the shape - and a retry, which looks at the machine first, settles both
 *    to what the hypervisor then reports.
 *
 * Nothing serialises two resizes of one machine, which is why the settling
 * restatements read the row under the lock rather than use the model this
 * job holds (MachineCommitment::asRecorded()). A destroy is serialised with
 * every restatement by the machine row's lock (MachineCommitment::restate()):
 * a restatement that finds the row gone writes nothing, and the resize ends
 * `vps.unknown_machine`, rather than committing a machine that no longer
 * exists (D7-1, round seven).
 *
 * The reservation row carries the machine's shape throughout, so the destroy
 * (which gives back what the row records) gives back what is held.
 *
 * What a paid upgrade that cannot fit comes to: the money moved when the
 * proration invoice was paid, before this job ran (ResizeOnPlanChangeSettlement).
 * A capacity refusal is retried by the engine with its backoff - a node
 * fills and empties, and room made by a destroy is room this job can use -
 * and when the attempts run out the job stops in review, never failed: the
 * upgrade stays on the operator's list and on lynomia_plan_change_total until
 * a person grows the machine or returns the money. It is never reported done
 * and never grown onto room the node does not have; the customer is told the
 * change is waiting for the team and what they paid is held
 * (NotifyOnProvisioningOutcome, plan_change_needs_review). The plan-change
 * quote asks the same refusal before the money moves (PlanChangeDelivery,
 * through MachineCommitment::whyTheGrowthWouldNotFit()), the payment asks it
 * again when it is opened, and the settlement once more when the capture is
 * heard - returning a change it refuses (ReturnAPlanChangeNoLongerDeliverable)
 * - so this is reached only when the room went between the settlement and the
 * resize.
 */
final readonly class ResizeVpsHandler implements ProvisioningHandler
{
    public function __construct(
        private ComputeProviderFactory $computeProviders,
        private SecretRedactor $redactor,
        private MachineCommitment $commitment,
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

        try {
            $provider = $this->computeProviders->for($cluster);

            // Looked at before it is changed (the class docblock).
            $actual = $provider->getVm($node->provider_name, (string) $machine->provider_id);
        } catch (ComputeProviderException $e) {
            // Only a read: nothing was asked of the machine and nothing is committed.
            return ProvisioningResult::failed(
                FailureClass::Transient,
                $e->errorCode(),
                $e->getMessage(),
                metadata: $this->redactor->redact($e->context()),
            );
        }

        if ($actual === null) {
            return ProvisioningResult::failed(
                FailureClass::Transient,
                'vps.machine_not_found',
                'The hypervisor reports no such machine, so nothing was resized.',
                metadata: ['virtual_machine_id' => $machineId, 'node' => $node->provider_name],
            );
        }

        $currentVcpu = $actual->vcpu ?? $machine->vcpu;
        $currentMemory = $actual->memoryMib ?? $machine->memory_mib;
        $currentDisk = $actual->diskGib ?? $machine->disk_gib;

        /*
         * The disk field is a growth, not a size. The provider contract says
         * so and the Proxmox adapter's "+" prefix depends on it: an absolute
         * value smaller than the current one silently truncates, and one
         * larger would be applied twice on a retry. So it is measured from
         * the disk the hypervisor reports now, and a disk already at or past
         * the target is not grown again.
         */
        $diskGrowth = $targetDisk === null || $targetDisk <= $currentDisk
            ? null
            : $targetDisk - $currentDisk;

        $request = new ResizeVmRequest(
            vcpu: $targetVcpu === $currentVcpu ? null : $targetVcpu,
            memoryMib: $targetMemory === $currentMemory ? null : $targetMemory,
            diskGib: $diskGrowth,
        );

        if ($request->isEmpty()) {
            /*
             * The machine is already the shape the plan sells — a retry after
             * a resize that landed (its answer lost, or its settle not
             * reached), or a plan change that only moved the price. Answered
             * as a success: the state the caller wanted is the state that
             * exists. The row is written to what the hypervisor reports, and
             * a commitment left raised by an earlier attempt is settled to
             * it.
             */
            $recorded = [$machine->vcpu, $machine->memory_mib, $machine->disk_gib];
            $machine->vcpu = $currentVcpu;
            $machine->memory_mib = $currentMemory;
            $machine->disk_gib = $currentDisk;
            $rewritten = $recorded !== [$machine->vcpu, $machine->memory_mib, $machine->disk_gib];

            if ($rewritten) {
                $machine->save();
            }

            if (($rewritten || $this->commitment->heldBy($machine, $node) !== null)
                && ! $this->commitment->restate($machine, $node, $this->commitment->asRecorded($machine), refuse: false)) {
                return $this->goneWhileResizing($machineId);
            }

            return ProvisioningResult::succeeded(
                metadata: ['virtual_machine_id' => $machineId, 'already_correct' => true],
            );
        }

        /*
         * The growth is committed before anything grows: the larger of what
         * is held (as locked) and the target, so neither the old shape nor
         * the new one is ever running on room nobody committed
         * (MachineCommitment::ceiling(), the growth the plan-change quote
         * asks about too).
         */
        try {
            if (! $this->commitment->restate($machine, $node, $this->commitment->ceiling($machine, $targetVcpu, $targetMemory, $targetDisk), refuse: true)) {
                return $this->goneWhileResizing($machineId);
            }
        } catch (NodeCapacityExceededException $e) {
            // See the class docblock for what this comes to for a paid upgrade.
            return ProvisioningResult::failed(
                FailureClass::Capacity,
                $e->errorCode(),
                $e->getMessage(),
                metadata: $this->redactor->redact([...$e->context(), 'virtual_machine_id' => $machineId]),
            );
        }

        try {
            $operation = $provider->resizeVm($node->provider_name, (string) $machine->provider_id, $request);
        } catch (ComputeProviderException $e) {
            /*
             * Indeterminate becomes a TIMEOUT, which the engine escalates and
             * never retries. A resize the platform stopped waiting for may
             * have grown the disk; an operator's retry looks at the machine
             * before it grows anything (the class docblock), so it does not
             * grow it again. Its commitment stays raised, for the same
             * reason: the machine may be the larger shape. A refusal changed
             * nothing, so the commitment goes back to the machine as
             * recorded.
             */
            if (! $e->isIndeterminate()) {
                $this->commitment->restate($machine, $node, $this->commitment->asRecorded($machine), refuse: false);
            }

            return ProvisioningResult::failed(
                $e->isIndeterminate() ? FailureClass::Timeout : FailureClass::Transient,
                $e->errorCode(),
                $e->getMessage(),
                metadata: $this->redactor->redact($e->context()),
            );
        }

        // Read back rather than assumed. This is the step that makes the
        // difference between "we asked" and "the machine is that size".
        try {
            $state = $provider->getVm($node->provider_name, (string) $machine->provider_id);
            $unread = $state === null ? 'the hypervisor reported no such machine' : null;
        } catch (ComputeProviderException $e) {
            $state = null;
            $unread = $this->redactor->redactString($e->getMessage());
        }

        if ($state === null) {
            /*
             * Settled to what is known (the class docblock): the shape read
             * before the resize or the one the accepted change makes, the
             * larger per dimension. Not left at the ceiling.
             */
            $known = new VmResources(
                vcpu: max($currentVcpu, $request->vcpu ?? $currentVcpu),
                memoryMib: max($currentMemory, $request->memoryMib ?? $currentMemory),
                diskGib: $currentDisk + ($diskGrowth ?? 0),
            );

            $settled = $this->commitment->restate($machine, $node, $known, refuse: false);

            return ProvisioningResult::failed(
                FailureClass::Permanent,
                'vps.resize_unverified',
                'The machine could not be read back after the resize.',
                metadata: [
                    'virtual_machine_id' => $machineId,
                    'reason' => (string) $unread,
                    'committed_vcpu' => $settled ? $known->vcpu : null,
                    'committed_memory_mib' => $settled ? $known->memoryMib : null,
                    'committed_disk_gib' => $settled ? $known->diskGib : null,
                ],
            );
        }

        $machine->vcpu = $state->vcpu ?? $targetVcpu ?? $machine->vcpu;
        $machine->memory_mib = $state->memoryMib ?? $targetMemory ?? $machine->memory_mib;
        $machine->disk_gib = $state->diskGib ?? $targetDisk ?? $machine->disk_gib;
        $machine->save();

        // The commitment is now the machine as the hypervisor confirmed it:
        // a shrink gives its difference back here. Recorded, not refused -
        // the machine is this size whatever the node says.
        if (! $this->commitment->restate($machine, $node, $this->commitment->asRecorded($machine), refuse: false)) {
            return $this->goneWhileResizing($machineId);
        }

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

    /**
     * The machine's row went while this resize ran - a destroy removed it
     * (MachineCommitment::restate() found it gone under its lock) - so
     * nothing was committed for it.
     */
    private function goneWhileResizing(string $machineId): ProvisioningResult
    {
        return ProvisioningResult::failed(
            FailureClass::Permanent,
            'vps.unknown_machine',
            'The virtual machine was removed while it was being resized, so nothing is committed for it.',
            metadata: ['virtual_machine_id' => $machineId],
        );
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
