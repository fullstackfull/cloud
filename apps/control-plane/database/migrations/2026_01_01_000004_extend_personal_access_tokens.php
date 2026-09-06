<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends Sanctum's token table with the metadata the public customer API
 * needs: which customer the token acts for, a per-token rate limit, an IP
 * allow-list, and revocation that is distinct from deletion so that an audit
 * trail survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            // A token always acts on behalf of exactly one customer account,
            // even when its owner belongs to several.
            $table->foreignUlid('customer_id')->nullable()->after('tokenable_id')
                ->constrained('customers')->cascadeOnDelete();

            // Requests per minute allowed for this specific token. Null falls
            // back to the tier default.
            $table->unsignedInteger('rate_limit_per_minute')->nullable();

            // Optional CIDR allow-list. An empty list means "any address".
            $table->jsonb('allowed_ip_ranges')->nullable();

            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_reason', 128)->nullable();
            $table->string('last_used_ip', 45)->nullable();

            $table->index(['customer_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn([
                'rate_limit_per_minute',
                'allowed_ip_ranges',
                'revoked_at',
                'revoked_reason',
                'last_used_ip',
            ]);
        });
    }
};
