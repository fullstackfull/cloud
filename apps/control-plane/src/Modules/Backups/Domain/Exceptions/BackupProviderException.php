<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The backup provider refused, or stopped answering.
 *
 * The same two-outcome split every provider boundary in this platform makes,
 * and it matters more here than almost anywhere else. A vzdump call that timed
 * out may have started a backup that is now running, writing to a datastore,
 * holding a lock on the machine's disks. Retrying it runs a second backup of
 * the same machine at the same time, which on a busy hypervisor is how a
 * customer's disk I/O disappears for an hour.
 *
 * So an indeterminate backup call is never retried. The row goes to
 * `NeedsReview` and an operator looks at the datastore.
 */
final class BackupProviderException extends DomainException
{
    private bool $indeterminate = false;

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function refused(string $provider, string $operation, string $because, array $context = []): self
    {
        $exception = new self(sprintf('%s refused %s: %s', $provider, $operation, $because));

        return $exception->withContext($context + [
            'provider' => $provider,
            'operation' => $operation,
            'reason' => $because,
        ]);
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function timedOut(string $provider, string $operation, array $context = []): self
    {
        $exception = new self(sprintf(
            '%s stopped answering during %s; whether a backup was started is unknown.',
            $provider,
            $operation,
        ));

        $exception->indeterminate = true;

        return $exception->withContext($context + [
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
        return 'backups.provider_failed';
    }

    public function httpStatus(): int
    {
        return 502;
    }
}
