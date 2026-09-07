<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backups of customer services, as the platform believes them to be.
 *
 * These rows are the platform's record, not the provider's. The provider's
 * datastore is the truth; this table is what the platform has been told and
 * when, and the two can disagree — a backup pruned by a retention job the
 * platform did not run is exactly the case a customer discovers at the worst
 * possible moment, and the way to find it is to compare the two.
 *
 * Nothing here concerns the platform's own backups. Lynomia's database and
 * configuration are backed up by infrastructure automation, verified and
 * restore-tested by the PBS Ansible role, and no customer can see or trigger
 * any of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            /*
             * Both the customer and the service. The service is what was
             * backed up; the customer is who may see it, and denormalising it
             * means the tenant scope is one column rather than a join that
             * somebody will eventually forget to write.
             */
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained('services')->cascadeOnDelete();

            /*
             * Nullable, and it stays nullable on purpose. A machine can be
             * destroyed while its backups remain — which is the situation a
             * customer restoring after an accidental termination is in, and
             * cascading the delete would remove the only copy of their data at
             * the exact moment they came looking for it.
             */
            $table->foreignUlid('virtual_machine_id')->nullable()->constrained('virtual_machines')->nullOnDelete();
            $table->foreignUlid('cluster_id')->nullable()->constrained('compute_clusters')->nullOnDelete();

            $table->string('provider', 32);
            $table->string('state', 24)->default('requested');
            $table->string('trigger', 16);
            $table->string('mode', 16);

            $table->string('node_name');
            $table->string('datastore');

            /*
             * The provider's handle on the operation that is running, and its
             * identifier for the archive that resulted. Without the first the
             * platform has started something it cannot ask about; without the
             * second it cannot restore or delete what it started.
             */
            $table->string('provider_task_id')->nullable();
            $table->string('archive_id')->nullable();

            $table->unsignedBigInteger('size_bytes')->nullable();

            // Verification is its own fact with its own time. Null means never
            // verified, which is not the same as verified and failed.
            $table->boolean('verified')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->string('verification_task_id')->nullable();

            /*
             * Retention as the platform understands it. The provider's prune
             * job is the thing that actually deletes, so this is a statement of
             * intent that a reconciler compares against what is really there.
             */
            $table->unsignedSmallInteger('retention_days')->nullable();
            $table->timestampTz('expires_at')->nullable();

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();

            // Polling bookkeeping: when the platform last asked, and when it
            // gives up asking and puts the row in front of a person.
            $table->timestampTz('last_polled_at')->nullable();
            $table->unsignedInteger('poll_count')->default(0);

            // Already scrubbed of anything credential-shaped by the adapter.
            $table->text('failure_reason')->nullable();

            $table->foreignUlid('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();

            $table->index(['customer_id', 'created_at']);
            $table->index(['service_id', 'created_at']);
            // The reconciler's query: everything still in flight, oldest first.
            $table->index(['state', 'last_polled_at']);
            // A provider task belongs to one row, and a duplicate would mean
            // two rows racing to interpret the same task.
            $table->unique(['provider', 'provider_task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
