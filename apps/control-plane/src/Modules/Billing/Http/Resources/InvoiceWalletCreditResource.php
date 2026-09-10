<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Http\Concerns\SerialisesMoney;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;

/**
 * A wallet ledger entry as an invoice document reports it.
 *
 * Wallet credit and a card are two different things and an invoice settled
 * from both has to show both: "paid", with no account of where the money came
 * from, is exactly the question that reaches support.
 *
 * The amount is signed and the direction is also stated in words, because a
 * minus sign on its own is easy to miss and colour means nothing to a screen
 * reader.
 *
 * @mixin WalletTransaction
 */
final class InvoiceWalletCreditResource extends JsonResource
{
    use SerialisesMoney;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'amount' => $this->money($this->resource->amount()),
            'direction' => $this->resource->isCredit() ? 'credit' : 'debit',
            'balance_after' => $this->money($this->resource->balanceAfter()),
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
