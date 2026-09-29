<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A hosting service that has already ended was asked to end again.
 *
 * The account action converges on an account that is already gone, which is
 * right for a retried cleanup. Ending a service is different: it is a decision
 * written into the audit trail, and a second one for a service that is over
 * would record an ending that never happened. The same refusal the VPS and
 * dedicated paths have always given (`vps.already_terminated`,
 * `dedicated.already_terminated`), for the kind that reached the service route
 * only with F-19.
 */
final class HostingServiceAlreadyEndedException extends DomainException
{
    public static function forService(string $serviceId): self
    {
        $exception = new self('This hosting service has already been terminated.');

        return $exception->withContext(['service_id' => $serviceId]);
    }

    public function errorCode(): string
    {
        return 'hosting.already_terminated';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
