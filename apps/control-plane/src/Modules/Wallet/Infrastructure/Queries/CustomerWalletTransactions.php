<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Infrastructure\Queries;

use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Wallet\Infrastructure\Models\Wallet;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;

/**
 * A customer's ledger entries, reached only through their own wallets.
 *
 * A wallet_transactions row carries no customer_id — it belongs to a wallet,
 * and the wallet belongs to a customer — so the join is what does the scoping.
 * Going through it means the customer constraint is part of the query's shape
 * rather than a filter that a later `orWhere` or a forgotten call site can
 * widen: another tenant's entry is not in the result set at all, and an entry
 * id from another tenant matches nothing.
 *
 * It also means every entry arrives with a wallet, which the resource needs:
 * an entry has no currency of its own.
 */
final class CustomerWalletTransactions
{
    /**
     * @return HasManyThrough<WalletTransaction, Wallet, Customer>
     */
    public static function of(Customer $customer): HasManyThrough
    {
        return $customer->hasManyThrough(WalletTransaction::class, Wallet::class);
    }
}
