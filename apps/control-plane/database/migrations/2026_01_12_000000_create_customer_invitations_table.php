<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An offer of membership in a customer account, made to an email address.
 *
 * It is a separate table from `customer_members` because the two answer
 * different questions and one of them can be answered before the other has a
 * subject: a membership row needs a user, and the person being invited very
 * often has no login yet. Putting the offer on the membership row would mean
 * either inventing a user for every invitation — an account nobody asked for,
 * with a mailbox that may never be read — or leaving `user_id` nullable and
 * letting every membership query remember to exclude the rows that are not
 * memberships at all.
 *
 * **The token is never stored.** What is stored is a SHA-256 of it, the same
 * way a password is, so that a copy of this table is not a set of working
 * invitations. Lookup is by hash, which is exact rather than a scan, so the
 * hashing costs nothing at read time.
 *
 * **An invitation is spent, not deleted.** Accepting, declining and revoking
 * each stamp their own timestamp, and the row stays: "who let this person in,
 * and when" is a question an operator asks after an incident, and a row that
 * was deleted on acceptance cannot answer it. `status()` on the model reads
 * these three columns plus `expires_at`, so there is no separate status column
 * that could disagree with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();

            /*
             * The address the offer was made to, lowercased by the action that
             * writes it. Acceptance compares this against the authenticated
             * user's own verified address, so an invitation forwarded to a
             * colleague does not admit the colleague.
             */
            $table->string('email');
            $table->string('role', 32);

            // SHA-256 of the token. Unique because two invitations sharing a
            // token would make redemption ambiguous, and because the lookup
            // that redeems one has to be a single-row hit.
            $table->string('token_hash', 64)->unique();

            $table->foreignUlid('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('declined_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();

            /*
             * How many times the invitation has been sent, and when last. A
             * resend does not mint a new token — the one in the first mail must
             * keep working, because that is the mail the invitee is most likely
             * to still have — so this is the only record that it happened.
             */
            $table->unsignedSmallInteger('sent_count')->default(1);
            $table->timestampTz('last_sent_at')->nullable();

            $table->foreignUlid('accepted_member_id')->nullable()
                ->constrained('customer_members')->nullOnDelete();

            $table->timestampsTz();

            // The listing query: one account's invitations, newest first.
            $table->index(['customer_id', 'created_at']);
            // The invitee's own query: "what am I being offered?", by address.
            $table->index('email');
        });

        /*
         * At most one live offer per address per account.
         *
         * Without it, pressing Invite twice makes two invitations with two
         * tokens, and revoking the one the screen shows leaves the other one
         * working — a revocation that did not revoke. Partial, so that a
         * declined or revoked offer does not block a second chance, and an
         * accepted one does not block re-inviting somebody who later left.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX customer_invitations_one_live_offer
                ON customer_invitations (customer_id, lower(email))
                WHERE accepted_at IS NULL AND declined_at IS NULL AND revoked_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_invitations');
    }
};
