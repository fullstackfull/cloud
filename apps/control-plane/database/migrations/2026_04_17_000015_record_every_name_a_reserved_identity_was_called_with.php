<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every hostname a create under the reserved identity was sent with.
 *
 * What establishes that a machine found at the reserved id is this build's
 * and not a stranger's is the name the hypervisor reports for it, compared
 * exactly against the names this job actually asked for. Written by the same
 * statement that writes the id, so there is no instant at which the platform
 * has called with a name it has not recorded.
 *
 * A list rather than a single value, and append-only: the comparison is
 * against every name a create under this identity sent, not only the latest.
 * `provisioning_jobs.payload` has exactly one writer in `src/` — the statement
 * that creates the row — so in practice the list holds one entry, and that
 * bound is pinned by tests rather than assumed. Nothing on the platform edits
 * a payload; the list is what keeps the comparison correct if something
 * outside the platform ever does, instead of letting a second name quietly
 * turn this build's own machine into a "stranger".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provisioning_jobs', function (Blueprint $table): void {
            $table->jsonb('reserved_provider_hostnames')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('provisioning_jobs', function (Blueprint $table): void {
            $table->dropColumn('reserved_provider_hostnames');
        });
    }
};
