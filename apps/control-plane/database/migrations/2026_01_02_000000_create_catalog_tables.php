<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product catalogue: what the platform sells, and what it costs.
 *
 * Prices are never stored on a product or a plan directly. A plan has many
 * prices — one per (currency, billing period) pair — because a KWD monthly
 * price and a USD yearly price are different commercial facts, not conversions
 * of one another. Nothing in the codebase converts between currencies at
 * checkout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // vps | dedicated | shared_hosting
            $table->string('kind', 32);
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Localised copy lives in jsonb keyed by locale, so adding a
            // language never requires a migration.
            $table->jsonb('name');
            $table->jsonb('description')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['kind', 'is_active', 'is_public']);
        });

        Schema::create('plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')->constrained('products')->cascadeOnDelete();

            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->jsonb('name');
            $table->jsonb('description')->nullable();

            /*
             * The resources this plan entitles the customer to. Deliberately a
             * document rather than columns: a VPS plan describes vCPU, memory
             * and disk, a hosting plan describes accounts, quota and inode
             * limits, and a dedicated plan describes a hardware profile. Forcing
             * all three into one table would produce a wall of nullable columns.
             * The shape is validated by a typed DTO on read.
             */
            $table->jsonb('resources');

            // Placement constraints the scheduler must honour, e.g. required
            // storage class or minimum node capabilities.
            $table->jsonb('placement_constraints')->nullable();

            // Null means unlimited. Enforced when an order is placed.
            $table->unsignedInteger('stock_limit')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['product_id', 'is_active', 'is_public']);
        });

        Schema::create('plan_prices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('plan_id')->constrained('plans')->cascadeOnDelete();

            $table->char('currency', 3);
            // hourly | daily | monthly | quarterly | yearly
            $table->string('billing_period', 16);

            // Integer minor units. Never a decimal or float column: a numeric
            // column would let the database engine round, and a float would lose
            // fils outright.
            $table->bigInteger('recurring_amount_minor');
            $table->bigInteger('setup_amount_minor')->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestampTz('available_from')->nullable();
            $table->timestampTz('available_until')->nullable();

            $table->timestampsTz();

            // Exactly one active price per plan, currency and period.
            $table->unique(['plan_id', 'currency', 'billing_period']);
            $table->index(['currency', 'is_active']);
        });

        Schema::create('addons', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')->nullable()->constrained('products')->cascadeOnDelete();

            $table->string('slug')->unique();
            $table->string('kind', 32);          // extra_ipv4 | extra_disk | backup | licence | …
            $table->jsonb('name');
            $table->jsonb('resources')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('max_quantity')->default(1);

            $table->timestampsTz();
        });

        Schema::create('addon_prices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('addon_id')->constrained('addons')->cascadeOnDelete();

            $table->char('currency', 3);
            $table->string('billing_period', 16);
            $table->bigInteger('recurring_amount_minor');
            $table->bigInteger('setup_amount_minor')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();

            $table->unique(['addon_id', 'currency', 'billing_period']);
        });

        Schema::create('plan_addon', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->foreignUlid('addon_id')->constrained('addons')->cascadeOnDelete();
            $table->boolean('is_required')->default(false);

            $table->unique(['plan_id', 'addon_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_addon');
        Schema::dropIfExists('addon_prices');
        Schema::dropIfExists('addons');
        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('products');
    }
};
