<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Raised when a debit would take a wallet below zero.
 *
 * The wallet is a stored-value balance, not a credit line: there is no
 * overdraft product behind it, so a negative balance would be money the
 * platform has already spent and cannot invoice for.
 */
final class InsufficientWalletBalanceException extends DomainException
{
    public static function forDebit(string $walletId, Money $balance, Money $requested): self
    {
        $exception = new self(sprintf(
            'Wallet balance of %s cannot cover a debit of %s.',
            $balance,
            $requested,
        ));

        return $exception->withContext([
            'wallet_id' => $walletId,
            'currency' => $balance->currency(),
            'balance_minor' => $balance->minorUnits(),
            'requested_minor' => $requested->minorUnits(),
            'shortfall_minor' => $requested->minus($balance)->minorUnits(),
        ]);
    }

    public function errorCode(): string
    {
        return 'wallet.insufficient_balance';
    }
}
