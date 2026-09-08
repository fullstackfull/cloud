<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the platform asked for a service to be destroyed.
 *
 * Distinct from `terminated_at`, which is when it *was* — and the gap between
 * the two is a queued job that may take minutes, or may be waiting behind a
 * provider that is not answering. Without this column the retention sweep has
 * no way to tell "nobody has started this" from "somebody started it and it
 * has not finished", so a daily sweep would ask again every day: a second
 * audit entry, a second notification telling a customer their data has been
 * destroyed, and a count that reports work it did not do.
 *
 * The provisioning job's idempotency key already stops a second machine being
 * destroyed. This stops the platform saying so twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->timestamp('termination_requested_at')->nullable()->after('retention_warned_at');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn('termination_requested_at');
        });
    }
};
