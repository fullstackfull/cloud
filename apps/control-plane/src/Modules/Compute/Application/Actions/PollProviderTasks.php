<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Compute\Domain\Enums\RemoteTaskStatus;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Application\Actions\RecordDrift;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftSeverity;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobNeedsReview;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Asks the hypervisor what became of the tasks the platform handed off to it.
 *
 * ---------------------------------------------------------------------------
 * Why a succeeded job is not the end of the story
 * ---------------------------------------------------------------------------
 *
 * On Proxmox, creating a machine answers in milliseconds with a UPID and
 * builds the machine minutes later. The handler writes its rows, the engine
 * marks the job succeeded, and the customer is told their server is ready —
 * all of which happened before the hypervisor had finished, and none of which
 * is evidence that it did. A clone that runs out of space on the target
 * storage, a template that vanished between placement and copy, a node that
 * rebooted mid-task: every one of those leaves the platform holding a machine
 * row, an assigned address and a billed service for something that does not
 * exist.
 *
 * `getTask()` has been implemented against Proxmox since Phase 6 and nothing
 * called it. This is the caller.
 *
 * ---------------------------------------------------------------------------
 * What it does about an answer
 * ---------------------------------------------------------------------------
 *
 * **Succeeded** — stamped and never looked at again. The platform's claim is
 * now backed by the hypervisor's own record.
 *
 * **Failed** — the job goes to `needs_review` and the event that puts it in
 * front of a person is raised. Nothing is destroyed, nothing is retried and no
 * address is released: the machine may exist in some half-built state, and
 * every one of those actions on a guess is worse than a queue entry an
 * operator reads. Drift is recorded too, because "the platform believes in a
 * machine the provider does not" is exactly what that ledger is for.
 *
 * **Still running, past the ceiling** — the same, classified as a timeout. The
 * Timeout Rule: an indeterminate destructive operation is never resolved by
 * assumption, in either direction. The task may still be running; what has
 * failed is the platform's ability to keep track of it.
 *
 * **No answer at all** — not knowing is the normal condition of a poller. The
 * attempt is recorded, the backoff widens, and it asks again.
 *
 * The backoff is bounded and exponential from the poll count already on the
 * row, so a task that has been running for an hour is asked about once every
 * few minutes rather than every time the sweep runs — a fleet of slow builds
 * must not turn into a fleet of API calls.
 */
