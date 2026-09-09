<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provider instances, and the credentials and licences they depend on.
 *
 * ===========================================================================
 * WHY AN INSTANCE AND NOT A SETTING
 * ===========================================================================
 *
 * Until now "which payment provider" was one value in the environment, and
 * that shape cannot express the thing the platform actually needs: a test
 * account and a live account, both configured, both working, and never
 * substitutable for one another. Same for a registrar sandbox beside a
 * registrar production account, and for a staging Proxmox cluster beside the
 * real one.
 *
 * So a provider is a row, an instance has an environment, and the requirement
 * engine refuses to let a development credential satisfy a production need.
 *
 * ===========================================================================
 * WHAT IS DELIBERATELY NOT HERE
 * ===========================================================================
 *
 * The secret. credential_references records that a credential exists, where to
 * find it, when it was last tested and whether it worked — and the value lives
 * in the deployment controller's secret backend. This table cannot leak a
 * password because it has never held one, which is a stronger guarantee than
 * remembering to redact.
 *
 * The same is true of licences: a licence key that is itself sensitive is
 * stored as a credential reference, and this row points at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_references', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('name')->unique();
            $table->string('purpose');
            $table->string('environment');

            // Where the value lives. A path or key in the secret backend, never
            // the value. The backend is named so that a second backend later
            // does not need a migration.
            $table->string('backend')->default('controller_environment');
            $table->string('backend_reference');

            $table->string('state')->default('missing');
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('rotates_at')->nullable();
            $table->timestamp('rotation_reminder_at')->nullable();

            // A short, non-reversible hint so an operator can tell two
            // credentials apart on a screen without either being shown. Last
            // four characters at most, and only ever of a public identifier
            // such as a token id — never of the secret half.
            $table->string('masked_hint')->nullable();

            // Who recorded it, when it was last rotated, and — if it has been
            // withdrawn — who did that and why. A revoked credential keeps its
            // row: the providers and machines that pointed at it still point
            // at it, and "this is blocked because its credential was revoked
            // on Tuesday by X" is the sentence an operator needs to read.
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUlid('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revoked_reason')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['environment', 'state']);
        });

        // Deferred from the registry migration, which creates managed_servers
        // before this table exists.
        Schema::table('managed_servers', function (Blueprint $table): void {
            $table->foreign('credential_reference_id')
                ->references('id')->on('credential_references')
                ->nullOnDelete();
        });

        Schema::create('licences', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('product');
            $table->string('licence_type')->nullable();
            $table->string('environment');
            $table->string('state')->default('unknown');

            // What it covers. A cPanel licence belongs to one machine; a
            // registrar authorisation belongs to no machine at all.
            $table->foreignUlid('managed_server_id')->nullable()->constrained('managed_servers')->nullOnDelete();

            $table->date('starts_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->date('renews_on')->nullable();
            $table->unsignedInteger('seats')->nullable();

            // The vendor's own reference — an account number or order id, which
            // is not a secret. If the licence KEY is sensitive it is a
            // credential reference instead.
            $table->string('external_reference')->nullable();
            $table->foreignUlid('credential_reference_id')->nullable()->constrained('credential_references')->nullOnDelete();

            // Provenance. A licence's state is recomputed from the calendar,
            // and an operator can override the calendar in one direction only:
            // by declaring the vendor rejected it. Who did that, when and why
            // sits on the row because it is the sentence beside every blocker
            // it causes.
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('state_changed_at')->nullable();
            $table->timestamp('renewed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->foreignUlid('invalidated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('invalidated_reason')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['state', 'expires_on']);
            $table->index(['product', 'environment']);
        });

        Schema::create('provider_instances', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('name')->unique();
            $table->string('category');
            // The concrete adapter: proxmox, cloudflare, cpanel, fake. Kept out
            // of every module's domain layer, exactly as the registrar boundary
            // already does.
            $table->string('driver');
            $table->string('environment');
            $table->string('state')->default('draft');

            $table->string('endpoint')->nullable();

            $table->foreignUlid('credential_reference_id')->nullable()->constrained('credential_references')->nullOnDelete();
            $table->foreignUlid('licence_id')->nullable()->constrained('licences')->nullOnDelete();

            // Where it runs, for the categories that live on a machine of ours.
            $table->foreignUlid('managed_server_id')->nullable()->constrained('managed_servers')->nullOnDelete();

            $table->string('connection_state')->default('not_tested');
            $table->text('connection_detail')->nullable();
            $table->timestamp('last_connection_test_at')->nullable();
            $table->timestamp('last_discovery_at')->nullable();
            $table->timestamp('last_successful_operation_at')->nullable();

            $table->string('readiness')->default('not_ready');
            $table->string('blocker')->nullable();

            // Who decided this provider may take real work, and who decided it
            // should stop. Kept as columns rather than left to the audit trail
            // because an operator looking at a provider that is off needs the
            // reason on the same screen, and "search the audit log" is what
            // people do instead of nothing only when they have time.
            $table->timestamp('enabled_at')->nullable();
            $table->foreignUlid('enabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disabled_at')->nullable();
            $table->foreignUlid('disabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('disabled_reason')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['category', 'environment', 'state']);
            $table->index('readiness');
        });

        // One enabled instance per category per environment. Two enabled
        // registrars in production is not a configuration somebody meant; it is
        // two answers to "where does a registration go", and the platform would
        // pick one arbitrarily.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX provider_instances_one_enabled_per_category
            ON provider_instances (category, environment)
            WHERE state = 'enabled'
        SQL);

        Schema::create('provider_capabilities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('provider_instance_id')->constrained('provider_instances')->cascadeOnDelete();

            $table->string('capability');
            $table->string('state')->default('unknown');
            $table->text('detail')->nullable();
            $table->timestamp('observed_at')->nullable();

            $table->timestamps();

            $table->unique(['provider_instance_id', 'capability']);
        });

        Schema::create('connection_tests', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Exactly one of the two. A test is against a machine or against a
            // provider account, and the check below refuses a row that is
            // against both or neither.
            $table->foreignUlid('managed_server_id')->nullable()->constrained('managed_servers')->cascadeOnDelete();
            $table->foreignUlid('provider_instance_id')->nullable()->constrained('provider_instances')->cascadeOnDelete();

            $table->string('result');
            // Ordered list of what was tried and what each step said. Sanitised
            // at the point of writing: a step records that authentication
            // failed, never what was sent.
            $table->jsonb('steps')->default('[]');
            $table->text('detail')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['managed_server_id', 'created_at']);
            $table->index(['provider_instance_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE connection_tests
            ADD CONSTRAINT connection_tests_have_exactly_one_subject
            CHECK (
                (managed_server_id IS NOT NULL AND provider_instance_id IS NULL)
                OR (managed_server_id IS NULL AND provider_instance_id IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::table('managed_servers', function (Blueprint $table): void {
            $table->dropForeign(['credential_reference_id']);
        });

        Schema::dropIfExists('connection_tests');
        Schema::dropIfExists('provider_capabilities');
        Schema::dropIfExists('provider_instances');
        Schema::dropIfExists('licences');
        Schema::dropIfExists('credential_references');
    }
};
