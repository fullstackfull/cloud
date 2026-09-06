<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use Database\Seeders\CatalogueSeeder;
use Database\Seeders\DevelopmentSeeder;
use Database\Seeders\InfrastructureSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Compute\Domain\Enums\ComputeDriver;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The development fixtures are the first thing a new contributor runs and the
 * thing a clean-room installation is verified against, so they get the same
 * treatment as application code.
 *
 * Two properties matter more than the row counts: the fixtures must be
 * internally consistent (a plan that cannot be fulfilled is worse than no plan),
 * and they must never reach production.
 */
final class DevelopmentFixturesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_development_seeder_produces_a_catalogue_that_the_inventory_can_actually_fulfil(): void
    {
        $this->seedDevelopmentFixtures();

        // Every dedicated plan names a hardware profile, and every one of those
        // profiles has free machines behind it. A plan whose profile matches
        // nothing is a product that can be bought and never delivered — the
        // dedicated reservation matches on exactly this string.
        $profiles = Plan::query()
            ->whereRelation('product', 'kind', ProductKind::Dedicated->value)
            ->get()
            ->map(fn (Plan $plan): mixed => $plan->resources['hardware_profile'] ?? null);

        $this->assertNotEmpty($profiles, 'The catalogue has no dedicated plans to check.');

        foreach ($profiles as $profile) {
            $this->assertIsString($profile, 'A dedicated plan does not name a hardware profile.');
            $this->assertGreaterThan(
                0,
                DedicatedServer::query()->where('hardware_profile', $profile)->count(),
                sprintf('Dedicated plan profile "%s" matches no machine in the inventory.', $profile),
            );
        }

        // Every VPS plan asks for a storage class the cluster actually offers.
        $classes = ComputeStorage::query()->pluck('storage_class')->map->value->all();

        foreach (Plan::query()->whereRelation('product', 'kind', ProductKind::Vps->value)->get() as $plan) {
            $required = $plan->placement_constraints['storage_class'] ?? null;

            if ($required !== null) {
                $this->assertContains(
                    $required,
                    $classes,
                    sprintf('VPS plan "%s" requires storage class "%s", which no pool provides.', $plan->slug, $required),
                );
            }
        }

        // Every plan is purchasable in the platform's default currency.
        foreach (Plan::query()->get() as $plan) {
            $this->assertTrue(
                PlanPrice::query()
                    ->where('plan_id', $plan->getKey())
                    ->where('currency', config('billing.default_currency'))
                    ->where('is_active', true)
                    ->exists(),
                sprintf('Plan "%s" has no active price in the default currency.', $plan->slug),
            );
        }

        // And there are addresses to hand out.
        $this->assertGreaterThan(
            0,
            IpAddress::query()->where('status', IpAddressStatus::Available)->count(),
            'The seeded subnet was never expanded, so nothing can be allocated.',
        );
    }

    #[Test]
    public function no_seeded_endpoint_points_at_a_real_provider(): void
    {
        $this->seedDevelopmentFixtures();

        // A development database that names a real hypervisor or a real panel is
        // one misconfigured APP_ENV away from acting on somebody's production
        // estate. Both drivers must be the fake, and no credential reference may
        // be recorded for either.
        foreach (ComputeCluster::query()->get() as $cluster) {
            $this->assertSame(ComputeDriver::Fake, $cluster->driver);
            $this->assertNull($cluster->api_endpoint);
            $this->assertNull($cluster->credentials_reference);
        }

        foreach (HostingNode::query()->get() as $node) {
            $this->assertSame(HostingPanel::Fake, $node->panel);
            $this->assertNull($node->credentials_reference);
        }

        // No out-of-band management endpoint is seeded at all: a BMC row carries
        // an address on a management network and a credential reference, and a
        // development database has no business holding either.
        $this->assertSame(0, BmcEndpoint::query()->count());
    }

    #[Test]
    public function seeding_twice_changes_nothing(): void
    {
        $this->seedDevelopmentFixtures();

        $before = $this->fixtureCounts();

        $this->seed(DevelopmentSeeder::class);

        $this->assertSame(
            $before,
            $this->fixtureCounts(),
            'Re-seeding duplicated fixtures; the seeders are not idempotent.',
        );
    }

    #[Test]
    public function every_development_seeder_refuses_to_run_in_production(): void
    {
        // Each one refuses on its own rather than relying on DevelopmentSeeder
        // to gate them, because `db:seed --class` addresses them directly.
        foreach ([DevelopmentSeeder::class, CatalogueSeeder::class, InfrastructureSeeder::class] as $seeder) {
            app()['env'] = 'production';

            try {
                app($seeder)->run();
                $this->fail(sprintf('%s ran in production.', class_basename($seeder)));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('must never run in production', $e->getMessage());
            } finally {
                app()['env'] = 'testing';
            }
        }
    }

    /**
     * Seed the way a real environment does: roles and permissions first, because
     * the development accounts are granted roles that must already exist.
     */
    private function seedDevelopmentFixtures(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DevelopmentSeeder::class);
    }

    /**
     * @return array<string, int>
     */
    private function fixtureCounts(): array
    {
        return [
            'products' => Product::query()->count(),
            'plans' => Plan::query()->count(),
            'prices' => PlanPrice::query()->count(),
            'addresses' => IpAddress::query()->count(),
            'servers' => DedicatedServer::query()->count(),
            'storages' => ComputeStorage::query()->count(),
        ];
    }
}
