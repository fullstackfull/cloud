<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A refund would return more than was ever captured.
 *
 * Providers do enforce this too, but only per request and only once the
 * request has been made. Two operators refunding the same transaction at the
 * same moment can each pass a naive "amount <= captured" check and together
 * overshoot, so the balance is recomputed under a row lock and this exception
 * is raised locally before any money moves.
 */
final class RefundExceedsCaptureException extends DomainException
{
    public static function forTransaction(
        string $transactionId,
        Money $requested,
        Money $refundable,
        Money $captured,
    ): self {
        $exception = new self(sprintf(
            'Cannot refund %s against transaction %s: only %s of the %s captured remains refundable.',
            (string) $requested,
            $transactionId,
            (string) $refundable,
            (string) $captured,
        ));

        return $exception->withContext([
            'transaction_id' => $transactionId,
            'requested_minor' => $requested->minorUnits(),
            'refundable_minor' => $refundable->minorUnits(),
            'captured_minor' => $captured->minorUnits(),
            'currency' => $requested->currency(),
        ]);
    }

    public function errorCode(): string
    {
        return 'payment.refund_exceeds_capture';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
