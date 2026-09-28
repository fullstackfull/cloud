<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Application\Handlers;

use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteTaskState;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteVmState;
use Lynomia\Modules\Compute\Domain\DTOs\ResizeVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\VmOperation;
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
 * same, nothing having been asked. A vCPU or memory figure that is not
 * reported (one the adapter could not read) is taken from the row. A disk
 * that is not reported is not: a growth measured from the row was applied
 * again whenever the row was behind the machine, exactly as above (D8-2, the
 * re-audit after round seven), so a resize that would grow the disk past the
 * row while the hypervisor reports no disk asks nothing and commits nothing
 * (`vps.disk_not_reported`, transient). A disk target no larger than the row
 * grows nothing either way.
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
 * Confirmed means two things. First, the task has finished. The Proxmox
 * adapter answers a disk growth with the UPID Proxmox gives it as
 * RemoteTaskStatus::Running (VmOperation::isInFlight()); the machine was read
 * back at once all the same, reported the disk it had, and the job succeeded
 * with the old disk recorded while the machine went on to grow (D8-1, the
 * re-audit after round seven). A resize answered in flight is now not read
 * back: its task is kept on the job's result (TASK_IN_FLIGHT) and the attempt
 * ends `vps.resize_in_progress`, transient - the engine's backoff, the way
 * the platform waits on a task elsewhere by asking again later
 * (PollProviderTasks) - with the commitment left at the ceiling, because the
 * machine is one shape or the other. The next attempt asks the task
 * (ComputeProvider::getTask()) before it looks at the machine: still running
 * is the same finding again, and an ask that fails is transient as a failed
 * look is, the task staying on the job (on the engine's own attempts; an
 * operator's retry can give up on it, below) - either way nothing is asked or
 * committed; finished, the task is forgotten and the attempt goes on as any
 * other, looking at the machine the task left. A task that finished in
 * failure is recorded on the job's result first (TASK_FAILED: its id, node,
 * status, redacted exit status and the attempt that found it) and logged;
 * the look then shows what it left, and what is still missing is asked for
 * again. The task's UPID and node are named in the attempt's message, which
 * is the job's `last_error` on the review list. When the attempts run out
 * with the task still running the job stops in review with the task on it,
 * and an operator's retry asks it first too. So does it when the attempts ran
 * out on asks that failed; if its own ask fails as well, the task is one
 * nobody can ask about, and the retry gives up on it (anOperatorRetried()):
 * the task is recorded on the job's result (TASK_UNASKABLE) and logged, and
 * the retry looks at the machine and settles from what the hypervisor
 * reports - the shape asked for already there is a success, with the row and
 * the commitment written to it, and what is missing is asked for, measured
 * from the machine as any retry measures it. Such a job used to have no way
 * out: every retry asked, failed and went back to review, an adoption is
 * refused for a resize, and the service's plan changes were held for ever
 * (A9-2, the re-audit after round eight). What the look cannot rule out: a
 * task that is still running at the hypervisor although it cannot be asked
 * about, with its disk growth not yet landed, is measured past, and its
 * growth is asked for again. The runbook (provisioning-stuck) has the
 * operator look for the task at the hypervisor before retrying. The task is not the job's
 * remote_job_id, which RetryProvisioningJob refuses to retry. A task
 * started by another job is not asked: nothing serialises two resizes of
 * one machine (below); on the customer's path a plan change is refused while
 * a job of the service is queued, running or in review (ServiceBusy).
 *
 * Second, the machine read back is the shape asked for, in every figure that
 * was sent (confirms()): the vCPU and the memory, and a disk at least the
 * target. A figure that was asked for and not reported used to be recorded
 * as the target; it is not confirmation now, and neither is a figure that
 * differs, and either settles as an unverified resize (below). What this
 * cannot establish without a real cluster: the adapter reads the machine
 * from Proxmox's `status/current`, and whether a vCPU or memory change that
 * Proxmox holds pending until the guest restarts (no hotplug) is reported
 * there as the old figure or the new one has not been observed - no real
 * Proxmox has been read. If it is the old figure, the resize is not
 * confirmed and stops in review with the commitment at the larger shape; if
 * it is the new figure, the row and the commitment record the new shape
 * while the guest runs the old one until it restarts. The simulator makes
 * vCPU and memory changes at once and cannot tell the two apart.
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
 *    is never refused (NodeCapacityPolicy::assessGrowth()), and a target no
 *    larger in any dimension than the machine as the hypervisor reported it
 *    just before (the look above) is recorded without asking
 *    (MachineCommitment::grows()) - a machine with no live commitment is
 *    committed whole, which read a pure shrink of it as growth (N3);
 *  - once the hypervisor has confirmed and the machine row holds the shape
 *    read back, the commitment is set to the machine row as read under its
 *    locks, which is where a shrink gives its difference back;
 *  - a refusal the hypervisor gave (nothing changed) sets it back to the
 *    machine row, read the same way; an outcome that is unknown leaves it
 *    raised, because the machine may have grown and the job goes to a person;
 *  - a resize the hypervisor accepted whose machine then cannot be read back,
 *    or is read back short of what was asked (`vps.resize_unverified`), is
 *    settled to what is known: the machine is either the shape read just
 *    before the resize or the shape the accepted change makes of it, so the
 *    commitment is set to the larger of the two, per dimension (and of any
 *    figure the read-back did report), and any excess the ceiling held above
 *    them is given back. It used to be left at the ceiling. The row is not
 *    written - nothing confirmed the shape. The failure is a timeout, so the
 *    job stops in review and is never retried by the engine: it was
 *    permanent, the job failed, a failed job does not hold the service's
 *    plan changes, and a downgrade was then quoted from the row the resize
 *    had not written - credited 27.000 KWD with no resize queued, and the
 *    machine stayed large (A8-1, the re-audit after round seven). In review
 *    it holds them (QuotePlanChange's ServiceBusy) until a person settles
 *    it; an operator's retry looks at the machine first and settles the row
 *    and the commitment to what the hypervisor then reports. An adoption,
 *    which would settle it without running it, is refused for a resize
 *    (AdoptOrphanResource: only a job that builds a resource adopts one).
 *
 * Nothing serialises two resizes of one machine, which is why the settling
 * restatements read the row under the lock rather than use the model this
 * job holds (MachineCommitment::asRecorded()). A destroy is serialised with
 * every restatement by the machine row's lock (MachineCommitment::restate()):
 * a restatement that finds the row gone writes nothing, and the resize ends
 * `vps.unknown_machine`, rather than committing a machine that no longer
 * exists (D7-1, round seven) - at each of its four restatements: the
 * ceiling, the settle after a refusal, the settle of an unverified resize
 * and the settle of a confirmed one (and the already-correct settle).
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
 * a person grows the machine (a retry) or returns the money (a refund of the
 * charge, docs/runbooks/provisioning-stuck.md §6), or until the service ends:
 * the money then goes back to the wallet without a person
 * (ReturnAnUpgradeTheEndPrevented), and the job is closed off the list
 * (CloseAJobWhoseServiceEnded), which returns it if nothing has yet. It used
 * to be kept once the service ended (R10-M, the final audit). It is never reported done
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
    /** The job's finding while the hypervisor is still running the resize's task. */
    public const string IN_PROGRESS = 'vps.resize_in_progress';

    /** The job's finding when a disk growth was asked and the hypervisor reported no disk to measure it from. */
    public const string DISK_NOT_REPORTED = 'vps.disk_not_reported';

    /** The job's finding when the machine read back after the resize does not confirm it. */
    public const string UNVERIFIED = 'vps.resize_unverified';

    /**
     * Where on the job's result the task an attempt left running is kept, so
     * the next attempt asks about it before it looks at the machine.
     */
    public const string TASK_IN_FLIGHT = 'resize_task_in_flight';

    /** Where on the job's result the last resize task that finished in failure is recorded. */
    public const string TASK_FAILED = 'resize_task_failed';

    /** Where on the job's result a task an operator's retry could not ask about is recorded. */
    public const string TASK_UNASKABLE = 'resize_task_unaskable';

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

            /*
             * A resize task an earlier attempt of this job left running is
             * asked about first (the class docblock): while it runs, the
             * machine still reports the shape it had, and a growth measured
             * from that would be applied twice.
             */
            $earlier = $this->taskAnEarlierAttemptLeftRunning($job);

            if ($earlier !== null) {
                try {
                    $task = $provider->getTask($earlier['node'], $earlier['id']);
                } catch (ComputeProviderException $e) {
                    // Transient, below - unless an operator's retry is asking
                    // (the class docblock): it gives up on the task and looks.
                    if (! $this->anOperatorRetried($job)) {
                        throw $e;
                    }

                    $this->giveUpOnTheTask($job, $earlier, $e);
                    $task = null;
                }

                if ($task !== null && ! $task->isFinished()) {
                    return $this->stillRunning($machineId, $earlier['id'], $earlier['node']);
                }

                // Finished: what it did is on the machine the look below reads.
                if ($task !== null) {
                    $this->forgetTheTask($job, $task);
                }
            }

            // Looked at before it is changed (the class docblock).
            $actual = $provider->getVm($node->provider_name, (string) $machine->provider_id);
        } catch (ComputeProviderException $e) {
            // Only reads: nothing was asked of the machine and nothing is
            // committed. A task not yet asked about stays on the job.
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

        if ($targetDisk !== null && $actual->diskGib === null && $targetDisk > $machine->disk_gib) {
            /*
             * A growth is measured from the disk the hypervisor reports, and
             * it reported none. Measured from the row instead, a growth that
             * already landed while the row was behind was applied again
             * (D8-2, the re-audit after round seven): nothing is asked and
             * nothing is committed until the disk can be read.
             */
            return ProvisioningResult::failed(
                FailureClass::Transient,
                self::DISK_NOT_REPORTED,
                'The hypervisor did not report the machine\'s disk, so the growth could not be measured from it and nothing was resized.',
                metadata: ['virtual_machine_id' => $machineId, 'requested_disk_gib' => $targetDisk],
            );
        }

        $currentVcpu = $actual->vcpu ?? $machine->vcpu;
        $currentMemory = $actual->memoryMib ?? $machine->memory_mib;
        $currentDisk = $actual->diskGib ?? $machine->disk_gib;
        $runs = new VmResources($currentVcpu, $currentMemory, $currentDisk);

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
            if (! $this->commitment->restate(
                $machine,
                $node,
                $this->commitment->ceiling($machine, $targetVcpu, $targetMemory, $targetDisk),
                // A shrink is recorded, never refused (MachineCommitment::grows()),
                // measured from the machine as the hypervisor reported it above.
                refuse: $this->commitment->grows($machine, $targetVcpu, $targetMemory, $targetDisk, $runs),
                // Only what the ceiling adds above what the machine runs is asked.
                alreadyRuns: $runs,
            )) {
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
            if (! $e->isIndeterminate()
                && ! $this->commitment->restate($machine, $node, $this->commitment->asRecorded($machine), refuse: false)) {
                return $this->goneWhileResizing($machineId);
            }

            return ProvisioningResult::failed(
                $e->isIndeterminate() ? FailureClass::Timeout : FailureClass::Transient,
                $e->errorCode(),
                $e->getMessage(),
                metadata: $this->redactor->redact($e->context()),
            );
        }

        if ($operation->isInFlight()) {
            /*
             * Accepted as a task that has not finished - Proxmox answers a
             * disk growth with a UPID (the class docblock). Read now, the
             * machine reports the disk it had, and that used to be recorded
             * as the resize's outcome. Kept on the job and asked about by the
             * next attempt; the commitment stays at the ceiling meanwhile,
             * because the machine is one shape or the other.
             */
            $this->rememberTheTask($job, $operation, $node->provider_name);

            return $this->stillRunning($machineId, $operation->taskId, $node->provider_name);
        }

        // Read back rather than assumed, once the task has finished. This is
        // the step that makes the difference between "we asked" and "the
        // machine is that size": a figure that was asked for and is not
        // reported at it is not taken to be so.
        try {
            $state = $provider->getVm($node->provider_name, (string) $machine->provider_id);
            $unread = $state === null ? 'the hypervisor reported no such machine' : null;
        } catch (ComputeProviderException $e) {
            $state = null;
            $unread = $this->redactor->redactString($e->getMessage());
        }

        if ($state !== null && ! $this->confirms($state, $request, $targetDisk)) {
            $unread = 'the machine read back is not the shape the resize asked for';
        }

        if ($state === null || $unread !== null) {
            /*
             * Settled to what is known (the class docblock): the shape read
             * before the resize, the one the accepted change makes, and
             * whatever the read-back reported, the larger per dimension. Not
             * left at the ceiling.
             */
            $known = new VmResources(
                vcpu: max($currentVcpu, $request->vcpu ?? $currentVcpu, $state?->vcpu ?? 0),
                memoryMib: max($currentMemory, $request->memoryMib ?? $currentMemory, $state?->memoryMib ?? 0),
                diskGib: max($currentDisk + ($diskGrowth ?? 0), $state?->diskGib ?? 0),
            );

            if (! $this->commitment->restate($machine, $node, $known, refuse: false)) {
                return $this->goneWhileResizing($machineId);
            }

            // A timeout: the machine may be either shape, and only a person
            // can go and look (the class docblock).
            return ProvisioningResult::failed(
                FailureClass::Timeout,
                self::UNVERIFIED,
                'The machine could not be read back as the shape the resize asked for.',
                metadata: [
                    'virtual_machine_id' => $machineId,
                    'reason' => (string) $unread,
                    'committed_vcpu' => $known->vcpu,
                    'committed_memory_mib' => $known->memoryMib,
                    'committed_disk_gib' => $known->diskGib,
                ],
            );
        }

        // What the hypervisor reported; a figure it did not report, which
        // was not asked for (confirms()), stays as the row had it.
        $machine->vcpu = $state->vcpu ?? $machine->vcpu;
        $machine->memory_mib = $state->memoryMib ?? $machine->memory_mib;
        $machine->disk_gib = $state->diskGib ?? $machine->disk_gib;
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
     * Whether the machine read back is the shape asked for, in every figure
     * that was asked: the vCPU and memory sent, and a disk at least the
     * target once a growth was sent. A figure asked for and not reported is
     * not confirmation.
     */
    private function confirms(RemoteVmState $state, ResizeVmRequest $request, ?int $targetDisk): bool
    {
        return ($request->vcpu === null || $state->vcpu === $request->vcpu)
            && ($request->memoryMib === null || $state->memoryMib === $request->memoryMib)
            && ($request->diskGib === null || ($state->diskGib !== null && $targetDisk !== null && $state->diskGib >= $targetDisk));
    }

    /**
     * The hypervisor has not finished the resize's task. Transient, so the
     * engine asks again after its backoff, and when the attempts run out the
     * job stops in review with the task still on it, where an operator's
     * retry asks about it first. Not recorded as the job's remote_job_id:
     * RetryProvisioningJob refuses a job that holds one, and a resize whose
     * task outlasted the attempts is exactly the job a retry must be able to
     * finish.
     */
    private function stillRunning(string $machineId, string $taskId, string $nodeName): ProvisioningResult
    {
        return ProvisioningResult::failed(
            FailureClass::Transient,
            self::IN_PROGRESS,
            // Named in the message because the message is the job's
            // last_error, which the review list shows.
            sprintf('The hypervisor has not finished the resize task %s on node %s; the machine is read back once it has.', $taskId, $nodeName),
            metadata: ['virtual_machine_id' => $machineId, 'task_id' => $taskId, 'node' => $nodeName],
        );
    }

    /**
     * @return array{id: string, node: string}|null
     */
    private function taskAnEarlierAttemptLeftRunning(ProvisioningJob $job): ?array
    {
        $task = ($job->result ?? [])[self::TASK_IN_FLIGHT] ?? null;

        if (! is_array($task) || ! is_string($task['id'] ?? null) || ! is_string($task['node'] ?? null)) {
            return null;
        }

        return ['id' => $task['id'], 'node' => $task['node']];
    }

    /**
     * Written the moment the task exists, as a create writes its handle: a
     * worker that dies before the engine records this attempt leaves it
     * behind for the next.
     */
    private function rememberTheTask(ProvisioningJob $job, VmOperation $operation, string $nodeName): void
    {
        $job->forceFill(['result' => [
            ...($job->result ?? []),
            self::TASK_IN_FLIGHT => ['id' => $operation->taskId, 'node' => $nodeName],
        ]])->save();
    }

    /**
     * The task has finished. A task that failed is recorded on the job
     * (TASK_FAILED) and logged before it is forgotten: the attempt goes on to
     * look at the machine, which shows what the failed task left, and asks
     * for what is still missing - but the failure is not lost with the task.
     */
    private function forgetTheTask(ProvisioningJob $job, RemoteTaskState $task): void
    {
        $result = $job->result ?? [];
        $finished = $result[self::TASK_IN_FLIGHT] ?? null;
        unset($result[self::TASK_IN_FLIGHT]);

        if (! $task->isSuccessful()) {
            $result[self::TASK_FAILED] = [
                'id' => $task->taskId,
                'node' => is_array($finished) ? ($finished['node'] ?? $task->nodeName) : $task->nodeName,
                'status' => $task->status->value,
                'exit_status' => $this->redactor->redactString((string) ($task->exitStatus ?? '')),
                'attempt' => $job->attempts,
            ];

            Log::warning('A resize task the hypervisor accepted ended in failure; the machine is looked at again before anything more is asked.', [
                'provisioning_job_id' => (string) $job->getKey(),
                ...$result[self::TASK_FAILED],
            ]);
        }

        $job->forceFill(['result' => $result])->save();
    }

    /**
     * Whether this attempt is an operator's retry of a job that used up its
     * attempts. The engine stops a job in review when its attempts run out
     * (RunProvisioningJob) and never claims it again; RetryProvisioningJob
     * requeues it without raising max_attempts, so the attempt it gets is
     * numbered past them. While a task is kept every finding of the ask (a
     * task still running, an ask that failed) is transient, so a job whose
     * asks keep failing reaches review this way. One stopped sooner - a
     * worker that died mid-attempt, which DetectStaleJobs sends to review -
     * is given what is left of its attempts by a retry, asking each time,
     * and the retry after those looks.
     */
    private function anOperatorRetried(ProvisioningJob $job): bool
    {
        return $job->attempts > $job->max_attempts;
    }

    /**
     * An operator's retry could not ask about the task either: the ask failed
     * on the attempt that used up the job's budget and again now. It is
     * recorded on the job's result (TASK_UNASKABLE: its id, node, the
     * failure's code and redacted message, and the attempt), logged, and
     * forgotten, and the attempt goes on to look at the machine, which
     * settles the job from what the hypervisor reports (the class docblock).
     *
     * @param  array{id: string, node: string}  $task
     */
    private function giveUpOnTheTask(ProvisioningJob $job, array $task, ComputeProviderException $e): void
    {
        $result = $job->result ?? [];
        unset($result[self::TASK_IN_FLIGHT]);

        $result[self::TASK_UNASKABLE] = [
            'id' => $task['id'],
            'node' => $task['node'],
            'error_code' => $e->errorCode(),
            'error' => $this->redactor->redactString($e->getMessage()),
            'attempt' => $job->attempts,
        ];

        Log::warning('A resize task could not be asked about on an operator\'s retry; the machine is looked at instead.', [
            'provisioning_job_id' => (string) $job->getKey(),
            ...$result[self::TASK_UNASKABLE],
        ]);

        $job->forceFill(['result' => $result])->save();
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
