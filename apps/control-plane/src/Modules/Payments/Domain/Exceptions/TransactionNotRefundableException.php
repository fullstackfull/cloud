<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Exceptions;

use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A refund was requested against something that never settled.
 *
 * Refunding a pending or failed charge is not a smaller version of refunding a
 * successful one — it either does nothing or cancels an authorisation — so it
 * is refused rather than quietly reinterpreted.
 */
final class TransactionNotRefundableException extends DomainException
{
    public static function inStatus(string $transactionId, TransactionStatus $status): self
    {
        $exception = new self(sprintf(
            'Transaction %s is %s and cannot be refunded; only a captured payment can.',
            $transactionId,
            $status->value,
        ));

        return $exception->withContext(['transaction_id' => $transactionId, 'status' => $status->value]);
    }

    public static function withoutProviderReference(string $transactionId): self
    {
        $exception = new self(sprintf(
            'Transaction %s has no provider reference, so there is nothing at the provider to refund.',
            $transactionId,
        ));

        return $exception->withContext(['transaction_id' => $transactionId]);
    }

    public function errorCode(): string
    {
        return 'payment.transaction_not_refundable';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
