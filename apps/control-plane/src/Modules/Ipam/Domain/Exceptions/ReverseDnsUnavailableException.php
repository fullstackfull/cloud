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
    private string $errorCode = 'ipam.reverse_dns_unavailable';

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

    /**
     * The configured provider holds no reverse zone covering this address.
     *
     * This is a capability answer, not a failure: reverse zones are delegated
     * by whoever assigned the address block, and holding a domain says nothing
     * about holding the `in-addr.arpa` delegation for the addresses it points
     * at. An account can serve every forward zone the platform owns and have no
     * authority over one PTR.
     *
     * It has its own code — the brief calls this state
     * REVERSE_DNS_PROVIDER_UNAVAILABLE, spelled here in the dotted lowercase
     * every other error code on this API uses — because the remedy is
     * different from the other two. "Your address is not internet-routed" is
     * answered by the customer; this one is answered by an operator arranging
     * the delegation, and a client that cannot tell them apart will tell the
     * customer to fix something they cannot reach.
     *
     * What it is NOT is a reason to publish nothing and report success.
     */
    public static function providerCannotServeZone(string $address, string $provider, string $ptrName): self
    {
        $exception = new self(
            'Reverse DNS for this address cannot be published: the configured DNS provider does not '
            .'hold the reverse zone for it. This is a platform configuration matter and has been recorded.'
        );

        $exception->errorCode = 'ipam.reverse_dns_provider_unavailable';

        // The PTR name is named for the operator reading the log — it is what
        // they will search the provider for. The provider's own name is here
        // for the same reason. Neither is a secret, and neither is the address.
        return $exception->withContext([
            'address' => $address,
            'provider' => $provider,
            'ptr_name' => $ptrName,
        ]);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
