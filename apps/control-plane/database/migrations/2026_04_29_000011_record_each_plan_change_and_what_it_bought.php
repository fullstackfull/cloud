<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each plan change a subscription went through, and what it bought.
 *
 * F-01's repair made a plan change settle its money, but the only record of
 * the change was the subscription row it overwrote and an audit entry. Two
 * things then had to be guessed that a row can simply say:
 *
 *  - **What a proration invoice bought.** The settlement listener resized to
 *    whatever plan the subscription held when the money arrived. Paying a
 *    0.667 KWD invoice for one change delivered the 90.000 KWD plan of a
 *    later, unpaid one. `proration_invoice_id` and `resources` are what the
 *    listener now reads: the machine is built to what that invoice paid for.
 *  - **How much a period has already given back.** A downgrade credits the
 *    wallet, and the credit is capped at what the period actually collected
 *    less what earlier changes already returned. `wallet_credit_minor` is
 *    that second figure, per change, as it was posted.
 *  - **What the subscription was billed before.** An upgrade moves the
 *    recurring amount at once, but the plan it moves onto is not paid for
 *    until its invoice is. `from_recurring_amount_minor` is what a voided
 *    upgrade puts back, and what a renewal bills while the upgrade's invoice
 *    is still unpaid. Nullable only for rows written before it existed.
 *
 * Written only by ApplyPlanChange, in the same transaction that moves the
 * plan and writes the invoice or the credit, so a row exists exactly when the
 * change happened. Never updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plan_changes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignUlid('from_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignUlid('to_plan_id')->nullable()->constrained('plans')->nullOnDelete();

            $table->char('currency', 3);
            $table->unsignedInteger('units');
            $table->bigInteger('credit_minor');
            $table->bigInteger('charge_minor');
            // The balance actually posted to the wallet by this change; zero
            // for an upgrade or a like-for-like move.
            $table->bigInteger('wallet_credit_minor');
            // The recurring amount the subscription carried before the move.
            $table->bigInteger('from_recurring_amount_minor')->nullable();

            // The invoice an upgrade left behind, when it left one.
            $table->foreignUlid('proration_invoice_id')->nullable()->unique()->constrained('invoices')->nullOnDelete();

            // The shape the change bought, as the quote stated it.
            $table->jsonb('resources');

            $table->foreignUlid('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('changed_at');
            $table->timestampsTz();

            $table->index(['subscription_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_changes');
    }
};
