<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Domain\Enums;

enum OrderStatus: string
{
    case Draft = 'draft';
    case PendingPayment = 'pending_payment';
    case PaymentFailed = 'payment_failed';
    case Paid = 'paid';
    case QueuedForProvisioning = 'queued_for_provisioning';
    case Provisioning = 'provisioning';
    case ProvisioningFailed = 'provisioning_failed';
    case ManualReview = 'manual_review';
    case Active = 'active';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case Terminated = 'terminated';

    /** Whether the order has reached a state it will not leave on its own. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Cancelled, self::Refunded, self::Terminated => true,
            default => false,
        };
    }

    /** Whether money has been captured for this order. */
    public function isPaid(): bool
    {
        return match ($this) {
            self::Paid, self::QueuedForProvisioning, self::Provisioning,
            self::ProvisioningFailed, self::ManualReview, self::Active,
            self::Suspended => true,
            default => false,
        };
    }

    /** Whether an operator needs to look at this order. */
    public function needsAttention(): bool
    {
        return match ($this) {
            self::ProvisioningFailed, self::ManualReview => true,
            default => false,
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
