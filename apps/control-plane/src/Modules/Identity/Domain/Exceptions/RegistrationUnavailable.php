<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Registration refused because this deployment cannot take an acceptance.
 *
 * 503 and not 422: nothing the visitor typed is wrong, and there is no field
 * to correct. The platform is not ready to enrol anyone, which is a condition
 * of the deployment and may be fixed without the visitor doing anything — so
 * the status says "try later" rather than "you made a mistake".
 *
 * It carries no context, and that is the point rather than an omission.
 *
 * Which of the platform's own launch prerequisites are outstanding is not a
 * visitor's business, and telling them would hand anybody who asked a
 * reliable readout of deployment state from an unauthenticated endpoint. The
 * first version of this class put the list of unpublished documents in the
 * exception context with a comment saying it was "logged and never rendered".
 * That was wrong: a domain exception's context is rendered to the client as
 * `error.details`, so the refusal answered every visitor with
 * `{"unpublished_documents":["terms","aup"]}`. A test asserting the response
 * body named nothing is what caught it.
 *
 * So the detail an operator needs is written to the log by the caller, where
 * it was supposed to be, and this exception says only that registration is
 * closed.
 */
final class RegistrationUnavailable extends DomainException
{
    public static function untilTheLegalDocumentsArePublished(): self
    {
        return new self('Registration is not available at the moment. Please try again later.');
    }

    public function errorCode(): string
    {
        return 'registration.unavailable';
    }

    public function httpStatus(): int
    {
        return 503;
    }
}
