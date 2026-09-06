<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Throwable;

/**
 * A control panel refused or failed a request.
 *
 * Adapters translate every transport and API failure into this one, so that
 * nothing above Infrastructure/Providers has to know what a WHM metadata
 * envelope or a DirectAdmin `error=1` body looks like — and, more importantly,
 * so that a raw HTTP client exception never reaches a log. A client exception
 * stringifies the request that caused it, and every request these adapters
 * make carries an API token or a login key in its Authorization header. The
 * message here is composed by us; the panel's own words travel only after they
 * have been through the adapter's scrubber and the shared redactor.
 */
final class HostingProviderException extends DomainException
{
    /**
     * Whether the panel's answer settles what happened to the request.
     *
     * False for a refusal the panel spoke out loud — a WHM envelope with
     * result=0 naming a package that does not exist, a DirectAdmin body with
     * error=1 — because the panel answered and nothing was created. True when
     * the platform stopped waiting: a timeout, a dropped connection, a gateway
     * that gave up in front of the panel.
     *
     * A timeout is not a failure. WHM's createacct builds a home directory,
     * a mail store, a database user and a DNS zone before it answers, and a
     * loaded node takes far longer over that than the platform is willing to
     * wait. A create that timed out has very possibly been accepted, so
     * retrying it is how a customer ends up with two accounts — the second of
     * which collides with the first on the username, or worse, succeeds under
     * a generated variant that nobody bills for and nobody deletes.
     */
    private bool $indeterminate = false;

    /**
     * @param  array<string, scalar|null>  $context  Already scrubbed by the adapter. Nothing that has not been
     *                                               through the connection's own scrubber and SecretRedactor
     *                                               may be put here.
     * @param  bool  $indeterminate  True when the outcome at the panel is unknown rather than known-failed.
     */
    public static function requestFailed(
        string $panel,
        string $operation,
        array $context = [],
        ?Throwable $previous = null,
        bool $indeterminate = false,
    ): self {
        $exception = new self(
            sprintf(
                $indeterminate
                    ? 'The %s panel did not answer the "%s" request in time; the outcome on the node is unknown.'
                    : 'The %s panel could not complete the "%s" request.',
                $panel,
                $operation,
            ),
            previous: $previous,
        );

        $exception->indeterminate = $indeterminate;

        return $exception->withContext([
            'panel' => $panel,
            'operation' => $operation,
            ...$context,
            // Last, so no adapter's context can accidentally overwrite it.
            // Carried in the context as well as on the object because that is
            // what survives into a log line and a queued job's failure record,
            // which is where somebody decides whether to retry.
            'indeterminate' => $indeterminate,
        ]);
    }

    /**
     * The panel answered, but with something the adapter cannot read — a body
     * that is not the documented envelope, a success with no account name.
     *
     * Separated from a plain failure because it means the two systems disagree
     * about the API, which no retry will fix.
     *
     * $indeterminate is set when the unreadable answer was to a MUTATION: a
     * createacct the panel accepted and did not name the account for is an
     * account that may well exist with no handle on it, which is a worse
     * position than an outright refusal.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function unexpectedResponse(
        string $panel,
        string $operation,
        string $detail,
        array $context = [],
        bool $indeterminate = false,
    ): self {
        $exception = new self(sprintf(
            'The %s panel returned a response the adapter could not interpret during "%s": %s',
            $panel,
            $operation,
            $detail,
        ));

        $exception->indeterminate = $indeterminate;

        return $exception->withContext([
            'panel' => $panel,
            'operation' => $operation,
            ...$context,
            'indeterminate' => $indeterminate,
        ]);
    }

    /**
     * Whether the request may still be in flight at the panel.
     *
     * A caller that mutates anything MUST branch on this: an indeterminate
     * create, package change or termination is quarantined for reconciliation
     * against the panel's own account list, never retried and never
     * compensated.
     */
    public function isIndeterminate(): bool
    {
        return $this->indeterminate;
    }

    public function errorCode(): string
    {
        return 'hosting.provider_request_failed';
    }

    /**
     * A gateway failure rather than a validation error: the caller did nothing
     * wrong. Whether it may be retried is NOT answered by this status — that is
     * what isIndeterminate() is for.
     */
    public function httpStatus(): int
    {
        return 502;
    }
}
