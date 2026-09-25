<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F-29, over the routes: a correct rule is worth nothing on a road that does
 * not ask it.
 *
 * Each case here is a value an operator types into the Control Center that the
 * platform later dials with a credential attached, and each was written to the
 * database without the endpoint policy having seen the part that is dialled.
 *
 *   - A hosting node's `hostname`. `WhmConnection::forNode()` and
 *     `DirectAdminConnection::forNode()` dial `https://{hostname}:{port}` with
 *     the panel's root token when the row has no `api_endpoint`, and neither
 *     the create road nor the edit road asked the policy about the hostname.
 *   - A provider endpoint whose host `parse_url` rewrites: the policy judged
 *     `169.254.169.254_` while the row kept the bytes that reach the socket.
 *   - A BMC address written with a separate port: the two were joined with a
 *     colon, so an IPv6 address plus its port was judged as a different IPv6
 *     address.
 */
final class EveryRoadToADialledAddressAsksThePolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Hostnames that are each this host, a metadata service, or a spelling of
     * one.
     *
     * @var list<string>
     */
    private const array POISONED_HOSTNAMES = [
        '169.254.169.254',
        '127.0.0.1',
        'localhost',
        '0x7f000001',
        'metadata.google.internal',
        '169.254.169.254.',
        '169。254。169。254',
        'vault.internal',
    ];

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->operator = User::factory()->create();
        $this->operator->syncRoles([Role::SuperAdmin->value]);
    }

    #[Test]
    public function a_hosting_node_whose_hostname_is_what_gets_dialled_cannot_be_registered_with_a_poisoned_one(): void
    {
        $datacenter = Datacenter::factory()->create();

        foreach (self::POISONED_HOSTNAMES as $index => $hostname) {
            $this->actingAs($this->operator)
                ->postJson('/api/admin/infrastructure/hosting-nodes', [
                    'datacenter_id' => $datacenter->getKey(),
                    'slug' => sprintf('poisoned-%d', $index),
                    'hostname' => $hostname,
                    'panel' => HostingPanel::Cpanel->value,
                ])
                ->assertConflict()
                ->assertJsonPath('error.code', 'endpoint_refused');
        }

        $this->assertDatabaseCount('hosting_nodes', 0);

        // The control: an ordinary name is registered, so the refusals above
        // are about the names and not about the road.
        $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/hosting-nodes', [
                'datacenter_id' => $datacenter->getKey(),
                'slug' => 'kw-web-1',
                'hostname' => 'web1.lynomia-hosting.net',
                'panel' => HostingPanel::Cpanel->value,
            ])
            ->assertCreated();
    }

    #[Test]
    public function a_hosting_node_hostname_cannot_be_edited_into_a_poisoned_one(): void
    {
        $node = HostingNode::factory()->panel(HostingPanel::Cpanel)->create([
            'hostname' => 'web2.lynomia-hosting.net',
            'api_endpoint' => null,
        ]);

        foreach (self::POISONED_HOSTNAMES as $hostname) {
            $this->actingAs($this->operator)
                ->putJson('/api/admin/infrastructure/hosting-nodes/'.$node->getKey(), ['hostname' => $hostname])
                ->assertConflict()
                ->assertJsonPath('error.code', 'endpoint_refused');
        }

        $this->assertSame('web2.lynomia-hosting.net', $node->fresh()?->hostname);
    }

    #[Test]
    public function clearing_the_api_endpoint_asks_about_the_hostname_that_will_be_dialled_instead(): void
    {
        // A row that arrived by another road — a seeder, an import, SQL —
        // holding a hostname nobody asked about, harmless only while the
        // endpoint is set.
        $node = HostingNode::factory()->panel(HostingPanel::Cpanel)->create([
            'hostname' => '169.254.169.254',
            'api_endpoint' => 'https://web3.lynomia-hosting.net:2087',
        ]);

        $this->actingAs($this->operator)
            ->putJson('/api/admin/infrastructure/hosting-nodes/'.$node->getKey(), ['api_endpoint' => null])
            ->assertConflict()
            ->assertJsonPath('error.code', 'endpoint_refused');

        $this->assertSame('https://web3.lynomia-hosting.net:2087', $node->fresh()?->api_endpoint);
    }

    #[Test]
    public function a_provider_endpoint_is_refused_for_the_bytes_that_were_written_not_the_host_a_parser_made_of_them(): void
    {
        foreach ([
            "https://169.254.169.254\n/latest/meta-data/",
            "https://169.254.169.254\t/",
            'https://169.254.169.254\\/',
        ] as $endpoint) {
            $this->actingAs($this->operator)
                ->postJson('/api/admin/providers', [
                    'name' => 'dns-'.uniqid(),
                    'driver' => 'cloudflare',
                    'category' => ProviderCategory::Dns->value,
                    'environment' => DeploymentEnvironment::Production->value,
                    'endpoint' => $endpoint,
                ])
                ->assertConflict()
                ->assertJsonPath('error.code', 'endpoint_refused');
        }

        $this->assertDatabaseCount('provider_instances', 0);
    }

    #[Test]
    public function a_bmc_address_and_its_port_are_judged_as_the_address_they_are(): void
    {
        foreach ([
            ['fd00:ec2::254', 80],
            ['::1', 623],
        ] as $index => [$address, $port]) {
            $server = DedicatedServer::factory()->create();

            $this->actingAs($this->operator)
                ->postJson('/api/admin/infrastructure/dedicated/'.$server->getKey().'/bmc', [
                    'protocol' => 'redfish',
                    'address' => $address,
                    'port' => $port,
                    'username' => sprintf('lynomia-ro-%d', $index),
                ])
                ->assertConflict()
                ->assertJsonPath('error.code', 'endpoint_refused');
        }

        $this->assertDatabaseCount('bmc_endpoints', 0);

        // A public IPv6 BMC with a port is an ordinary thing to have.
        $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/dedicated/'.DedicatedServer::factory()->create()->getKey().'/bmc', [
                'protocol' => 'redfish',
                'address' => '2001:4860:4860::8888',
                'port' => 8443,
            ])
            ->assertCreated();
    }
}
