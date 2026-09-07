<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Application\Queries;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;

/**
 * The one place an address query is scoped to a customer.
 *
 * Every read and every write on the customer surface starts here, so what a
 * controller holds is already a relation hanging off the acting customer rather
 * than a global `IpAssignment::query()` that somebody has to remember to
 * constrain. `->whereKey($id)->firstOrFail()` on this relation 404s for another
 * tenant's id instead of fetching the row and refusing afterwards — and on this
 * table "afterwards" is already too late, because the row names the subnet and
 * the pool the platform put that customer in.
 *
 * This belongs on Customer as an `ipAssignments()` relation. It lives here
 * instead because the Identity module is owned elsewhere; the relation object
 * `$customer->hasMany(IpAssignment::class)` returns is exactly what that method
 * would return, so moving it later is a one-line change at this single call
 * site.
 *
 * ---------------------------------------------------------------------------
 * Why `live()` is part of the scope and not a convenience
 * ---------------------------------------------------------------------------
 *
 * Assignment rows are append-only history: releasing an address stamps
 * `released_at` and keeps the row forever, because "who held 203.0.113.10 on
 * the 4th of March" is the first question an abuse report or a law-enforcement
 * request asks. That history is precisely what must not be published here.
 *
 * A released address is very often somebody else's by now — that is what the
 * quarantine window and the pool exist to arrange — so a customer surface that
 * showed released rows would be showing a customer an address another customer
 * is currently answering on, and would let them set its reverse DNS. The scope
 * is therefore "assigned to you, right now", and the history stays where it
 * belongs: in the audit trail, reachable by operators.
 */
final class CustomerIpAssignments
{
    /**
     * @return HasMany<IpAssignment, Customer>
     */
    public static function of(Customer $customer): HasMany
    {
        return $customer->hasMany(IpAssignment::class)
            ->whereNull('ip_assignments.released_at');
    }
}
