<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which payment failure the dunning counter last counted.
 *
 * `failed_payment_count` was incremented once per delivery of PaymentFailed,
 * not once per failure. A delivery is not a failure: a provider redelivers a
 * webhook, a queued listener is retried after a crash, and — until F-08 fixed
 * the retry clocks — the queue itself handed a still-running listener to a
 * second worker. Each of those counted the same declined card again, walking a
 * customer towards suspension for a payment they had failed once.
 *
 * The failed transaction's id is the key, read and written under the
 * subscription's row lock, so a second delivery of the same failure — even one
 * running at the same moment — finds it already counted. It is deliberately
 * not cleared when the customer pays: a failure redelivered after the payment
 * that settled it must not reopen dunning either.
 *
 * No foreign key. It records which event was counted, and a counter's history
 * should not stop a transaction row being archived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->ulid('last_counted_payment_failure_id')->nullable()->after('failed_payment_count');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('last_counted_payment_failure_id');
        });
    }
};
