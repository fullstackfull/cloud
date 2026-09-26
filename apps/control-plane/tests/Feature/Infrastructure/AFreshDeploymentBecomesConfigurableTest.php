<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Sleep;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\PxeAuthorisationStatus;
use Lynomia\Modules\Dedicated\Infrastructure\DedicatedProviderFactory;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\PxeBootAuthorisation;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Application\Services\LocalPlacementFeasibility;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The whole thing, from an empty database, with nothing but supported paths.
 *
 * ---------------------------------------------------------------------------
 * What this is a rehearsal of
 * ---------------------------------------------------------------------------
 *
 * Somebody has just run the migrations on a new deployment. They have a shell
 * and nothing else: no account, no estate, no catalogue. This test is the
 * sequence they would actually follow, and every step of it is a console
 * command an operator runs or an HTTP request an operator makes.
 *
 * Nothing here uses a factory for anything it is proving, no seeder runs
 * except the one a production deployment runs — roles and permissions, which
 * create no account and no inventory — and no query writes. The reference
 * topology, which is a model of an estate and says so in its own banner, is
 * never loaded.
 *
 * ---------------------------------------------------------------------------
 * Where it stops
 * ---------------------------------------------------------------------------
 *
 * It used to stop where the platform's own local feasibility rule stopped
 * refusing, and its name claimed more than that: it was green while every VPS
 * and Dedicated build on the estate it made was refused `ipam.pool_exhausted`
 * — the operator's subnet route wrote no address rows — and while a Dedicated
 * build had no OS install profile anything could write (F-02, and the
 * re-audit's note that this test's subject was narrower than its name).
 *
 * So it now goes on to what "could sell" means: a customer buys a VPS, a
 * Dedicated server and a Shared Hosting account on the estate the operator
 * built, pays, and each is built. The provider boundary is the simulator — a
 * controlled hypervisor swapped in for the Proxmox cluster the operator
 * registered, the controlled BMC for the machine's controller, and a
 * controlled panel for the cPanel node — and the operator's own sweeps
 * (`infrastructure:reconcile`, `dedicated:sync-inventory`,
 * `hosting:sync-nodes`) are what read the estate from them, as they would
 * from the real things. Everything above that boundary is the real code.
 *
 * The customer and the payment come from factories: they are the input to
 * the build, not what is being proven. It proves nothing about whether a real
 * hypervisor answers, a real panel is licensed, or a card can be charged.
 * Configured is an operator's statement; verified is a provider's, and no
 * real provider has been asked anything here.
 */
final class AFreshDeploymentBecomesConfigurableTest extends TestCase
{
    use RefreshDatabase;

    private string $fleetPath = '';

