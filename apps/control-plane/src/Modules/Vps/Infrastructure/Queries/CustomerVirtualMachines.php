<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Infrastructure\Queries;

use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * The one place a virtual machine query is scoped to a customer.
 *
 * A machine has no customer_id of its own — it hangs off a service, and the
 * service is what the account owns. That indirection is exactly why this class
 * exists: written out at each call site it becomes a join somebody eventually
 * forgets, or worse, a `VirtualMachine::findOrFail()` followed by a comparison
 * that has already had another tenant's row in hand.
 *
 * Started from the customer, `->whereKey($id)->firstOrFail()` on this relation
 * cannot return another tenant's machine. It 404s, which is also the only
 * answer that does not confirm the id names something real.
 *
 * This belongs on Customer as a `virtualMachines()` relation. It lives here
 * instead because the Identity module is owned elsewhere; the object built
 * below is precisely what that method would return, so moving it later is a
 * one-line change at this single call site. The same reasoning, and the same
 * shape, as Billing's CustomerInvoices.
 */
final class CustomerVirtualMachines
{
    /**
     * @return HasManyThrough<VirtualMachine, Service, Customer>
     */
    public static function of(Customer $customer): HasManyThrough
    {
        return $customer
            ->hasManyThrough(
                VirtualMachine::class,
                Service::class,
                'customer_id',      // services.customer_id
                'service_id',       // virtual_machines.service_id
                'id',               // customers.id
                'id',               // services.id
            )
            /*
             * Qualified, because a through-relation selects
             * `virtual_machines.*` alongside the joined service's key and both
             * tables have an `id`. Without the table name an unqualified
             * whereKey() is ambiguous to PostgreSQL and the query fails rather
             * than quietly matching the wrong column — but "fails" here would
             * be a 500 on every show request, so it is stated once.
             */
            ->select('virtual_machines.*');
    }
}
