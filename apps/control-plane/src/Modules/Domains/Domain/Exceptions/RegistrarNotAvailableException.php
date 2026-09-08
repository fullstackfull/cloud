<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A registrar this platform has a seat for and cannot yet talk to.
 *
 * The honest failure at the boundary. It is not a bug, not an outage and not
 * something a customer can retry: the integration does not exist, and saying
 * so plainly is better than a timeout that looks like it might work next time.
 */
final class RegistrarNotAvailableException extends DomainException
{
    public static function forSyRegistry(): self
    {
        return (new self(
            '.sy domains are not on sale yet. The registry integration is waiting on licensing and technical documentation.',
        ))->withContext(['provider' => 'sy_registry']);
    }

    /**
     * A namespace routed to a driver that does not serve it.
     *
     * A catalogue mistake rather than a missing integration, and it is worth
     * its own constructor because the two need different people: this one is
     * fixed by editing a TLD row, and the one above by signing an agreement.
     */
    public static function doesNotServe(string $provider, string $tld): self
    {
        return (new self(
            'This namespace is not on sale.',
        ))->withContext(['provider' => $provider, 'tld' => $tld]);
    }

    public function errorCode(): string
    {
        return 'domain.registrar_not_available';
    }

    /**
     * 503 rather than 500: nothing is broken. The capability is not there yet,
     * and the distinction matters to whoever is reading the alert.
     */
    public function httpStatus(): int
    {
        return 503;
    }
}
