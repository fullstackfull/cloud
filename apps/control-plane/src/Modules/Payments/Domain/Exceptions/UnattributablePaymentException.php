<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A verified capture arrived that we cannot attach to a customer.
 *
 * The customer id travels in the intent metadata we set ourselves, so its
 * absence means either a payment created outside the platform or a metadata
 * bug. Guessing the owner would credit the wrong account, so the event is
 * failed and left for an operator with the provider reference in hand.
 */
final class UnattributablePaymentException extends DomainException
{
    public static function forReference(string $provider, string $reference): self
    {
        $exception = new self(sprintf(
            'The %s payment %s carries no customer reference, so it cannot be attributed to an account.',
            $provider,
            $reference,
        ));

        return $exception->withContext(['provider' => $provider, 'provider_reference' => $reference]);
    }

    public function errorCode(): string
    {
        return 'payment.unattributable';
    }
}
