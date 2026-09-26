<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Exceptions;

use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A decommission the platform will not perform.
 *
 * Every refusal here protects one of two people: the customer who may still be
 * about to pay, or the next customer, who must not be sold a machine with
 * somebody else's data on its disks.
 */
final class DecommissionRefusedException extends DomainException
{
    private string $errorCode = 'dedicated.decommission_refused';

    public static function becauseItIsAlreadyOver(string $serviceId): self
    {
        $exception = new self('This service has already been terminated.');

        return $exception->withContext(['service_id' => $serviceId])->as('dedicated.already_terminated');
    }

    public static function becauseThereIsNoServer(string $serviceId): self
    {
        $exception = new self('This service has no dedicated server attached to it.');

        return $exception->withContext(['service_id' => $serviceId])->as('dedicated.no_server_attached');
    }

    public static function becauseItIsStillInService(string $serviceId, ServiceStatus $status): self
    {
        $exception = new self(
            'A service is suspended before it is decommissioned, so that a customer who pays late keeps their machine.',
        );

        return $exception->withContext(['service_id' => $serviceId, 'status' => $status->value])
            ->as('dedicated.decommission_before_suspension');
    }

    public static function becauseTheRetentionWindowIsOpen(string $serviceId, string $releasesAt): self
    {
        $exception = new self('The retention window on this suspended service has not elapsed.');

        return $exception->withContext(['service_id' => $serviceId, 'releases_at' => $releasesAt])
            ->as('dedicated.retention_window_open');
    }

    /**
     * Raised by both doors out of maintenance — returning a machine to stock
     * and retiring it — so the sentence names both. It reaches no API body
     * and no locale file (a caller is answered from `dedicated.still_assigned`
     * in the error catalogue), but it does land verbatim in the structured
     * `ERROR` log on every refusal from either door, which is what an
     * operator reading logs sees.
     */
    public static function becauseItIsStillSomebodys(string $serverId): self
    {
        $exception = new self(
            'This server is still assigned to a customer, so it can neither go back into stock nor leave the fleet until its service is decommissioned.',
        );

        return $exception->withContext(['dedicated_server_id' => $serverId])
            ->as('dedicated.still_assigned');
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return 409;
    }

    private function as(string $code): self
    {
        $this->errorCode = $code;

        return $this;
    }
}
