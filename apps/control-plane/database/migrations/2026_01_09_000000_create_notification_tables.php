<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the platform has told each customer, and whether it arrived.
 *
 * A hosting platform that creates, fails, suspends and terminates a customer's
 * servers without ever telling them is not a product. This is the record of
 * every such message: one row per notification, and one row per attempt to
 * deliver it down a channel.
 *
 * ---------------------------------------------------------------------------
 * Two tables, not one
 * ---------------------------------------------------------------------------
 *
 * `notifications` is what happened. `notification_deliveries` is what the
 * platform did about it. Collapsing them would mean either one row per
 * channel — so the in-app inbox shows the same event twice because it was also
 * emailed — or a delivery status column that means "the worst of the channels",
 * which is unreadable at exactly the moment somebody is asking why a customer
 * did not get an email.
 *
 * ---------------------------------------------------------------------------
 * The deduplication key
 * ---------------------------------------------------------------------------
 *
 * `idempotency_key` is unique, and it is the whole defence against a queue
 * doing its job. Every listener that sends one of these runs on a queue with
 * retries, and a provisioning job that succeeds after two attempts must not
 * tell the customer their server is ready three times. The key is built from
 * the event, the customer, the subject and the occurrence — never from a
 * timestamp, which would make every retry unique and defeat the point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();

            /*
             * Nullable, and deliberately not a foreign key. A notification
             * about a terminated service must survive the service row, and
             * "your server was deleted" is precisely the message somebody
             * reads after the thing it describes is gone.
             */
            $table->ulid('user_id')->nullable();

            $table->string('type', 64);
            $table->string('category', 32);

            $table->string('subject_type', 191)->nullable();
            $table->string('subject_id', 64)->nullable();

            /*
             * Rendered at read time from these, not stored as prose. The
             * customer's language can change between the event and the moment
             * they open the inbox, and a stored English sentence cannot be
             * shown in Arabic afterwards.
             */
            $table->jsonb('data')->nullable();

            // Where the notification points. Stored as a path rather than a
            // full URL so it stays correct when the portal's host changes.
            $table->string('link', 255)->nullable();

            $table->timestampTz('read_at')->nullable();

            $table->string('idempotency_key', 191)->unique();

            $table->timestampsTz();

            // The inbox query: this customer's notifications, newest first.
            $table->index(['customer_id', 'created_at']);
            // The unread badge, which every page load asks for.
            $table->index(['customer_id', 'read_at']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('notification_id')->constrained('notifications')->cascadeOnDelete();

            $table->string('channel', 32);
            $table->string('status', 16)->default('pending');

            // Where it was sent, as it was at the time. An address that has
            // since changed must not rewrite the record of where a message
            // actually went.
            $table->string('destination', 255)->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('failed_at')->nullable();

            // Truncated and redacted by the writer. A provider's bounce message
            // can quote the whole message it refused.
            $table->string('failure_reason', 500)->nullable();

            $table->timestampsTz();

            $table->unique(['notification_id', 'channel']);
            // The operator screen: what has failed to reach anybody.
            $table->index(['status', 'created_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('category', 32);
            $table->string('channel', 32);
            $table->boolean('enabled')->default(true);

            $table->timestampsTz();

            $table->unique(['user_id', 'category', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notifications');
    }
};
