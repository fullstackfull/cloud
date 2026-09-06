<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fixtures for tables IPAM depends on but does not own.
 *
 * provisioning_jobs belongs to the provisioning module. IPAM reads its status
 * column and holds a foreign key to it, so the tests need real rows — but
 * writing them with the query builder rather than through that module's model
 * keeps the dependency pointing one way, which is the same reason the reaper
 * reads the status as a string.
 */
trait CreatesIpamFixtures
{
    /**
     * @param  string  $status  A provisioning_jobs.status value. 'running' and 'queued'
     *                          are alive; 'failed' is terminal.
     */
    protected function createProvisioningJob(string $status = 'running', ?string $customerId = null): string
    {
        $id = (string) Str::ulid();

        DB::table('provisioning_jobs')->insert([
            'id' => $id,
            'customer_id' => $customerId,
            'idempotency_key' => 'test-'.Str::lower(Str::random(16)),
            'kind' => 'create_vps',
            'provider' => 'fake',
            'status' => $status,
            'attempts' => 1,
            'max_attempts' => 3,
            'timeout_seconds' => 900,
            'payload' => json_encode(['plan' => 'cx-2'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
