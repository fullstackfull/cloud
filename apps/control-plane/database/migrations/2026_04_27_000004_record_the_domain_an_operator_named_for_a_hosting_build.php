<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The domain an operator named for a stopped hosting build.
 *
 * F-04 gave a hosting build refused over its name (`hosting.domain_missing`,
 * `hosting.domain_in_use`) an operator's way out: name the domain, then
 * retry. It first recorded that name by rewriting the job's payload, and F-15
 * holds that a job's payload is written once, when the job is created, and
 * never again — a VPS create decides whether a machine it finds is its own by
 * the names its payload gave it, and that decision is only as narrow as the
 * payload is stable. The name an operator supplies is a fact about the job
 * learned after it was created, so it is recorded in a column of its own and
 * the payload keeps what the job was created with.
 *
 * The build serves this name when it is set and the payload's
 * `primary_domain` when it is not — one rule, in
 * `ProvisioningJob::hostingDomain()`. Written only by
 * `NameTheDomainAHostingJobWillServe`, and always folded.
 *
 * Nullable, and it stays nullable: most hosting builds are never corrected,
 * and every other job kind serves no domain at all. 253 is RFC 1035's
 * ceiling on a name without its root dot, as on `order_items.domain`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provisioning_jobs', function (Blueprint $table): void {
            $table->string('operator_named_domain', 253)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('provisioning_jobs', function (Blueprint $table): void {
            $table->dropColumn('operator_named_domain');
        });
    }
};
