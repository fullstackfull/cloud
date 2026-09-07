<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The generic Service. Billing operates against this, never against a
         * provider-specific resource, so a plan that moves from one hypervisor
         * to another does not touch a single invoice or subscription row.
         */
        Schema::create('services', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUlid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignUlid('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->foreignUlid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignUlid('plan_id')->nullable()->constrained('plans')->nullOnDelete();

            $table->string('kind', 32);              // vps | dedicated | shared_hosting
            $table->string('status', 32);            // pending | provisioning | active | suspended | terminated | failed
            $table->string('label')->nullable();

            $table->foreignUlid('region_id')->nullable()->constrained('regions')->nullOnDelete();

            // The resources the customer is entitled to, snapshotted from the
            // plan at purchase. A later plan edit must not silently resize a
            // machine someone is running.
            $table->jsonb('resources');

            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('terminated_at')->nullable();

            $table->timestampsTz();

            $table->index(['customer_id', 'status']);
            $table->index(['kind', 'status']);
        });

        /*
         * One provisioning job per unit of work, with the properties that make
         * infrastructure automation survivable.
         */
        Schema::create('provisioning_jobs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('service_id')->nullable()->constrained('services')->cascadeOnDelete();
            $table->foreignUlid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignUlid('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            /*
             * The idempotency key. Unique, and the reason a retried job, a
             * redelivered webhook and a double-clicked purchase converge on one
             * resource instead of three.
             */
            $table->string('idempotency_key', 128)->unique();

            $table->string('kind', 48);              // create_vps | destroy_vps | create_hosting_account | …
            $table->string('provider', 32);
            $table->string('status', 24)->default('queued'); // queued | running | succeeded | failed | needs_review | cancelled

            /*
             * The provider's own job id, persisted BEFORE the call is treated as
             * in flight. Without it a timeout is unrecoverable: the platform
             * cannot tell whether the resource exists, and every recovery is a
             * guess that risks creating a second one.
             */
            $table->string('remote_job_id')->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->unsignedInteger('timeout_seconds')->default(900);

            $table->jsonb('payload');
            // Provider response with credentials stripped by the redactor.
            $table->jsonb('result')->nullable();

            $table->string('failure_class', 32)->nullable();  // transient | permanent | timeout | capacity
            $table->text('last_error')->nullable();

            $table->string('correlation_id', 64)->nullable();

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();

            $table->timestampsTz();

            $table->index(['status', 'next_attempt_at']);
            $table->index(['service_id', 'kind']);
            // Finds jobs that have been running longer than they should.
            $table->index(['status', 'started_at']);
        });

        Schema::create('provisioning_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('provisioning_job_id')->constrained('provisioning_jobs')->cascadeOnDelete();

            $table->unsignedSmallInteger('attempt_number');
            $table->string('status', 24);
            $table->string('remote_job_id')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('response_metadata')->nullable();

            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestampTz('created_at');

            $table->unique(['provisioning_job_id', 'attempt_number']);
        });

        Schema::create('virtual_machines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignUlid('cluster_id')->nullable()->constrained('compute_clusters')->nullOnDelete();
            $table->foreignUlid('node_id')->nullable()->constrained('compute_nodes')->nullOnDelete();
            $table->foreignUlid('template_id')->nullable()->constrained('vm_templates')->nullOnDelete();

            // The provider's identifier, e.g. a Proxmox VMID.
            $table->string('provider_id')->nullable();
            $table->string('hostname');

            $table->unsignedInteger('vcpu');
            $table->unsignedBigInteger('memory_mib');
            $table->unsignedBigInteger('disk_gib');

            $table->string('power_state', 16)->default('unknown'); // running | stopped | suspended | unknown
            $table->string('os_family', 32)->nullable();
            $table->string('os_version', 32)->nullable();

            $table->timestampTz('last_reconciled_at')->nullable();
            $table->boolean('has_drift')->default(false);
            $table->jsonb('drift_details')->nullable();

            $table->timestampsTz();

            $table->unique(['cluster_id', 'provider_id']);
            $table->index('service_id');
            $table->index('has_drift');
        });

        /*
         * Drift between what the platform believes and what a provider reports.
         *
         * Recorded rather than auto-healed. Every automated remedy for drift is
         * one bug away from deleting production, so each row is an operator
         * decision waiting to be made.
         */
        Schema::create('resource_drifts', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('provider', 32);
            $table->string('resource_type', 48);
            $table->ulid('service_id')->nullable();
            $table->string('provider_reference')->nullable();

            // missing_at_provider | orphan_at_provider | state_mismatch | spec_mismatch
            // | suspension_mismatch
            $table->string('kind', 32);
            $table->string('severity', 16)->default('warning');

            $table->jsonb('expected')->nullable();
            $table->jsonb('observed')->nullable();

            $table->string('status', 16)->default('open');   // open | acknowledged | resolved
            $table->foreignUlid('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution')->nullable();
            $table->timestampTz('resolved_at')->nullable();

            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->unsignedInteger('occurrences')->default(1);

            $table->timestampsTz();

            $table->index(['status', 'kind']);
            $table->index(['provider', 'resource_type']);
        });

        Schema::table('ip_reservations', function (Blueprint $table): void {
            $table->foreign('provisioning_job_id')->references('id')->on('provisioning_jobs')->nullOnDelete();
        });

        Schema::table('ip_assignments', function (Blueprint $table): void {
            $table->foreign('service_id')->references('id')->on('services')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ip_assignments', function (Blueprint $table): void {
            $table->dropForeign(['service_id']);
        });
        Schema::table('ip_reservations', function (Blueprint $table): void {
            $table->dropForeign(['provisioning_job_id']);
        });

        Schema::dropIfExists('resource_drifts');
        Schema::dropIfExists('virtual_machines');
        Schema::dropIfExists('provisioning_attempts');
        Schema::dropIfExists('provisioning_jobs');
        Schema::dropIfExists('services');
    }
};
