<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Application\Services\LocalPlacementFeasibility;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
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
 * At the point where the platform's own local feasibility rule stops refusing.
 * That is the honest end of this phase: it proves a production configuration
 * path exists, and it proves nothing whatever about whether a hypervisor
 * answers, a panel is licensed, or a card can be charged. Configured is an
 * operator's statement; verified is a provider's, and no provider has been
 * asked anything here.
 */
final class AFreshDeploymentBecomesConfigurableTest extends TestCase
{
    use RefreshDatabase;

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

        $this->created('/api/admin/infrastructure/networks', $first, [
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
        $hosting = $feasibility->resolve(Plan::query()->with('product')->findOrFail($hostingPlan));

        $this->assertTrue(
            $vps->isFeasible(),
            'A VPS plan is still unplaceable after an operator configured the estate: '.($vps->blockedReason ?? ''),
        );
        $this->assertTrue(
            $hosting->isFeasible(),
            'A hosting plan is still unplaceable after an operator mapped its package: '
            .($hosting->blockedReason ?? ''),
        );

        // The cluster the placement resolved to is the one the operator made.
        $this->assertSame($cluster, $vps->values['cluster_id'] ?? null);

        // ---- 8. and none of it claims anything was contacted ---------------
        $this->assertNull(
            ComputeCluster::query()->sole()->last_synced_at,
            'A cluster nobody has spoken to is reporting a sync. Configured is not verified.',
        );
    }

    /**
     * A product, a plan and a price, through the catalogue endpoints that
     * already existed — this phase adds none of them.
     */
    private function catalogue(User $operator, ProductKind $kind, string $productSlug, string $planSlug): string
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
            'resources' => ['vcpu' => 1, 'memory_mib' => 2048, 'disk_gib' => 20, 'ipv4_count' => 1],
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
