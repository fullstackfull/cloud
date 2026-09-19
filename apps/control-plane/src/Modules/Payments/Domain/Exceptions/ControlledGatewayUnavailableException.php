<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The controlled test gateway was asked to do something it must not.
 *
 * Either a real payment provider is configured — in which case nothing in the
 * platform may approve its own payments — or the caller presented a client
 * credential that does not belong to the intent.
 */
final class ControlledGatewayUnavailableException extends DomainException
{
    public static function becauseARealProviderIsConfigured(): self
    {
        return new self('The controlled payment gateway is not available when a real payment provider is configured.');
    }

    public static function becauseTheClientCredentialDoesNotMatch(): self
    {
        return new self('That credential does not belong to this payment.');
    }

    public function errorCode(): string
    {
        return 'payment.controlled_gateway_unavailable';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
