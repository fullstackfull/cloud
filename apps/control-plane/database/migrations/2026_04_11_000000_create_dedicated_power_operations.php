<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere to keep the promise an idempotency key makes.
 *
 * A dedicated power request used to go straight from an HTTP handler to a
 * management controller with nothing in between, so the endpoint could not
 * accept an `Idempotency-Key`: a header that was required and then ignored is
 * worse than no header, because a client retries believing it is protected.
 * Two identical requests therefore reached the chassis twice, and the second
 * reset interrupted the boot the first one started.
 *
 * This is the place that was missing. One row per intent, claimed before the
 * controller is called, and the unique index on the key is what makes the
 * claim atomic rather than a read followed by a hopeful write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dedicated_power_operations', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('dedicated_server_id')->constrained('dedicated_servers')->cascadeOnDelete();

            // Nullable because an operator acting through a console command is
            // not acting for a customer, and a null here is that fact rather
            // than a missing value.
            $table->foreignUlid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignUlid('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action', 16);

            /*
             * The whole point of the table.
             *
             * Unique platform-wide, and built by DedicatedIdempotencyKey from
             * the machine, the verb and a hash of the caller's own key — so
             * one customer's "reboot-1" cannot claim another's row, and the
             * same caller repeating the same key against the same verb on the
             * same machine converges on this row instead of the controller.
             */
            $table->string('idempotency_key', 191)->unique();

            // 'claimed' until the controller answers; then one of the three
            // outcomes a BMC call can have.
            $table->string('outcome', 16);

            $table->boolean('accepted')->nullable();
            $table->string('resulting_power_state', 16)->nullable();

            /*
             * What the controller answered, kept whole rather than
             * reconstructed.
             *
             * A replay has to hand the caller the operation the first request
             * produced — its stable verb, the endpoint it went to, that
             * endpoint's protocol, and the controller's own task handle where
             * there was one. Rebuilding those from the request would be a
             * second, quieter guess about a call nobody made.
             */
            $table->string('provider_operation', 64)->nullable();
            $table->foreignUlid('bmc_endpoint_id')->nullable()->constrained('bmc_endpoints')->nullOnDelete();
            $table->string('bmc_protocol', 16)->nullable();
            $table->string('provider_task_id')->nullable();

            // The platform's own error code for a refusal or a timeout, so a
            // replay can be answered with the same verdict rather than by
            // sending a second request to find out again.
            $table->string('failure_code', 64)->nullable();

            $table->timestamp('requested_at');
            $table->timestamp('settled_at')->nullable();

            $table->timestamps();

            // "What has been asked of this chassis lately", which is the
            // question an operator reading a reset storm asks.
            $table->index(['dedicated_server_id', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dedicated_power_operations');
    }
};
