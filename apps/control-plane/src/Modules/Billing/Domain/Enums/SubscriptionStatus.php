<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Terminated = 'terminated';

    /** Whether the underlying service should still be running. */
    public function serviceShouldRun(): bool
    {
        return match ($this) {
            // Past due keeps running: the customer is inside the grace period,
            // and cutting service the moment a card declines loses customers to
            // an expired card rather than to non-payment.
            self::Active, self::PastDue => true,
            default => false,
        };
    }

    /** Whether the renewal worker should keep invoicing this subscription. */
    public function shouldRenew(): bool
    {
        return match ($this) {
            self::Active, self::PastDue => true,
            default => false,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Cancelled, self::Terminated => true,
            default => false,
        };
    }
}
