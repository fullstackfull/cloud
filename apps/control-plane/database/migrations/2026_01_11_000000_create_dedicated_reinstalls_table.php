<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every time a physical machine's disks were erased at a customer's request.
 *
 * The provisioning job records that work of kind `reinstall_dedicated` ran and
 * how it ended. That is the right record for the engine and the wrong one for
 * this operation, because the question an operator asks about a physical
 * rebuild is not "did it fail" but **"did the installer start"** — and the
 * answer decides whether the customer still has their data.
 *
 * `destructive_started_at` is that answer. It is stamped when the power cycle
 * is issued, which is the moment the machine boots into an installer, and it
 * is deliberately a timestamp rather than a boolean: an operator reading a
 * failed rebuild needs to know when the disks went, so they can say what the
 * last good backup was.
 *
 * `provisioning_job_id` is unique. A redelivered job finds the operation it
 * already started rather than beginning a second one — and on this hardware a
 * second one means a machine erased twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dedicated_reinstalls', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('dedicated_server_id')->constrained('dedicated_servers')->cascadeOnDelete();

            /*
             * Kept even if the service goes. A destructive act on a customer's
             * machine has to outlive their cancellation — that is exactly when
             * somebody asks what happened to it.
             */
            $table->ulid('service_id')->nullable();
            $table->ulid('customer_id')->nullable();

            $table->foreignUlid('provisioning_job_id')->unique()->constrained('provisioning_jobs')->cascadeOnDelete();

            $table->string('state', 32);

            $table->ulid('os_install_profile_id')->nullable();
            $table->string('os_install_profile_slug', 191)->nullable();

            /*
             * The controller and the authorisation, written as soon as they
             * exist rather than on completion. Between them they are what lets
             * an operator ask a management controller what actually happened
             * to a machine the platform lost sight of.
             */
            $table->ulid('pxe_boot_authorisation_id')->nullable();
            $table->ulid('bmc_endpoint_id')->nullable();
            $table->string('bmc_protocol', 32)->nullable();
            $table->string('power_operation', 64)->nullable();

            // What the rebuild was required not to change, snapshotted.
            $table->jsonb('preserved')->default('{}');

            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();

            $table->timestamp('state_changed_at')->nullable();
            /** When the machine was power-cycled into the installer. */
            $table->timestamp('destructive_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['dedicated_server_id', 'created_at']);
            $table->index(['state', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dedicated_reinstalls');
    }
};