    #[Test]
    public function an_empty_deployment_is_brought_to_the_point_where_it_could_sell(): void
    {
        Notification::fake();

        // ---- 0. what a production deployment actually starts with ----------
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(0, User::query()->count());
        $this->assertSame(
            0,
            User::query()->role(Role::SuperAdmin->value)->count(),
            'Somebody can already administer this deployment; the rehearsal starts in the wrong place.',
        );

        // ---- 1. the one thing that cannot happen inside the platform -------
        $this->artisan('operator:bootstrap', [
            'email' => 'ops@lynomia.test',
            '--name' => 'Deployment Operator',
        ])->assertSuccessful();

        /** @var User $first */
        $first = User::query()->where('email', 'ops@lynomia.test')->sole();

        // ---- 2. and it closes behind itself --------------------------------
        $this->artisan('operator:bootstrap', ['email' => 'someone-else@lynomia.test'])->assertFailed();
        $this->assertSame(1, User::query()->role(Role::SuperAdmin->value)->count());

        // ---- 3. the operator takes the account over and signs in -----------
        $this->postJson('/api/v1/password/reset', [
            'token' => Password::broker()->createToken($first),
            'email' => 'ops@lynomia.test',
            'password' => 'Deployment-Day-2026!',
            'password_confirmation' => 'Deployment-Day-2026!',
        ])->assertSuccessful();

        $this->postJson('/api/v1/login', [
            'email' => 'ops@lynomia.test',
            'password' => 'Deployment-Day-2026!',
        ])->assertSuccessful();

        // ---- 4. a second operator, from inside the platform ----------------
        $this->actingAs($first)
            ->postJson('/api/admin/operators', [
                'email' => 'noc@lynomia.test',
                'name' => 'NOC Shift',
                'roles' => [Role::Noc->value],
            ])
            ->assertCreated();

        $this->assertTrue(
            User::query()->where('email', 'noc@lynomia.test')->sole()->hasRole(Role::Noc->value),
            'The first operator could not create the second, which is the two-super-admins dead end.',
        );

        // ---- 5. the estate, parent before child ----------------------------
        $region = $this->created('/api/admin/infrastructure/regions', $first, [
            'slug' => 'kw-central',
            'name' => ['en' => 'Kuwait Central', 'ar' => 'الكويت الوسطى'],
            'country' => 'KW',
            'city' => 'Kuwait City',
        ]);

        $datacenter = $this->created('/api/admin/infrastructure/datacenters', $first, [
            'region_id' => $region,
            'slug' => 'kw-dc-1',
            'name' => 'Kuwait DC 1',
        ]);

        $cluster = $this->created('/api/admin/infrastructure/clusters', $first, [
            'datacenter_id' => $datacenter,
            'slug' => 'kw-pve-1',
            'name' => 'Kuwait Proxmox 1',
            'driver' => 'proxmox',
            'api_endpoint' => 'https://pve.mgmt.example:8006',
        ]);

        $this->created('/api/admin/infrastructure/templates', $first, [
            'cluster_id' => $cluster,
            'slug' => 'ubuntu-lts',
            'name' => ['en' => 'Ubuntu LTS', 'ar' => 'أوبنتو إل تي إس'],
            'os_family' => 'ubuntu',
            'os_version' => '24.04',
            'architecture' => 'x86_64',
            'provider_reference' => 'local:vztmpl/ubuntu-24.04',
            'cloud_init' => true,
            'guest_agent' => true,
            'requires_licence' => false,
        ]);

        $network = $this->created('/api/admin/infrastructure/networks', $first, [
            'datacenter_id' => $datacenter,
            'slug' => 'kw-public-1',
            'name' => 'Kuwait Public 1',
            'purpose' => 'public',
            'vlan_id' => 100,
            'bridge' => 'vmbr0',
            'is_customer_facing' => true,
        ]);

        $pool = $this->created('/api/admin/infrastructure/ip-pools', $first, [
            'datacenter_id' => $datacenter,
            'slug' => 'kw-public-v4',
            'name' => 'Kuwait Public IPv4',
            'ip_version' => 4,
            'scope' => 'public',
        ]);

        $this->created('/api/admin/infrastructure/ip-pools/'.$pool.'/subnets', $first, [
            'cidr' => '203.0.113.0/24',
            'gateway' => '203.0.113.1',
            // The segment a machine given one of these addresses is attached
            // to: a build refuses an address whose subnet names no bridge.
            'network_id' => $network,
        ]);

        /*
         * A management pool beside it, because a real estate has one and
         * because it is the case that must not quietly become capacity: the
         * placement rule counts customer-allocatable pools, so a second pool
         * of the wrong kind would either be picked or make the answer
         * ambiguous. Neither may happen.
         */
        $this->created('/api/admin/infrastructure/ip-pools', $first, [
            'datacenter_id' => $datacenter,
            'slug' => 'kw-mgmt-v4',
            'name' => 'Kuwait Management',
            'ip_version' => 4,
            'scope' => 'management',
        ]);

        $this->created('/api/admin/infrastructure/hosting-nodes', $first, [
            'datacenter_id' => $datacenter,
            'slug' => 'kw-web-1',
            'hostname' => 'web1.mgmt.example',
            'panel' => 'cpanel',
            'max_accounts' => 400,
        ]);

        $server = $this->created('/api/admin/infrastructure/dedicated', $first, [
            'datacenter_id' => $datacenter,
            'manufacturer' => 'Supermicro',
            'model' => 'SYS-1029P',
            'serial' => 'SM-KW-0001',
            'hardware_profile' => 'ded-standard-1',
        ]);

        $this->created('/api/admin/infrastructure/dedicated/'.$server.'/bmc', $first, [
            'protocol' => 'redfish',
            'address' => '10.20.0.7',
            'username' => 'lynomia-ro',
        ]);

        // ---- 6. the catalogue, through the paths that already existed ------
        $vpsPlan = $this->catalogue($first, ProductKind::Vps, 'cloud-vps', 'cx-1');
        $hostingPlan = $this->catalogue($first, ProductKind::SharedHosting, 'shared-hosting', 'hosting-starter');

        $this->created('/api/admin/catalogue/hosting-packages', $first, [
            'slug' => 'hosting-starter-pkg',
            'panel_package_name' => 'lyn_starter',
            'plan_id' => $hostingPlan,
            'disk_quota_mib' => 20480,
            'is_active' => true,
        ]);

        // ---- 7. what the platform now says about all of it ------------------
        /** @var LocalPlacementFeasibility $feasibility */
        $feasibility = app(LocalPlacementFeasibility::class);

        $vps = $feasibility->resolve(Plan::query()->with('product')->findOrFail($vpsPlan));
        $hosting = $feasibility->resolveForSale(Plan::query()->with('product')->findOrFail($hostingPlan));

        $this->assertTrue(
            $vps->isFeasible(),
            'A VPS plan is still unplaceable after an operator configured the estate: '.($vps->blockedReason ?? ''),
        );
        /*
         * Hosting is not placeable yet, and that is the honest answer. The
         * node was registered with nothing claimed about it — `panel_licensed`
         * false until `hosting:sync-nodes` has asked the panel — and the
         * hosting scheduler excludes an unlicensed node, so the build would
         * refuse every account on it. Feasibility used to check only the
         * package and called this plan sellable; since F-07's round-three
         * repair it asks the scheduler, and the refusal it gives is the
         * build's own. The step that turns this green is a licence answer
         * from a real panel, which no test here may ask for.
         */
        $this->assertFalse(
            $hosting->isFeasible(),
            'A hosting plan was called placeable on a fleet whose only node has never had its licence confirmed.',
        );
        $this->assertStringContainsString('No hosting node can take an account', (string) $hosting->blockedReason);

        // The cluster the placement resolved to is the one the operator made.
        $this->assertSame($cluster, $vps->values['cluster_id'] ?? null);

        // ---- 8. and none of it claims anything was contacted ---------------
        $this->assertNull(
            ComputeCluster::query()->sole()->last_synced_at,
            'A cluster nobody has spoken to is reporting a sync. Configured is not verified.',
        );

        // ---- 9. the address space the operator registered can be handed out
        $this->assertGreaterThan(
            0,
            app(IpAllocator::class)->customerAllocatableCount(IpPool::query()->findOrFail($pool)),
            'The block the operator registered is not one the allocator can give a customer.',
        );

        // ---- 10. what a Dedicated build installs, and a plan to sell -------
        $this->created('/api/admin/infrastructure/os-install-profiles', $first, [
            'slug' => 'ubuntu-2404-standard',
            'name' => ['en' => 'Ubuntu 24.04 LTS', 'ar' => 'أوبنتو 24.04'],
            'os_family' => 'ubuntu',
            'os_version' => '24.04',
            'installer' => 'autoinstall',
            'template' => "#cloud-config\nautoinstall:\n  version: 1\n  identity:\n    hostname: {{ hostname }}\n"
                ."  network:\n    ethernets:\n      primary:\n        addresses: [{{ ipv4_address }}/{{ ipv4_prefix_length }}]\n"
                ."        gateway4: {{ ipv4_gateway }}\n  timezone: {{ timezone }}",
            'defaults' => ['timezone' => 'Asia/Kuwait'],
        ]);

        $dedicatedPlan = $this->catalogue($first, ProductKind::Dedicated, 'dedicated', 'ded-standard', [
            'hardware_profile' => 'ded-standard-1',
            'ipv4_count' => 1,
        ]);

        // ---- 11. the providers, as simulators, and the operator's sweeps ---
        $this->standInForTheProviders(ComputeCluster::query()->sole(), HostingNode::query()->sole());

        $this->artisan('infrastructure:reconcile')->assertSuccessful();

        // Discovery is not authorisation: the sweep records what it found in
        // maintenance, and the operator puts it into service.
        $discovered = ComputeNode::query()->where('cluster_id', $cluster)->get();
        $this->assertNotEmpty($discovered, 'The reconcile sweep found no node on the cluster the operator registered.');

        foreach ($discovered as $node) {
            $this->actingAs($first)
                ->putJson('/api/admin/infrastructure/nodes/'.$node->getKey().'/status', [
                    'status' => 'active',
                    'reason' => 'Cabled, patched and in monitoring on deployment day.',
                ])
                ->assertOk();
        }

        $this->artisan('dedicated:sync-inventory')->assertSuccessful();
        $this->artisan('hosting:sync-nodes')->assertSuccessful();

        $this->assertTrue(
            HostingNode::query()->sole()->panel_licensed,
            'The node the operator registered is not licensed after the panel said it was.',
        );

        // ---- 12. a customer buys all three, and pays -----------------------
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        $vps = $this->buy($customer, $vpsPlan);
        $dedicated = $this->buy($customer, $dedicatedPlan);
        $hosting = $this->buy($customer, $hostingPlan, domain: 'first-customer.example.test');

        // ---- 13. and each one is built on the estate the operator made -----
        $this->assertBuilt($vps, 'VPS');
        $this->assertBuilt($dedicated, 'Dedicated');
        $this->assertBuilt($hosting, 'Shared Hosting');

        $machine = VirtualMachine::query()->where('service_id', $vps->getKey())->sole();
        $this->assertSame($cluster, (string) $machine->cluster_id);

        $server = DedicatedServer::query()->findOrFail($server);
        $this->assertSame(DedicatedServerStatus::Active, $server->status);
        $this->assertSame((string) $dedicated->getKey(), (string) $server->service_id);

        $account = HostingAccount::query()->where('service_id', $hosting->getKey())->sole();
        $this->assertSame(HostingAccountStatus::Active, $account->status);
        $this->assertSame((string) HostingNode::query()->sole()->getKey(), (string) $account->hosting_node_id);

        // Both machines came up on addresses out of the operator's /24, and
        // neither on its network, gateway or broadcast address.
        $given = IpAddress::query()
            ->whereIn('id', IpAssignment::query()->select('ip_address_id'))
            ->pluck('address')
            ->all();

        sort($given);
        $this->assertCount(2, $given);

        foreach ($given as $address) {
            $this->assertStringStartsWith('203.0.113.', $address);
            $this->assertNotContains($address, ['203.0.113.0', '203.0.113.1', '203.0.113.255']);
        }
    }

