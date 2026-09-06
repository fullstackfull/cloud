<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The selected provider cannot settle in the requested currency.
 *
 * Raised before any request leaves the process. Currencies are never converted
 * to something the provider does accept: the customer agreed to a price in one
 * currency and must be charged in that currency.
 */
final class UnsupportedCurrencyException extends DomainException
{
    public static function forProvider(string $provider, string $currency): self
    {
        $exception = new self(sprintf(
            'The %s provider does not settle in %s, and the platform never converts a price to make a charge fit.',
            $provider,
            $currency,
        ));

        return $exception->withContext(['provider' => $provider, 'currency' => $currency]);
    }

    public function errorCode(): string
    {
        return 'payment.unsupported_currency';
    }
}
