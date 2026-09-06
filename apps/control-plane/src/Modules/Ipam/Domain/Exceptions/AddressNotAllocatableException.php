<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Domain\Exceptions;

use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Enums\IpPoolScope;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Something asked for an address that must not be handed out.
 *
 * The infrastructure addresses (network, broadcast, gateway) are the dangerous
 * case: assigning the gateway does not break one customer, it removes the
 * default route for every host on the subnet at once.
 */
final class AddressNotAllocatableException extends DomainException
{
    public static function becauseOfStatus(string $address, IpAddressStatus $status): self
    {
        $exception = new self(sprintf(
            'Address %s is %s and cannot be allocated.',
            $address,
            $status->value,
        ));

        return $exception->withContext(['address' => $address, 'status' => $status->value]);
    }

    public static function infrastructureAddress(string $address, string $role): self
    {
        $exception = new self(sprintf(
            'Address %s is the subnet %s address and is never allocatable.',
            $address,
            $role,
        ));

        return $exception->withContext(['address' => $address, 'role' => $role]);
    }

    /**
     * The pool exists and has free addresses, but not for this holder.
     *
     * Management addresses reach the hypervisor and BMC control planes; one on
     * a customer's NIC is a lateral-movement path into the platform itself.
     */
    public static function notCustomerAllocatable(string $poolSlug, IpPoolScope $scope): self
    {
        $exception = new self(sprintf(
            'IP pool "%s" is %s scoped and its addresses are never assigned to customer services.',
            $poolSlug,
            $scope->value,
        ));

        return $exception->withContext(['pool_slug' => $poolSlug, 'scope' => $scope->value]);
    }

    /**
     * IPv6 is modelled as a delegated prefix per service, not as rows. There
     * is no v6 address to allocate because there is no v6 address table.
     */
    public static function ipv6IsDelegatedNotEnumerated(string $cidr): self
    {
        $exception = new self(sprintf(
            'Subnet %s is IPv6: v6 is delegated as a prefix per service and is never expanded into individual addresses.',
            $cidr,
        ));

        return $exception->withContext(['cidr' => $cidr]);
    }

    public function errorCode(): string
    {
        return 'ipam.address_not_allocatable';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
