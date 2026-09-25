<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Application\Queries;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;

/**
 * The addresses in a pool that are waiting for a person, and who for.
 *
 * A held quarantine — an address released off a physical machine that is
 * still racked with it configured — has no clock, and ends only when somebody
 * returns that machine to stock or retires it. The way that goes wrong is
 * silently: a machine left in maintenance and forgotten, and its addresses
 * out of circulation with it for as long as nobody looks. `ipam:capacity`
 * looks, and this is what it reads. Each row names the machine its address
 * is waiting for, and when it was released to wait, so an operator can go to
 * the right rack. A plausible wrong row — the wrong chassis, the wrong date —
 * is worse than no row, because it sends them to the wrong one.
 *
 * ---------------------------------------------------------------------
 * inPool(), clause by clause
 * ---------------------------------------------------------------------
 *
 * The unit is a clause that can be deleted on its own, not a method call:
 * a list whose items were method calls let a finer clause hide inside a
 * coarser bullet, and that is how every earlier version of this list was
 * incomplete. Each bullet says what holds it — a test in
 * `tests/Feature/Ipam/HeldQuarantineReportTest.php` unless another file is
 * named — or why nothing can.
 *
 *  - `Subnet::where('ip_pool_id', …)`, the pool filter. Held by
 *    `only_held_addresses_in_this_pool_are_listed`, whose other pool holds a
 *    held address of its own.
 *  - `if ($subnetIds === []) return []`. Inert: `whereIn` over an empty list
 *    compiles to `0 = 1`, so the query it skips returns the same empty list.
 *    It saves a round trip, no more.
 *  - `leftJoin` rather than a join. Held by
 *    `an_address_with_nobody_behind_it_is_listed_last_and_unattributed`: an
 *    inner join drops the address with no assignment behind it, which is the
 *    row most likely to be a control-plane defect.
 *  - The join's `on(ip_assignments.ip_address_id = ip_addresses.id)`. Inert by
 *    construction: the subquery below already pins the joined row to this
 *    address's own latest assignment before the `on` is consulted. Kept
 *    because a join with no `on` reads as a cross join.
 *  - The subquery's correlation, `inner_assignments.ip_address_id =
 *    ip_addresses.id`. Held by every test that expects a holder: without it
 *    the subquery picks one assignment for the whole table and every other
 *    address loses its holder.
 *  - `order by inner_assignments.assigned_at desc`. Held by
 *    `the_holder_is_the_latest_assignment_and_a_tie_goes_to_the_later_row`,
 *    which includes a later assignment carrying the lower id.
 *  - `, inner_assignments.id desc`, the tiebreak. Held by the same test's
 *    tied pairs: `assigned_at` is `timestamp(0)`, so two assignments on one
 *    address in the same second tie, and only the ULID says which came
 *    second. Both insertion orders are built, so the test pins the direction
 *    of the key and not merely its presence.
 *  - `limit 1` in the subquery. Held by any address with two assignments:
 *    without it Postgres refuses a subquery returning more than one row.
 *  - `whereIn('ip_addresses.subnet_id', …)`. Held with the pool filter above;
 *    the two are one filter in two halves.
 *  - `status = quarantined`. Held by `only_held_addresses_in_this_pool_are_listed`
 *    (a live address in the same pool).
 *  - `quarantined_until is null`. Held by the same test (an address already on
 *    a clock in the same pool).
 *  - `order by released_at asc`, oldest wait first. Held by
 *    `the_list_names_the_machine_each_address_is_waiting_for_oldest_first`.
 *  - `nulls last`. Held by the unattributed test. Written out rather than left
 *    to Postgres's default for ascending order, because it is a decision: an
 *    undated row sorts after every dated one, and when the list is cut at its
 *    limit the undated rows go first. That is a cost, and it is not answered
 *    by saying an undated row is no evidence of a long wait — the cut is not
 *    about waits. A dated row cut today moves up tomorrow; an undated row is
 *    cut for as long as the pool holds enough dated ones, and undated rows
 *    are the likeliest to be a defect. What makes it acceptable is that the
 *    report prints the true total above the list, so a truncated list says
 *    that it is truncated.
 *  - `order by ip_addresses.address`. Held by
 *    `equal_release_stamps_are_listed_by_address_whichever_order_they_were_released_in`.
 *    Equal `released_at` is the ordinary case, not an edge: a decommission
 *    releases every address a machine was wearing, and the stamps are equal
 *    because `released_at` is `timestamp(0)` — each release writes its own
 *    `now()`, and a transaction does not freeze the clock. The test ties two
 *    pools released in opposite orders, since one tied pair can agree with an
 *    undecided sort by accident.
 *  - `order by ip_addresses.id`, the last key. Held by
 *    `one_address_in_two_overlapping_subnets_is_still_ordered`. The address is
 *    not unique at the scope this query works at: the constraint is
 *    `UNIQUE (subnet_id, address)`, a pool holds many subnets, and overlapping
 *    subnets are accepted today.
 *  - `limit`. Held by `the_report_states_the_true_total_when_it_lists_fewer`.
 *  - The six `(string)` casts in the map. Inert because of the schema: the
 *    selected columns are `character varying`, `character(26)` and
 *    `timestamp(0) with time zone`, all of which come back as strings. (Not
 *    because PDO stringifies everything; on this connection it does not.)
 *  - The four null guards in the map. `assignment_id`, `holder_id` and
 *    `held_since` are held by the unattributed test's null assertions;
 *    `holder_type`'s by `an_assignment_with_no_holder_type_is_reported_unattributed`,
 *    where a cast null would print as an empty holder instead.
 *
 * Nothing else in `tests/`, `routes/` or `bootstrap/` calls this method, so
 * a clause no bullet here names is a clause no test holds. A line added to
 * inPool() without a bullet here is that defect again.
 */
