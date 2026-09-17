<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each account accepted, which revision of it, and when.
 *
 * The registration form has always asked a customer to accept a terms of
 * service and an acceptable use policy, and the API has always required the
 * box to be ticked. Nothing was ever written down: `accepts_terms` was a
 * validation rule and nothing else, so the platform could not answer the only
 * question that matters afterwards — which text did this person agree to?
 *
 * Append-only by design. An acceptance is evidence about a past moment, so
 * there is nothing to update: a customer accepting a newer revision adds a
 * row, and the earlier row stays exactly as it was. That is why there is a
 * `created_at` and no `updated_at`, and why the model refuses updates.
 *
 * The URL is captured alongside the version rather than only referenced from
 * configuration, so an acceptance stays legible after the documents move.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_acceptances', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            /*
             * The user and not the customer account.
             *
             * A person accepts terms; an account does not. An organisation
             * with four members has four people who each agreed to something,
             * possibly different revisions at different times, and collapsing
             * that onto the account would lose which of them ever did.
             *
             * Cascades on delete: an acceptance belonging to no one is not
             * evidence of anything, and keeping it after the account it
             * identifies is gone would be retaining personal data with no
             * purpose left to serve.
             */
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('document_type', 32);
            $table->string('document_version', 64);
            $table->string('document_url', 2048);

            /*
             * Stored rather than inferred from `created_at`, because they are
             * not the same fact: one is when the person accepted, the other is
             * when this row was written. They coincide today and a backfill,
             * an import or a migration from another platform would separate
             * them.
             */
            $table->timestamp('accepted_at');
            $table->timestamp('created_at')->nullable();

            // The question this table is asked: what has this person accepted,
            // most recent first.
            $table->index(['user_id', 'document_type', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
    }
};
