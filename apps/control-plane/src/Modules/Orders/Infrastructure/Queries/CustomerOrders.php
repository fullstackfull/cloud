<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Infrastructure\Queries;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;

/**
 * The one place an order query is scoped to a customer.
 *
 * Every read of an order on the customer surface starts here, so the query
 * handed back is already a relation hanging off the acting customer rather than
 * a global `Order::query()` that somebody must remember to constrain. An
 * unscoped row is therefore never in hand: `->whereKey($id)->firstOrFail()` on
 * this relation returns 404 for another tenant's id instead of returning the
 * row and relying on a check that follows it.
 *
 * This belongs on Customer as an `orders()` relation. It lives here instead
 * because the Identity module is owned elsewhere; the relation object built by
 * `$customer->hasMany(Order::class)` is the same one that method would return,
 * and moving it later is a one-line change at this single call site.
 */
final class CustomerOrders
{
    /**
     * @return HasMany<Order, Customer>
     */
    public static function of(Customer $customer): HasMany
    {
        return $customer->hasMany(Order::class);
    }
}
