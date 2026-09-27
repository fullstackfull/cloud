<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * When a paid plan change was returned instead of delivered, and why: the
 * moment its settlement found the change could no longer be delivered (a
 * hosting package withdrawn, a node that can no longer hold the growth, a
 * machine gone) between the opening of the payment and its capture
 * (ResizeOnPlanChangeSettlement). What the invoice held went back to the
 * wallet against the invoice, and the subscription went back to the plan it
 * came from.
 *
 * Read by PlanChangeDelivery::aPaidChangeAwaitsDelivery(): a change returned
 * is not waiting to be delivered, and must not hold the subscription's next
 * change until its period renews.
 *
 * Nullable, and nothing is back-filled: before this, such a change was
 * recorded delivered (delivered_at) and its money kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plan_changes', function (Blueprint $table): void {
            $table->timestamp('returned_at')->nullable();
            $table->string('return_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plan_changes', function (Blueprint $table): void {
            $table->dropColumn(['returned_at', 'return_reason']);
        });
    }
};
