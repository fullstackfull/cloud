<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';
    case Uncollectible = 'uncollectible';
    case Refunded = 'refunded';

    /**
     * Whether the invoice has been issued to the customer.
     *
     * Everything from Open onwards is a document the customer has seen and may
     * have filed for tax, so its numbers, dates and billing snapshot are frozen.
     */
    public function isIssued(): bool
    {
        return $this !== self::Draft;
    }

    public function isSettled(): bool
    {
        return match ($this) {
            self::Paid, self::Void, self::Refunded => true,
            default => false,
        };
    }

    public function isCollectible(): bool
    {
        return $this === self::Open;
    }
}
