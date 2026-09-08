<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A WordPress site, which is four things that have to line up.
 *
 * ===========================================================================
 * WHY THIS IS ITS OWN ROW AND NOT A FLAG ON A HOSTING ACCOUNT
 * ===========================================================================
 *
 * Because "WordPress hosting" is not a hosting account with a checkbox. It is
 * a hosting account, a name pointed at it, a certificate for that name, and an
 * installation that actually answers — and every one of those four can be
 * finished while another is not.
 *
 * A boolean on `hosting_accounts` could only say "somebody asked for
 * WordPress". It could not say which of the four steps is outstanding, and the
 * screen would have to guess. The guess a screen makes here is always the same
 * one — a green tick the moment the account exists — and the customer clicks
 * through to a certificate warning on a site that is not there yet.
 *
 * ===========================================================================
 * WHAT IS DELIBERATELY NOT STORED
 * ===========================================================================
 *
 * The WordPress administrator's password. It is generated, handed to the
 * installer, and shown to the customer once. A copy here would be a copy of
 * every customer's site credentials in one table, protecting nothing: the
 * customer can reset it from their own dashboard, and support has no business
 * signing in as them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wordpress_sites', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();

            /*
             * The hosting account this site lives in. Nullable only while the
             * account is still being built: the row exists first so the
             * customer's screen has something to show progress against.
             */
            $table->foreignUlid('hosting_account_id')->nullable()
                ->constrained('hosting_accounts')->nullOnDelete();

            $table->foreignUlid('service_id')->nullable()->constrained('services')->nullOnDelete();

            /*
             * The name, and the domain row behind it where this platform holds
             * one. External domains have a name and no row, which is a
             * supported case and not a missing foreign key: a customer may
             * point a name registered anywhere at this hosting.
             */
            $table->string('domain', 253);
            $table->foreignUlid('domain_id')->nullable()->constrained('domains')->nullOnDelete();

            /*
             * How the customer answered "where does the name come from". Kept
             * because the answer changes what the platform must wait for: a
             * name registered here is delegated in seconds, and an external one
             * waits on somebody editing DNS at another company.
             */
            $table->string('domain_source', 24);

            $table->string('state', 32);

            /*
             * What the platform has actually established about the site, as
             * opposed to what it asked for. Each is written when it is proved
             * and not when it is requested.
             */
            $table->boolean('dns_ready')->default(false);
            $table->boolean('installed')->default(false);
            $table->string('ssl_status', 16)->default('unknown');
            $table->timestampTz('verified_at')->nullable();

            $table->string('site_url')->nullable();
            $table->string('admin_username')->nullable();

            // The address the installer files as the site administrator's.
            // Personal data, encrypted like every other contact on this
            // platform.
            $table->text('admin_email')->nullable();

            $table->string('wordpress_version', 32)->nullable();

            $table->string('locale', 12)->nullable();

            $table->text('failure_reason')->nullable();
            $table->text('review_reason')->nullable();

            $table->timestampTz('reconciled_at')->nullable();

            $table->timestampsTz();

            $table->index(['customer_id', 'state']);
            $table->index(['state', 'created_at']);
        });

        /*
         * One live site per name.
         *
         * The same rule the domains and DNS tables carry, for the same reason:
         * two sites answering for one name is a race between two
         * installations, and whichever loses leaves a certificate order and a
         * document root nobody owns. Partial, so a name whose site was removed
         * can be used again.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX wordpress_sites_one_live_per_domain
            ON wordpress_sites (domain)
            WHERE state NOT IN ('removed', 'failed')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_sites');
    }
};