final readonly class PollProviderTasks
{
    private const string RESOURCE = 'virtual_machine';

    public function __construct(
        private ComputeProviderFactory $providers,
        private RecordDrift $drift,
        private SecretRedactor $redactor,
    ) {}

    /**
     * @return array{polled: int, confirmed: int, review: int}
     */
    public function execute(): array
    {
        $polled = 0;
        $confirmed = 0;
        $review = 0;

        foreach ($this->due() as $job) {
            $machine = $this->machineFor($job);
            $cluster = $machine?->cluster()->first();

            if ($machine === null || $cluster === null) {
                /*
                 * Nothing to ask with. Deliberately not treated as a failed
                 * task: a job whose machine row has since been destroyed is a
                 * terminated service, not a broken build, and marking it for
                 * review would put every ended VPS in an operator's queue.
                 */
                $job->forceFill(['remote_task_state' => RemoteTaskStatus::Unknown->value])->save();

                continue;
            }

            $polled++;

            try {
                $state = $this->providers->for($cluster)->getTask(
                    (string) $job->remote_task_node,
                    (string) $job->remote_job_id,
                );
            } catch (ComputeProviderException $e) {
                $this->recordAttempt($job);

                if ($this->overdue($job)) {
                    $this->sendForReview(
                        $job,
                        FailureClass::Timeout,
                        'The hypervisor could not be asked what became of this task: '
                            .$this->redactor->redactString($e->getMessage()),
                    );

                    $review++;
                }

                continue;
            }

            $this->recordAttempt($job);

            if ($state->status === RemoteTaskStatus::Succeeded) {
                $job->forceFill(['remote_task_state' => RemoteTaskStatus::Succeeded->value])->save();
                $confirmed++;

                continue;
            }

            if ($state->status === RemoteTaskStatus::Failed) {
                $job->forceFill(['remote_task_state' => RemoteTaskStatus::Failed->value])->save();

                $this->reportDrift($job, $machine, $state->exitStatus);

                $this->sendForReview(
                    $job,
                    FailureClass::Permanent,
                    'The hypervisor task this job started ended in failure: '
                        .$this->redactor->redactString($state->exitStatus ?? 'no exit status was given'),
                );

                $review++;

                continue;
            }

            // Running, or a status the adapter could not read. Both are "ask
            // again", until the ceiling.
            if ($this->overdue($job)) {
                $job->forceFill(['remote_task_state' => RemoteTaskStatus::Unknown->value])->save();

                $this->reportDrift($job, $machine, 'the task was still running when the platform stopped waiting');

                $this->sendForReview(
                    $job,
                    FailureClass::Timeout,
                    'The hypervisor task this job started has not finished, and the platform has stopped waiting for it.',
                );

                $review++;
            }
        }

        return ['polled' => $polled, 'confirmed' => $confirmed, 'review' => $review];
    }

    private function recordAttempt(ProvisioningJob $job): void
    {
        $job->forceFill([
            'remote_task_polled_at' => now(),
            'remote_task_poll_count' => $job->remote_task_poll_count + 1,
        ])->save();
    }

    /**
     * Whether the platform has waited long enough to stop waiting.
     *
     * Measured from the job finishing rather than from the first poll: the
     * question is how long the *task* has had, and a sweep that started late
     * must not give a stuck build another hour for its own tardiness.
     */
    private function overdue(ProvisioningJob $job): bool
    {
        $ceiling = max(1, (int) config('compute.tasks.give_up_after_minutes', 60));

        $since = $job->finished_at ?? $job->updated_at;

        return $since === null || $since->addMinutes($ceiling)->isPast();
    }

    private function reportDrift(ProvisioningJob $job, VirtualMachine $machine, ?string $detail): void
    {
        $this->drift->execute(
            provider: $job->provider,
            resourceType: self::RESOURCE,
            kind: DriftKind::MissingAtProvider,
            providerReference: (string) ($machine->provider_id ?? $machine->getKey()),
            serviceId: (string) ($job->service_id ?? ''),
            expected: ['job' => $job->kind->value, 'task' => (string) $job->remote_job_id],
            observed: ['task_outcome' => $this->redactor->redactString((string) $detail)],
            // The platform is billing for, and showing the customer, a machine
            // whose build the hypervisor says did not finish.
            severity: DriftSeverity::Critical,
        );
    }

    /**
     * Puts the job in front of a person, through the same door every other
     * stuck job uses.
     *
     * No compensation is run. A build whose task failed may have left a disk,
     * a machine or nothing at all behind, and releasing its address into the
     * pool on that guess is how the next customer gets an address that still
     * answers for somebody else.
     */
    private function sendForReview(ProvisioningJob $job, FailureClass $class, string $reason): void
    {
        $job->forceFill([
            'status' => ProvisioningJobStatus::NeedsReview,
            'failure_class' => $class,
            'last_error' => $reason,
        ])->save();

        event(new ProvisioningJobNeedsReview(
            provisioningJobId: (string) $job->getKey(),
            kind: $job->kind,
            serviceId: $job->service_id,
            remoteJobId: $job->remote_job_id,
            failureClass: $class,
            reason: $reason,
        ));
    }

    private function machineFor(ProvisioningJob $job): ?VirtualMachine
    {
        if ($job->service_id === null) {
            return null;
        }

        return VirtualMachine::query()->where('service_id', $job->service_id)->first();
    }

    /**
     * Jobs whose task nobody has confirmed, that are due another ask.
     *
     * Exponential from the poll count and clamped, so a fleet of slow builds
     * does not become a fleet of API calls: a task asked about five times is
     * asked about again in sixteen minutes, not in one.
     *
     * @return list<ProvisioningJob>
     */
    private function due(): array
    {
        $base = max(1, (int) config('compute.tasks.poll_base_minutes', 1));
        $cap = max($base, (int) config('compute.tasks.poll_max_minutes', 15));
        $now = CarbonImmutable::now();

        /** @var list<ProvisioningJob> $jobs */
        $jobs = ProvisioningJob::query()
            ->where('status', ProvisioningJobStatus::Succeeded->value)
            ->whereNull('remote_task_state')
            ->whereNotNull('remote_job_id')
            ->whereNotNull('remote_task_node')
            ->orderByRaw('remote_task_polled_at asc nulls first')
            ->limit(max(1, (int) config('compute.tasks.poll_batch', 100)))
            ->get()
            ->all();

        return array_values(array_filter($jobs, static function (ProvisioningJob $job) use ($base, $cap, $now): bool {
            if ($job->remote_task_polled_at === null) {
                return true;
            }

            $wait = min($cap, $base * (2 ** min(10, $job->remote_task_poll_count)));

            return $job->remote_task_polled_at->addMinutes($wait)->lessThanOrEqualTo($now);
        }));
    }
}
