<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Enums;

/**
 * What kind of money movement a transaction row records.
 *
 * The sign of the amount is not enough: a refund and a chargeback both move
 * money back to the customer but have entirely different accounting and
 * dispute consequences.
 */
enum TransactionKind: string
{
    case Charge = 'charge';
    case Refund = 'refund';
    case Chargeback = 'chargeback';
    case Adjustment = 'adjustment';

    /** Whether this kind moves money towards the platform. */
    public function isInbound(): bool
    {
        return $this === self::Charge;
    }
}
