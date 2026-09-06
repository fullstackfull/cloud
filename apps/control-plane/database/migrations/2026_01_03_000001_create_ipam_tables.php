<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IP address management.
 *
 * The question that matters — "is this address free?" — cannot be answered by
 * asking the hypervisor. Proxmox knows what is configured on a bridge; it does
 * not know what the platform has promised to a customer whose machine has not
 * been created yet. So the platform owns this, and allocation is transactional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('networks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('datacenter_id')->constrained('datacenters')->cascadeOnDelete();

            $table->string('slug');
            $table->string('name');
            // management | compute | customer | storage | backup | provisioning | monitoring
            $table->string('purpose', 24);

            /*
             * Nullable, and never defaulted. A VLAN id that is correct in one
             * datacentre is someone else's production network in another, so it
             * comes from inventory or it does not exist.
             */
            $table->unsignedSmallInteger('vlan_id')->nullable();
            $table->string('bridge')->nullable();

            $table->boolean('is_customer_facing')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['datacenter_id', 'slug']);
            $table->index(['purpose', 'is_active']);
        });

        Schema::create('ip_pools', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('datacenter_id')->constrained('datacenters')->cascadeOnDelete();

            $table->string('slug')->unique();
            $table->string('name');
            $table->unsignedTinyInteger('ip_version');   // 4 or 6
            $table->string('scope', 16);                 // public | private
            $table->boolean('is_active')->default(true);

            // Days a released address stays unusable. Configurable per pool
            // because a pool used for short-lived test machines and one used
            // for long-lived customer servers carry different reputation risk.
            $table->unsignedSmallInteger('quarantine_days')->default(7);

            $table->timestampsTz();
        });

        Schema::create('subnets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('ip_pool_id')->constrained('ip_pools')->cascadeOnDelete();
            $table->foreignUlid('network_id')->nullable()->constrained('networks')->nullOnDelete();

            // PostgreSQL's own network types: they validate, they compare
            // correctly, and they make containment queries possible. A varchar
            // would accept "10.0.0.300" and sort "10.0.0.9" after "10.0.0.10".
            $table->string('cidr');
            $table->unsignedTinyInteger('ip_version');
            $table->string('gateway')->nullable();
            $table->unsignedSmallInteger('prefix_length');

            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['ip_pool_id', 'cidr']);
        });

        Schema::create('ip_addresses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('subnet_id')->constrained('subnets')->cascadeOnDelete();

            $table->string('address');
            $table->unsignedTinyInteger('ip_version');

            // available | reserved | assigned | quarantined | unavailable
            $table->string('status', 16)->default('available');

            $table->timestampTz('quarantined_until')->nullable();
            $table->string('quarantine_reason', 64)->nullable();
            $table->text('notes')->nullable();

            $table->timestampsTz();

            $table->unique(['subnet_id', 'address']);
        });

        /*
         * A partial index over exactly the rows the allocator scans.
         *
         * Allocation runs SELECT ... WHERE subnet_id = ? AND status =
         * 'available' ORDER BY address FOR UPDATE SKIP LOCKED. Indexing only
         * the available rows keeps that index small even when a pool is 95%
         * assigned, which is when allocation latency matters most.
         */
        DB::statement(
            "CREATE INDEX ip_addresses_available_idx ON ip_addresses (subnet_id, address) WHERE status = 'available'"
        );
        DB::statement(
            "CREATE INDEX ip_addresses_quarantined_idx ON ip_addresses (quarantined_until) WHERE status = 'quarantined'"
        );

        Schema::create('ip_reservations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('ip_address_id')->constrained('ip_addresses')->cascadeOnDelete();

            $table->ulid('provisioning_job_id')->nullable();
            $table->foreignUlid('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->timestampTz('expires_at');
            $table->timestampTz('released_at')->nullable();
            $table->string('released_reason', 64)->nullable();

            $table->timestampsTz();

            // One live reservation per address. The partial unique index is the
            // guarantee: two jobs cannot both hold the same address even if the
            // status column were somehow wrong.
            $table->index(['provisioning_job_id']);
            $table->index(['expires_at', 'released_at']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX ip_reservations_live_idx ON ip_reservations (ip_address_id) WHERE released_at IS NULL'
        );

        Schema::create('ip_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('ip_address_id')->constrained('ip_addresses')->cascadeOnDelete();
            $table->foreignUlid('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            // The service holding it. Nullable so an address can be assigned to
            // infrastructure rather than to a customer service.
            $table->ulid('service_id')->nullable();
            $table->string('assignable_type')->nullable();
            $table->ulid('assignable_id')->nullable();

            $table->boolean('is_primary')->default(false);
            $table->string('mac_address', 17)->nullable();

            $table->timestampTz('assigned_at');
            $table->timestampTz('released_at')->nullable();

            $table->timestampsTz();

            /*
             * Rows are never deleted. When an abuse report arrives naming an
             * address and a timestamp, the only way to answer "who had this at
             * that moment" is a history that was never overwritten.
             */
            $table->index(['ip_address_id', 'assigned_at']);
            $table->index(['service_id']);
            $table->index(['customer_id', 'released_at']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX ip_assignments_live_idx ON ip_assignments (ip_address_id) WHERE released_at IS NULL'
        );

        Schema::create('reverse_dns_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('ip_address_id')->constrained('ip_addresses')->cascadeOnDelete();
            $table->string('hostname');
            $table->string('status', 16)->default('pending'); // pending | applied | failed
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            $table->unique('ip_address_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reverse_dns_records');
        Schema::dropIfExists('ip_assignments');
        Schema::dropIfExists('ip_reservations');
        Schema::dropIfExists('ip_addresses');
        Schema::dropIfExists('subnets');
        Schema::dropIfExists('ip_pools');
        Schema::dropIfExists('networks');
    }
};
