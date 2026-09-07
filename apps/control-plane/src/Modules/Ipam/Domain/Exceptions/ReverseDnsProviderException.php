<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The DNS provider refused a record, or stopped answering while writing one.
 *
 * The two cases are carried apart on purpose, and the flag is the whole reason
 * this class exists rather than a plain exception:
 *
 *  - **refused** — the provider answered, and the answer was no. Nothing was
 *    written; the record is failed and the customer can be told so.
 *  - **indeterminate** — the platform stopped waiting. The provider may have
 *    published the record a moment after the socket closed, and asking again
 *    is not free: a retry loop against a zone API is how one address ends up
 *    with a record nobody meant to keep. A timed-out publish is left where it
 *    is, marked pending, for a person to look at.
 */
final class ReverseDnsProviderException extends DomainException
{
    private bool $indeterminate = false;

    public static function refused(string $address, string $because): self
    {
        $exception = new self(sprintf('The DNS provider refused the reverse record for %s: %s', $address, $because));

        return $exception->withContext(['address' => $address, 'reason' => $because]);
    }

    /**
     * The call did not come back. NOT a failure, and never retried from an
     * HTTP request or from a job that has already made the attempt.
     */
    public static function timedOut(string $address): self
    {
        $exception = new self(sprintf(
            'The DNS provider stopped answering while publishing the reverse record for %s; '
            .'whether the record was written is unknown.',
            $address,
        ));

        $exception->indeterminate = true;

        return $exception->withContext(['address' => $address, 'indeterminate' => true]);
    }

    public function isIndeterminate(): bool
    {
        return $this->indeterminate;
    }

    public function errorCode(): string
    {
        return 'ipam.reverse_dns_provider_failed';
    }

    /**
     * 502: the platform's own request was fine and a third party did not
     * complete it. Reached only when something calls a provider synchronously;
     * the customer endpoint publishes out of band and never renders this.
     */
    public function httpStatus(): int
    {
        return 502;
    }
}
