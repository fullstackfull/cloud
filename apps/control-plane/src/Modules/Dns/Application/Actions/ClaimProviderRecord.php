<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;

/**
 * Stamp a row with the provider record it now answers for — and take that
 * record away from any other live row in the zone that claimed it.
 *
 * ---------------------------------------------------------------------------
 * Why the platform, and not the adapter
 * ---------------------------------------------------------------------------
 *
 * A publish that finds its value already in the zone adopts that record's
 * identifier. That is not a defect: it is the only thing that lets a publish
 * whose answer was lost find the record it already created instead of
 * creating a second, which is the Timeout Rule as this module applies it. But
 * from inside an adapter, "the record I created and did not hear about" and
 * "another row's record whose value has moved onto mine" are the same read
 * and the same answer. They are separated by one fact, and it lives in
 * `dns_records`: whether another live row already holds that identifier.
 *
 * The platform opens that window by itself. `ChangeRecord` writes a row's new
 * value at once and publishes afterwards, so while row A says Y the zone's
 * record still says X — and `dns_records_one_live_value` then permits row B
 * at X. B's publish finds X and adopts A's identifier; A's publish then
 * rewrites that same record to Y. Both rows `active`, one record in the zone
 * where the customer asked for two, and deleting either empties the name
 * while the other still reads live. (F-11: "two local rows share one provider
 * id; deleting one kills the survivor".)
 *
 * ---------------------------------------------------------------------------
 * Disown, rather than refuse or share
 * ---------------------------------------------------------------------------
 *
 *  - The other row's claim is **refuted, not doubted**. The record under that
 *    identifier has just been read carrying *this* row's value, and at one
 *    `(type, name)` the live-value index guarantees the other row's value
 *    differs. So that row's value is not what the record says, whatever it
 *    once was.
 *  - Refusing would mark `failed` a publish whose value is at that moment
 *    being served. A customer reading `failed` publishes again — the
 *    duplicate this module exists to prevent.
 *  - The failure mode of disowning is a record too many; the failure mode it
 *    replaces is a record silently destroyed. And it is not silent: a live
 *    row whose value is not in the zone is the critical
 *    `missing_at_provider` drift the sweep already reports — and with no
 *    identifier left to delete by, a later delete of that row goes by value
 *    and cannot take this row's record.
 *
 * The guard is scoped to live rows in the same zone. A deleted row is
 * history, and nothing reads a deleted row's identifier; a row in another
 * zone holding the same string names some other zone's record, because an
 * identifier names a record, whatever zone the string reappears in.
 *
 * Called by the two writers that stamp an identifier — `PublishRecord` and
 * `ReconcileZones` — and by nothing that merely clears one. The partial
 * unique index `dns_records_one_live_provider_record` holds the same rule
 * for any writer not written yet.
 */
final readonly class ClaimProviderRecord
{
    /**
     * @param  array<string, mixed>  $attributes  Written with the transition
     */
    public function execute(DnsRecord $row, DnsState $next, ?string $providerRecordId, array $attributes = []): void
    {
        DB::transaction(static function () use ($row, $next, $providerRecordId, $attributes): void {
            if ($providerRecordId !== null && $providerRecordId !== '') {
                DnsRecord::query()
                    ->where('dns_zone_id', $row->dns_zone_id)
                    ->where('provider_record_id', $providerRecordId)
                    ->where('state', '!=', DnsState::Deleted->value)
                    ->whereKeyNot($row->getKey())
                    ->update(['provider_record_id' => null, 'updated_at' => now()]);
            }

            $row->transitionTo($next, [...$attributes, 'provider_record_id' => $providerRecordId]);
        });
    }
}
