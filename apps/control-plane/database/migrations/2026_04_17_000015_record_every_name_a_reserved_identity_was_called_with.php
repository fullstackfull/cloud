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
 * exactly against the names recorded here as ones a create under the id was
 * sent with. Written by its own statement immediately before each create is
 * sent — not with the id, which is reserved before an attempt knows whether
 * it will send anything — so the list holds no name that was only reserved,
 * holds nothing until a create is about to be sent, and there is no instant
 * at which the platform has called with a name it has not recorded. (A
 * create that never left after its name was written — its worker died, or
 * it failed before its request went — leaves a name nothing was sent with;
 * a machine carrying it is taken to be possibly this build's, the safe way.)
 *
 * A list rather than a single value, and append-only: the comparison is
 * against every name a create under this identity sent, not only the latest.
 * `provisioning_jobs.payload` has exactly one writer in `src/` — the statement
 * that creates the row — so in practice the list holds one entry, and that
 * bound is pinned by tests rather than assumed: a census of the writes to the
 * column in the source's text, in every form its shapes list, which names the
 * forms it does not read, and a behavioural pin that drives a create's job
 * through the engine, every operator act on it and both sweepers.
 * Nothing on the platform edits a payload; the list
 * is what keeps the comparison correct if something outside the platform
 * ever does, instead of letting a second name quietly turn this build's own
 * machine into a "stranger".
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
