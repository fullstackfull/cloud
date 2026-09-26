<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

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

    /**
     * Part of the capture has already gone back to the customer's wallet
     * against the invoice it paid, and a card refund of it would return the
     * same money twice. Operator-facing: refunds are issued from the Control
     * Center.
     */
    public static function becauseItWentToTheWallet(
        string $transactionId,
        string $invoiceId,
        Money $requested,
        Money $stillHeld,
    ): self {
        $exception = new self(sprintf(
            'Cannot refund %s against transaction %s: only %s of invoice %s has not already been returned, part of it to the customer\'s wallet.',
            (string) $requested,
            $transactionId,
            (string) $stillHeld,
            $invoiceId,
        ));

        $exception->errorCode = 'payment.refund_exceeds_what_is_held';

        return $exception->withContext([
            'transaction_id' => $transactionId,
            'invoice_id' => $invoiceId,
            'requested_minor' => $requested->minorUnits(),
            'refundable_minor' => $stillHeld->minorUnits(),
            'currency' => $requested->currency(),
        ]);
    }

    private string $errorCode = 'payment.refund_exceeds_capture';

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
