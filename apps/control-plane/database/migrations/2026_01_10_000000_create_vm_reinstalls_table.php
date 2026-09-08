<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every time a customer's disk was replaced, and what the platform intended to
 * keep when it did.
 *
 * ---------------------------------------------------------------------------
 * Why this is not just the provisioning job
 * ---------------------------------------------------------------------------
 *
 * The job row records that work of kind `reinstall` ran and how it ended. That
 * is the right record for the engine and the wrong one for this operation, for
 * two reasons.
 *
 * The first is the question an operator asks: *had the disk already been
 * destroyed when it failed?* A job that ends "failed" cannot answer it. The
 * state column here can, because it names the phase the operation stopped in,
 * and everything from `reinstalling` onwards means the old data is gone.
 *
 * The second is `preserved`. A reinstall's correctness is entirely about what
 * did NOT change — the machine's provider id, its address, its MAC, its
 * shape — and those facts are read from live rows that keep moving. Snapshotting
 * them at the moment the rebuild started is what makes it possible to say
 * afterwards whether the machine that came back is the machine that went in.
 *
 * ---------------------------------------------------------------------------
 * One row per attempt, and one attempt per row
 * ---------------------------------------------------------------------------
 *
 * `provisioning_job_id` is unique. A retried worker must find the operation it
 * already started rather than beginning a second one, and "this machine was
 * reinstalled twice" must remain a statement about two customer requests, not
 * about one request a queue delivered twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vm_reinstalls', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('virtual_machine_id')->constrained('virtual_machines')->cascadeOnDelete();

            /*
             * Kept even if the service goes. A reinstall is a destructive act
             * on a customer's data, and the record of it must outlive the
             * service being cancelled — that is exactly when somebody asks.
             */
            $table->ulid('service_id')->nullable();
            $table->ulid('customer_id')->nullable();

            // Unique: the worker's retry finds this row, it does not add one.
            $table->foreignUlid('provisioning_job_id')->unique()->constrained('provisioning_jobs')->cascadeOnDelete();

            $table->string('state', 32);

            /*
             * The image that was laid down. Nullable because a reinstall may
             * legitimately name no template — the customer asked for "the same
             * again" — and the machine's own os_family decides.
             */
            $table->ulid('template_id')->nullable();
            $table->string('template_reference', 191)->nullable();

            /*
             * The provider's own handles, written the moment they exist rather
             * than on completion.
             *
             * This is what makes an indeterminate reinstall recoverable: a
             * worker that died between the call and the answer leaves a row
             * naming the task an operator can look up in the node's task log,
             * and the machine it was working on. Without them the only honest
             * thing to say about a timed-out reinstall is that something
             * happened to a machine somewhere.
             */
            $table->string('provider_task_id', 191)->nullable();
            $table->string('provider_resource_id', 64)->nullable();
            $table->string('provider_node', 64)->nullable();

            // What the reinstall was required not to change, snapshotted.
            $table->jsonb('preserved')->default('{}');

            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();

            $table->timestamp('state_changed_at')->nullable();
            $table->timestamp('destroyed_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // The portal shows a machine's most recent reinstall, and the
            // operator queue lists everything waiting for a person.
            $table->index(['virtual_machine_id', 'created_at']);
            $table->index(['state', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vm_reinstalls');
    }
};
