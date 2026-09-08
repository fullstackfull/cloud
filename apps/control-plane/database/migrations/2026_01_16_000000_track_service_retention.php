<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a service stops serving, why, and when the data behind it goes.
 *
 * **The date is stored rather than computed.** `suspended_at` plus a number
 * from configuration would give the same answer today and a different one the
 * morning after somebody changed the number — and the customer has already
 * been told a date. A promise that moves when a config file changes is not a
 * promise; it is a default.
 *
 * `ended_reason` exists because the sweep must be able to tell a customer who
 * cancelled from a customer who has not paid. Destroying the first one's data
 * on the day they chose is the deal; destroying the second one's automatically
 * is a decision this platform does not make without a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->timestamp('retention_ends_at')->nullable()->after('suspended_at');
            $table->timestamp('retention_warned_at')->nullable()->after('retention_ends_at');
            $table->string('ended_reason', 32)->nullable()->after('retention_warned_at');

            // The sweep's only query: what is out of time. Indexed on the two
            // columns it filters by, because it runs on every service the
            // platform has ever sold.
            $table->index(['status', 'retention_ends_at']);
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropIndex(['status', 'retention_ends_at']);
            $table->dropColumn(['retention_ends_at', 'retention_warned_at', 'ended_reason']);
        });
    }
};
