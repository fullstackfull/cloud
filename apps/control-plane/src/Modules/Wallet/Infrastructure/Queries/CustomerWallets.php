<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Infrastructure\Queries;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Wallet\Infrastructure\Models\Wallet;

/**
 * The one place a wallet query is scoped to a customer.
 *
 * Every read of a wallet on the customer surface starts here, so what a caller
 * receives is already a relation hanging off the acting customer rather than a
 * global `Wallet::query()` that somebody has to remember to constrain. An
 * unscoped wallet is therefore never in hand, and there is no
 * `where('customer_id', ...)` at a call site to forget.
 *
 * This belongs on Customer as a `wallets()` relation. It lives here instead
 * because the Identity module is owned elsewhere; the relation object built by
 * `$customer->hasMany(Wallet::class)` is exactly the one that method would
 * return, so moving it later is a one-line change at this single call site.
 * The same shape, and the same reason, as Billing's CustomerInvoices.
 */
final class CustomerWallets
{
    /**
     * @return HasMany<Wallet, Customer>
     */
    public static function of(Customer $customer): HasMany
    {
        return $customer->hasMany(Wallet::class);
    }
}
