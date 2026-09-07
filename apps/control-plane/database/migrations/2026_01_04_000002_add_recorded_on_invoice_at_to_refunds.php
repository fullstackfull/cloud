<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separates "this refund belongs to that invoice" from "that invoice has
 * counted it".
 *
 * `refunds.invoice_id` was doing both jobs, and it cannot: IssueRefund writes
 * the column when it creates the row, so the booking that reduces
 * amount_refunded_minor found the refund already attached, took itself for a
 * redelivery, and returned without recording anything. A refund paid out
 * through the provider left the invoice reading as fully settled.
 *
 * The timestamp is the marker now, and only the booking that moves
 * amount_refunded_minor writes it. It is left null on existing rows on
 * purpose: nothing has ever reduced an invoice by them, so they are unrecorded
 * and must stay bookable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->timestampTz('recorded_on_invoice_at')->nullable()->after('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropColumn('recorded_on_invoice_at');
        });
    }
};
