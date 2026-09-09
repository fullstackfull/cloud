<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Desired state, and the plan-approve-apply-verify path that gets a machine there.
 *
 * ===========================================================================
 * WHY A PLAN IS A ROW
 * ===========================================================================
 *
 * Because approval has to attach to something specific. "Alice approved the
 * monitoring rollout" is worthless if the plan changed afterwards, so a plan is
 * stored, hashed over its own contents, and an approval records the hash it
 * approved. Regenerating the plan produces a different hash and the approval no
 * longer matches — which is the mechanism, rather than a rule somebody has to
 * remember.
 *
 * ===========================================================================
 * WHY PROFILES DO NOT CONTAIN COMMANDS
 * ===========================================================================
 *
 * A software profile names an Ansible role that exists in infrastructure/ and
 * has been reviewed. It cannot name a shell command, and the architecture gate
 * checks that every component's role is present in the tree. The alternative —
 * letting an admin field become an argument to a playbook — turns the control
 * panel into a remote shell with a nicer font.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('software_components', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('key')->unique();
            $table->string('name');
            $table->string('category');

            // The reviewed Ansible role in infrastructure/ansible/roles that
            // installs this. Checked by an architecture test, so a component
            // naming a role nobody wrote fails the build rather than a run.
            $table->string('ansible_role')->nullable();

            $table->string('version_policy')->nullable();
            $table->boolean('requires_licence')->default(false);
            $table->string('licence_product')->nullable();

            // How the platform confirms it is actually there afterwards.
            $table->string('verification')->nullable();

            $table->jsonb('depends_on')->default('[]');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('software_profiles', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('key')->unique();
            $table->string('name');
            $table->string('intended_role');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('profile_components', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('software_profile_id')->constrained('software_profiles')->cascadeOnDelete();
            $table->foreignUlid('software_component_id')->constrained('software_components')->cascadeOnDelete();

            $table->boolean('is_required')->default(true);
            // Configuration for this component in this profile. A validated
            // schema, not free text: the component declares what it accepts.
            $table->jsonb('configuration')->default('{}');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['software_profile_id', 'software_component_id']);
        });

        Schema::create('desired_states', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('managed_server_id')->constrained('managed_servers')->cascadeOnDelete();
            $table->foreignUlid('software_profile_id')->nullable()->constrained('software_profiles')->nullOnDelete();

            $table->jsonb('overrides')->default('{}');
            $table->foreignUlid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('managed_server_id');
        });

        Schema::create('deployment_plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('managed_server_id')->constrained('managed_servers')->cascadeOnDelete();

            // Every change the plan would make, each with its own risk and
            // whether it is destructive. Rendered for a person, and hashed.
            $table->jsonb('changes')->default('[]');
            $table->jsonb('unchanged')->default('[]');
            $table->jsonb('blockers')->default('[]');

            $table->string('risk')->default('none');
            $table->boolean('requires_reboot')->default(false);
            $table->boolean('requires_downtime')->default(false);
            $table->boolean('is_destructive')->default(false);
            $table->string('required_safety_class');
            $table->boolean('is_applicable')->default(false);

            // Over the changes, so an approval cannot survive an edit.
            $table->string('fingerprint', 64);

            $table->foreignUlid('planned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['managed_server_id', 'created_at']);
        });

        Schema::create('deployment_approvals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('deployment_plan_id')->constrained('deployment_plans')->cascadeOnDelete();

            // The fingerprint as it stood when this was approved. Compared
            // again at apply time; a mismatch refuses the run.
            $table->string('approved_fingerprint', 64);
            $table->foreignUlid('approved_by')->constrained('users')->cascadeOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('approved_at');
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();
        });

        Schema::create('deployment_jobs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('managed_server_id')->constrained('managed_servers')->cascadeOnDelete();
            $table->foreignUlid('deployment_plan_id')->nullable()->constrained('deployment_plans')->nullOnDelete();
            $table->foreignUlid('deployment_approval_id')->nullable()->constrained('deployment_approvals')->nullOnDelete();

            $table->string('state')->default('requested');
            $table->string('kind');

            // Same shape as the provisioning module's guard: one row per
            // logical request, so a redelivered message cannot start a second
            // run against the same machine.
            $table->string('idempotency_key')->unique();

            $table->jsonb('steps')->default('[]');
            $table->text('failure_detail')->nullable();
            $table->string('failure_class')->nullable();

            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index(['state', 'created_at']);
            $table->index(['managed_server_id', 'created_at']);
        });

        // One deployment in flight per machine. Two playbooks writing the same
        // /etc at once is not a race the application should try to survive.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX deployment_jobs_one_in_flight_per_server
            ON deployment_jobs (managed_server_id)
            WHERE state IN ('requested', 'preflight', 'planning', 'awaiting_approval', 'queued', 'applying', 'verifying')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_jobs');
        Schema::dropIfExists('deployment_approvals');
        Schema::dropIfExists('deployment_plans');
        Schema::dropIfExists('desired_states');
        Schema::dropIfExists('profile_components');
        Schema::dropIfExists('software_profiles');
        Schema::dropIfExists('software_components');
    }
};
