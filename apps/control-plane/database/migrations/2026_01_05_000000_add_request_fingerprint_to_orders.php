<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the idempotency key was used to order.
 *
 * The key alone answers "have I seen this request before?" but not "was it the
 * same request?", and the difference matters. A client that reuses a key for a
 * genuinely different basket - a retry built from a stale form, a queue that
 * replays a message after the cart changed, a library that derives the key from
 * the session rather than the payload - was silently handed back the first
 * order. The customer sees a confirmation for something they did not just buy,
 * and no error is raised anywhere.
 *
 * Storing a fingerprint of the priced request lets the second submission be
 * told apart from a replay: same key and same fingerprint is a retry and
 * returns the original order; same key and a different fingerprint is a
 * conflict and says so.
 *
 * Nullable, because orders placed before this column existed have no
 * fingerprint and must keep replaying as they always did rather than start
 * answering 409.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // SHA-256 hex. Not a unique index: two different customers may
            // legitimately order the same basket, and the same customer may
            // legitimately order it twice under two different keys.
            $table->char('request_fingerprint', 64)->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('request_fingerprint');
        });
    }
};
