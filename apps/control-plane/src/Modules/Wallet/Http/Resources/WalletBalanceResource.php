<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Http\Concerns\SerialisesMoney;
use Lynomia\Modules\Wallet\Domain\ValueObjects\WalletBalance;

/**
 * What a customer may see of one of their own balances.
 *
 * `balance_minor` is not exposed as a bare integer and neither is the raw
 * column: the figure goes out as a Money object so the three minor digits of
 * KWD cannot be mistaken for two.
 *
 * Absent on purpose:
 *
 *  - customer_id: the caller already knows which account they are acting for,
 *    and the id buys them nothing but a shape to probe with.
 *  - created_at: when the platform opened an internal row is not part of the
 *    customer's financial history. When the balance last *moved* is, and that
 *    is updated_at.
 *
 * @mixin WalletBalance
 */
final class WalletBalanceResource extends JsonResource
{
    use SerialisesMoney;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WalletBalance $balance */
        $balance = $this->resource;

        return [
            // Null until the customer has actually transacted in this
            // currency. Reading a balance does not open a wallet.
            'wallet_id' => $balance->walletId,
            'currency' => $balance->currency(),
            'balance' => $this->money($balance->balance),
            'updated_at' => $balance->updatedAt?->toIso8601String(),
        ];
    }
}
