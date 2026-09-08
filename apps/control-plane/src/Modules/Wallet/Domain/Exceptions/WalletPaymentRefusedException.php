<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A wallet payment the platform will not make.
 *
 * None of these is "you do not have enough". Paying part of an invoice from a
 * balance that cannot cover it is the ordinary case and is not refused — the
 * remainder simply stays payable by card. What is refused is a request that
 * would apply nothing, or one that cannot be applied without converting a
 * currency the platform never converts.
 */
final class WalletPaymentRefusedException extends DomainException
{
    private string $errorCode = 'wallet.payment_refused';

    private int $status = 409;

    public static function becauseNothingIsOwed(string $invoiceId): self
    {
        return (new self('This invoice has nothing left to pay.'))
            ->withContext(['invoice_id' => $invoiceId])
            ->as('wallet.nothing_is_owed');
    }

    public static function becauseTheBalanceIsEmpty(string $currency): self
    {
        return (new self('There is no credit in this currency to apply.'))
            ->withContext(['currency' => $currency])
            ->as('wallet.no_credit_in_currency');
    }

    /**
     * A customer holding KWD credit and a USD invoice is not short of money;
     * the platform simply has no rate it would honour, and inventing one would
     * settle an invoice at a number nobody agreed. Both currencies are named
     * so the customer can see what is actually wrong.
     */
    public static function becauseTheCurrencyDiffers(string $invoiceCurrency, string $walletCurrency): self
    {
        return (new self('Credit is not converted between currencies.'))
            ->withContext(['invoice_currency' => $invoiceCurrency, 'wallet_currency' => $walletCurrency])
            ->as('wallet.currency_mismatch')
            ->withStatus(422);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    private function as(string $code): self
    {
        $this->errorCode = $code;

        return $this;
    }

    private function withStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }
}
