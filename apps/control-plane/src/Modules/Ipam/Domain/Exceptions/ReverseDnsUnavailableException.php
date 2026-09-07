<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Domain\Exceptions;

use Lynomia\Modules\Ipam\Domain\Enums\IpPoolScope;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The address exists and belongs to the caller, but no PTR can be published
 * for it.
 *
 * 409 rather than 422: nothing about the request is malformed. The address is
 * simply not one the platform can publish reverse DNS for, and no rewording of
 * the body changes that.
 */
final class ReverseDnsUnavailableException extends DomainException
{
    /**
     * The address is not routed on the public internet.
     *
     * PTR records for RFC 1918 space are not delegated to anybody — there is no
     * zone for the platform to write into — and a management address is never a
     * customer's to name. Both are refused here rather than discovered as a
     * provider rejection minutes later.
     */
    public static function notInternetRouted(IpPoolScope $scope): self
    {
        $exception = new self(sprintf(
            'Reverse DNS is only published for internet-routed addresses; this address is in a %s pool.',
            $scope->value,
        ));

        return $exception->withContext(['scope' => $scope->value]);
    }

    /**
     * The assignment has ended. The address may already be somebody else's, so
     * a PTR naming this customer's host would be published onto a stranger's
     * address.
     */
    public static function assignmentReleased(string $assignmentId): self
    {
        $exception = new self('That address is no longer assigned, so its reverse DNS cannot be changed.');

        return $exception->withContext(['assignment_id' => $assignmentId]);
    }

    public function errorCode(): string
    {
        return 'ipam.reverse_dns_unavailable';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
