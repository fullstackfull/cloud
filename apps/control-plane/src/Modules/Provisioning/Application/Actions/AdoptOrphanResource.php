<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobSucceeded;
use Lynomia\Modules\Provisioning\Domain\Exceptions\OrphanAdoptionRejectedException;
use Lynomia\Modules\Provisioning\Domain\StateMachines\ProvisioningJobStateMachine;
use Lynomia\Modules\Provisioning\Domain\StateMachines\ServiceStateMachine;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningAttempt;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;

/**
 * Attaches a resource the provider has already built to the job that built it.
 *
 * This is the other half of never retrying a timeout. A job in review means a
 * machine may exist that the platform does not know about: unbilled,
 * unmanaged, and holding an address the next customer is about to be given.
 * The recovery is to go and look, and then to say "that one is ours" — which
 * is what this action records. Re-running the build instead would leave the
 * first machine orphaned forever and charge the customer for the second.
 *
 * The guards exist because getting adoption wrong is worse than the orphan it
 * fixes:
 *
 *  - a running job is refused, because a live attempt may be about to write
 *    its own reference over the one being adopted;
 *  - a settled job is refused, because it already points at a resource and
 *    overwriting that reference would orphan the first machine while making
 *    the books look tidy;
 *  - a reference another job already claims is refused, because two jobs
 *    pointing at one machine means the next termination deletes a server
 *    somebody else is still paying for.
 */
