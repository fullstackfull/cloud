<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The DNS provider refused an operation, or stopped answering during one.
 *
 * The same two-outcome split the reverse-DNS side makes, and for the same
 * reason: a refusal is a decision the platform can act on, and a timeout is an
 * unknown that must not be resolved by trying again. A retried record creation
 * is how a zone ends up with two answers for one name.
 */
final class DnsProviderException extends DomainException
{
    private bool $indeterminate = false;

    public static function refused(string $provider, string $operation, string $because): self
    {
        $exception = new self(sprintf('%s refused %s: %s', $provider, $operation, $because));

        return $exception->withContext([
            'provider' => $provider,
            'operation' => $operation,
            'reason' => $because,
        ]);
    }

    /**
     * The call did not come back. Never retried by whoever made it.
     */
    public static function timedOut(string $provider, string $operation): self
    {
        $exception = new self(sprintf(
            '%s stopped answering during %s; whether it took effect is unknown.',
            $provider,
            $operation,
        ));

        $exception->indeterminate = true;

        return $exception->withContext([
            'provider' => $provider,
            'operation' => $operation,
            'indeterminate' => true,
        ]);
    }

    public function isIndeterminate(): bool
    {
        return $this->indeterminate;
    }

    public function errorCode(): string
    {
        return 'dns.provider_failed';
    }

    public function httpStatus(): int
    {
        return 502;
    }
}
