<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUlid('placed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Human-facing reference, sequential and immutable once assigned.
            $table->string('number')->unique();

            $table->string('status', 32);
            $table->char('currency', 3);

            // Money totals, all integer minor units in the order's currency.
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);

            $table->foreignUlid('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();

            /*
             * Guards against a double-clicked purchase creating two orders.
             * The client supplies a key; the unique index makes the second
             * attempt return the first order rather than duplicating it.
             */
            $table->string('idempotency_key', 128)->nullable();

            $table->jsonb('billing_snapshot')->nullable();
            $table->text('notes')->nullable();

            $table->timestampTz('placed_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->unique(['customer_id', 'idempotency_key']);
            $table->index(['customer_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained('orders')->cascadeOnDelete();

            // Nulled rather than cascaded if a plan is later removed: an order
            // is a historical record and must survive catalogue changes.
            $table->foreignUlid('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignUlid('addon_id')->nullable()->constrained('addons')->nullOnDelete();

            /*
             * Snapshot of what was actually sold, at the price and description
             * shown to the customer. Re-deriving this from the catalogue later
             * would silently rewrite history the first time a price changes.
             */
            $table->string('kind', 32);              // plan | addon
            $table->string('name');
            $table->string('billing_period', 16);
            $table->jsonb('resources_snapshot')->nullable();

            $table->unsignedInteger('quantity')->default(1);
            $table->bigInteger('unit_recurring_minor');
            $table->bigInteger('unit_setup_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor');

            $table->decimal('tax_rate', 9, 6)->default(0);
            $table->string('tax_name')->nullable();

            $table->timestampsTz();

            $table->index('order_id');
        });

        /*
         * Every state change, with who or what caused it. An order that ends up
         * in MANUAL_REVIEW is useless to an operator without this.
         */
        Schema::create('order_transitions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained('orders')->cascadeOnDelete();

            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);

            // user | system | webhook | job | admin
            $table->string('actor_type', 24);
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('reason')->nullable();
            $table->jsonb('context')->nullable();
            $table->string('correlation_id', 64)->nullable();

            $table->timestampTz('created_at');

            $table->index(['order_id', 'created_at']);
        });

        Schema::table('coupon_redemptions', function (Blueprint $table): void {
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('coupon_redemptions', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
        });

        Schema::dropIfExists('order_transitions');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
