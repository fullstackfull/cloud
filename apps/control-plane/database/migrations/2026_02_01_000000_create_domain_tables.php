<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lynomia Domains: the names an account holds, and every attempt to change one.
 *
 * ---------------------------------------------------------------------------
 * Why a domain is not a Service and not a Subscription
 * ---------------------------------------------------------------------------
 *
 * Everything else this platform sells is a thing that runs until somebody
 * stops paying for it: a machine, an account on a panel, a chassis in a rack.
 * A domain is a term. It is bought for a number of years, it expires on a date
 * the registry decides, and renewing it costs a different amount from buying
 * it. Modelling it as a subscription would mean a monthly price that does not
 * exist, a proration that cannot be computed, and a suspension that has no
 * meaning — a registry does not suspend a name, it expires it.
 *
 * So domains have their own table and their own renewal path, and they reach
 * the platform's billing through orders and invoices like everything else.
 *
 * ---------------------------------------------------------------------------
 * Why operations are separate rows
 * ---------------------------------------------------------------------------
 *
 * The same separation the platform already draws between a Service and a
 * ProvisioningJob. A domain has a state — do we hold it — and an attempt to
 * register, renew, transfer or redeem it has its own: queued, running, failed,
 * indeterminate. Collapsing the two would mean a domain in state `registering`
 * with nowhere to record which of three attempts is the live one, what it
 * cost, or which invoice paid for it.
 *
 * It is also what makes the Timeout Rule expressible here. A registration that
 * did not answer leaves an operation `indeterminate` and the domain
 * `registration_pending`; nothing retries it, because a retried registration
 * is a second year somebody pays for.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * What the platform will sell, per top-level domain.
         *
         * Separate from plans and prices, because a plan is a shape (this much
         * disk, this much memory) sold at a price, and a TLD is a namespace
         * whose price depends on the operation and sometimes on the exact name
         * being bought. One row per TLD, and the five prices below are five
         * different numbers rather than one number at different times.
         */
        Schema::create('domain_tlds', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Without the dot: `com`, `sy`, `com.sy`. Stored lowercase; the
            // application normalises before it looks anything up.
            $table->string('tld', 63)->unique();

            $table->boolean('enabled')->default(false);

            /*
             * Which registrar adapter serves this namespace. Per TLD rather
             * than per deployment: `.sy` will be served by a different
             * authority from `.com` for as long as this platform exists, and
             * a single global setting could not express that.
             */
            $table->string('provider', 32);

            /*
             * What may be done with this namespace *commercially*, which is
             * not the same question as what the provider can technically do.
             * A TLD whose registry is fine but whose reseller agreement has
             * lapsed is `enabled = false` with every capability still true.
             */
            $table->boolean('allows_registration')->default(true);
            $table->boolean('allows_transfer')->default(true);
            $table->boolean('allows_renewal')->default(true);
            $table->boolean('supports_premium')->default(false);

            $table->unsignedTinyInteger('minimum_term_years')->default(1);
            $table->unsignedTinyInteger('maximum_term_years')->default(1);

            $table->char('currency', 3);

            /*
             * Selling prices, per year of term, in integer minor units.
             *
             * Five columns rather than one, because they are five prices. A
             * renewal is routinely dearer than a registration — the first year
             * is the loss leader across this entire industry — and a
             * redemption is a registry penalty that can be an order of
             * magnitude above either.
             */
            $table->bigInteger('registration_price_minor');
            $table->bigInteger('renewal_price_minor');
            $table->bigInteger('transfer_price_minor');
            $table->bigInteger('redemption_price_minor')->nullable();

            /*
             * What each costs the platform. Nullable because a provider that
             * does not publish its cost leaves the margin unknowable rather
             * than zero, and a zero here would report the whole selling price
             * as profit in a reconciliation somebody makes decisions on.
             */
            $table->bigInteger('registration_cost_minor')->nullable();
            $table->bigInteger('renewal_cost_minor')->nullable();
            $table->bigInteger('transfer_cost_minor')->nullable();
            $table->bigInteger('redemption_cost_minor')->nullable();

            /*
             * Registry lifecycle windows, in days, and every one of them is
             * nullable on purpose.
             *
             * The famous "30 days grace, 30 days redemption" is an ICANN gTLD
             * convention, not a law: ccTLDs differ, some registries have no
             * redemption period at all, and `.sy`'s rules are not published
             * anywhere this platform can read. A null here means "this
             * platform does not know", which is a fact the expiry sweep can
             * act on honestly; a default of 30 would be an invention that
             * looks like knowledge.
             */
            $table->unsignedSmallInteger('grace_days')->nullable();
            $table->unsignedSmallInteger('redemption_days')->nullable();

            /*
             * What a registry demands of a registrant beyond the ordinary
             * contact set — a national identifier, a local presence, a
             * document. Free-form because it differs per registry and because
             * inventing a schema for requirements nobody has published would
             * be inventing the requirements.
             */
            $table->jsonb('registrant_requirements')->nullable();

            $table->timestampsTz();

            $table->index(['enabled', 'tld']);
        });

        Schema::create('domains', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->string('name', 253);
            $table->string('tld', 63);

            $table->string('state', 32);

            /*
             * Which adapter holds it, recorded per row. A deployment that
             * changes registrar still has to be able to say who holds the
             * names it registered last year, and the TLD table's answer is
             * about new registrations rather than about history.
             */
            $table->string('provider', 32);
            $table->string('provider_reference')->nullable();

            $table->unsignedTinyInteger('term_years')->default(1);

            $table->timestampTz('registered_at')->nullable();

            /*
             * What the registry says, not what the platform computed.
             *
             * Renewal writes this from the provider's authoritative answer
             * rather than by adding a year, because a registry that renewed
             * from the expiry date and one that renewed from today give
             * different answers, and the difference is a year of somebody's
             * domain.
             */
            $table->timestampTz('expires_at')->nullable();

            $table->boolean('auto_renew')->default(true);

            /*
             * The registrar's transfer lock. Nullable because "this provider
             * does not support locking" is a third answer, and storing it as
             * false would tell a customer their domain is unlocked when the
             * truth is that nobody can say.
             */
            $table->boolean('transfer_locked')->nullable();

            /*
             * The delegation, as the platform last set or read it. A domain
             * using Lynomia DNS and one pointed at somebody else are the same
             * shape here; the difference is only which names are in the list.
             */
            $table->jsonb('nameservers')->nullable();

            /*
             * The zone, when the customer asked for one. Nullable and
             * `nullOnDelete`, and the direction matters: a domain may point at
             * a zone, and nothing in the DNS module may ever look back. A
             * customer can hold a domain here and serve its DNS elsewhere, or
             * hold it elsewhere and serve its DNS here, and both have to keep
             * working.
             */
            $table->foreignUlid('dns_zone_id')->nullable()->constrained('dns_zones')->nullOnDelete();

            $table->timestampTz('reconciled_at')->nullable();

            // Why a person is needed, when one is. Never a provider credential.
            $table->text('review_reason')->nullable();

            $table->timestampsTz();

            $table->index(['customer_id', 'state']);
            $table->index(['state', 'expires_at']);
            $table->index('expires_at');
        });

        /*
         * One live holder per name, across the whole platform.
         *
         * The same rule the DNS zones table has, for the same reason: a domain
         * has one holder in the registry, so two accounts cannot both hold it
         * here. Partial, so a name this platform lost — transferred away,
         * expired, deleted — can be registered again later by anybody.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX domains_one_live_holder
            ON domains (name)
            WHERE state NOT IN ('deleted', 'transferred_away', 'failed')
        SQL);

        /*
         * The contact set a registration was made with, as it was then.
         *
         * A snapshot rather than a pointer at the customer's profile. A
         * registration made in 2026 was made with the registrant the registry
         * recorded in 2026, and a customer who changes their address next year
         * has not retroactively changed what was filed — which matters when a
         * registry asks the platform to prove what it submitted.
         *
         * Every personal column here is encrypted at rest and none of them may
         * reach a log line, a metric label or an audit context.
         */
        Schema::create('domain_contacts', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('domain_id')->constrained('domains')->cascadeOnDelete();

            // registrant | administrative | technical | billing
            $table->string('role', 16);

            $table->text('name');
            $table->text('organisation')->nullable();
            $table->text('email');
            $table->text('phone');
            $table->text('address_line_one');
            $table->text('address_line_two')->nullable();
            $table->text('city');
            $table->text('region')->nullable();
            $table->text('postal_code')->nullable();
            $table->char('country', 2);

            $table->string('provider_reference')->nullable();

            $table->timestampsTz();

            $table->unique(['domain_id', 'role']);
        });

        /*
         * Every attempt to acquire, keep or move a name.
         *
         * The money lives here rather than on the domain because a domain has
         * many of these over its life and each one cost a different amount at
         * a different time. A margin computed at display time from today's
         * price list is a margin nobody can reconcile against what was
         * actually charged.
         */
        Schema::create('domain_operations', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            /*
             * Nullable, and this is the interesting one. A registration exists
             * before the domain does: the row is written when the customer
             * pays, and the domain row appears when a registrar says yes. An
             * operation whose domain is still null and whose state is
             * indeterminate is exactly the case an operator has to resolve.
             */
            $table->foreignUlid('domain_id')->nullable()->constrained('domains')->nullOnDelete();

            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();

            // The name this operation is about, kept even when domain_id is
            // null, because otherwise an indeterminate registration is a row
            // about nothing.
            $table->string('name', 253);

            // register | renew | transfer | redeem
            $table->string('kind', 24);
            $table->string('state', 32);

            $table->unsignedTinyInteger('term_years')->default(1);

            $table->char('currency', 3);
            $table->bigInteger('price_minor');
            $table->bigInteger('cost_minor')->nullable();

            $table->foreignUlid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignUlid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            /*
             * What the platform sent, so a redelivered job, a double-clicked
             * button and a retried worker all reach the same row instead of
             * buying a second year.
             */
            $table->string('idempotency_key', 128);

            $table->string('provider', 32);
            $table->string('provider_reference')->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('last_attempted_at')->nullable();

            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();

            $table->timestampTz('completed_at')->nullable();

            $table->timestampsTz();

            $table->unique('idempotency_key');
            $table->index(['customer_id', 'kind', 'state']);
            $table->index(['state', 'created_at']);
            $table->index('name');
        });

        /*
         * A price the platform stands behind, for a moment.
         *
         * The whole reason this table exists is that the client must never be
         * authoritative about what a domain costs. A premium name can be a
         * hundred times the ordinary price for its TLD, so a checkout that
         * accepted a submitted amount would let somebody buy
         * `insurance.com` at the price of `some-unremarkable-name.com`.
         *
         * The customer is given a quote id. Checkout re-reads this row, checks
         * it has not expired, checks it belongs to the acting account, and
         * uses the price stored here. Nothing about the amount travels through
         * the browser.
         */
        Schema::create('domain_quotes', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->string('name', 253);
            $table->string('tld', 63);

            // register | renew | transfer | redeem
            $table->string('operation', 24);

            $table->unsignedTinyInteger('term_years')->default(1);
            $table->boolean('premium')->default(false);

            $table->char('currency', 3);
            $table->bigInteger('price_minor');
            $table->bigInteger('cost_minor')->nullable();

            // What the provider called this quote, where it gave one. A
            // premium price usually has to be presented back to the registry
            // with the order, and inventing it is how a registration is
            // refused at the last step.
            $table->string('provider_reference')->nullable();

            $table->string('provider', 32);

            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();

            $table->timestampsTz();

            $table->index(['customer_id', 'expires_at']);
            $table->index(['name', 'operation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_quotes');
        Schema::dropIfExists('domain_operations');
        Schema::dropIfExists('domain_contacts');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('domain_tlds');
    }
};
