<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the platform knows about a backup it is trying to get rid of.
 *
 * Deleting a backup is the one destructive operation on this table, and the
 * evidence it leaves has to answer a question somebody will ask months later:
 * *who asked for this to go, why, and did the provider actually do it?*
 *
 * **The row is never deleted.** A backup that has gone still has a history —
 * when it was taken, how big it was, who asked for it to be removed — and that
 * history is what answers "where is my backup from March". `state = deleted`
 * plus `provider_deleted_at` says the archive is gone; deleting the row would
 * say nothing at all.
 *
 * **`provider_deleted_at` is not stamped by asking.** It is stamped when a
 * subsequent listing of the datastore no longer contains the archive. The
 * provider accepting a delete is not the provider having deleted, and a row
 * that said `deleted` on the strength of an accepted call would be telling a
 * customer their data is gone while it is still occupying a datastore they are
 * being billed for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            // Who asked, and when. Nullable because most backups are never
            // asked about — they expire and the sweep deals with them.
            $table->timestampTz('deletion_requested_at')->nullable()->after('failure_reason');
            $table->foreignUlid('deletion_requested_by_user_id')->nullable()
                ->after('deletion_requested_at')
                ->constrained('users')->nullOnDelete();

            /*
             * customer | retention | operator | service_terminated
             *
             * Kept because the answer changes what an operator does about a
             * backup that will not delete: a customer's request that failed is
             * a support conversation, and a retention sweep that failed is a
             * datastore filling up.
             */
            $table->string('deletion_reason', 32)->nullable()->after('deletion_requested_by_user_id');

            // The provider's handle on the deletion, where the provider issues
            // one. Proxmox's prune is synchronous and issues none, so this is
            // nullable and its absence is not a failure.
            $table->string('deletion_task_id')->nullable()->after('deletion_reason');

            // Stamped only when a listing no longer contains the archive.
            $table->timestampTz('provider_deleted_at')->nullable()->after('deletion_task_id');

            // How many times the platform has asked the provider to remove it
            // and found it still there. Bounded, and a row that exhausts the
            // bound goes in front of a person rather than looping for ever.
            $table->unsignedSmallInteger('deletion_attempts')->default(0)->after('provider_deleted_at');

            /*
             * A backup the platform must not remove even when it expires.
             *
             * The only writer today is the termination path, which holds a
             * customer's last backups through the retention window: a customer
             * who cancels by mistake on the 1st and asks on the 20th should
             * still have something to restore.
             */
            $table->timestampTz('protected_until')->nullable()->after('expires_at');

            // The sweep's query: expired, not protected, not already going.
            $table->index(['state', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->dropIndex(['state', 'expires_at']);
            $table->dropConstrainedForeignId('deletion_requested_by_user_id');
            $table->dropColumn([
                'deletion_requested_at',
                'deletion_reason',
                'deletion_task_id',
                'provider_deleted_at',
                'deletion_attempts',
                'protected_until',
            ]);
        });
    }
};
