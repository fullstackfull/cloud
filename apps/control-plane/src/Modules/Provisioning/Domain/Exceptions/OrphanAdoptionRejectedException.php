<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Exceptions;

use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * An adoption the platform refuses to perform.
 *
 * Adoption writes "this provider resource is the one this job created" into
 * the record. Getting that wrong is worse than the orphan it fixes: a job that
 * already succeeded points at one machine, and adopting a second reference
 * would silently orphan the first while making the books look tidy.
 */
final class OrphanAdoptionRejectedException extends DomainException
{
    private string $errorCode = 'provisioning.adoption_rejected';

    public static function becauseJobIsSettled(string $jobId, ProvisioningJobStatus $status): self
    {
        $exception = new self('This job has already been settled, so it cannot adopt a resource.');

        return $exception->withContext(['provisioning_job_id' => $jobId, 'status' => $status->value])
            ->as('provisioning.adoption_job_settled');
    }

    public static function becauseJobIsRunning(string $jobId): self
    {
        $exception = new self('This job is still running; adopting a resource under a live attempt would race it.');

        return $exception->withContext(['provisioning_job_id' => $jobId])
            ->as('provisioning.adoption_job_running');
    }

    public static function becauseReferenceIsAlreadyClaimed(string $reference, string $jobId): self
    {
        $exception = new self('That provider resource is already attached to another job.');

        return $exception->withContext(['provider_reference' => $reference, 'claimed_by_job_id' => $jobId])
            ->as('provisioning.adoption_reference_claimed');
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
