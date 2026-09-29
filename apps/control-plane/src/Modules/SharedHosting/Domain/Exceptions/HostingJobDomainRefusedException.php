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
 *  - `hosting.job_not_settled` — renaming is for a build that failed or is
 *    waiting for review, the two states a retry starts from. A running job is
 *    being worked on by a worker that has already read its payload, so
 *    changing it underneath that worker changes nothing it will do and
 *    misleads whoever reads the job afterwards; a queued one is read by
 *    whichever worker picks it up, so a correction racing that pickup lands
 *    or not by chance. A succeeded build has made its account and a
 *    cancelled one will make none, so a new name on either changes nothing
 *    the platform will do;
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

    public static function becauseItIsNotAwaitingRepair(string $jobId, string $status): self
    {
        $exception = new self(sprintf(
            'The job is %s. Only a build that failed, or is waiting for review, can have its domain corrected and then be retried.',
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
