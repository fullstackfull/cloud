<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Infrastructure\Queries;

use Illuminate\Database\Eloquent\Builder;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;

/**
 * The zones an account holds, and how one of them is addressed.
 *
 * Three controllers — the zone, its records and its import/export — each had
 * their own copy of the same two `where` clauses. That was survivable while
 * the only handle was a ULID; it stopped being survivable the moment a zone
 * became addressable by its name as well, because a lookup that accepted a
 * name in one controller and not in another would make `/dns/example.com`
 * work until the customer edited a record.
 *
 * So the scoping and the addressing live here, once.
 *
 * Deleted zones are excluded rather than 403'd: a released zone is gone as far
 * as the customer is concerned, and the row survives only so the platform can
 * remember it once released the name.
 */
final class CustomerZones
{
    /**
     * @return Builder<DnsZone>
     */
    public static function of(string $customerId): Builder
    {
        return DnsZone::query()
            ->where('customer_id', $customerId)
            ->where('state', '!=', DnsState::Deleted->value);
    }

    /**
     * One zone, by its id or by its name.
     *
     * The name is lower-cased first: DNS is case-insensitive, the platform
     * stores the lower-case form, and a customer who typed a capital should
     * not be told their zone does not exist. Both forms are constrained to the
     * account, so another account's zone name answers with the same 404 as a
     * zone that was never claimed.
     *
     * @param  Builder<DnsZone>  $query
     * @return Builder<DnsZone>
     */
    public static function identified(Builder $query, string $idOrName): Builder
    {
        return $query->where(fn (Builder $inner): Builder => $inner
            ->where('id', $idOrName)
            ->orWhere('name', mb_strtolower($idOrName)));
    }
}
