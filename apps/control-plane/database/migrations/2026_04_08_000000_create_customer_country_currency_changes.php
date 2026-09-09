<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A request to change an account's country or currency, with the analysis
 * that was run on it, the decision an operator took, and when it was
 * applied. The account row itself changes only when a row here reaches
 * `applied`; nothing else on the platform writes those two columns after
 * registration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_country_currency_changes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('state', 32);
            $table->char('from_country', 2)->nullable();
            $table->char('to_country', 2)->nullable();
            $table->char('from_currency', 3);
            $table->char('to_currency', 3);
            $table->text('reason');
            $table->json('impact');
            $table->foreignUlid('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->timestampTz('analysed_at');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('scheduled_for')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampsTz();

            $table->index(['customer_id', 'state']);
            $table->index(['state', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_country_currency_changes');
    }
};
