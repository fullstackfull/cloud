<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which cluster a reserved provider identity means something in.
 *
 * A hypervisor id is unique within one cluster and means nothing outside it:
 * VMID 41327 on one Proxmox cluster and VMID 41327 on another are two
 * unrelated machines. A reserved identity recorded without its cluster would
 * let a job whose payload came to name a different cluster look for its
 * earlier build in the wrong place, find nothing, and build again — the very
 * second machine the identity was reserved to prevent. With the cluster
 * recorded, a create refuses to run under an identity reserved elsewhere.
 *
 * No foreign key: the column names a row in the Compute module, and the
 * provisioning engine does not depend on Compute's tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provisioning_jobs', function (Blueprint $table): void {
            // `after()` is ignored by the Postgres grammar; it is kept as a
            // statement of which column this one qualifies.
            $table->string('reserved_cluster_id', 26)->nullable()->after('reserved_provider_nodes');
        });
    }

    public function down(): void
    {
        Schema::table('provisioning_jobs', function (Blueprint $table): void {
            $table->dropColumn('reserved_cluster_id');
        });
    }
};
