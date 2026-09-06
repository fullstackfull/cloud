<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Domain\Enums;

enum WalletTransactionKind: string
{
    case Topup = 'topup';
    case Refund = 'refund';
    case Payment = 'payment';
    case Adjustment = 'adjustment';
    case Promotional = 'promotional';

    /**
     * Whether the entry may only be written with a named human behind it.
     *
     * Every other kind is the recorded consequence of something that happened
     * elsewhere — a captured payment, a settled invoice, a campaign — and can
     * be traced back through invoice_id or transaction_id. An adjustment has
     * no such origin: it is somebody deciding a balance should be different.
     * An unattributed one is a finding waiting to happen in an audit.
     */
    public function requiresActor(): bool
    {
        return $this === self::Adjustment;
    }
}
