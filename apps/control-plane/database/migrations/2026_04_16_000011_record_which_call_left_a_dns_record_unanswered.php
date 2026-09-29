<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which call left a record `indeterminate`: a publish, or a delete.
 *
 * Reconciliation settled an indeterminate row by one reading — "the value is
 * not in the zone" meant deleted, "it is" meant active — which is right only
 * for one of the two calls in each case. A publish that never arrived was
 * settled as a deletion while the customer still believed the record was
 * theirs; a delete that never arrived was settled as live, stamped with the
 * provider's identifier, and reported as no drift at all. The row could not
 * say which call it was waiting on, so nothing could tell. (F-11.)
 *
 * Nullable, and not backfilled: a row written before this column cannot say,
 * and a guess would be the defect over again. `IndeterminateAfter`'s docblock
 * says what a sweep does with a null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dns_records', function (Blueprint $table): void {
            $table->string('indeterminate_after', 16)->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('dns_records', function (Blueprint $table): void {
            $table->dropColumn('indeterminate_after');
        });
    }
};
