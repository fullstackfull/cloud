<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Exceptions\RepointRefusedException;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Vps\Application\Handlers\CreateVpsHandler;

/**
 * Move a VPS create off a provider identity that somebody else's machine holds.
 *
 * F-15 made every attempt of a create ask for one reserved identity and look
 * under it before building. That closes the second machine, and opens a dead
 * end: the identity is derived from the order's key, a derived id collides
 * with an existing machine often enough to be an operational certainty at
 * scale, and a job whose identity is taken would otherwise find the same
 * stranger on every retry for ever. This is the route out, and the only one.
 *
 * It is also the one operator act in this area that can build a second
 * machine if it is taken at the wrong moment, because what it grants is "you
 * may build under a different id" — and a build under a different id cannot
 * see a machine this job left under the old one. So it acts on one finding
 * only, and refuses everything else with its own code:
 *
 *  - **The job has stopped for a person** (`provisioning.repoint_job_not_stopped`). A
 *    queued or running attempt may be about to use, or be using, the identity
 *    this would replace.
 *  - **It holds an identity at all** (`provisioning.repoint_nothing_reserved`).
 *  - **Nothing of this job's is at the provider** (`provisioning.repoint_would_duplicate`):
 *    no provider reference and no provider task. If there is one, the way out
 *    is adoption.
 *  - **Its last finding is that the identity is taken**
 *    (`provisioning.repoint_no_identity_finding`).
 *  - **That finding is current** (`provisioning.repoint_finding_is_stale`) in BOTH
 *    dimensions it can go stale in: it must be about the identity the job
 *    holds now, and it must have been written by the job's last attempt. The
 *    second is not belt and braces. An attempt that built under a new identity
 *    and then died leaves the previous attempt's finding behind — the stale
 *    sweeper moves the job to review on its deadline alone — and a repoint
 *    licensed by that old finding would build a second machine beside the one
 *    the dead attempt left. `DetectStaleJobs` now overwrites the finding too;
 *    each half closes that door on its own.
 *  - **The machine is established to be somebody else's**
 *    (`provisioning.repoint_ownership_not_established`): reason `named_otherwise` and
 *    nothing weaker. A machine reported with no name, or named as this job
 *    called it but shaped otherwise, may be this build's own.
 *
 * There is no override. An operator who knows the machine is a stranger when
 * the platform could not establish it has the hypervisor to rename or remove
 * it from, after which a retry finds the identity free — and every one of
 * those steps is a statement a person can be asked about later.
 *
 * What it changes is the reserved identity and nothing else: the new id is
 * drawn from the same range by the same rule the create uses, never one this
 * job has held before, and the node and name lists start empty because they
 * are about the identity they were recorded under. The old identity, its
 * lists and the finding that licensed the move are kept in `result.repoints`.
 * The job is not requeued — the operator retries it, and the retry looks under
 * the new identity before it builds.
 */
