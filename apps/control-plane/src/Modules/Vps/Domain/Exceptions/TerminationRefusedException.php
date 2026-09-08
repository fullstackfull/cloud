<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Domain\Exceptions;

use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A termination the platform will not start.
 *
 * Each refusal is a case where destroying the machine would be irreversible
 * and wrong: a service somebody is still paying for, a suspension that may yet
 * end with a payment, or work that is already done.
 */
final class TerminationRefusedException extends DomainException
{
    private string $errorCode = 'vps.termination_refused';

    public static function becauseItIsAlreadyOver(string $serviceId): self
    {
        $exception = new self('This service has already been terminated.');

        return $exception->withContext(['service_id' => $serviceId])->as('vps.already_terminated');
    }

    public static function becauseThereIsNoMachine(string $serviceId): self
    {
        $exception = new self('This service has no virtual machine to destroy.');

        return $exception->withContext(['service_id' => $serviceId])->as('vps.no_machine_to_destroy');
    }

    public static function becauseItIsStillInService(string $serviceId, ServiceStatus $status): self
    {
        $exception = new self(
            'A service is suspended before it is terminated, so that a customer who pays late gets their machine back.',
        );

        return $exception->withContext(['service_id' => $serviceId, 'status' => $status->value])
            ->as('vps.termination_before_suspension');
    }

    public static function becauseTheRetentionWindowIsOpen(string $serviceId, string $releasesAt): self
    {
        $exception = new self('The retention window on this suspended service has not elapsed.');

        return $exception->withContext(['service_id' => $serviceId, 'releases_at' => $releasesAt])
            ->as('vps.retention_window_open');
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
