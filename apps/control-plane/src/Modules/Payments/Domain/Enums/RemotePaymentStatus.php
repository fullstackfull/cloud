<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Enums;

use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;

/**
 * The state of a payment as the provider sees it.
 *
 * Deliberately separate from TransactionStatus: this is the provider's opinion
 * at a point in time, while a Transaction is our own settled record. Keeping
 * them apart means a provider adding a new intermediate state cannot silently
 * change what "succeeded" means in our ledger.
 */
enum RemotePaymentStatus: string
{
    case RequiresAction = 'requires_action';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isSuccessful(): bool
    {
        return $this === self::Succeeded;
    }

    public function isFinal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * How this provider state is recorded in our own ledger.
     */
    public function toTransactionStatus(): TransactionStatus
    {
        return match ($this) {
            self::Succeeded => TransactionStatus::Succeeded,
            self::Failed => TransactionStatus::Failed,
            self::Cancelled => TransactionStatus::Cancelled,
            self::RequiresAction, self::Processing => TransactionStatus::Pending,
        };
    }
}
