<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physical and virtual infrastructure the platform orchestrates.
 *
 * Every capacity figure here is a cache of what the provider reports, refreshed
 * by a sync worker. The platform's own allocations are the authoritative record
 * of what it has promised; the provider is authoritative for what exists. The
 * two disagree routinely, which is why drift is modelled rather than assumed
 * away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('slug')->unique();
            $table->jsonb('name');
            $table->char('country', 2);
            $table->string('city')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('accepts_new_services')->default(true);
            $table->timestampsTz();
        });

        Schema::create('datacenters', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('region_id')->constrained('regions')->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('facility')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('compute_clusters', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('datacenter_id')->constrained('datacenters')->cascadeOnDelete();

            $table->string('slug')->unique();
            $table->string('name');
            // proxmox | fake — the driver, not the vendor's product name.
            $table->string('driver', 32);

            /*
             * A REFERENCE to credentials, never the credentials. The secret
             * lives in the deployment's secret store; putting it here would put
             * it in every database backup, every replica and every developer's
             * restore.
             */
            $table->string('credentials_reference')->nullable();
            $table->string('api_endpoint')->nullable();
            $table->boolean('verify_tls')->default(true);

            $table->string('status', 24)->default('active'); // active | draining | maintenance | offline
            $table->timestampTz('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();

            $table->timestampsTz();

            $table->index(['datacenter_id', 'status']);
        });

        Schema::create('compute_nodes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('cluster_id')->constrained('compute_clusters')->cascadeOnDelete();

            // The node's name in the provider, e.g. the Proxmox node name.
            $table->string('provider_name');
            $table->string('status', 24)->default('active'); // active | draining | maintenance | offline

            // Physical capacity, as reported by the provider.
            $table->unsignedInteger('cpu_cores');
            $table->unsignedBigInteger('memory_mib');
            $table->unsignedBigInteger('storage_gib');

            /*
             * What the platform has promised. Kept separate from what the
             * provider currently reports as used: a machine that is powered off
             * still holds its allocation, and scheduling against "currently
             * used" would oversell the moment customers stop their VMs.
             */
            $table->unsignedInteger('allocated_cpu_cores')->default(0);
            $table->unsignedBigInteger('allocated_memory_mib')->default(0);
            $table->unsignedBigInteger('allocated_storage_gib')->default(0);

            // Provider-reported live usage, for dashboards and alerting only.
            $table->decimal('reported_cpu_usage', 5, 4)->nullable();
            $table->unsignedBigInteger('reported_memory_used_mib')->nullable();

            $table->decimal('cpu_overcommit_ratio', 5, 2)->default(4.00);
            $table->unsignedTinyInteger('memory_headroom_percent')->default(10);

            $table->unsignedInteger('vm_count')->default(0);
            $table->boolean('is_healthy')->default(true);
            $table->timestampTz('last_seen_at')->nullable();

            $table->jsonb('capabilities')->nullable();

            $table->timestampsTz();

            $table->unique(['cluster_id', 'provider_name']);
            // Drives placement: find schedulable nodes cheaply.
            $table->index(['status', 'is_healthy']);
        });

        Schema::create('compute_storages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('cluster_id')->constrained('compute_clusters')->cascadeOnDelete();
            $table->foreignUlid('node_id')->nullable()->constrained('compute_nodes')->cascadeOnDelete();

            $table->string('provider_name');
            $table->string('storage_class', 32);     // nvme | ssd | hdd | ceph
            $table->boolean('shared')->default(false);
            $table->unsignedBigInteger('total_gib')->nullable();
            $table->unsignedBigInteger('available_gib')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();

            $table->unique(['cluster_id', 'node_id', 'provider_name']);
        });

        Schema::create('vm_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('cluster_id')->nullable()->constrained('compute_clusters')->cascadeOnDelete();

            $table->string('slug');
            $table->jsonb('name');
            $table->string('os_family', 32);         // ubuntu | debian | almalinux | rocky | windows
            $table->string('os_version', 32);
            $table->string('architecture', 16)->default('x86_64');

            $table->string('provider_reference')->nullable();
            $table->string('checksum', 128)->nullable();
            $table->string('checksum_algorithm', 16)->nullable();

            $table->boolean('cloud_init')->default(true);
            $table->boolean('guest_agent')->default(true);

            /*
             * Proprietary images are not redistributed without a licence, and
             * the platform records which ones need one rather than leaving it to
             * an operator to remember.
             */
            $table->boolean('requires_licence')->default(false);
            $table->string('licence_note')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['cluster_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vm_templates');
        Schema::dropIfExists('compute_storages');
        Schema::dropIfExists('compute_nodes');
        Schema::dropIfExists('compute_clusters');
        Schema::dropIfExists('datacenters');
        Schema::dropIfExists('regions');
    }
};
