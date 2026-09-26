<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two facts a backup row needs so that one operation is never judged by
 * another's clock (F-09).
 *
 * `verification_started_at` is when the verification the row is now waiting
 * on was started. `ReconcileBackup` gives up on a task after
 * `backups.max_poll_hours`, and it used to measure every operation from the
 * archive's own `started_at` — when the BACKUP was taken — so a verification
 * or a restore of an archive older than the window was handed to a person on
 * its first poll. A restore already had its stamp (`restore_started_at`),
 * which nothing read; a verification had none. `verification_requested_at`
 * is not that stamp: it is the sweep's fair-ordering key and is written on a
 * refused attempt too.
 *
 * `quarantined_from` is the state a row was in when it went to `NeedsReview`
 * — which operation a person is being asked to settle. It is what lets a
 * restore that went to review keep holding the machine against a second
 * restore (the provider may still be writing the disks), and what tells an
 * operator settling the row which outcomes are even possible.
 *
 * The backfill is for rows already in flight or in review when this runs:
 *
 *  - a `verifying` row takes its request stamp, or failing that its last
 *    update, as the start of its verification;
 *  - a `restoring` row without a start stamp (none should exist) takes its
 *    last update;
 *  - a `needs_review` row whose last restore started after its last restore
 *    finished is recorded as an interrupted restore. That is a conservative
 *    reading, chosen because the alternative is to release a machine that a
 *    provider may still be writing to; every other review row is left
 *    unattributed, and the settling route refuses a row it cannot attribute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->timestampTz('verification_started_at')->nullable()->after('verification_requested_at');
            $table->string('quarantined_from', 24)->nullable()->after('state');
        });

        DB::table('backups')
            ->where('state', 'verifying')
            ->whereNull('verification_started_at')
            ->update(['verification_started_at' => DB::raw('coalesce(verification_requested_at, updated_at, created_at)')]);

        DB::table('backups')
            ->where('state', 'restoring')
            ->whereNull('restore_started_at')
            ->update(['restore_started_at' => DB::raw('coalesce(updated_at, created_at)')]);

        DB::table('backups')
            ->where('state', 'needs_review')
            ->whereNull('quarantined_from')
            ->whereNotNull('restore_started_at')
            ->where(static function ($query): void {
                $query->whereNull('restored_at')->orWhereColumn('restored_at', '<', 'restore_started_at');
            })
            ->update(['quarantined_from' => 'restoring']);
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->dropColumn(['verification_started_at', 'quarantined_from']);
        });
    }
};
