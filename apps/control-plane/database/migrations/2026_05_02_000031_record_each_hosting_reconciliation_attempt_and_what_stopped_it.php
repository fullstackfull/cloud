<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the reconciliation sweep last asked a hosting node for its accounts,
 * and why the last attempt stopped short, if it did.
 *
 * `reconciled_at` says when a node's accounts were last compared, and it is
 * only written when they were. The sweep also ordered by it, least recent
 * first, so a node whose listing could not be read — a panel that did not
 * answer, a listing the adapter refused as ambiguous — kept `reconciled_at`
 * null and stayed at the front of every sweep, and a batch's worth of them
 * starved every other node. Nothing recorded that it had happened.
 *
 * `reconcile_attempted_at` is written on every attempt, read or not, and is
 * what the sweep orders by. `reconcile_error` is the last attempt's refusal,
 * cleared by one that is read, and it is what an operator's node list shows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hosting_nodes', function (Blueprint $table): void {
            $table->timestamp('reconcile_attempted_at')->nullable()->after('reconciled_at');
            $table->text('reconcile_error')->nullable()->after('reconcile_attempted_at');
        });
    }

    public function down(): void
    {
        Schema::table('hosting_nodes', function (Blueprint $table): void {
            $table->dropColumn(['reconcile_attempted_at', 'reconcile_error']);
        });
    }
};
