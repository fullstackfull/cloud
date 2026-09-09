<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per attempt to apply a zone import: what it would have done, and
 * whether it was applied, refused, or found its preview stale. The metric
 * over `outcome` is how often customers hit a wall, which is how a wall
 * that needs a better sentence gets found. No zone text is stored: the
 * records that landed are in dns_records with their own audit rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_zone_imports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('dns_zone_id')->constrained('dns_zones')->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('mode', 16);
            $table->string('outcome', 16);
            $table->unsignedInteger('added')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('removed')->default(0);
            $table->unsignedInteger('unchanged')->default(0);
            $table->unsignedInteger('refused')->default(0);
            $table->unsignedInteger('ignored')->default(0);
            $table->char('fingerprint', 64);
            $table->foreignUlid('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['dns_zone_id', 'created_at']);
            $table->index('outcome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_zone_imports');
    }
};
