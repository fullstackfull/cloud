<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * A refund was requested for zero or a negative amount.
 *
 * A negative refund is a charge wearing a disguise, and a zero refund is a
 * provider round trip that can only fail. Both are refused before anything is
 * reserved.
 */
final class InvalidRefundAmountException extends DomainException
{
    public static function notPositive(Money $amount): self
    {
        $exception = new self(sprintf(
            'A refund must be for a positive amount; %s was requested.',
            (string) $amount,
        ));

        return $exception->withContext([
            'requested_minor' => $amount->minorUnits(),
            'currency' => $amount->currency(),
        ]);
    }

    public function errorCode(): string
    {
        return 'payment.invalid_refund_amount';
    }
}
