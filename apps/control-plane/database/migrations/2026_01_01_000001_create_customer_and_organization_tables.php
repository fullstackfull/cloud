<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers and organizations.
 *
 * A "customer" is the commercial counterparty that owns services and receives
 * invoices. It is deliberately separate from "user", which is a login. An
 * organization customer has many users with different roles; an individual
 * customer has exactly one. Billing, ordering and provisioning all reference
 * the customer, never the user, so that transferring account ownership or
 * adding a colleague does not touch a single service or invoice row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('type', 16);                  // individual | organization
            $table->string('status', 24)->default('active'); // active | suspended | closed
            $table->string('display_name');

            // Commercial identity.
            $table->string('legal_name')->nullable();
            $table->string('registration_number', 64)->nullable();
            $table->string('tax_id', 64)->nullable();
            $table->boolean('tax_exempt')->default(false);

            // Default billing currency for this customer. Invoices are issued in
            // this currency and never silently converted.
            $table->char('currency', 3);

            // Billing address, kept denormalised on the customer because it is
            // the address of record at the time of invoicing; invoices snapshot
            // their own copy so that later edits never rewrite history.
            $table->string('billing_email')->nullable();
            $table->string('billing_phone', 32)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->char('country', 2)->nullable();

            // Credit and risk.
            $table->boolean('requires_manual_review')->default(false);
            $table->text('internal_notes')->nullable();

            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('status');
            $table->index('country');
        });

        // Membership of a user in a customer account, with a role scoped to
        // that customer. A user may belong to several customer accounts (an
        // agency managing clients, for example).
        Schema::create('customer_members', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('role', 32);                  // owner | administrator | member | billing
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignUlid('invited_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();

            $table->unique(['customer_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_members');
        Schema::dropIfExists('customers');
    }
};
