<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * One LIVE capacity reservation per key, and any number of released ones.
 *
 * The key is a provisioning job's idempotency key, and the unique index on it
 * used to cover released rows too. A released reservation is history — the
 * capacity it recorded was given back, and the row is kept so the release can
 * be seen — but under that index it also held the key for ever. A VPS create
 * whose automatic retries ran out settled in review, its reservation was
 * released, and the operator's retry of the SAME job, under the same key,
 * found no live reservation (`ReserveNodeCapacity` looks for one), committed
 * the node's counters and then tripped the index on the released row: a
 * unique violation, the transaction rolled back, and the job back in review
 * with no way forward but editing rows by hand.
 *
 * Partial, on the predicate `ReserveNodeCapacity` and `ReleaseNodeCapacity`
 * read a reservation's liveness by: at most one row with a given key and no
 * `released_at`. That keeps the index doing the job it was created for — two
 * workers that both found no live row serialise on it, and the loser's
 * transaction rolls back with its counter increment — while a key whose
 * reservation was released can be committed again, as a new row, with the old
 * one left as it was. Re-opening the released row instead would have written
 * the new commitment over the record of the old one, on a node the retry may
 * not have been placed on. Raw SQL because the schema builder cannot express a
 * partial index.
 *
 * Nothing to clean up first: unique over every row implies unique over the
 * live ones, so no existing data can violate the new index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_capacity_reservations', function (Blueprint $table): void {
            $table->dropUnique(['reservation_key']);
            // Lookups of a key's history, released rows included.
            $table->index('reservation_key');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX node_capacity_reservations_one_live_per_key
                ON node_capacity_reservations (reservation_key)
                WHERE released_at IS NULL
        SQL);
    }

    public function down(): void
    {
        // Fails, as it should, once a key has been reserved again after a
        // release: the old index cannot hold that history.
        DB::statement('DROP INDEX IF EXISTS node_capacity_reservations_one_live_per_key');

        Schema::table('node_capacity_reservations', function (Blueprint $table): void {
            $table->dropIndex(['reservation_key']);
            $table->unique('reservation_key');
        });
    }
};
