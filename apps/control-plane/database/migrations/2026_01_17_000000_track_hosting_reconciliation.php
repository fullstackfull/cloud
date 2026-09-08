<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a hosting node's accounts were last compared with the panel's.
 *
 * Deliberately not `last_synced_at`, which is the health sweep's stamp. They
 * answer different questions — "what is this node's load and disk" against
 * "does the panel hold the accounts we think it does" — run on different
 * schedules, and cost different amounts. One column for both would mean the
 * cheap sweep kept resetting the expensive one's clock, and the comparison
 * would quietly stop happening.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hosting_nodes', function (Blueprint $table): void {
            $table->timestamp('reconciled_at')->nullable()->after('last_sync_error');
        });
    }

    public function down(): void
    {
        Schema::table('hosting_nodes', function (Blueprint $table): void {
            $table->dropColumn('reconciled_at');
        });
    }
};
