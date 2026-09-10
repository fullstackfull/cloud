<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A provisioning job learns who asked for it.
 *
 * Backups, file restores, WordPress operations and zone imports have recorded
 * their requester since they were built. Provisioning jobs never have — and
 * provisioning jobs are where a power action and a rebuild live, which is
 * exactly the question the audit put: a team of three cannot see who rebooted
 * what.
 *
 * Nullable, and it stays nullable. Two kinds of row legitimately have no user
 * behind them: everything written before this column existed, and everything
 * the platform starts itself — the build that follows a paid order, the
 * suspension that follows an unpaid one, the reconciler correcting drift. Those
 * are the platform acting, not a person, and the customer's activity feed says
 * so rather than attributing them to whoever happened to be signed in.
 *
 * The index is on the column alone: it is read to resolve a handful of actor
 * names for a page of activity, never to scan a user's history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provisioning_jobs', function (Blueprint $table): void {
            $table->foreignUlid('requested_by_user_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('provisioning_jobs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('requested_by_user_id');
        });
    }
};
