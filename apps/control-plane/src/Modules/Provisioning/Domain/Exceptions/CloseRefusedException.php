<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Exceptions;

use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A close the platform will not perform (CloseAJobWhoseServiceEnded).
 *
 * Closing takes a job off the review list without running it or settling
 * what it did. That is only safe where nothing the job did can matter any
 * more - its service has ended - and where it cannot have left a resource
 * behind that nobody would then look for.
 */
final class CloseRefusedException extends DomainException
{
    private string $errorCode = 'provisioning.close_refused';

    public static function becauseTheJobIsNotInReview(string $jobId, ProvisioningJobStatus $status): self
    {
        $exception = new self('Only a job waiting for review can be closed.');

        return $exception->withContext(['provisioning_job_id' => $jobId, 'status' => $status->value])
            ->as('provisioning.close_not_in_review');
    }

    /**
     * Only a job that changes a resource which already exists - a power
     * change, a resize, a package change - is closed. A build or a destroy in
     * review may have left a resource the platform does not know about, and
     * closing it would take the only pointer to that resource off the review
     * list; a rebuild is settled by its operation's own verdict. The
     * WordPress kinds are not accepted either: a close says nothing about
     * the sites they act on.
     */
    public static function becauseOfItsKind(string $jobId, ProvisioningJobKind $kind): self
    {
        $exception = new self('Only a power change, a resize or a package change can be closed: a build or a destroy may have left a resource at the provider, and a rebuild is settled by its operation\'s own verdict.');

        return $exception->withContext(['provisioning_job_id' => $jobId, 'kind' => $kind->value])
            ->as('provisioning.close_not_for_this_kind');
    }

    public static function becauseTheServiceHasNotEnded(string $jobId, ?string $serviceId): self
    {
        $exception = new self('The service this job works for has not ended, so what the job was doing still matters: retry it.');

        return $exception->withContext(['provisioning_job_id' => $jobId, 'service_id' => $serviceId])
            ->as('provisioning.close_service_not_ended');
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
