<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * There were not enough allocatable addresses to satisfy a reservation.
 *
 * This is thrown, never returned as null. A null would be checked at the one
 * call site the author remembered and silently propagated everywhere else, and
 * the failure mode of a missing check here is a VM built with no address —
 * provisioned, billed, and unreachable.
 *
 * It is deliberately loud in a second way: exhaustion is a capacity signal,
 * not a customer error. The context carries the scope so the alert names the
 * pool an operator has to widen.
 */
final class IpPoolExhaustedException extends DomainException
{
    public static function forSubnet(string $subnetId, string $cidr, int $requested, int $available): self
    {
        $exception = new self(sprintf(
            'Subnet %s has %d allocatable address(es) left; %d were requested.',
            $cidr,
            $available,
            $requested,
        ));

        return $exception->withContext([
            'scope_type' => 'subnet',
            'subnet_id' => $subnetId,
            'cidr' => $cidr,
            'requested' => $requested,
            'available' => $available,
        ]);
    }

    public static function forPool(string $poolId, string $slug, int $requested, int $available): self
    {
        $exception = new self(sprintf(
            'IP pool "%s" has %d allocatable address(es) left; %d were requested.',
            $slug,
            $available,
            $requested,
        ));

        return $exception->withContext([
            'scope_type' => 'pool',
            'pool_id' => $poolId,
            'slug' => $slug,
            'requested' => $requested,
            'available' => $available,
        ]);
    }

    /**
     * Exhausted as a VPS build asks: counting only addresses in subnets whose
     * network a customer machine can be plugged into. The sentence says so,
     * because "0 left" in a pool full of available addresses on a bridgeless
     * or management segment sends an operator to buy address space when what
     * is missing is a bridge.
     */
    public static function onSegmentsAMachineCanBeAttachedTo(string $name, string $id, string $scopeType, int $requested, int $available): self
    {
        $exception = new self(sprintf(
            '%s "%s" has %d allocatable address(es) left on a network a customer machine can be attached to '
            .'(active, customer-facing, with a bridge); %d were requested. Addresses on any other network are not '
            .'counted.',
            $scopeType === 'subnet' ? 'Subnet' : 'IP pool',
            $name,
            $available,
            $requested,
        ));

        return $exception->withContext([
            'scope_type' => $scopeType,
            $scopeType === 'subnet' ? 'subnet_id' : 'pool_id' => $id,
            $scopeType === 'subnet' ? 'cidr' : 'slug' => $name,
            'requested' => $requested,
            'available' => $available,
            'only_attachable_networks' => true,
        ]);
    }

    public function errorCode(): string
    {
        return 'ipam.pool_exhausted';
    }

    /**
     * 409 rather than 422: the request was perfectly valid, it simply cannot
     * be satisfied in the platform's current state. A retry after capacity is
     * added will succeed with the identical body.
     */
    public function httpStatus(): int
    {
        return 409;
    }
}
