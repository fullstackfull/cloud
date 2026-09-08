<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Forward DNS: the zones an account holds and the records in them.
 *
 * **The platform's belief, not the zone's contents.** Every row here says what
 * this platform asked a provider to publish and what it last saw. It is not a
 * copy of the zone: a record somebody added through the provider's own console
 * is not represented here, is not deleted by anything here, and shows up as
 * drift. A table that pretended to be the zone would be a table that removed
 * what it did not recognise.
 *
 * **Zone names are unique across the platform, not per account.** Two accounts
 * cannot both hold `example.com` because DNS itself will not have it — one
 * delegation, one holder. First claim wins, and an operator moves it if the
 * claim was wrong. Nothing here verifies that the claimant owns the domain;
 * see docs/dns.md for exactly what that does and does not mean.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_zones', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();

            /*
             * Optional, and it stays optional. A zone is frequently held by an
             * account that has no service pointing at it yet — somebody moves
             * their DNS first and orders the machine afterwards — and a schema
             * that insisted on a service would force the platform to invent
             * one or refuse the order of operations customers actually use.
             */
            $table->foreignUlid('service_id')->nullable()->constrained('services')->nullOnDelete();

            $table->string('name', 253);
            $table->string('state', 32);

            /*
             * Which adapter published it. Recorded per row rather than read
             * from configuration at display time, because a deployment that
             * changes provider still has to be able to say who holds the zones
             * it created last year.
             */
            $table->string('provider', 32);
            $table->string('provider_zone_id')->nullable();

            /*
             * What the customer must delegate to. Held as the provider gave
             * them, because a customer typing these into their registrar needs
             * the provider's answer and not the platform's idea of it.
             */
            $table->json('nameservers')->nullable();

            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('failure_reason')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'state']);
            $table->index(['state', 'last_synced_at']);
        });

        Schema::create('dns_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('dns_zone_id')->constrained('dns_zones')->cascadeOnDelete();

            $table->string('type', 8);

            // Fully qualified, always. Storing it relative would mean every
            // read had to know the zone to make sense of the row, and "@" and
            // "" and "example.com." are three spellings of one name that a
            // relative column invites into the same table.
            $table->string('name', 253);

            $table->text('content');
            $table->integer('ttl');
            $table->integer('priority')->nullable();

            /*
             * CAA is (flags, tag, value) and providers disagree about whether
             * they want the presentation form or the fields. The structured
             * shape is stored so the adapter decides, rather than the platform
             * storing one provider's spelling and translating it back.
             */
            $table->json('data')->nullable();

            $table->string('state', 32);
            $table->string('provider_record_id')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('last_published_at')->nullable();

            $table->timestamps();

            $table->index(['dns_zone_id', 'state']);
            $table->index(['dns_zone_id', 'name']);
        });

        /*
         * One live claim per domain.
         *
         * Partial rather than absolute: a domain an account has given up is a
         * domain anybody may claim, which is what DNS itself does when a
         * delegation is withdrawn. An absolute unique index would mean the
         * first account ever to type a name held it for the lifetime of the
         * platform, including after they left.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX dns_zones_one_live_claim
                ON dns_zones (name)
                WHERE state <> 'deleted'
        SQL);

        /*
         * One live row per (zone, type, name, value).
         *
         * Not per (zone, type, name): several A records under one name is how
         * round robin is spelled, and several MX records under one name is how
         * mail works. What must not happen is the same value twice, which is a
         * double-click turning into two identical records the customer then
         * has to delete individually.
         *
         * `md5(content)` because a TXT value can be longer than an index will
         * take. Collisions are not a security question here: the worst case is
         * refusing a second record whose value differs from the first, and the
         * customer sees the refusal.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX dns_records_one_live_value
                ON dns_records (dns_zone_id, type, name, md5(content))
                WHERE state <> 'deleted'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_records');
        Schema::dropIfExists('dns_zones');
    }
};
