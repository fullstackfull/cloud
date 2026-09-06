<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A string that was supposed to be an address, a CIDR or a MAC was not one.
 *
 * Addresses are stored as strings, so this exception is the only thing
 * standing between a typo and a row that no allocator will ever match and no
 * hypervisor will ever accept. Validation happens on the way in, at the value
 * object, rather than at each call site.
 */
final class InvalidIpAddressException extends DomainException
{
    public static function forAddress(string $address): self
    {
        $exception = new self(sprintf('"%s" is not a valid IP address.', $address));

        return $exception->withContext(['address' => $address]);
    }

    public static function forCidr(string $cidr, string $because): self
    {
        $exception = new self(sprintf('"%s" is not a valid CIDR block: %s.', $cidr, $because));

        return $exception->withContext(['cidr' => $cidr, 'reason' => $because]);
    }

    public static function forMacAddress(string $mac): self
    {
        $exception = new self(sprintf('"%s" is not a valid MAC address.', $mac));

        return $exception->withContext(['mac_address' => $mac]);
    }

    /**
     * The address does not belong to the subnet it is being filed under.
     */
    public static function outsideSubnet(string $address, string $cidr): self
    {
        $exception = new self(sprintf('Address %s does not fall inside %s.', $address, $cidr));

        return $exception->withContext(['address' => $address, 'cidr' => $cidr]);
    }

    public function errorCode(): string
    {
        return 'ipam.invalid_ip_address';
    }
}