final readonly class RepointReservedIdentity
{
    /** How far down the derived sequence to look for an id this job has not held. */
    private const int MAX_CANDIDATES = 64;

    /**
     * @throws RepointRefusedException
     */
    public function execute(ProvisioningJob $job, string $evidence, string $repointedBy = 'system'): ProvisioningJob
    {
        return DB::transaction(function () use ($job, $evidence, $repointedBy): ProvisioningJob {
            /** @var ProvisioningJob $locked */
            $locked = ProvisioningJob::query()->lockForUpdate()->findOrFail($job->getKey());

            $finding = $this->assertRepointable($locked);

            $result = $locked->result ?? [];
            /** @var list<array<string, mixed>> $history */
            $history = is_array($result['repoints'] ?? null) ? array_values($result['repoints']) : [];

            $from = (string) $locked->reserved_provider_id;
            $held = [$from, ...array_map(static fn (array $entry): string => (string) ($entry['from'] ?? ''), $history)];
            $to = $this->nextIdentity($locked, $held, count($history));

            $history[] = [
                'from' => $from,
                'to' => $to,
                'cluster_id' => $locked->reserved_cluster_id,
                'nodes' => $locked->reserved_provider_nodes ?? [],
                'hostnames' => $locked->reserved_provider_hostnames ?? [],
                'finding' => $finding,
                'repointed_by' => $repointedBy,
                'evidence' => $evidence,
                'at' => now()->toIso8601String(),
            ];
            $result['repoints'] = $history;

            $locked->reserved_provider_id = $to;
            $locked->reserved_provider_nodes = null;
            $locked->reserved_provider_hostnames = null;
            $locked->result = $result;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Every refusal, in the order a person would want to be told them.
     *
     * @return array<string, mixed> The finding that licenses the repoint.
     *
     * @throws RepointRefusedException
     */
    private function assertRepointable(ProvisioningJob $job): array
    {
        $jobId = (string) $job->getKey();

        if (! in_array($job->status, [ProvisioningJobStatus::Failed, ProvisioningJobStatus::NeedsReview], true)) {
            throw RepointRefusedException::becauseTheJobHasNotStopped($jobId, $job->status->value);
        }

        if ($job->kind !== ProvisioningJobKind::CreateVps || $job->reservedProviderIdentity() === null) {
            throw RepointRefusedException::becauseNothingIsReserved($jobId);
        }

        $result = $job->result ?? [];
        $reference = $result['provider_reference'] ?? null;

        if (is_string($reference) && $reference !== '') {
            throw RepointRefusedException::becauseSomethingWasBuilt($jobId, 'provider resource '.$reference);
        }

        if ($job->remote_job_id !== null && $job->remote_job_id !== '') {
            throw RepointRefusedException::becauseSomethingWasBuilt($jobId, 'provider task '.$job->remote_job_id);
        }

        /** @var array<string, mixed> $finding */
        $finding = is_array($result['error'] ?? null) ? $result['error'] : [];
        $code = is_string($finding['code'] ?? null) ? $finding['code'] : null;

        if ($code !== CreateVpsHandler::IDENTITY_TAKEN) {
            throw RepointRefusedException::becauseTheLastFindingIsNotATakenIdentity($jobId, $code);
        }

        $attempt = $finding['attempt'] ?? null;

        if (! is_int($attempt) || $attempt !== $job->attempts) {
            throw RepointRefusedException::becauseTheFindingIsStale($jobId, sprintf(
                'the finding was written by attempt %s and the job has made %d',
                is_int($attempt) ? (string) $attempt : 'unknown',
                $job->attempts,
            ));
        }

        if (($finding['reserved_provider_id'] ?? null) !== $job->reserved_provider_id) {
            throw RepointRefusedException::becauseTheFindingIsStale($jobId, sprintf(
                'the finding is about identity %s and the job holds %s',
                is_string($finding['reserved_provider_id'] ?? null) ? $finding['reserved_provider_id'] : 'unknown',
                (string) $job->reserved_provider_id,
            ));
        }

        $reason = is_string($finding['reason'] ?? null) ? $finding['reason'] : null;

        if ($reason !== CreateVpsHandler::REASON_NAMED_OTHERWISE) {
            throw RepointRefusedException::becauseOwnershipIsNotEstablished($jobId, $reason);
        }

        return $finding;
    }

    /**
     * The next id down this job's own derived sequence that it has never held.
     *
     * Deterministic, so the same job repointed on two machines lands on the
     * same id, and drawn by the create's own rule so it stays in the range
     * the platform's machines live in.
     *
     * @param  list<string>  $held
     */
    private function nextIdentity(ProvisioningJob $job, array $held, int $repointsSoFar): string
    {
        for ($n = $repointsSoFar + 1; $n <= $repointsSoFar + self::MAX_CANDIDATES; $n++) {
            $candidate = (string) CreateVpsHandler::derivedId($job->idempotency_key.'|repoint|'.$n);

            if (! in_array($candidate, $held, true)) {
                return $candidate;
            }
        }

        // Unreachable in practice: 64 draws from 90,000 ids all landing on
        // ids this one job has already held.
        throw RepointRefusedException::becauseNothingIsReserved((string) $job->getKey());
    }
}
