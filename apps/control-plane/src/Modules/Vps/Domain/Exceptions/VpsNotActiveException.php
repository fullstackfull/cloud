<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Domain\Exceptions;

use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The machine is not in a state where the platform may act on it at all.
 *
 * Refused outright rather than queued, and the difference matters more than it
 * looks. A queued power action against a service that is still being built
 * lands on the hypervisor at an unpredictable point in the build; against a
 * suspended service it undoes the suspension the platform imposed; against a
 * terminated one it starts a machine somebody has stopped paying for.
 *
 * The customer is told which state the service is actually in, because "no"
 * with no reason is a support ticket. The state is the acting customer's own
 * service, so nothing about another tenant leaks by naming it.
 */
final class VpsNotActiveException extends DomainException
{
    public static function forStatus(ServiceStatus $status): self
    {
        return (new self(sprintf(
            'This service is %s, so operations against it are not accepted. Only an active service can be started, '
            .'stopped, rebooted, reinstalled or attached to.',
            $status->value,
        )))->withContext(['service_status' => $status->value]);
    }

    public function errorCode(): string
    {
        return 'vps.not_active';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