    /**
     * The simulators, standing where the operator's providers would.
     *
     * One adapter factory for each kind for the whole test, the way a worker
     * holds one — so the hypervisor the reconcile sweep read is the one the
     * build creates on. The controlled panel answers its licence from its own
     * side, never from the node row, so the node the operator registered is
     * licensed once `hosting:sync-nodes` has asked it; and the controlled
     * hypervisor's default fleet reports its storage totals from its pools,
     * as a Proxmox node does.
     */
    private function standInForTheProviders(ComputeCluster $cluster, HostingNode $node): void
    {
        $this->fleetPath = sys_get_temp_dir().'/lynomia-fresh-'.getmypid().'-'.uniqid().'.state';
        config()->set('compute.fake.state_path', $this->fleetPath);
        config()->set('dedicated.provider', 'fake');

        $this->app->singleton(ComputeProviderFactory::class);
        $this->app->singleton(DedicatedProviderFactory::class);
        $this->app->singleton(HostingProviderFactory::class);

        app(ComputeProviderFactory::class)->swap($cluster, new FakeComputeProvider);
        app(HostingProviderFactory::class)->swap($node, new FakeHostingProvider);

        // Stand in for the boot server, which marks an install finished when
        // the installer reports success.
        PxeBootAuthorisation::created(static function (PxeBootAuthorisation $authorisation): void {
            $authorisation->forceFill([
                'status' => PxeAuthorisationStatus::Completed,
                'booted_at' => now(),
                'completed_at' => now(),
            ])->saveQuietly();
        });

        Sleep::fake();
    }

