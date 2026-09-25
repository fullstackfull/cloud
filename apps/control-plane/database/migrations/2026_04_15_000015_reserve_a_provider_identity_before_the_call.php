<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The identity a create will ask the provider for, written down before it asks.
 *
 * F-15. A VPS create used to choose its hypervisor id with a fresh
 * `random_int` on every attempt and record it nowhere until the provider had
 * answered. A create whose answer was lost — the cluster accepted it and the
 * request was abandoned — therefore left no trace the platform could read: no
 * task id, no provider reference, nothing for `RetryProvisioningJob`'s
 * "something was built" refusal to find. The operator's retry drew a new id
 * and built a second machine beside the first.
 *
 * `reserved_provider_id` is the id every attempt of this job asks for, fixed
 * by the first one to reach the provider call and never replaced by a later
 * one. `reserved_provider_nodes` is every node a create under that id was sent
 * to, append-only, because placement is recomputed per attempt and a retry may
 * land somewhere else — the place to look for what an earlier attempt built is
 * every node it was sent to, not only the one this attempt chose.
 *
 * Both nullable: a job that never reached a provider call has reserved
 * nothing, and every job kind other than a create reserves nothing at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provisioning_jobs', function (Blueprint $table): void {
            $table->string('reserved_provider_id', 64)->nullable();
            $table->jsonb('reserved_provider_nodes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('provisioning_jobs', function (Blueprint $table): void {
            $table->dropColumn(['reserved_provider_id', 'reserved_provider_nodes']);
        });
    }
};
