<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Application\Queries;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;

/**
 * The one place a dedicated-server query is scoped to a customer.
 *
 * Every read and every write on the customer surface starts here, so what a
 * controller holds is already a relation hanging off the acting customer
 * rather than a global `DedicatedServer::query()` somebody has to remember to
 * constrain. An unscoped machine is therefore never in hand:
 * `->whereKey($id)->firstOrFail()` on this relation 404s for another tenant's
 * id instead of fetching the row and relying on a check that runs afterwards —
 * by which point the row, its rack, its datacenter and its BMC endpoints have
 * all already been read.
 *
 * This belongs on Customer as a `dedicatedServers()` relation. It lives here
 * instead because the Identity module is owned elsewhere; the relation object
 * built by `$customer->hasMany(DedicatedServer::class)` is exactly what that
 * method would return, so moving it later is a one-line change at this single
 * call site.
 *
 * ---------------------------------------------------------------------------
 * Why the status filter is part of the scope and not a convenience
 * ---------------------------------------------------------------------------
 *
 * `dedicated_servers.customer_id` is not cleared when a machine goes back into
 * stock. The lifecycle is `active → maintenance → available`, and only the
 * next reservation overwrites the column — so between the wipe and the next
 * sale, a machine that is nobody's still carries the previous customer's id.
 *
 * A scope of "customer_id = me" alone would therefore show a customer a
 * machine that has already been erased and returned to inventory, and would
 * let them power cycle it while an operator is standing in front of it. The
 * statuses below are the ones in which the machine is genuinely theirs:
 *
 *  - `reserved`, `provisioning` — bought, being made ready;
 *  - `active` — theirs and running;
 *  - `maintenance` — still theirs, temporarily in an operator's hands;
 *  - `failed` — still theirs, and the state they most need to be able to see.
 *
 * `available` is excluded because it means the machine belongs to nobody, and
 * `retired` because the row survives to answer "what happened to my old
 * server" for support and for audit, not to sit in a live inventory list.
 */
final class CustomerDedicatedServers
{
    /**
     * The statuses in which a machine is the acting customer's to see.
     *
     * @return list<string>
     */
    public static function heldStatuses(): array
    {
        return [
            DedicatedServerStatus::Reserved->value,
            DedicatedServerStatus::Provisioning->value,
            DedicatedServerStatus::Active->value,
            DedicatedServerStatus::Maintenance->value,
            DedicatedServerStatus::Failed->value,
        ];
    }

    /**
     * @return HasMany<DedicatedServer, Customer>
     */
    public static function of(Customer $customer): HasMany
    {
        return $customer->hasMany(DedicatedServer::class)
            ->whereIn('dedicated_servers.status', self::heldStatuses())
            // Belt and braces against the one mistake whose cost is a customer
            // being shown a decommissioned machine as if it were live. The
            // status filter already excludes `retired`; a row carrying a
            // retirement date and some other status is a data problem, and the
            // customer surface is not where it should first become visible.
            ->whereNull('dedicated_servers.retired_at');
    }
}
