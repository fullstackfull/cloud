<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Infrastructure\Queries;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * The one place a subscription query is scoped to a customer.
 *
 * The same rule as CustomerInvoices, and it matters more here: a subscription
 * id is not only readable, it is the argument to a cancellation. Handing the
 * controller a relation rather than a global query means the id in the URL is
 * resolved *within* the acting account, so another tenant's subscription is
 * never loaded — let alone cancelled — and the request 404s.
 *
 * @see CustomerInvoices for why this is not a relation on Customer itself.
 */
final class CustomerSubscriptions
{
    /**
     * @return HasMany<Subscription, Customer>
     */
    public static function of(Customer $customer): HasMany
    {
        return $customer->hasMany(Subscription::class);
    }
}
