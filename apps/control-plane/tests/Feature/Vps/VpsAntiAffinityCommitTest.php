<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lynomia\Modules\Compute\Domain\Enums\StorageClass;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Infrastructure\Models\Network;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Vps\Application\Handlers\CreateVpsHandler;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Anti-affinity has to survive the gap between scoring and committing.
 *
 * The scheduler excludes a node the customer is already on, but it does that
 * before any lock and against machines that had already been written — and
 * this handler writes its virtual_machines row only AFTER the hypervisor has
 * answered, so the window is the whole length of the provider call rather than
 * a millisecond. Three concurrent orders from one customer can therefore all
 * score against an empty node and all choose it, and the customer's redundant
 * cluster ends up behind one power supply.
 *
 * ReserveNodeCapacity re-counts under the node's row lock for exactly that
 * reason, and the limit the scheduler decided on has to travel from the
 * decision to that commitment or the re-count never runs.
 *
 * The race is reproduced deterministically: the competing machine is written
 * the moment the reservation takes the node's row lock, which is precisely
 * when a concurrent worker's machine would become visible.
 */
final class VpsAntiAffinityCommitTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_machine_that_appears_between_scoring_and_the_lock_is_refused_at_the_commit(): void
    {
        config()->set('compute.scheduler.max_customer_vms_per_node', 1);

        $cluster = ComputeCluster::factory()->create(['driver' => 'fake']);

        $node = ComputeNode::factory()->create([
            'cluster_id' => $cluster->id,
            'provider_name' => 'pve-01',
            'cpu_cores' => 32,
            'memory_mib' => 131072,
            'storage_gib' => 2048,
        ]);

        ComputeStorage::factory()->onNode($node)->create([
            'provider_name' => 'local-nvme',
            'storage_class' => StorageClass::Nvme,
            'total_gib' => 2048,
            'available_gib' => 2048,
        ]);

        $network = Network::factory()->create(['bridge' => 'vmbr1', 'vlan_id' => 1234]);
        $subnet = Subnet::factory()
            ->forBlock('198.51.100.8/29', gateway: '198.51.100.9')
            ->create(['network_id' => $network->getKey()]);
        app(SeedSubnetAddresses::class)->execute($subnet);

        $customer = Customer::factory()->create();

        $service = Service::factory()->create(['customer_id' => $customer->id, 'kind' => 'vps']);
        $rival = Service::factory()->create(['customer_id' => $customer->id, 'kind' => 'vps']);

        $job = ProvisioningJob::factory()->create([
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'kind' => 'create_vps',
            'provider' => 'fake',
            'status' => 'running',
            'payload' => [
                'cluster_id' => $cluster->id,
                'ip_pool_id' => $subnet->ipPool->id,
                'vcpu' => 2,
                'memory_mib' => 4096,
                'disk_gib' => 40,
                'hostname' => 'web-02',
            ],
        ]);

        /*
         * The other order's machine lands the instant this one takes the row
         * lock — after scoring saw an empty node, before the commitment is
         * written. That is the whole window, and the only defence against it
         * is the re-count under the lock.
         */
        $raced = false;

        DB::listen(function (QueryExecuted $query) use (&$raced, $node, $rival): void {
            if ($raced || ! str_contains($query->sql, 'compute_nodes') || ! str_contains(strtolower($query->sql), 'for update')) {
                return;
            }

            $raced = true;

            DB::table('virtual_machines')->insert([
                'id' => (string) Str::ulid(),
                'service_id' => $rival->getKey(),
                'cluster_id' => $node->cluster_id,
                'node_id' => $node->getKey(),
                'hostname' => 'web-01',
                'vcpu' => 2,
                'memory_mib' => 4096,
                'disk_gib' => 40,
                'power_state' => 'running',
                'has_drift' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $result = app(CreateVpsHandler::class)->execute($job);

        $this->assertTrue($raced, 'The reservation never took the node row lock, so the race was not reproduced.');

        $this->assertFalse(
            $result->successful,
            'The second machine for this customer was committed onto a node the anti-affinity limit had already filled.',
        );
        $this->assertSame(FailureClass::Capacity, $result->failureClass);
        $this->assertSame('compute.node_capacity_exceeded', $result->errorCode);
    }
}
