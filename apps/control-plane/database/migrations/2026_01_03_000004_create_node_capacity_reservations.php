<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which machine a node's committed capacity belongs to.
 *
 * Without it, capacity commitment is a bare counter increment, and a retried
 * provisioning job commits a second time for the same machine. The counter then
 * never comes back: release is driven by destroying a machine, and there is only
 * one machine to destroy. A node loses capacity permanently, silently, and in
 * proportion to how often provisioning is retried — which is highest exactly
 * when the fleet is already under strain.
 *
 * The unique index on the reservation key is what makes the commitment
 * idempotent, in the same way the provisioning job's own key is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_capacity_reservations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('node_id')->constrained('compute_nodes')->cascadeOnDelete();
            $table->foreignUlid('storage_id')->nullable()->constrained('compute_storages')->nullOnDelete();

            /*
             * Usually the provisioning job's idempotency key. Anything stable
             * for the machine being placed works, as long as a retry produces
             * the same value.
             */
            $table->string('reservation_key', 128);

            $table->ulid('service_id')->nullable();
            $table->foreignUlid('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->unsignedInteger('vcpu');
            $table->unsignedBigInteger('memory_mib');
            $table->unsignedBigInteger('disk_gib');

            $table->timestampTz('released_at')->nullable();
            $table->string('released_reason', 64)->nullable();

            $table->timestampsTz();

            $table->unique('reservation_key');
            $table->index(['node_id', 'released_at']);
            $table->index(['service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_capacity_reservations');
    }
};
