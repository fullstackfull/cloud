<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Infrastructure\Queries;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;

/**
 * The one place a payment query is scoped to a customer.
 *
 * Every read of a payment on the customer surface starts here, so what the
 * caller receives is already a relation hanging off the acting customer rather
 * than a global `Transaction::query()` that somebody has to remember to
 * constrain. An unscoped payment is therefore never in hand: `->whereKey($id)
 * ->firstOrFail()` on this relation 404s for another tenant's id instead of
 * fetching the row and relying on a check that runs afterwards — by which
 * point the amount, the provider reference and the invoice it settles have
 * already been read.
 *
 * This belongs on Customer as a `transactions()` relation. It lives here
 * instead because the Identity module is owned elsewhere; the relation object
 * built by `$customer->hasMany(Transaction::class)` is exactly the one that
 * method would return, so moving it later is a one-line change at this single
 * call site.
 */
final class CustomerTransactions
{
    /**
     * @return HasMany<Transaction, Customer>
     */
    public static function of(Customer $customer): HasMany
    {
        return $customer->hasMany(Transaction::class);
    }
}
