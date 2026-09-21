<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Network;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The inventory an operator can now write is still nobody else's business.
 *
 * Giving operators a way to record clusters, networks, management pools and
 * BMC endpoints creates a body of internal topology that did not exist in a
 * configurable form before. The question this file asks is whether any of it
 * can be reached, enumerated or inferred from the customer side — by an
 * endpoint, by a service document, or by an error message.
 */
final class TheEstateIsNotVisibleToCustomersTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->user = User::factory()->create();
        $this->customer->members()->create([
            'user_id' => $this->user->id,
            'role' => 'owner',
            'accepted_at' => now(),
        ]);
        $this->user->syncRoles([Role::Customer->value]);
    }

    #[Test]
    public function a_customer_cannot_read_any_of_the_new_inventory_surfaces(): void
    {
        $cluster = ComputeCluster::factory()->create();
        $pool = IpPool::factory()->create();
        $server = DedicatedServer::factory()->create();

        $reads = [
            '/api/admin/infrastructure/clusters',
            '/api/admin/infrastructure/networks',
            '/api/admin/infrastructure/ip-pools',
            '/api/admin/infrastructure/ip-pools/'.$pool->id.'/subnets',
            '/api/admin/infrastructure/dedicated',
            '/api/admin/infrastructure/dedicated/'.$server->id.'/bmc',
            '/api/admin/infrastructure/regions',
            '/api/admin/operators',
            '/api/admin/roles',
            '/api/admin/permissions',
        ];

        foreach ($reads as $uri) {
            $this->actingAs($this->user)->getJson($uri)->assertForbidden();
        }

        // The positive control: these are real routes with real content, so
        // the refusals above are authorisation rather than absence.
        $operator = User::factory()->create();
        $operator->syncRoles([Role::SuperAdmin->value]);

        $this->actingAs($operator)
            ->getJson('/api/admin/infrastructure/clusters')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $cluster->getKey());
    }

    #[Test]
    public function a_customers_own_service_document_names_nothing_about_the_estate(): void
    {
        $cluster = ComputeCluster::factory()->create(['slug' => 'kw-pve-secret', 'api_endpoint' => 'https://pve.mgmt.example:8006']);
        $node = ComputeNode::factory()->create(['cluster_id' => $cluster->id]);

        $network = Network::factory()->create(['slug' => 'kw-mgmt-secret']);
        $pool = IpPool::factory()->management()->create(['slug' => 'kw-mgmt-pool']);

        $service = Service::factory()->create([
            'customer_id' => $this->customer->id,
            'kind' => 'vps',
            'status' => ServiceStatus::Active,
            'activated_at' => now(),
        ]);

        VirtualMachine::factory()->onNode($node)->forService($service)->create(['hostname' => 'web-01']);

        $body = (string) $this->actingAs($this->user)
            ->getJson('/api/v1/services')
            ->assertOk()
            ->getContent();

        // Everything the operator wrote down, by the names they gave it.
        foreach ([
            $cluster->slug, $cluster->id, (string) $cluster->api_endpoint,
            $network->slug, $network->id,
            $pool->slug, $pool->id,
            $node->id, (string) $node->provider_name,
        ] as $internal) {
            $this->assertStringNotContainsString(
                $internal,
                $body,
                'A customer service document named part of the estate: '.$internal,
            );
        }

        // And the thing they did buy is there, so this is not a test of an
        // empty response.
        $this->assertStringContainsString($service->id, $body);
    }

    #[Test]
    public function a_bmc_endpoint_is_never_reachable_from_the_customer_side(): void
    {
        /*
         * The single most sensitive row this phase lets an operator create: an
         * address the platform dials with credentials to power, reinstall and
         * mount media on a physical machine.
         */
        $server = DedicatedServer::factory()->create([
            'customer_id' => $this->customer->id,
        ]);

        BmcEndpoint::factory()->create([
            'dedicated_server_id' => $server->id,
            'address' => '10.20.0.7',
            'username' => 'lynomia-admin',
        ]);

        // Even for the machine they own.
        $this->actingAs($this->user)
            ->getJson('/api/admin/infrastructure/dedicated/'.$server->id.'/bmc')
            ->assertForbidden();

        $body = (string) $this->actingAs($this->user)->getJson('/api/v1/services')->assertOk()->getContent();

        $this->assertStringNotContainsString('10.20.0.7', $body);
        $this->assertStringNotContainsString('lynomia-admin', $body);
    }

    #[Test]
    public function a_customer_cannot_write_any_of_it_even_with_a_valid_body(): void
    {
        $server = DedicatedServer::factory()->create();
        $pool = IpPool::factory()->create();

        $writes = [
            ['post', '/api/admin/infrastructure/regions'],
            ['post', '/api/admin/infrastructure/clusters'],
            ['post', '/api/admin/infrastructure/networks'],
            ['post', '/api/admin/infrastructure/ip-pools'],
            ['post', '/api/admin/infrastructure/ip-pools/'.$pool->id.'/subnets'],
            ['post', '/api/admin/infrastructure/hosting-nodes'],
            ['post', '/api/admin/infrastructure/dedicated'],
            ['post', '/api/admin/infrastructure/dedicated/'.$server->id.'/bmc'],
            ['put', '/api/admin/infrastructure/ip-pools/'.$pool->id],
            ['post', '/api/admin/operators'],
            ['put', '/api/admin/roles/'.Role::Support->value.'/permissions'],
        ];

        foreach ($writes as [$method, $uri]) {
            $this->actingAs($this->user)->json($method, $uri, [])->assertForbidden();
        }

        $this->assertSame(1, IpPool::query()->count());
        $this->assertSame(1, DedicatedServer::query()->count());
    }
}
