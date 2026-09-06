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
         * Tax rules are resolved by country (and optionally state) at the moment
         * an invoice is issued, then snapshotted onto the invoice line. Changing
         * a rate later must never alter an invoice that has already been issued.
         */
        Schema::create('tax_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('name');
            $table->char('country', 2);
            $table->string('state', 64)->nullable();

            // Rate as an exact decimal string, e.g. "0.150" for 15%. Stored as
            // numeric rather than a float so the value is exact, and applied
            // through Money's explicit rounding rather than PHP arithmetic.
            $table->decimal('rate', 9, 6);

            // inclusive: the displayed price already contains the tax.
            $table->boolean('is_inclusive')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestampTz('effective_from');
            $table->timestampTz('effective_until')->nullable();

            $table->timestampsTz();

            $table->index(['country', 'state', 'is_active']);
        });

        Schema::create('coupons', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Stored uppercase; matched case-insensitively at redemption.
            $table->string('code')->unique();
            $table->jsonb('name')->nullable();

            // percentage | fixed_amount
            $table->string('discount_type', 16);
            // For percentage: an exact decimal such as "0.100". For fixed: null.
            $table->decimal('percentage', 9, 6)->nullable();
            // For fixed_amount: minor units in the coupon's own currency.
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->nullable();

            // Whether the discount applies once or to every renewal.
            $table->boolean('applies_to_renewals')->default(false);
            $table->unsignedSmallInteger('duration_cycles')->nullable();

            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('max_redemptions_per_customer')->default(1);
            $table->unsignedInteger('redemption_count')->default(0);

            $table->bigInteger('minimum_order_amount_minor')->nullable();

            // Empty means "any plan".
            $table->jsonb('applicable_plan_ids')->nullable();
            $table->jsonb('applicable_product_kinds')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_until')->nullable();

            $table->timestampsTz();

            $table->index(['is_active', 'valid_from', 'valid_until']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->ulid('order_id')->nullable();

            $table->bigInteger('discount_amount_minor');
            $table->char('currency', 3);

            $table->timestampTz('created_at');

            $table->index(['coupon_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('tax_rules');
    }
};
