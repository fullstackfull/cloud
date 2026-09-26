<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use Database\Seeders\CatalogueSeeder;
use Database\Seeders\DevelopmentSeeder;
use Database\Seeders\InfrastructureSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Compute\Domain\Enums\ComputeDriver;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Shared\Domain\Services\ReferenceValues;
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
    public function every_seeded_hosting_plan_can_be_quoted_and_bought(): void
    {
        /*
         * Asked over HTTP, as the seeded customer, because that is how the
         * seeded catalogue is used and nothing else in the suite buys from it.
         *
         * The catalogue and the reference estate both map a package onto the
         * same three hosting plans. While the reference packages were loaded
         * on sale, every plan had two packages on sale and a purchase took
         * whichever row the heap returned first — sometimes a package from an
         * estate whose own header says nothing in it exists. The platform now
         * refuses to pick between two (F-32), so the reference packages load
         * withdrawn, and this is what says the demo catalogue is still for sale.
         */
        $this->seedDevelopmentFixtures();

        $customer = User::query()->where('email', 'customer@lynomia.local')->sole();

        $plans = Plan::query()
            ->whereRelation('product', 'kind', ProductKind::SharedHosting->value)
            ->orderBy('slug')
            ->get();

        $this->assertCount(3, $plans, 'The seeded catalogue no longer has its three hosting plans.');

        foreach ($plans as $plan) {
            $basket = [
                'items' => [['plan_id' => (string) $plan->getKey(), 'quantity' => 1, 'domain' => $plan->slug.'.example.test']],
                'billing_period' => BillingPeriod::Monthly->value,
            ];

            $this->actingAs($customer)
                ->postJson('/api/v1/orders/quote', $basket)
                ->assertOk();

            $this->actingAs($customer)
                ->withHeader('Idempotency-Key', 'seeded-'.$plan->slug)
                ->postJson('/api/v1/orders', $basket)
                ->assertCreated();
        }
    }

    #[Test]
    public function no_seeded_endpoint_points_at_a_real_provider(): void
    {
        $this->seedDevelopmentFixtures();

        /*
         * A development database that names a real hypervisor or a real panel is
         * one misconfigured APP_ENV away from acting on somebody's production
         * estate. Both drivers must be the fake, and no credential reference may
         * be recorded for either.
         *
         * The endpoint assertion changed in Gap 4 from "must be null" to "must
         * be null or a fake:// marker", and the second is not weaker. `fake://`
         * is this platform's own word for a controlled endpoint: it is the only
         * shape EndpointPolicy accepts for a controlled driver, no socket is
         * ever opened for one, and it says what it is — where a null endpoint
         * says nothing and reads as "not configured yet". Anything else,
         * including any URL, still fails.
         */
        foreach (ComputeCluster::query()->get() as $cluster) {
            $this->assertSame(ComputeDriver::Fake, $cluster->driver);
            $this->assertNull($cluster->credentials_reference);

            if ($cluster->api_endpoint !== null) {
                $this->assertMatchesRegularExpression(
                    '/^fake:\/\/[a-z0-9-]{1,60}$/',
                    $cluster->api_endpoint,
                    sprintf('Cluster "%s" has an endpoint that is not a controlled marker.', $cluster->slug),
                );
            }
        }

        foreach (HostingNode::query()->get() as $node) {
            $this->assertSame(HostingPanel::Fake, $node->panel);
            $this->assertNull($node->credentials_reference);
        }

        /*
         * Out-of-band management.
         *
         * This used to assert that no BMC row is seeded at all, because the old
         * seeder deliberately created none — the objection being that a BMC row
         * carries an address on a management network and a credential
         * reference, and a development database has no business holding either.
         *
         * Gap 4 models the BMC relationship, because a reference estate that
         * cannot express "this chassis has a BMC" cannot show an engineer what
         * Lynomia will ask them for. So the assertion now refuses the two things
         * that were actually objectionable, rather than the row that carried
         * them: no credential, not even half of one, and no address that could
         * be reached. That is a stronger statement than a count of zero, which
         * said nothing about a row somebody might add later.
         */
        foreach (BmcEndpoint::query()->get() as $bmc) {
            $this->assertNull($bmc->credentials_reference, 'A seeded BMC names a credential reference.');
            $this->assertNull($bmc->username, 'A seeded BMC names a username, which is half a credential.');
            $this->assertTrue(
                (new ReferenceValues)->isDocumentationAddress($bmc->address),
                sprintf('Seeded BMC address "%s" is not in a range reserved for documentation.', $bmc->address),
            );
        }
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