final readonly class HeldQuarantineAddresses
{
    /** How many rows one report lists; the report states the true total. */
    public const int DEFAULT_LIMIT = 100;

    /**
     * @return list<array{
     *     address_id: string,
     *     address: string,
     *     assignment_id: ?string,
     *     holder_type: ?string,
     *     holder_id: ?string,
     *     held_since: ?string,
     * }>
     */
    public function inPool(IpPool $pool, int $limit = self::DEFAULT_LIMIT): array
    {
        /** @var list<string> $subnetIds */
        $subnetIds = Subnet::query()
            ->where('ip_pool_id', $pool->getKey())
            ->pluck('id')
            ->all();

        if ($subnetIds === []) {
            return [];
        }

        return DB::table('ip_addresses')
            ->leftJoin('ip_assignments', static function (JoinClause $join): void {
                $join->on('ip_assignments.ip_address_id', '=', 'ip_addresses.id')
                    ->whereRaw(
                        'ip_assignments.id = (select inner_assignments.id from ip_assignments inner_assignments'
                        .' where inner_assignments.ip_address_id = ip_addresses.id'
                        .' order by inner_assignments.assigned_at desc, inner_assignments.id desc limit 1)',
                    );
            })
            ->whereIn('ip_addresses.subnet_id', $subnetIds)
            ->where('ip_addresses.status', IpAddressStatus::Quarantined->value)
            ->whereNull('ip_addresses.quarantined_until')
            ->orderByRaw('ip_assignments.released_at asc nulls last')
            ->orderBy('ip_addresses.address')
            ->orderBy('ip_addresses.id')
            ->limit($limit)
            ->get([
                'ip_addresses.id as address_id',
                'ip_addresses.address as address',
                'ip_assignments.id as assignment_id',
                'ip_assignments.assignable_type as holder_type',
                'ip_assignments.assignable_id as holder_id',
                'ip_assignments.released_at as held_since',
            ])
            ->map(static fn (object $row): array => [
                'address_id' => (string) $row->address_id,
                'address' => (string) $row->address,
                'assignment_id' => $row->assignment_id === null ? null : (string) $row->assignment_id,
                'holder_type' => $row->holder_type === null ? null : (string) $row->holder_type,
                'holder_id' => $row->holder_id === null ? null : (string) $row->holder_id,
                'held_since' => $row->held_since === null ? null : (string) $row->held_since,
            ])
            ->values()
            ->all();
    }
}
