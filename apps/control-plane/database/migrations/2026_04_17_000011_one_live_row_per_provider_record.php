<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * One live row per provider record, per zone.
 *
 * Two live rows holding one provider identifier is the audit's F-11 sentence:
 * "two local rows share one provider id; deleting one kills the survivor".
 * The platform reached it by itself, with no third party: `ChangeRecord`
 * writes a row's new value at once and publishes afterwards, so for a moment
 * `dns_records_one_live_value` permits a second row at the value the first no
 * longer carries — and that row's publish finds the value in the zone and
 * adopts its identifier. `ClaimProviderRecord` is the repair, called by both
 * writers that stamp an identifier. This index is what holds for the writer
 * nobody has written yet: a closed list of writers is exactly the shape that
 * reopened this finding twice.
 *
 * Partial, on the same predicate as the claim's guard: a deleted row is
 * history, and a row with no identifier holds nothing. Raw SQL because the
 * schema builder cannot express a partial index.
 *
 * The `UPDATE` first, because `CREATE UNIQUE INDEX` over violating data fails —
 * a migration green in every test database that would stop a production
 * deploy. It keeps, per `(zone, identifier)`, the row most recently published
 * (a `pending` row with the newest `last_published_at` wins over an older
 * `active` one, correctly: its queued publish is what will next rewrite that
 * record), then the most recently touched, then — so that two rows with equal
 * timestamps are not settled arbitrarily — the greatest id. The rest are
 * disowned exactly as the claim disowns: identifier cleared, row otherwise
 * untouched, and a live row whose value is not in the zone is what the sweep
 * already reports. Its predicate is the index's predicate, so it cannot leave
 * a violation behind. **No test exercises this `UPDATE`**: `RefreshDatabase`
 * migrates an empty schema. It is read code, not measured code.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE dns_records
               SET provider_record_id = NULL
             WHERE state <> 'deleted'
               AND provider_record_id IS NOT NULL
               AND id NOT IN (
                   SELECT DISTINCT ON (dns_zone_id, provider_record_id) id
                     FROM dns_records
                    WHERE state <> 'deleted'
                      AND provider_record_id IS NOT NULL
                    ORDER BY dns_zone_id, provider_record_id,
                             last_published_at DESC NULLS LAST,
                             updated_at DESC NULLS LAST,
                             id DESC
               )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX dns_records_one_live_provider_record
                ON dns_records (dns_zone_id, provider_record_id)
                WHERE state <> 'deleted' AND provider_record_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS dns_records_one_live_provider_record');
    }
};
