<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Throwable;

/**
 * The panel could not be made to open a session, said in words a customer may
 * read.
 *
 * This class exists because of a collision between two things that are each
 * correct on their own.
 *
 * {@see HostingProviderException} carries the node's hostname, the WHM function
 * that was called and the panel's own words in its context, and it is right to:
 * that context is what an operator reads in the log when a node starts
 * refusing, and CpanelHostingProvider deliberately records the config key its
 * root API token is read from so a missing credential can be found without
 * guessing. And the shared renderer in bootstrap/app.php publishes a
 * DomainException's context verbatim as `error.details`, which is also right —
 * a domain exception's context is normally the caller's own order id or invoice
 * number.
 *
 * Together, on the one endpoint of this module that talks to a node while a
 * customer waits, they hand that customer the name of the machine their
 * neighbours' sites run on, the shape of the platform's WHM integration and the
 * config key naming its root credential. Every resource in this module goes out
 * of its way to withhold exactly that; a 502 must not be the door it walks back
 * out of.
 *
 * So the provider's exception is caught at the edge of the module and
 * re-thrown as this one, with the original chained as `previous` so that
 * nothing is lost from the log, the failure report or the redaction the log
 * stack already applies to an exception chain. What changes is only what the
 * customer is told: that the panel could not be reached, and which of their own
 * accounts it was about.
 *
 * The error code for a panel failure is deliberately unchanged from
 * HostingProviderException's. A client branching on
 * `hosting.provider_request_failed` is branching on a true fact — the platform
 * could not complete a request to a control panel — and the code is the part of
 * the contract that is meant to be stable.
 */
final class HostingPanelSessionFailedException extends DomainException
{
    private string $errorCode = 'hosting.provider_request_failed';

    private int $status = 502;

    /**
     * The panel refused, failed, or never answered.
     *
     * One message for all three. The distinction between "the panel said no"
     * and "the panel did not say anything in time" is an operational verdict —
     * it decides whether a job may be replayed — and it is recorded on the
     * chained exception for the log. It is not advice a customer can act on,
     * and a response that announced a timeout would invite exactly the client
     * retry loop this endpoint must not encourage.
     */
    public static function panelUnreachable(string $accountId, ?Throwable $previous = null): self
    {
        $exception = new self(
            'The control panel for this hosting account could not be reached just now, so no sign-in '
            .'session was opened. Nothing about your account has changed. Please try again shortly.',
            previous: $previous,
        );

        return $exception->withContext(['account_id' => $accountId]);
    }

    /**
     * The platform's own record of the node is incomplete or names a panel this
     * build cannot drive.
     *
     * Separated from the above because it is the platform's fault rather than
     * the node's, and a 5xx that says "bad gateway" for a missing config key
     * sends an operator looking at the wrong machine. The customer is told the
     * same thing either way.
     */
    public static function platformMisconfigured(string $accountId, ?Throwable $previous = null): self
    {
        $exception = new self(
            'This hosting account cannot be signed in to at the moment because of a problem on our side. '
            .'It has been recorded and nothing about your account has changed.',
            previous: $previous,
        );

        $exception->errorCode = 'hosting.panel_not_configured';
        $exception->status = 500;

        return $exception->withContext(['account_id' => $accountId]);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }
}
