<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Infrastructure\Queries;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;

/**
 * The one place an invoice query is scoped to a customer.
 *
 * Every read of an invoice on the customer surface starts here, so what the
 * caller receives is already a relation hanging off the acting customer rather
 * than a global `Invoice::query()` that somebody has to remember to constrain.
 * An unscoped invoice is therefore never in hand: `->whereKey($id)
 * ->firstOrFail()` on this relation 404s for another tenant's id instead of
 * fetching the row and relying on a check that runs afterwards — by which
 * point the document, its lines and its totals have already been read.
 *
 * This belongs on Customer as an `invoices()` relation. It lives here instead
 * because the Identity module is owned elsewhere; the relation object built by
 * `$customer->hasMany(Invoice::class)` is exactly the one that method would
 * return, so moving it later is a one-line change at this single call site.
 */
final class CustomerInvoices
{
    /**
     * @return HasMany<Invoice, Customer>
     */
    public static function of(Customer $customer): HasMany
    {
        return $customer->hasMany(Invoice::class);
    }
}
