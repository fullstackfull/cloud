<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hosting packages, indexed by the plan they are sold under (F-32).
 *
 * Every question the platform asks of this table on a purchase or a plan
 * change is asked by `plan_id`: `HostingPackageForPlan` reads the packages on
 * sale for a plan (`limit 2`), and when there are none asks whether any
 * package names the plan at all. The column was created with
 * `foreignUlid('plan_id')->constrained()`, and on PostgreSQL a foreign key
 * creates no index — so the only indexes were the primary key and the slug.
 *
 * ---------------------------------------------------------------------------
 * This index fixes nothing, and is argued down here on purpose
 * ---------------------------------------------------------------------------
 *
 * F-32's defect was a withdrawn package being chosen by row order, and the
 * fix for that is the resolver: it filters `is_active` and refuses to choose
 * between two packages on sale, so its answer does not depend on which plan
 * node the planner picks. This index changes how fast that answer arrives,
 * and at catalogue scale it does not even change that:
 *
 *   - At catalogue scale — tens of rows; a development seed writes six — the
 *     planner reads the whole table, with the index or without it, once the
 *     table has statistics. Seq Scan either way.
 *   - The crossover, where the index starts being used, is between 200 and
 *     400 rows, and it is not one number. Measured on the row described below:
 *
 *       rows (ANALYZEd)   resolver's on-sale read   resolver's probe   bare `limit 1`
 *       no index, 6–5,000  Seq Scan                  Seq Scan           Seq Scan
 *       index, up to 220   Seq Scan                  Seq Scan           Seq Scan
 *       index, 230–360     Bitmap Heap Scan          Seq Scan           Seq Scan
 *       index, 370 and up  Bitmap Heap Scan          Index Only Scan    Index Scan
 *
 *     The resolver's own on-sale read (`plan_id = ? and is_active` with
 *     `limit 2`) reaches the index as a Bitmap Heap Scan between 220 and 230
 *     rows; the `exists` probe and a bare `plan_id = ? limit 1` only between
 *     360 and 370. So the shape of the query moves the crossover by as much as
 *     anything else does, and the width of the row moves it too, since the
 *     planner costs pages. Other measurements in this programme have put it
 *     at 250, 300 and 350; they are different queries or different rows, not
 *     contradictions. "Between 200 and 400" is the claim that has held in all
 *     of them.
 *
 * At 5,000 rows it is worth having. Unindexed, the on-sale read is a Seq Scan
 * over 112 buffers taking 0.45–0.58 ms; with this index it is a Bitmap Heap
 * Scan over 3 buffers taking 0.02–0.04 ms. Conditions, because those figures
 * are not portable: 5,000 rows, two per plan (one on sale, one withdrawn), an
 * average row of 176 bytes over 112 heap pages, ANALYZEd, PostgreSQL 16.13,
 * three runs on one development machine, and the plan id bound as an untyped
 * prepared-statement parameter — which resolves to `character`, as the
 * driver's parameters do. (A key built with `lpad()` is `text`, and a `text`
 * comparison against this `character(26)` column cannot use the index at all;
 * a ladder built that way measures something the application never runs.)
 * Buffer counts are a page count and move with row width, and timings move
 * with the machine: earlier measurements in this programme put the unindexed
 * buffer count at 66, 103 and 131, and no two of anybody's timings have
 * agreed. The figures that have reproduced every time anybody measured them,
 * this one included, are the index sizes at 5,000 rows: **184 kB** for
 * `(plan_id)` and **264 kB** for `(plan_id, is_active)`.
 *
 * `(plan_id)` alone, not `(plan_id, is_active)`: the probe asks by `plan_id`
 * alone, a plan has a handful of packages so filtering `is_active` on the heap
 * costs a row or two, and the composite is 43% larger for that.
 *
 * ---------------------------------------------------------------------------
 * Two things this index does to anybody reproducing F-32
 * ---------------------------------------------------------------------------
 *
 * A table nobody has analysed plans differently. A freshly migrated and
 * seeded table carries `relpages = 0, reltuples = -1` in `pg_class`, and six
 * rows never reach autovacuum's analyse threshold — so on exactly that table
 * the planner takes this index even for six rows. `ANALYZE`, `VACUUM`,
 * `VACUUM FULL`, `REINDEX` and a `CREATE INDEX` over existing rows all give it
 * statistics, and it goes back to a Seq Scan. Those are one mechanism, not
 * several disagreements.
 *
 * And the original demonstration stops working once this index is chosen.
 * Without it, "update the row the scan meets first, then ask again" moves that
 * row to the end of the heap and the answer flips — the defect, shown. With
 * it, an UPDATE touching no indexed column is HOT: the index entry keeps
 * pointing at the row's original slot, an index scan returns rows in index
 * order, and the answer does not flip. Measured on a fresh seed: after one
 * UPDATE the heap order puts `ref_starter` first and an index scan still
 * answers `lyn_starter`. The defect is real; that recipe for showing it is
 * valid only where the planner reads the heap, which is why the test that
 * uses it switches index scans off first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hosting_packages', function (Blueprint $table): void {
            $table->index('plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('hosting_packages', function (Blueprint $table): void {
            $table->dropIndex(['plan_id']);
        });
    }
};
