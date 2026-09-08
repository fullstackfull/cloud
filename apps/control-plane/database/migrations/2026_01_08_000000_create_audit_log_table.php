<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who did the irreversible thing, and when.
 *
 * The platform has referred to "the audit row" and "the audit trail" in a
 * dozen docblocks since the first module was written, and has shipped a
 * permission called audit.view. None of it existed. An operator could void an
 * invoice, adopt an orphaned machine, restore a backup over a customer's live
 * data or unsuspend an account, and the only trace was the changed row itself
 * — which records the new value and not the person, and which the next change
 * overwrites.
 *
 * Four decisions in the shape:
 *
 *  - **The actor is denormalised.** actor_label holds the operator's name and
 *    email as they were at the time. A foreign key alone would make the trail
 *    read differently after somebody is renamed or deleted, and an audit trail
 *    that changes retroactively is not one.
 *
 *  - **The subject is polymorphic and unconstrained.** No foreign key, on
 *    purpose: the whole value of the row is that it survives the thing it
 *    describes. A cascade would delete the record of a deletion.
 *
 *  - **Context is jsonb and passes through SecretRedactor before it is
 *    written.** Audit entries quote request payloads, and payloads carry
 *    tokens. This table is read by more people than any other.
 *
 *  - **There is no updated_at and nothing may write one.** Rows are appended
 *    and never amended. The application enforces it; the missing column means
 *    a careless mass-update fails loudly rather than silently rewriting
 *    history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Nullable because the scheduler and queue workers act too, and a
            // reconciliation that adopted a machine is exactly as worth
            // recording as an operator who did it by hand.
            $table->ulid('actor_id')->nullable();
            $table->string('actor_type', 32)->default('system');
            $table->string('actor_label')->nullable();

            $table->string('action', 64);

            $table->string('subject_type', 64)->nullable();
            $table->string('subject_id', 64)->nullable();

            /*
             * Recorded when the subject belongs to one account, so that a
             * customer's own history can be assembled without scanning every
             * row on the platform — and so a support agent scoped to one
             * account can be shown their part of it.
             */
            $table->ulid('customer_id')->nullable();

            $table->jsonb('context')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamp('created_at')->useCurrent();

            // The two questions actually asked of this table: what happened to
            // this thing, and what has this person been doing.
            $table->index(['subject_type', 'subject_id', 'created_at'], 'audit_log_subject_index');
            $table->index(['actor_id', 'created_at'], 'audit_log_actor_index');
            $table->index(['customer_id', 'created_at'], 'audit_log_customer_index');
            $table->index(['action', 'created_at'], 'audit_log_action_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
