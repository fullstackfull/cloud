<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Throwable;

/**
 * A baseboard management controller refused or failed a request.
 *
 * Every adapter translates its transport failures into this one type, so that
 * nothing above Infrastructure/Providers knows what a Redfish error payload
 * looks like or what ipmitool prints on stderr — and, far more importantly, so
 * that a raw client exception never reaches a log. A Guzzle exception
 * stringifies the request that caused it, and every Redfish request carries an
 * Authorization header; a Symfony process exception stringifies the command
 * line, and every ipmitool command line carries the BMC password in argv.
 *
 * The message here is composed by us. The controller's own words travel only
 * after the adapter has scrubbed them.
 */
final class DedicatedProviderException extends DomainException
{
    /**
     * Whether the controller's answer settles what happened to the request.
     *
     * False for a refusal spoken out loud — a 400 naming an unsupported reset
     * type, a 401 — because the BMC answered and nothing happened. True when
     * the platform stopped waiting: a timeout, a dropped connection, an
     * ipmitool process killed at its deadline.
     *
     * On physical hardware the distinction is worth more than it is on a
     * hypervisor. A power-off request that timed out may well have been
     * executed, and a reset that timed out may have dropped a machine into a
     * network install that is running right now. Retrying either is how a
     * customer's server is power cycled twice, or reinstalled while it is
     * being reinstalled.
     */
    private bool $indeterminate = false;

    /**
     * @param  array<string, scalar|null>  $context  Already scrubbed by the adapter. Nothing that has not been
     *                                               through the redactor may be put here.
     */
    public static function requestFailed(
        string $provider,
        string $operation,
        array $context = [],
        ?Throwable $previous = null,
        bool $indeterminate = false,
    ): self {
        $exception = new self(
            sprintf(
                $indeterminate
                    ? 'The %s BMC adapter did not get an answer to the "%s" request in time; what the controller did is unknown.'
                    : 'The %s BMC adapter could not complete the "%s" request.',
                $provider,
                $operation,
            ),
            previous: $previous,
        );

        $exception->indeterminate = $indeterminate;

        return $exception->withContext([
            'provider' => $provider,
            'operation' => $operation,
            ...$context,
            // Last, so no adapter's context can overwrite it, and carried in
            // the context as well as on the object because the context is what
            // survives into a log line and a failed job's record — which is
            // where somebody decides whether to try again.
            'indeterminate' => $indeterminate,
        ]);
    }

    /**
     * The controller answered with something the adapter cannot read.
     *
     * Separated from a plain failure because it means the two systems disagree
     * about the API, which no retry fixes. $indeterminate is set when the
     * unreadable answer was to a MUTATION: a reset the BMC accepted and
     * described in a way we cannot parse is a machine that may be rebooting.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function unexpectedResponse(
        string $provider,
        string $operation,
        string $detail,
        array $context = [],
        bool $indeterminate = false,
    ): self {
        $exception = new self(sprintf(
            'The %s BMC adapter received a response it could not interpret during "%s": %s',
            $provider,
            $operation,
            $detail,
        ));

        $exception->indeterminate = $indeterminate;

        return $exception->withContext([
            'provider' => $provider,
            'operation' => $operation,
            ...$context,
            'indeterminate' => $indeterminate,
        ]);
    }

    /**
     * Whether the request may still be in flight at the controller.
     *
     * A caller that changed anything physical MUST branch on this. An
     * indeterminate power or boot operation is quarantined for a human, never
     * retried and never compensated.
     */
    public function isIndeterminate(): bool
    {
        return $this->indeterminate;
    }

    public function errorCode(): string
    {
        return 'dedicated.provider_request_failed';
    }

    /**
     * A gateway failure rather than a validation error: the caller did nothing
     * wrong. Whether it may be retried is NOT answered by this status — that
     * is what isIndeterminate() is for.
     */
    public function httpStatus(): int
    {
        return 502;
    }
}
