<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Enums;

enum RefundStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Whether this refund still lays claim to part of the captured amount.
     *
     * A pending refund counts against the refundable balance: the money is
     * already promised to the customer, and a second refund issued while the
     * first is in flight would overshoot the capture.
     */
    public function reservesFunds(): bool
    {
        return match ($this) {
            self::Pending, self::Succeeded => true,
            self::Failed, self::Cancelled => false,
        };
    }
}