final readonly class AdoptOrphanResource
{
    public function __construct(
        private ProvisioningJobStateMachine $jobStates,
        private ServiceStateMachine $serviceStates,
        private TransitionService $transitionService,
    ) {}

    /**
     * @param  string  $providerReference  The resource itself, e.g. a Proxmox VMID or a cPanel account.
     * @param  string|null  $remoteJobId  The provider's job id, when the orphan was found through one.
     * @param  array<string, mixed>  $evidence  What the operator saw at the provider, kept as the
     *                                          justification for the adoption.
     *
     * @throws OrphanAdoptionRejectedException
     */
    public function execute(
        ProvisioningJob $job,
        string $providerReference,
        ?string $remoteJobId = null,
        array $evidence = [],
        string $adoptedBy = 'system',
    ): ProvisioningJob {
        $adopted = DB::transaction(function () use ($job, $providerReference, $remoteJobId, $evidence, $adoptedBy): ProvisioningJob {
            // Taken before anything is read, so that the check for an existing
            // claimant and the write that becomes one are a single step.
            $this->lockReference($providerReference, $remoteJobId);

            /** @var ProvisioningJob $locked */
            $locked = ProvisioningJob::query()->lockForUpdate()->findOrFail($job->getKey());

            $this->assertAdoptable($locked);
            $this->assertReferenceIsUnclaimed($locked, $providerReference, $remoteJobId);

            $this->jobStates->assertCanTransition($locked->status, ProvisioningJobStatus::Succeeded);

            $attemptNumber = $locked->attempts + 1;
            $result = $locked->result ?? [];
            $result['provider_reference'] = $providerReference;
            $result['adoption'] = [
                'adopted_by' => $adoptedBy,
                'adopted_at' => now()->toIso8601String(),
                'previous_status' => $locked->status->value,
                'evidence' => $evidence,
            ];

            $locked->status = ProvisioningJobStatus::Succeeded;
            $locked->remote_job_id = $remoteJobId ?? $locked->remote_job_id;
            $locked->attempts = $attemptNumber;
            $locked->failure_class = null;
            $locked->next_attempt_at = null;
            $locked->finished_at = now();
            $locked->result = $result;
            // last_error is left alone on purpose: the failure that produced
            // the orphan is why this row is interesting, and erasing it would
            // make an adopted job indistinguishable from a clean build.
            $locked->save();

            // Recorded as an attempt so the job's history reads as what
            // actually happened: three failed calls and a human.
            ProvisioningAttempt::create([
                'provisioning_job_id' => $locked->getKey(),
                'attempt_number' => $attemptNumber,
                'status' => ProvisioningJobStatus::Succeeded,
                'remote_job_id' => $locked->remote_job_id,
                'response_metadata' => ['adopted' => true, 'adopted_by' => $adoptedBy, 'evidence' => $evidence],
                'created_at' => now(),
            ]);

            $this->syncService($locked);

            return $locked;
        });

        event(new ProvisioningJobSucceeded(
            provisioningJobId: (string) $adopted->getKey(),
            kind: $adopted->kind,
            serviceId: $adopted->service_id,
            remoteJobId: $adopted->remote_job_id,
            adopted: true,
        ));

        return $adopted;
    }

    /**
     * @throws OrphanAdoptionRejectedException
     */
    private function assertAdoptable(ProvisioningJob $job): void
    {
        if ($job->status === ProvisioningJobStatus::Running) {
            throw OrphanAdoptionRejectedException::becauseJobIsRunning((string) $job->getKey());
        }

        if (in_array($job->status, [ProvisioningJobStatus::Succeeded, ProvisioningJobStatus::Cancelled], true)) {
            throw OrphanAdoptionRejectedException::becauseJobIsSettled((string) $job->getKey(), $job->status);
        }
    }

    /**
     * Serialise everyone who is about to claim this same machine.
     *
     * assertReferenceIsUnclaimed() is a SELECT, and a SELECT sees only what is
     * committed. Two operators — or two runs of a reconciler — who find the
     * same stray machine and attach it to their own timed-out jobs would both
     * read no claimant and both write one, because the row lock each takes is
     * on its own job and there is no unique index over result->provider_reference
     * to catch them. Two jobs pointing at one machine is how the next
     * termination deletes a server somebody else is still paying for.
     *
     * The lock is transaction-scoped, so it is released by the commit or the
     * rollback and cannot be leaked by a process that dies mid-adoption. The
     * remote job id is locked as well, because it is the second dimension the
     * claimant check tests and therefore the second way two jobs can converge
     * on one resource.
     */
    private function lockReference(string $providerReference, ?string $remoteJobId): void
    {
        $keys = ['adoption|reference|'.$providerReference];

        if ($remoteJobId !== null) {
            $keys[] = 'adoption|remote_job|'.$remoteJobId;
        }

        // Sorted so that two adoptions naming the same pair of keys always
        // take them in the same order and cannot deadlock against each other.
        sort($keys);

        foreach ($keys as $key) {
            DB::statement('select pg_advisory_xact_lock(hashtext(?))', [$key]);
        }
    }

    /**
     * @throws OrphanAdoptionRejectedException
     */
    private function assertReferenceIsUnclaimed(ProvisioningJob $job, string $providerReference, ?string $remoteJobId): void
    {
        $claimant = ProvisioningJob::query()
            ->whereKeyNot($job->getKey())
            ->where(function (Builder $query) use ($providerReference, $remoteJobId): void {
                $query->where('result->provider_reference', $providerReference);

                if ($remoteJobId !== null) {
                    $query->orWhere('remote_job_id', $remoteJobId);
                }
            })
            ->first();

        if ($claimant !== null) {
            throw OrphanAdoptionRejectedException::becauseReferenceIsAlreadyClaimed(
                $providerReference,
                (string) $claimant->getKey(),
            );
        }
    }

    /**
     * Move the service to where a successful job would have left it.
     *
     * Guarded rather than asserted: a service that is not where this job
     * expected it to be is a mismatch for an operator to look at, not a reason
     * to refuse to record a machine that demonstrably exists.
     */
    private function syncService(ProvisioningJob $job): void
    {
        $target = $job->kind->serviceStatusOnSuccess();
        $service = $target === null ? null : $job->service()->lockForUpdate()->first();

        if ($service === null || $target === null) {
            return;
        }

        foreach ($this->routeTo($service->status, $target) as $step) {
            // Reassigned because each step must start from the row as the
            // previous one left it; the caller's copy is stale after the first.
            $service = $this->transitionService->execute($service, $step);
        }
    }

    /**
     * The legal states a service must pass through to reach where adoption
     * leaves it.
     *
     * This exists because of the ordinary case, not an exotic one. A create
     * that timed out marked the service failed — correctly, since at that
     * moment nobody knew whether anything existed. Adoption is the answer to
     * that question, and it is "yes", so the service has to be able to reach
     * active. failed → active is not a legal edge and must not become one: the
     * legal route is the one the state machine already documents for a build
     * that goes round again, failed → provisioning → active. Without this the
     * adoption records the machine, the platform bills for it, and the
     * customer's service reads "failed" for ever.
     *
     * Only one intermediate hop is considered, and only over edges the state
     * machine already allows, so nothing here can invent a route out of a
     * terminal state: terminated has no outgoing transitions, so a terminated
     * service still cannot be resurrected by an adoption.
     *
     * @return list<ServiceStatus>
     */
    private function routeTo(ServiceStatus $from, ServiceStatus $to): array
    {
        if ($from === $to) {
            return [];
        }

        if ($this->serviceStates->canTransition($from, $to)) {
            return [$to];
        }

        foreach ($this->serviceStates->reachableFrom($from) as $intermediate) {
            if ($this->serviceStates->canTransition($intermediate, $to)) {
                return [$intermediate, $to];
            }
        }

        // No legal route. The mismatch is the operator's to explain; refusing
        // to record a machine that exists would be worse than reporting it.
        return [];
    }
}
