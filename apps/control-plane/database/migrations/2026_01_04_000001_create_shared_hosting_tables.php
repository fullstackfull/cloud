<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hosting_nodes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('datacenter_id')->constrained('datacenters')->cascadeOnDelete();

            $table->string('slug')->unique();
            $table->string('hostname');
            // cpanel | directadmin | fake
            $table->string('panel', 24);
            $table->string('panel_version')->nullable();

            $table->string('api_endpoint')->nullable();
            // A reference to the secret store. A WHM API token creates and
            // deletes every account on the machine.
            $table->string('credentials_reference')->nullable();
            $table->boolean('verify_tls')->default(true);

            $table->string('status', 24)->default('active'); // active | draining | maintenance | offline
            $table->boolean('accepts_new_accounts')->default(true);

            /*
             * Licensing state, tracked rather than assumed. cPanel, DirectAdmin,
             * CloudLinux and LiteSpeed are commercial products; a node whose
             * licence has lapsed cannot create accounts, and finding that out
             * at provisioning time means a customer has already paid.
             */
            $table->boolean('panel_licensed')->default(false);
            $table->timestampTz('licence_checked_at')->nullable();
            $table->string('licence_status', 32)->nullable();

            $table->boolean('cloudlinux')->default(false);
            $table->boolean('litespeed')->default(false);

            // Capacity. Disk is the one that breaks everything at once when it
            // runs out: a full shared node stops accepting mail and breaks every
            // site on it simultaneously.
            $table->unsignedInteger('max_accounts')->nullable();
            $table->unsignedInteger('account_count')->default(0);
            $table->unsignedBigInteger('disk_total_mib')->nullable();
            $table->unsignedBigInteger('disk_used_mib')->nullable();
            $table->decimal('load_average', 6, 2)->nullable();

            $table->timestampTz('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();

            $table->timestampsTz();

            $table->index(['status', 'accepts_new_accounts']);
            $table->index(['panel', 'status']);
        });

        Schema::create('hosting_packages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('plan_id')->nullable()->constrained('plans')->nullOnDelete();

            $table->string('slug')->unique();
            $table->string('panel_package_name');

            $table->unsignedBigInteger('disk_quota_mib')->nullable();
            $table->unsignedBigInteger('bandwidth_quota_mib')->nullable();
            $table->unsignedInteger('max_addon_domains')->nullable();
            $table->unsignedInteger('max_subdomains')->nullable();
            $table->unsignedInteger('max_databases')->nullable();
            $table->unsignedInteger('max_email_accounts')->nullable();

            /*
             * CloudLinux resource limits. Recorded on the package whether or not
             * CloudLinux is licensed on the node, and the platform reports which
             * of the two it is rather than implying isolation it does not have.
             */
            $table->unsignedInteger('cpu_limit_percent')->nullable();
            $table->unsignedBigInteger('memory_limit_mib')->nullable();
            $table->unsignedInteger('io_limit_kbps')->nullable();
            $table->unsignedInteger('process_limit')->nullable();
            $table->unsignedInteger('entry_process_limit')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('hosting_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hosting_node_id')->constrained('hosting_nodes')->cascadeOnDelete();
            $table->foreignUlid('hosting_package_id')->nullable()->constrained('hosting_packages')->nullOnDelete();
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->ulid('service_id')->nullable();

            $table->string('username', 32);
            $table->string('primary_domain');
            $table->string('status', 24)->default('pending'); // pending | active | suspended | terminated

            $table->ulid('ip_address_id')->nullable();

            // Usage, refreshed by a sync worker. Never authoritative for
            // billing on its own — the panel is.
            $table->unsignedBigInteger('disk_used_mib')->nullable();
            $table->unsignedBigInteger('bandwidth_used_mib')->nullable();
            $table->timestampTz('usage_synced_at')->nullable();

            $table->string('ssl_status', 24)->nullable();
            $table->timestampTz('ssl_expires_at')->nullable();

            $table->timestampTz('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();
            $table->timestampTz('terminated_at')->nullable();

            $table->timestampsTz();

            // A username is unique per node, not globally: two nodes can each
            // have a "shop" account and the panel is fine with that.
            $table->unique(['hosting_node_id', 'username']);
            $table->index(['customer_id', 'status']);
            $table->index('primary_domain');
        });

        Schema::table('hosting_accounts', function (Blueprint $table): void {
            $table->foreign('service_id')->references('id')->on('services')->nullOnDelete();
            $table->foreign('ip_address_id')->references('id')->on('ip_addresses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hosting_accounts', function (Blueprint $table): void {
            $table->dropForeign(['service_id']);
            $table->dropForeign(['ip_address_id']);
        });

        Schema::dropIfExists('hosting_accounts');
        Schema::dropIfExists('hosting_packages');
        Schema::dropIfExists('hosting_nodes');
    }
};
