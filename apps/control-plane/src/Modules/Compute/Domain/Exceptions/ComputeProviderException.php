<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Throwable;

/**
 * A hypervisor refused or failed a request.
 *
 * Adapters translate every transport and API failure into this one, so that
 * nothing above Infrastructure/Providers has to know what a Proxmox error
 * envelope looks like — and, more importantly, so that a raw client exception
 * never reaches a log. A Guzzle exception stringifies the request that caused
 * it, and for this provider that request carries the API token in an
 * Authorization header. The message here is composed by us; the hypervisor's
 * own text travels only after it has been through the redactor.
 */
final class ComputeProviderException extends DomainException
{
    /**
     * Whether the hypervisor's answer settles what happened to the request.
     *
     * False for a refusal the cluster spoke out loud — a 400 naming a storage
     * that does not exist, a 403 — because the cluster answered and nothing
     * was built. True when the platform stopped waiting: a timeout, a dropped
     * connection, a gateway that gave up in front of the cluster. A timeout is
     * not a failure. Proxmox answers a create in milliseconds and builds for
     * minutes; a request that timed out may well have been accepted, and the
     * machine may be booting right now.
     *
     * The distinction has to survive the translation, because the two demand
     * opposite responses and the caller cannot re-derive it. Retrying an
     * indeterminate create gives the customer a second machine the platform
     * never bills for and never deletes; releasing its node capacity and
     * moving on gives that capacity to somebody else while the first machine
     * is still running on it. The only correct handling is to keep the
     * reservation, keep the task id, and ask the cluster what happened.
     */
    private bool $indeterminate = false;

    /**
     * @param  array<string, scalar|null>  $context  Already redacted by the adapter. Nothing that has not been
     *                                               through SecretRedactor may be put here.
     * @param  bool  $indeterminate  True when the outcome at the hypervisor is unknown rather than known-failed.
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
                    ? 'The %s compute provider did not answer the "%s" request in time; the outcome at the cluster is unknown.'
                    : 'The %s compute provider could not complete the "%s" request.',
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
            // Last, so that no adapter's context can accidentally overwrite
            // it. Carried in the context as well as on the object because
            // that is what survives into a log line and a queued job's
            // failure record, which is where somebody decides whether to
            // retry.
            'indeterminate' => $indeterminate,
        ]);
    }

    /**
     * Whether the request may still be in flight at the hypervisor.
     *
     * A caller that mutates anything MUST branch on this: an indeterminate
     * create, resize or destroy is quarantined for reconciliation against the
     * task id, never retried and never compensated.
     */
    public function isIndeterminate(): bool
    {
        return $this->indeterminate;
    }

    /**
     * The provider answered, but with something this adapter cannot read —
     * a missing UPID, a body that is not the documented envelope.
     *
     * Separated from a plain failure because it means the two systems disagree
     * about the API, which no retry will fix.
     *
     * $indeterminate is set when the unreadable answer was to a MUTATION: a
     * create the cluster accepted and did not give a UPID for is a machine
     * that may well be building with no handle on it, which is a worse
     * position than an outright refusal and must be quarantined, not retried.
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
            'The %s compute provider returned a response the adapter could not interpret during "%s": %s',
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

    public function errorCode(): string
    {
        return 'compute.provider_request_failed';
    }

    /**
     * A gateway failure rather than a validation error: the caller did nothing
     * wrong. Whether it may be retried is NOT answered by this status — that
     * is what isIndeterminate() is for, and a mutation whose outcome is
     * unknown must never be retried on the strength of a 502.
     */
    public function httpStatus(): int
    {
        return 502;
    }
}
