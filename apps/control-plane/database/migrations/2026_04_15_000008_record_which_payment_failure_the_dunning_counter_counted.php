<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which payment failures the dunning counter has counted.
 *
 * `failed_payment_count` was incremented once per delivery of PaymentFailed,
 * not once per failure. A delivery is not a failure: a provider redelivers a
 * webhook, a queued listener is retried after a crash, and — until F-08 fixed
 * the retry clocks — the queue itself handed a still-running listener to a
 * second worker. Each of those counted the same declined card again, walking a
 * customer towards suspension for a payment they had failed once.
 *
 * One row per failure counted, so the answer to "was this one counted?" is
 * the same however many failures have been counted since. A single "last
 * counted" column cannot give it: a failure delivered again after a newer one
 * finds the newer one's id there and is counted a second time. And a delivery
 * after the customer has paid is not rare — `payments:reconcile` records a
 * failed intent under its own event id, and the provider's webhook for the
 * same intent arrives later under another, announcing the same failed
 * transaction again.
 *
 * Written with an insert that does nothing on conflict, under the
 * subscription's row lock, so a second delivery of the same failure — even
 * one running at the same moment — finds it already counted. The rows are
 * deliberately not cleared when the customer pays: a failure delivered again
 * after the payment that settled the subscription must not reopen dunning
 * either.
 *
 * No foreign key to `transactions`. This records which event was counted, and
 * a counter's history should not stop a transaction row being archived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_counted_payment_failures', function (Blueprint $table): void {
            $table->foreignUlid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->ulid('payment_failure_id');
            $table->timestampTz('counted_at');

            $table->primary(['subscription_id', 'payment_failure_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_counted_payment_failures');
    }
};
