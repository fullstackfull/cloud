<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

final class UnknownPaymentProviderException extends DomainException
{
    /**
     * @param  list<string>  $known
     */
    public static function named(string $name, array $known): self
    {
        $exception = new self(sprintf(
            'No payment provider is registered under "%s". Registered providers: %s.',
            $name,
            $known === [] ? 'none' : implode(', ', $known),
        ));

        return $exception->withContext(['requested' => $name, 'known' => implode(',', $known)]);
    }

    public function errorCode(): string
    {
        return 'payment.unknown_provider';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
