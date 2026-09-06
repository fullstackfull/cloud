<?php

declare(strict_types=1);

namespace Tests\Feature\Compute\Concerns;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;

/**
 * Fixtures for tests that cannot use RefreshDatabase.
 *
 * A concurrency test needs two connections to see the same rows, which means
 * the rows have to be genuinely committed rather than held inside the
 * transaction RefreshDatabase wraps every test in. Committed rows have to be
 * removed for real, so the wipe is part of the deal.
 */
trait CreatesComputeFleet
{
    private ?ComputeCluster $fleetCluster = null;

    protected function cluster(): ComputeCluster
    {
        return $this->fleetCluster ??= ComputeCluster::factory()->create();
    }

    /**
     * Children first: the foreign keys are what would otherwise refuse.
     */
    protected function wipe(): void
    {
        $this->fleetCluster = null;

        foreach ([
            'virtual_machines',
            'compute_storages',
            'compute_nodes',
            'vm_templates',
            'compute_clusters',
            'datacenters',
            'regions',
            // Ownership too: anti-affinity is a fact about a customer's
            // services, so a test of it commits rows in those tables and has
            // to take them away again.
            'services',
            'customers',
        ] as $table) {
            DB::connection((string) config('database.default'))->table($table)->delete();
        }
    }
}
