<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Http\Concerns\SerialisesMoney;
use Lynomia\Modules\Wallet\Application\DTOs\WalletCreditQuote;

/**
 * What paying this invoice from credit would do, before it is done.
 *
 * Three figures rather than one, because a payment screen has to say all three
 * — what you have, what this takes, what is left to pay — and a client that
 * subtracted them itself would be re-implementing the rule that a wallet
 * cannot overpay an invoice.
 *
 * @mixin WalletCreditQuote
 */
final class WalletCreditQuoteResource extends JsonResource
{
    use SerialisesMoney;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WalletCreditQuote $quote */
        $quote = $this->resource;

        return [
            'available' => $this->money($quote->available),
            'applicable' => $this->money($quote->applicable),
            'remaining' => $this->money($quote->remaining),
            'is_payable' => $quote->isPayable,
        ];
    }
}
