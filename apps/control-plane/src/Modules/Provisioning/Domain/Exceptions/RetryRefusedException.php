<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Exceptions;

use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A retry the platform will not perform, whoever is asking.
 *
 * An operator retry exists because some failures really are safe to run again:
 * a provider that refused before it built anything, a capacity error on a
 * cluster that has since been extended. The refusals below are the cases where
 * running the job again would not repeat an attempt but cause a second event —
 * a second machine, a second erased disk — and no permission makes that a
 * decision an operator should be able to take by pressing a button.
 */
final class RetryRefusedException extends DomainException
{
    private string $errorCode = 'provisioning.retry_refused';

    /**
     * The job has not stopped, so there is nothing to retry.
     */
    public static function becauseJobIsNotSettled(string $jobId, ProvisioningJobStatus $status): self
    {
        $exception = new self('This job has not stopped, so it cannot be run again.');

        return $exception->withContext(['provisioning_job_id' => $jobId, 'status' => $status->value])
            ->as('provisioning.retry_not_settled');
    }

    /**
     * The job's attempt reached the provider and something exists as a result.
     *
     * Adoption is the way out of this, not retrying: the resource is there and
     * a second attempt would build another beside it.
     */
    public static function becauseSomethingWasBuilt(string $jobId, string $evidence): self
    {
        $exception = new self(
            'This job already has a resource at the provider. Adopt it instead of running the job again.',
        );

        return $exception->withContext(['provisioning_job_id' => $jobId, 'provider_evidence' => $evidence])
            ->as('provisioning.retry_would_duplicate');
    }

    /**
     * The job destroys data, and its operation is already past the point where
     * the customer's disk stopped being intact.
     */
    public static function becauseTheDataIsAlreadyGone(string $jobId, string $operationState): self
    {
        $exception = new self(
            'This operation has already destroyed data. Running it again cannot undo that and may destroy more.',
        );

        return $exception->withContext(['provisioning_job_id' => $jobId, 'operation_state' => $operationState])
            ->as('provisioning.retry_destroys_again');
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
