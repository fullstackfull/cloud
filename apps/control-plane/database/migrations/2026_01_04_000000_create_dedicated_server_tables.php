<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physical server inventory.
 *
 * The defining difference from a VPS is that the machine already exists. It is
 * either free or it is not, and no amount of retrying conjures another one — so
 * an order for a hardware profile with nothing free waits for an operator
 * rather than failing and refunding a customer who was content to wait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('racks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('datacenter_id')->constrained('datacenters')->cascadeOnDelete();
            $table->string('name');
            $table->string('row')->nullable();
            $table->unsignedTinyInteger('units')->default(42);
            $table->timestampsTz();

            $table->unique(['datacenter_id', 'name']);
        });

        Schema::create('dedicated_servers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('datacenter_id')->constrained('datacenters')->cascadeOnDelete();
            $table->foreignUlid('rack_id')->nullable()->constrained('racks')->nullOnDelete();

            $table->string('manufacturer');
            $table->string('model');
            // The one identifier that is stable across every rebuild, reinstall
            // and customer the machine ever has.
            $table->string('serial')->unique();
            $table->string('asset_tag')->nullable();

            $table->unsignedTinyInteger('rack_unit')->nullable();
            $table->unsignedTinyInteger('height_units')->default(1);

            // A hardware profile a plan can require, so ordering matches stock
            // without describing every component in the catalogue.
            $table->string('hardware_profile', 64)->nullable();

            $table->string('status', 24)->default('available');
            // available | reserved | provisioning | active | maintenance | failed | retired

            $table->string('power_state', 16)->default('unknown'); // on | off | unknown

            $table->foreignUlid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->ulid('service_id')->nullable();

            $table->timestampTz('reserved_until')->nullable();
            $table->ulid('reserved_by_order_id')->nullable();

            $table->text('notes')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('retired_at')->nullable();

            $table->timestampsTz();

            // Drives "what can I sell right now".
            $table->index(['status', 'hardware_profile']);
            $table->index(['datacenter_id', 'status']);
            $table->index('customer_id');
        });

        Schema::create('server_components', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('dedicated_server_id')->constrained('dedicated_servers')->cascadeOnDelete();

            $table->string('kind', 24);          // cpu | memory | disk | raid | nic | psu | gpu
            $table->string('model')->nullable();
            $table->string('serial')->nullable();
            $table->unsignedInteger('quantity')->default(1);

            // Kind-specific detail: cores and clock for a CPU, capacity and
            // interface for a disk. Columns for all of them would be a wall of
            // nulls; the shape is validated by a typed DTO on read.
            $table->jsonb('attributes')->nullable();

            $table->string('health', 16)->default('unknown'); // ok | degraded | failed | unknown
            $table->timestampTz('health_checked_at')->nullable();

            $table->timestampsTz();

            $table->index(['dedicated_server_id', 'kind']);
            $table->index('health');
        });

        /*
         * Out-of-band management endpoints.
         *
         * A separate table because a BMC is a separate computer with its own
         * address, its own credentials and its own network — and because
         * putting the credential reference next to the customer assignment
         * makes it far too easy to expose one with the other.
         */
        Schema::create('bmc_endpoints', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('dedicated_server_id')->constrained('dedicated_servers')->cascadeOnDelete();

            $table->string('protocol', 16);      // redfish | ilo | ipmi
            $table->string('address');           // management network only
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('username')->nullable();

            /*
             * A REFERENCE to the secret store, never the password. A BMC
             * credential is power control and virtual media on a physical host;
             * storing it here would put it in every backup and every replica.
             */
            $table->string('credentials_reference')->nullable();

            $table->boolean('verify_tls')->default(true);
            $table->string('firmware_version')->nullable();
            $table->timestampTz('last_contacted_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestampsTz();

            $table->unique(['dedicated_server_id', 'protocol']);
        });

        /*
         * Unattended installation profiles: which OS, which template, and the
         * partitioning the machine is built with.
         */
        Schema::create('os_install_profiles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('slug')->unique();
            $table->jsonb('name');
            $table->string('os_family', 32);
            $table->string('os_version', 32);
            $table->string('installer', 24);     // autoinstall | preseed | kickstart
            $table->text('template');            // the installer config, templated
            $table->jsonb('defaults')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        /*
         * One PXE boot, authorised explicitly, for one machine.
         *
         * The row exists so that a boot is a recorded decision rather than a
         * standing configuration. A machine left with PXE first in its boot
         * order reinstalls itself the next time it reboots for any reason —
         * which is a customer's entire server erased by a power cut.
         */
        Schema::create('pxe_boot_authorisations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('dedicated_server_id')->constrained('dedicated_servers')->cascadeOnDelete();
            $table->foreignUlid('os_install_profile_id')->nullable()->constrained('os_install_profiles')->nullOnDelete();
            $table->ulid('provisioning_job_id')->nullable();

            $table->string('mac_address', 17);
            $table->string('status', 16)->default('pending'); // pending | booted | installed | expired | revoked

            // A narrow window, because an authorisation that never expires is a
            // standing invitation to reinstall.
            $table->timestampTz('expires_at');
            $table->timestampTz('booted_at')->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->foreignUlid('authorised_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('authorisation_reason');

            $table->jsonb('rendered_config')->nullable();

            $table->timestampsTz();

            $table->index(['mac_address', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::table('dedicated_servers', function (Blueprint $table): void {
            $table->foreign('service_id')->references('id')->on('services')->nullOnDelete();
            $table->foreign('reserved_by_order_id')->references('id')->on('orders')->nullOnDelete();
        });

        Schema::table('pxe_boot_authorisations', function (Blueprint $table): void {
            $table->foreign('provisioning_job_id')->references('id')->on('provisioning_jobs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pxe_boot_authorisations', function (Blueprint $table): void {
            $table->dropForeign(['provisioning_job_id']);
        });
        Schema::table('dedicated_servers', function (Blueprint $table): void {
            $table->dropForeign(['service_id']);
            $table->dropForeign(['reserved_by_order_id']);
        });

        Schema::dropIfExists('pxe_boot_authorisations');
        Schema::dropIfExists('os_install_profiles');
        Schema::dropIfExists('bmc_endpoints');
        Schema::dropIfExists('server_components');
        Schema::dropIfExists('dedicated_servers');
        Schema::dropIfExists('racks');
    }
};