    private function buy(Customer $customer, string $plan, ?string $domain = null): Service
    {
        $order = app(PlaceOrder::class)->execute(
            $customer,
            new CheckoutRequest(
                lines: [new CheckoutLine($plan, 1, $domain)],
                billingPeriod: BillingPeriod::Monthly,
                couponCode: null,
                idempotencyKey: null,
            ),
        );

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('order_id', $order->getKey())->sole();

        $transaction = Transaction::factory()->create([
            'customer_id' => $customer->getKey(),
            'invoice_id' => $invoice->getKey(),
            'amount_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
        ]);

        app(SettleInvoice::class)->execute($invoice, $transaction);

        return Service::query()->where('order_id', $order->getKey())->sole();
    }

    private function assertBuilt(Service $service, string $what): void
    {
        $service->refresh();

        $job = ProvisioningJob::query()->where('service_id', $service->getKey())->first();

        $this->assertSame(
            ServiceStatus::Active,
            $service->status,
            sprintf(
                'The %s bought on the estate the operator built was not built: service %s, job %s, %s.',
                $what,
                $service->status->value,
                $job?->status->value ?? 'none',
                ($job?->last_error ?? '').' '.json_encode($job?->result ?? $service->resources),
            ),
        );
    }

    protected function tearDown(): void
    {
        if ($this->fleetPath !== '' && is_file($this->fleetPath)) {
            @unlink($this->fleetPath);
        }

        parent::tearDown();
    }

