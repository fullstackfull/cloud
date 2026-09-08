<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support: tickets, the messages on them, and the files attached to those.
 *
 * **Three tables and not four.** The brief's model named a "conversation"
 * between the ticket and its messages. A ticket *is* the conversation — there
 * is no product in which one ticket holds two separate threads — and a table
 * that always has exactly one row per parent is a join every query pays for
 * and nothing ever uses. If ticket-to-ticket merging is ever built, that is
 * when a thread becomes a thing of its own.
 *
 * **The state is on the ticket, the history is in the messages.** Nothing
 * derives "who replied last" by scanning the thread: `last_reply_at` and
 * `awaiting` are stamped by the action that writes the reply, because the
 * operator queue is sorted by them and a sort that has to read every message
 * of every ticket is a queue that stops loading at a few thousand tickets.
 *
 * **Attachments are rows, not paths in a message body.** A file has an owner,
 * a size, a type and a place it lives, and every one of those is something the
 * platform has to be able to answer without opening the file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            /*
             * The customer, not the user. A ticket belongs to the account so
             * that a colleague can pick it up when the person who opened it is
             * on leave — the same reason invoices and services belong to the
             * account. Who opened it is recorded separately.
             */
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUlid('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Human-facing, sequential, and not the id. "Ticket 01M1ZK…" is
             * unreadable over a phone call, which is exactly when a reference
             * is most needed.
             */
            $table->string('reference', 24)->unique();

            $table->string('subject');
            $table->string('category', 32);
            $table->string('status', 32);
            $table->string('priority', 16);

            /*
             * What the ticket is about, when it is about something. All
             * nullable and all `nullOnDelete`: a ticket about a machine that
             * was terminated is often the most important ticket in the queue,
             * and losing it because the subject went away would be the worst
             * possible moment to lose it.
             */
            $table->foreignUlid('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignUlid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->foreignUlid('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Stamped by the action that writes each reply rather than derived
             * from the messages, because the queue sorts on them.
             */
            $table->timestampTz('last_reply_at')->nullable();
            $table->string('last_reply_by', 16)->nullable();   // customer | operator

            // The two numbers a support team is actually measured on.
            $table->timestampTz('first_responded_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();

            $table->unsignedSmallInteger('reopened_count')->default(0);

            $table->timestampsTz();

            // The customer's own list, newest first.
            $table->index(['customer_id', 'created_at']);
            // The operator queue: everything open, worst first, oldest first.
            $table->index(['status', 'priority', 'last_reply_at']);
            $table->index('assigned_to_user_id');
        });

        Schema::create('support_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('ticket_id')->constrained('support_tickets')->cascadeOnDelete();

            /*
             * Nullable for a message the platform wrote itself — "this ticket
             * was reopened automatically" — and for one whose author's login
             * has since been deleted. A message with no author is still part
             * of the record.
             */
            $table->foreignUlid('author_user_id')->nullable()->constrained('users')->nullOnDelete();

            // customer | operator | system. Kept alongside the author because
            // the same person can be both on different tickets, and because a
            // deleted author must not turn an operator's note into a
            // customer's message.
            $table->string('author_kind', 16);

            $table->text('body');

            /*
             * An operator's note to their colleagues. Never returned on the
             * customer surface — enforced in the query, not in the resource,
             * so a note cannot leak through an endpoint that forgot to filter.
             */
            $table->boolean('is_internal_note')->default(false);

            $table->timestampsTz();

            $table->index(['ticket_id', 'created_at']);
        });

        Schema::create('support_attachments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('message_id')->constrained('support_messages')->cascadeOnDelete();

            $table->string('disk', 32);
            $table->string('path');

            /*
             * What the uploader called it, kept only to show and to name the
             * download. It is never used to build the stored path: a filename
             * from a customer is attacker-controlled, and a path built from one
             * is a traversal waiting to happen.
             */
            $table->string('original_name');

            /*
             * The type the platform decided from the bytes, not the type the
             * browser claimed. Serving a customer's "image/png" that is really
             * an HTML document is stored cross-site scripting.
             */
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum', 64);

            $table->timestampsTz();

            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_attachments');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
    }
};
