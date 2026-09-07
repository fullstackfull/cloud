<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere to record a restore.
 *
 * The backups table has carried Restoring and Restored states since it was
 * created, and had nowhere to put what a restore actually did: no task id, no
 * start, no finish, and no record of who asked for the most destructive
 * operation the API offers.
 *
 * The task id is its own column rather than reusing provider_task_id, for two
 * reasons. Overwriting that column would erase the identifier of the backup
 * itself — the one thing that lets an operator find the archive if a restore
 * goes wrong, which is precisely when they need it. And the table carries a
 * unique index on (provider, provider_task_id), so a restore task written
 * there could collide with an unrelated backup's task on the same provider
 * and fail the write at the worst possible moment. Verification already has
 * its own column for exactly this reason; restore now matches it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->string('restore_task_id')->nullable()->after('verification_task_id');
            $table->timestampTz('restore_started_at')->nullable()->after('finished_at');
            $table->timestampTz('restored_at')->nullable()->after('restore_started_at');

            // Nulled rather than cascaded, like every other actor reference
            // here: the record that a restore happened must survive the
            // account of the person who asked for it being removed.
            $table->foreignUlid('restored_by_user_id')->nullable()->after('requested_by_user_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('restored_by_user_id');
            $table->dropColumn(['restore_task_id', 'restore_started_at', 'restored_at']);
        });
    }
};
