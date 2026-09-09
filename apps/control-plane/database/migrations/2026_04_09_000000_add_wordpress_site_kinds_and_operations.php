<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A site row learns what it is — a production site, a staging copy of
 * one, or a clone that started as one — and which site it was copied
 * from. Copies and pushes get their own rows: each is a toolkit operation
 * with a state of its own, and a push that timed out must be visible as
 * exactly that, on the site it may have half-overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wordpress_sites', function (Blueprint $table): void {
            $table->string('kind', 16)->default('production');
            $table->foreignUlid('parent_site_id')->nullable()->constrained('wordpress_sites')->nullOnDelete();
            $table->index(['parent_site_id', 'kind']);
        });

        Schema::create('wordpress_site_operations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUlid('wordpress_site_id')->constrained('wordpress_sites')->cascadeOnDelete();
            $table->foreignUlid('target_site_id')->nullable()->constrained('wordpress_sites')->nullOnDelete();
            $table->string('kind', 32);
            $table->string('state', 32);
            $table->string('scope', 16)->nullable();
            $table->json('impact')->nullable();
            $table->foreignUlid('provisioning_job_id')->nullable()->constrained('provisioning_jobs')->nullOnDelete();
            $table->text('failure_reason')->nullable();
            $table->foreignUlid('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['wordpress_site_id', 'state']);
            $table->index(['kind', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_site_operations');

        Schema::table('wordpress_sites', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_site_id');
            $table->dropColumn('kind');
        });
    }
};
