<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * When a paid plan change was delivered: the moment its settlement was heard
 * while the subscription was still live (ResizeOnPlanChangeSettlement).
 *
 * An upgrade paid for and never delivered because the subscription ended is
 * returned to the wallet (ReturnAnUpgradeTheEndPrevented, OA-3). "Delivered"
 * used to be read off whether a resize job existed, and a settlement heard
 * while the subscription was live that queued nothing - the machine already
 * had the shape, or there was nothing to resize - read as undelivered, and
 * its money was returned when the subscription later ended. The settlement
 * now records that it ran, whatever it queued.
 *
 * Nullable, and nothing is back-filled: a change settled before this column
 * existed is judged by the job, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plan_changes', function (Blueprint $table): void {
            $table->timestamp('delivered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plan_changes', function (Blueprint $table): void {
            $table->dropColumn('delivered_at');
        });
    }
};
