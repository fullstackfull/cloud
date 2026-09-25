<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A correction to the domain a hosting build will serve, refused.
 *
 * The name a domain may take is answered by `HostingDomainConflictException`;
 * this is the other half — whether this job may be renamed at all, and
 * whether what was sent is a name:
 *
 *  - `hosting.job_not_a_hosting_build` — only a hosting account build carries
 *    a primary domain;
 *  - `hosting.job_not_settled` — a queued or running job is being worked on
 *    by a worker that has already read its payload, so changing it underneath
 *    that worker changes nothing it will do and misleads whoever reads the
 *    job afterwards. Renaming is for a job that has stopped;
 *  - `hosting.domain_unusable` — the platform's own host-name rule refused
 *    it, and the sentence says why.
 */
final class HostingJobDomainRefusedException extends DomainException
{
    private string $errorCode = 'hosting.job_not_settled';

    private int $httpStatus = 409;

    public static function becauseItIsNotAHostingBuild(string $jobId, string $kind): self
    {
        $exception = new self('Only a hosting account build serves a domain.');
        $exception->errorCode = 'hosting.job_not_a_hosting_build';

        return $exception->withContext(['job_id' => $jobId, 'kind' => $kind]);
    }

    public static function becauseItHasNotStopped(string $jobId, string $status): self
    {
        $exception = new self(sprintf(
            'The job is %s. Its domain can be corrected once it has stopped, and then retried.',
            $status,
        ));

        return $exception->withContext(['job_id' => $jobId, 'status' => $status]);
    }

    public static function becauseItIsNotAHostName(string $domain, string $reason): self
    {
        $exception = new self(sprintf('"%s" cannot be served as a domain: %s.', $domain, $reason));
        $exception->errorCode = 'hosting.domain_unusable';
        $exception->httpStatus = 422;

        return $exception->withContext(['reason' => $reason]);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
