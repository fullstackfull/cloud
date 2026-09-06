<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records what the platform has promised out of each storage pool.
 *
 * Shared storage — a Ceph pool, an NFS export — is visible from every node in
 * the cluster, and its capacity was being counted into each node's own figure.
 * Three nodes seeing a 1 TiB pool made the scheduler believe in 3 TiB, and the
 * fleet oversold the pool by exactly the number of nodes that could reach it.
 * The symptom arrives as customer machines failing to start on a full datastore,
 * which reads as a storage fault rather than a control-plane one.
 *
 * Committed capacity therefore lives on the pool, where it is counted once, and
 * placement reserves against it under a row lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compute_storages', function (Blueprint $table): void {
            $table->unsignedBigInteger('committed_gib')->default(0)->after('available_gib');
        });
    }

    public function down(): void
    {
        Schema::table('compute_storages', function (Blueprint $table): void {
            $table->dropColumn('committed_gib');
        });
    }
};
