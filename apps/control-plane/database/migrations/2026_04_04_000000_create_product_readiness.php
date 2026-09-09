<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_readiness', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // One row per product the platform sells. Written by assessment,
            // never by hand: the state is a conclusion, and the row keeps the
            // evidence it was concluded from.
            $table->string('product')->unique();
            $table->string('state')->default('not_ready');
            $table->string('blocker')->nullable();
            $table->text('detail')->nullable();
            $table->jsonb('requirements')->default('[]');
            $table->jsonb('dependencies')->default('{}');
            $table->timestamp('assessed_at')->nullable();
            $table->timestamp('state_changed_at')->nullable();

            // The one rung a person climbs. Kept on the row so that the
            // reason and the reference outlive the declaration: a withdrawal
            // says what was withdrawn.
            $table->timestamp('declared_sellable_at')->nullable();
            $table->foreignUlid('declared_sellable_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('declared_reason')->nullable();
            $table->string('validation_reference')->nullable();
            $table->timestamp('sellability_withdrawn_at')->nullable();
            $table->text('sellability_withdrawn_reason')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_readiness');
    }
};
