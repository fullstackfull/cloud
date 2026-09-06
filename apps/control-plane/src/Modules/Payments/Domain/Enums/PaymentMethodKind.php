<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Enums;

enum PaymentMethodKind: string
{
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Knet = 'knet';
    case Wallet = 'wallet';

    /**
     * Whether the stored reference can be charged again without the customer
     * being present. KNET and one-off bank transfers cannot.
     */
    public function supportsOffSession(): bool
    {
        return match ($this) {
            self::Card, self::Wallet => true,
            self::BankTransfer, self::Knet => false,
        };
    }
}
