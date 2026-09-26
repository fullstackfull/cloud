<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Domain\Exceptions;

use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * An operator tried to clear a quarantine that is not theirs to clear.
 *
 * Clearance — adopting the address, or releasing it by hand — exists for one
 * kind of quarantine: a timeout, which no clock can end because time answers
 * none of its question. Every other quarantine ends on its own terms, and a
 * door built to recover capacity after a timeout must not become a way to
 * cut one of those short. See IpAllocator::adoptQuarantinedAddress().
 */
final class QuarantineNotClearableException extends DomainException
{
    private string $errorCode = 'ipam.quarantine_not_clearable';

    /** The address is not in quarantine at all — never was, or already cleared. */
    public static function becauseItIsNotQuarantined(string $address, IpAddressStatus $status): self
    {
        $exception = new self(sprintf(
            'Address %s is %s, not quarantined, so there is nothing to clear.',
            $address,
            $status->value,
        ));

        return $exception->withContext(['address' => $address, 'status' => $status->value])
            ->as('ipam.address_not_quarantined');
    }

    /** The address is quarantined, and its quarantine ends without an operator. */
    public static function becauseItsQuarantineIsNotATimeout(string $address, ?ReleaseReason $reason): self
    {
        $exception = new self(sprintf(
            'Address %s is quarantined for %s, which ends on the pool\'s window or when its machine is declared empty, not by clearance.',
            $address,
            $reason?->value ?? 'no recorded reason',
        ));

        return $exception->withContext(['address' => $address, 'quarantine_reason' => $reason?->value]);
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
