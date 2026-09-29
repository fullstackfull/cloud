<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A repoint the platform will not perform, whoever is asking.
 *
 * Moving a create to a new provider identity is the way out of one finding
 * only: a machine somebody else owns, by name, sitting at the id this build
 * reserved. In every other case a new identity is a licence to build a
 * machine beside one that may already be this build's — the second machine
 * F-15 exists to prevent — so each refusal below names the case, and there is
 * no flag that overrides any of them. Adoption and a person's look at the
 * hypervisor are the routes out of those.
 */
final class RepointRefusedException extends DomainException
{
    private string $errorCode = 'provisioning.repoint_refused';

    /**
     * The job is not stopped awaiting a person. Queued or running, an attempt
     * may be about to use, or be using, the identity this would replace;
     * settled, there is nothing left to build.
     */
    public static function becauseTheJobHasNotStopped(string $jobId, string $status): self
    {
        return (new self('This job is not stopped awaiting a person, so its identity cannot be changed.'))
            ->withContext(['provisioning_job_id' => $jobId, 'status' => $status])
            ->as('provisioning.repoint_job_not_stopped');
    }

    /**
     * Not a VPS create, or a create that never reserved anything.
     */
    public static function becauseNothingIsReserved(string $jobId): self
    {
        return (new self('This job holds no provider identity, so there is nothing to repoint.'))
            ->withContext(['provisioning_job_id' => $jobId])
            ->as('provisioning.repoint_nothing_reserved');
    }

    /**
     * The job already carries a provider reference or a provider task: a
     * machine exists that is this job's, and adoption is the way forward.
     */
    public static function becauseSomethingWasBuilt(string $jobId, string $evidence): self
    {
        return (new self('This job already has a resource at the provider. Adopt it instead of moving the job to a new identity.'))
            ->withContext(['provisioning_job_id' => $jobId, 'provider_evidence' => $evidence])
            ->as('provisioning.repoint_would_duplicate');
    }

    /**
     * The job's last finding is not that its identity is taken.
     */
    public static function becauseTheLastFindingIsNotATakenIdentity(string $jobId, ?string $finding): self
    {
        return (new self('This job\'s last attempt did not find its identity taken, so there is nothing a new identity would fix.'))
            ->withContext(['provisioning_job_id' => $jobId, 'last_finding' => $finding])
            ->as('provisioning.repoint_no_identity_finding');
    }

    /**
     * The finding that the identity is taken belongs to an earlier attempt,
     * or to an identity the job no longer holds.
     */
    public static function becauseTheFindingIsStale(string $jobId, string $why): self
    {
        return (new self('The finding that this job\'s identity is taken is not from its last attempt, or not about the identity it holds now. Retry it to find out what is there now.'))
            ->withContext(['provisioning_job_id' => $jobId, 'why' => $why])
            ->as('provisioning.repoint_finding_is_stale');
    }

    /**
     * A machine is at the identity and it was not established that it is
     * somebody else's.
     */
    public static function becauseOwnershipIsNotEstablished(string $jobId, ?string $reason): self
    {
        return (new self('The machine at this job\'s identity was not established to be somebody else\'s. Look at it; if it is this build\'s, adopt it.'))
            ->withContext(['provisioning_job_id' => $jobId, 'reason' => $reason])
            ->as('provisioning.repoint_ownership_not_established');
    }

    /**
     * Every id the repoint would draw next is one this job has already held.
     * Not a finding about the job: the draw ran out, and a person picks the
     * way forward at the hypervisor.
     */
    public static function becauseNoFreshIdentityRemains(string $jobId, int $candidates): self
    {
        return (new self('Every provider identity this job could be moved to is one it has already held.'))
            ->withContext(['provisioning_job_id' => $jobId, 'candidates_tried' => $candidates])
            ->as('provisioning.repoint_no_fresh_identity');
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return 409;
    }

    private function as(string $code): self
    {
        $this->errorCode = $code;

        return $this;
    }
}