    /**
     * A product, a plan and a price, through the catalogue endpoints that
     * already existed — this phase adds none of them.
     */
    /**
     * @param  array<string, mixed>|null  $resources
     */
    private function catalogue(User $operator, ProductKind $kind, string $productSlug, string $planSlug, ?array $resources = null): string
    {
        $product = $this->created('/api/admin/catalogue/products', $operator, [
            'kind' => $kind->value,
            'slug' => $productSlug,
            'name' => ['en' => $productSlug, 'ar' => $productSlug],
            'is_active' => true,
            'is_public' => true,
        ]);

        $plan = $this->created('/api/admin/catalogue/plans', $operator, [
            'product_id' => $product,
            'slug' => $planSlug,
            'name' => ['en' => $planSlug, 'ar' => $planSlug],
            'resources' => $resources ?? ['vcpu' => 1, 'memory_mib' => 2048, 'disk_gib' => 20, 'ipv4_count' => 1],
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->created('/api/admin/catalogue/plans/'.$plan.'/prices', $operator, [
            'currency' => 'KWD',
            'billing_period' => 'monthly',
            'recurring_amount_minor' => 9000,
            'is_active' => true,
        ]);

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function created(string $uri, User $operator, array $payload): string
    {
        $response = $this->actingAs($operator)->postJson($uri, $payload);

        $response->assertCreated();

        return (string) $response->json('data.id');
    }
}
