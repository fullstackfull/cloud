<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A currency the platform does not bill in reached a place that would have
 * booked money in it.
 *
 * Refusing is the whole point. The alternative — substituting the platform's
 * default — is how a customer ends up holding an invoice in a currency nobody
 * mentioned, which is the defect this exception exists to make impossible.
 */
final class UnsupportedBillingCurrencyException extends DomainException
{
    /**
     * @param  list<string>  $enabled
     */
    public static function forCurrency(string $currency, array $enabled): self
    {
        $exception = new self(sprintf(
            'This platform does not bill in %s; it bills in %s.',
            $currency,
            implode(', ', $enabled),
        ));

        return $exception->withContext([
            'currency' => $currency,
            'currencies' => implode(', ', $enabled),
        ]);
    }

    public function errorCode(): string
    {
        return 'billing.currency_not_supported';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
