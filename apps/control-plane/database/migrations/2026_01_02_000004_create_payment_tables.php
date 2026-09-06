<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->string('provider', 32);          // stripe | myfatoorah
            // The provider's token. Card numbers, CVVs and expiry dates are
            // never stored by this platform under any circumstances.
            $table->string('provider_reference');

            $table->string('kind', 24);              // card | apple_pay | knet | …
            $table->string('brand', 32)->nullable();
            $table->string('last_four', 4)->nullable();
            $table->unsignedTinyInteger('expiry_month')->nullable();
            $table->unsignedSmallInteger('expiry_year')->nullable();

            $table->boolean('is_default')->default(false);
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'provider_reference']);
            $table->index(['customer_id', 'is_default']);
        });

        Schema::create('transactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->string('provider', 32);
            $table->string('kind', 24);              // charge | refund | wallet_debit | credit
            $table->string('status', 24);            // pending | succeeded | failed | cancelled

            $table->bigInteger('amount_minor');
            $table->char('currency', 3);

            /*
             * The provider's own identifier. Unique per provider, so a
             * redelivered webhook cannot record the same charge twice.
             */
            $table->string('provider_reference')->nullable();

            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message')->nullable();

            // Provider response with credentials stripped by the redactor.
            $table->jsonb('provider_metadata')->nullable();

            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'provider_reference']);
            $table->index(['customer_id', 'status']);
            $table->index(['invoice_id', 'status']);
        });

        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignUlid('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignUlid('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();

            $table->unsignedSmallInteger('attempt_number');
            $table->string('status', 24);
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message')->nullable();
            $table->timestampTz('next_retry_at')->nullable();

            $table->timestampsTz();

            $table->index(['invoice_id', 'attempt_number']);
            $table->index('next_retry_at');
        });

        Schema::create('refunds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignUlid('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 24);
            $table->string('reason');

            $table->string('provider_reference')->nullable();
            $table->jsonb('provider_metadata')->nullable();

            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->index('transaction_id');
        });

        /*
         * Every webhook the platform has processed, keyed by the provider's own
         * event id.
         *
         * The unique constraint is the replay defence: a redelivered event —
         * which every payment provider does routinely — inserts nothing and
         * therefore provisions nothing a second time. This table is written
         * before the event is acted on, not after.
         */
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('provider', 32);
            $table->string('provider_event_id');
            $table->string('event_type', 128);

            $table->string('status', 24)->default('received'); // received | processed | failed | ignored
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->jsonb('payload');
            $table->string('signature_verified_by', 64)->nullable();

            $table->timestampTz('provider_created_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'provider_event_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('payment_methods');
    }
};
