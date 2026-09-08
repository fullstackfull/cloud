<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Contracts\DestructiveOperationLedger;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\Exceptions\RetryRefusedException;
use Lynomia\Modules\Provisioning\Domain\StateMachines\ProvisioningJobStateMachine;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * Putting a stopped job back into the pool, because a person decided to.
 *
 * The job state machine has always had the edges — `failed → queued` and
 * `needs_review → queued`, both commented as things an operator does — and
 * nothing could travel them. The result was that the platform's most common
 * recoverable failure had no recovery: a build that hit a full cluster, a
 * control panel that was down for the ten minutes the order arrived in, an
 * address pool that had been exhausted and has since been extended. All of
 * them settle as failed, all of them would succeed on a second run, and the
 * only way to ask for one was to write to the table by hand.
 *
 * ---------------------------------------------------------------------------
 * What it will not do
 * ---------------------------------------------------------------------------
 *
 * A retry is not a repair, and the three refusals are the difference:
 *
 *  - **A job that has not stopped.** Queued or running work does not need a
 *    person; retrying it is how one order becomes two machines.
 *  - **A job that built something.** The attempt reached the provider and a
 *    resource exists. Adoption is the way out of that — it attaches what is
 *    already there — and a retry would leave the first one orphaned, unbilled,
 *    and holding an address.
 *  - **A job that has destroyed data.** A reinstall past the destructive line
 *    cannot be undone by running it again, and running it again lands a second
 *    installation on top of whatever the first one wrote.
 *
 * There is deliberately no way to force past any of them. An operator who
 * knows better than the platform has adoption for the second case and the
 * operation's own review verdict for the third; both of those record what the
 * person saw, which "force" never does.
 *
 * ---------------------------------------------------------------------------
 * One attempt, not a new budget
 * ---------------------------------------------------------------------------
 *
 * `max_attempts` is not raised. The job is claimable again and gets exactly one
 * run; if it fails the same way, the engine's own accounting sends it back to
 * review rather than letting a single button restart a loop.
 */
final readonly class RetryProvisioningJob
{
    public function __construct(
        private ProvisioningJobStateMachine $jobStates,
        private TransitionService $transitionService,
        private DestructiveOperationLedger $destructive,
    ) {}

    /**
     * @throws RetryRefusedException
     */
    public function execute(ProvisioningJob $job): ProvisioningJob
    {
        $requeued = DB::transaction(function () use ($job): ProvisioningJob {
            /** @var ProvisioningJob $locked */
            $locked = ProvisioningJob::query()->lockForUpdate()->findOrFail($job->getKey());

            if (! in_array($locked->status, [ProvisioningJobStatus::Failed, ProvisioningJobStatus::NeedsReview], true)) {
                throw RetryRefusedException::becauseJobIsNotSettled((string) $locked->getKey(), $locked->status);
            }

            $built = $this->whatItBuilt($locked);

            if ($built !== null) {
                throw RetryRefusedException::becauseSomethingWasBuilt((string) $locked->getKey(), $built);
            }

            $destroyed = $this->destructive->destructionState($locked);

            if ($destroyed !== null) {
                throw RetryRefusedException::becauseTheDataIsAlreadyGone((string) $locked->getKey(), $destroyed);
            }

            $this->jobStates->assertCanTransition($locked->status, ProvisioningJobStatus::Queued);

            $locked->status = ProvisioningJobStatus::Queued;
            /*
             * Now, not after a backoff. The operator has just looked at the
             * failure and decided the condition behind it is gone; making them
             * wait a computed interval for a decision a person already took
             * would only make them press the button again.
             */
            $locked->next_attempt_at = now();
            $locked->finished_at = null;
            /*
             * The error is left where it is. It is the reason this retry
             * happened, and clearing it would leave the screen claiming the
             * job's last attempt went fine.
             */
            $locked->save();

            $this->putTheServiceBackIntoProvisioning($locked);

            return $locked;
        });

        /*
         * Dispatched after the commit, for the same reason the engine claims
         * before it calls anybody: a worker that read this row inside the
         * transaction would find the job still failed and do nothing, and the
         * operator's retry would silently disappear.
         */
        RunProvisioningJob::dispatch((string) $requeued->getKey());

        return $requeued;
    }

    /**
     * What this job left at the provider, as a short phrase, or null if it
     * left nothing the platform knows about.
     *
     * Both columns are checked because they mean different things and either
     * one is enough: `remote_job_id` is a task the provider accepted, and
     * `result.provider_reference` is the resource that task produced.
     */
    private function whatItBuilt(ProvisioningJob $job): ?string
    {
        $result = $job->result ?? [];

        $reference = $result['provider_reference'] ?? null;

        if (is_string($reference) && $reference !== '') {
            return 'provider resource '.$reference;
        }

        return $job->remote_job_id === null || $job->remote_job_id === ''
            ? null
            : 'provider task '.$job->remote_job_id;
    }

    /**
     * A failed build's service goes back to `provisioning`, so the run that is
     * about to happen can finish it.
     *
     * Without this the retry would succeed at the provider and then be unable
     * to say so: the handler's success transitions the service to active, and
     * `failed → active` is not an edge the state machine has — deliberately,
     * because a service that went from failed to active without passing
     * through a build is a row nobody can explain.
     */
    private function putTheServiceBackIntoProvisioning(ProvisioningJob $job): void
    {
        if (! $job->kind->failsServiceOnFailure() || $job->service_id === null) {
            return;
        }

        $service = Service::query()->find($job->service_id);

        if ($service === null || $service->status !== ServiceStatus::Failed) {
            return;
        }

        $this->transitionService->execute($service, ServiceStatus::Provisioning);
    }
}
