<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Queries;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;

/**
 * The one place a hosting account query is scoped to a customer.
 *
 * Every account this module hands to HTTP starts here, so that a lookup is
 * `$customer->accounts()->whereKey($id)->firstOrFail()` in shape: another
 * tenant's id matches no row and the request 404s. The alternative — a
 * `HostingAccount::findOrFail()` followed by a comparison — has already had
 * the row, its username and its primary domain in hand by the time it decides
 * to refuse, and one forgotten comparison is a cross-tenant read.
 *
 * This belongs on Customer as a `hostingAccounts()` relation, and the object
 * built below is exactly what that method would return. It lives here because
 * the Identity module is owned elsewhere and several modules are being
 * published in parallel; moving it later is a one-line change at this single
 * call site. Same shape and same reasoning as Vps's CustomerVirtualMachines.
 */
final class CustomerHostingAccounts
{
    /**
     * @return HasMany<HostingAccount, Customer>
     */
    public static function of(Customer $customer): HasMany
    {
        return $customer->hasMany(HostingAccount::class, 'customer_id');
    }
}
