<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The estate: the machines Lynomia runs on, and where they physically are.
 *
 * ===========================================================================
 * WHY A SERVER TABLE WHEN COMPUTE NODES ALREADY EXIST
 * ===========================================================================
 *
 * compute_nodes, hosting_nodes and dedicated_servers each describe a machine
 * in the vocabulary of what it does for customers: capacity, accounts, a
 * chassis somebody rents. None of them describes a machine in the vocabulary
 * of what an operator may do TO it — which credential reaches it, what was
 * discovered from it, whether anybody has agreed we may write to its disks.
 *
 * Those questions are the same for a hypervisor, a backup host and a machine
 * that has no role yet, and answering them three times in three tables is how
 * a classification ends up set on one and not the others.
 *
 * So a server row is the operator's view, and it points at the role-specific
 * row when one exists. A machine can be registered here before anybody has
 * decided what it is for, which is exactly the state a new delivery arrives in.
 *
 * ===========================================================================
 * RACKS ALREADY EXIST
 * ===========================================================================
 *
 * The datacenter -> rack -> machine hierarchy was built with the dedicated
 * server module: racks has name, row and units, and dedicated_servers hangs
 * off it by rack_unit and height_units. This migration adds the two fields the
 * control centre needs and does not have — power and network notes, which are
 * what an operator actually wants when standing in front of the rack — and
 * uses the same column names for a managed server's position so the two kinds
 * of machine can be read off one rack diagram.
 *
 * ===========================================================================
 * WHAT THE DATABASE ENFORCES, RATHER THAN THE APPLICATION
 * ===========================================================================
 *
 * Two things, because both are load-bearing and neither should depend on a
 * code path being taken:
 *
 *   1. safety_class defaults to do_not_touch. A row inserted by a seeder, a
 *      fixture or a hand-written INSERT is not touchable.
 *
 *   2. allow_reimage may only be true when safety_class is reimage_allowed,
 *      as a CHECK constraint. The Ansible inventory validator enforces the
 *      same rule on the YAML side; this is the same rule on the row side, so
 *      the two halves of the estate cannot disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('racks', function (Blueprint $table): void {
            // What somebody standing in front of the rack needs and the
            // dedicated module never had to record: which circuits feed it and
            // which switch ports it patches into.
            $table->text('power_notes')->nullable();
            $table->text('network_notes')->nullable();
        });

        Schema::create('managed_servers', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Identity
            $table->string('name')->unique();
            $table->string('environment');
            $table->string('state')->default('registered');

            // Location. All nullable: a machine can be registered from a
            // delivery note before anybody has racked it.
            $table->foreignUlid('datacenter_id')->nullable()->constrained('datacenters')->nullOnDelete();
            $table->foreignUlid('rack_id')->nullable()->constrained('racks')->nullOnDelete();
            // Same names and same types as dedicated_servers, so one rack
            // elevation can be drawn from both tables without a translation.
            $table->unsignedTinyInteger('rack_unit')->nullable();
            $table->unsignedTinyInteger('height_units')->default(1);

            // Declared hardware metadata. What a person wrote down; discovery
            // writes its own answers to server_facts rather than over these.
            $table->string('vendor')->nullable();
            $table->string('model')->nullable();
            $table->string('serial')->nullable();
            $table->string('asset_tag')->nullable();

            // How we reach it. Hostnames and addresses are configuration, not
            // secrets — the credential that opens them is held elsewhere.
            $table->string('management_address')->nullable();
            $table->unsignedSmallInteger('management_port')->nullable();
            $table->string('bmc_address')->nullable();
            $table->unsignedSmallInteger('bmc_port')->nullable();

            $table->string('operating_system')->nullable();

            // How we authenticate to it. A reference, never a secret — the
            // same architecture provider instances use, because a machine's
            // BMC password is exactly as dangerous as a registrar's API key.
            // The foreign key is added by the provider migration, which is
            // where credential_references is created.
            $table->ulid('credential_reference_id')->nullable();

            // Safety. The two columns the CHECK constraint below ties together.
            $table->string('safety_class')->default('do_not_touch');
            $table->boolean('allow_reimage')->default(false);
            $table->text('safety_reason')->nullable();
            $table->foreignUlid('safety_changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('safety_changed_at')->nullable();

            // What we last learned.
            $table->string('connection_state')->default('not_tested');
            $table->timestamp('last_connection_test_at')->nullable();
            $table->timestamp('last_discovery_at')->nullable();
            $table->timestamp('last_deployment_at')->nullable();
            $table->timestamp('last_verification_at')->nullable();

            // The role-specific row, when the machine has been given a role.
            $table->foreignUlid('compute_node_id')->nullable()->constrained('compute_nodes')->nullOnDelete();
            $table->foreignUlid('hosting_node_id')->nullable()->constrained('hosting_nodes')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['environment', 'state']);
            $table->index('safety_class');
            $table->index(['rack_id', 'rack_unit']);
        });

        // The rule that matters most in this migration, and the reason it is a
        // constraint rather than a validation: allow_reimage on a machine that
        // is not classified reimage_allowed is not a mistake to report, it is a
        // row that must not exist.
        DB::statement(<<<'SQL'
            ALTER TABLE managed_servers
            ADD CONSTRAINT managed_servers_reimage_needs_class
            CHECK (allow_reimage = false OR safety_class = 'reimage_allowed')
        SQL);

        Schema::create('server_facts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('managed_server_id')->constrained('managed_servers')->cascadeOnDelete();

            // Dotted path: hardware.cpu.cores, hardware.memory.mib, bmc.firmware.
            $table->string('key');
            $table->text('value')->nullable();
            $table->string('source');

            // Kept rather than overwritten, so "when did the RAM change" has an
            // answer. Bounded by a sweep rather than growing forever: superseded
            // rows older than the retention window are pruned, and the current
            // row for each key is never pruned.
            $table->timestamp('observed_at');
            $table->timestamp('superseded_at')->nullable();

            $table->timestamps();

            $table->index(['managed_server_id', 'key', 'superseded_at']);
        });

        // One current value per key per server. A partial unique index, so
        // history rows (which carry superseded_at) are unconstrained while the
        // present cannot be ambiguous.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX server_facts_one_current_value
            ON server_facts (managed_server_id, key)
            WHERE superseded_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('server_facts');
        Schema::dropIfExists('managed_servers');

        Schema::table('racks', function (Blueprint $table): void {
            $table->dropColumn(['power_notes', 'network_notes']);
        });
    }
};
