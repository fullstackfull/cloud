<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Database\Seeders\CatalogueSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Backups\Domain\ValueObjects\BackupPolicy;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A deployment can be told what it sells without a seeder, SQL or a code edit.
 *
 * ===========================================================================
 * THE GATE THIS IS
 * ===========================================================================
 *
 * E-11: nothing could write `products`, `plans`, `plan_prices` or
 * `hosting_packages` in production. `CatalogueSeeder` writes them and refuses
 * to run in production — correctly; a seeder that invents prices will
 * eventually invoice somebody for them — the browser seeder is for the browser
 * suite, and the reference topology is simulation. So a clean deployment could
 * model everything it sells and sell none of it, and the only routes forward
 * were raw SQL or a code change.
 *
 * This test starts from four empty tables and builds a working offering
 * through the supported operator API. It uses **no factory** for any catalogue
 * row, no `DB::table` insert and no seeder: a factory would prove that Eloquent
 * can write the table, which was never in doubt and was exactly what made the
 * gap invisible for so long. What was in doubt is whether a person can.
 *
 * If every production catalogue writer disappeared again, this fails — which
 * is the point of writing it as a bootstrap rather than as four endpoint
 * tests.
 *
 * ===========================================================================
 * WHAT IT DELIBERATELY DOES NOT ASSERT
 * ===========================================================================
 *
 * That anything became sellable. It did not, and it must not:
 * `REAL_INFRA_VERIFIED` and `REAL_HOSTING_VERIFIED` are NONE, no provider is
 * registered, and readiness is decided by the readiness engine rather than by
 * the catalogue. Configuration operability is the claim. Sellability is a
 * different one, and {@see APreparedProductCannotBeSoldByConfiguringItTest}
 * holds the line on it.
 */
final class AnEmptyProductionCatalogueCanBeBuiltByAnOperatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function an_operator_builds_a_shared_hosting_offering_from_nothing(): void
    {
        // The starting position, asserted rather than assumed: this is what a
        // freshly migrated deployment looks like.
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, Plan::query()->count());
        $this->assertSame(0, PlanPrice::query()->count());
        $this->assertSame(0, HostingPackage::query()->count());

        $operator = $this->operator();

        // 1. The product.
        $product = $this->actingAs($operator)->postJson('/api/admin/catalogue/products', [
            'kind' => 'shared_hosting',
            'slug' => 'web-hosting',
            'name' => ['en' => 'Web Hosting', 'ar' => 'استضافة المواقع'],
            'description' => ['en' => 'Shared hosting on managed nodes.', 'ar' => 'استضافة مشتركة.'],
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 10,
        ]);

        $product->assertCreated()
            ->assertJsonPath('data.kind', 'shared_hosting')
            ->assertJsonPath('data.slug', 'web-hosting')
            ->assertJsonPath('data.name.ar', 'استضافة المواقع');

        $productId = $product->json('data.id');

        // 2. The plan.
        $plan = $this->actingAs($operator)->postJson('/api/admin/catalogue/plans', [
            'product_id' => $productId,
            'slug' => 'web-hosting-starter',
            'name' => ['en' => 'Starter', 'ar' => 'المبتدئ'],
            'resources' => [
                'disk_quota_mib' => 10240,
                'bandwidth_quota_mib' => 512000,
                'max_databases' => 10,
            ],
            'is_active' => true,
            'is_public' => true,
        ]);

        $plan->assertCreated()->assertJsonPath('data.slug', 'web-hosting-starter');
        $planId = $plan->json('data.id');

        // 3. The price, in whole minor units. 9.000 KWD is 9000 fils.
        $priced = $this->actingAs($operator)->postJson('/api/admin/catalogue/plans/'.$planId.'/prices', [
            'currency' => 'KWD',
            'billing_period' => 'monthly',
            'recurring_amount_minor' => 9000,
            'setup_amount_minor' => 0,
            'is_active' => true,
        ]);

        $priced->assertCreated()
            ->assertJsonPath('data.prices.0.currency', 'KWD')
            ->assertJsonPath('data.prices.0.recurring_amount_minor', 9000)
            ->assertJsonPath('data.priced_in', ['KWD']);

        // 4. The package the panel will build the account under.
        $package = $this->actingAs($operator)->postJson('/api/admin/catalogue/hosting-packages', [
            'slug' => 'hosting-web-hosting-starter',
            'panel_package_name' => 'lyn_starter',
            'plan_id' => $planId,
            'disk_quota_mib' => 10240,
            'bandwidth_quota_mib' => 512000,
            'max_databases' => 10,
            'is_active' => true,
        ]);

        $package->assertCreated()
            ->assertJsonPath('data.panel_package_name', 'lyn_starter')
            ->assertJsonPath('data.mapped', true);

        // The offering exists, and it was built by a person through an API.
        $this->assertSame(1, Product::query()->count());
        $this->assertSame(1, Plan::query()->count());
        $this->assertSame(1, PlanPrice::query()->count());
        $this->assertSame(1, HostingPackage::query()->count());

        // The mapping the preflight has been failing on is now satisfiable.
        $this->actingAs($operator)->getJson('/api/admin/catalogue/hosting-packages')
            ->assertOk()
            ->assertJsonPath('meta.orderable', 1);

        // And every step left a trail.
        foreach ([
            AuditAction::CatalogueProductRecorded,
            AuditAction::CataloguePlanRecorded,
            AuditAction::CataloguePriceSet,
            AuditAction::CatalogueHostingPackageMapped,
        ] as $action) {
            $this->assertDatabaseHas('audit_log', ['action' => $action->value]);
        }
    }

    #[Test]
    public function a_vps_offering_is_built_the_same_way(): void
    {
        $operator = $this->operator();

        $product = $this->actingAs($operator)->postJson('/api/admin/catalogue/products', [
            'kind' => 'vps',
            'slug' => 'cloud-vps',
            'name' => ['en' => 'Cloud VPS', 'ar' => 'خادم افتراضي'],
            'is_active' => true,
            'is_public' => true,
        ])->assertCreated();

        $plan = $this->actingAs($operator)->postJson('/api/admin/catalogue/plans', [
            'product_id' => $product->json('data.id'),
            'slug' => 'cloud-vps-1',
            'name' => ['en' => 'VPS 1', 'ar' => 'خادم ١'],
            'resources' => ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 80],
            'is_active' => true,
            'is_public' => true,
        ])->assertCreated();

        $this->actingAs($operator)->postJson('/api/admin/catalogue/plans/'.$plan->json('data.id').'/prices', [
            'currency' => 'KWD',
            'billing_period' => 'monthly',
            'recurring_amount_minor' => 15000,
            'is_active' => true,
        ])->assertCreated();

        $this->actingAs($operator)->getJson('/api/admin/catalogue/plans')
            ->assertOk()
            ->assertJsonPath('meta.unpriced', 0);
    }

    #[Test]
    public function a_backup_policy_is_part_of_the_plan_an_operator_records(): void
    {
        /*
         * Backups is an approved Complete product and has no catalogue kind of
         * its own, which raises the fair question of whether it can be
         * configured at all through these endpoints.
         *
         * It can, because it is not sold as a separate line: BackupPolicy is
         * read from the plan's resources, and the Backups module references
         * addons nowhere. So a VPS plan recorded here carries its own backup
         * terms, and the resources document is stored exactly as given rather
         * than filtered down to the keys this endpoint happens to know about.
         *
         * That last part is what this asserts. A write path that kept only the
         * keys it validated would silently drop every product-specific setting
         * a plan carries, and the first symptom would be backups retained for
         * the default seven days on a plan that sold thirty.
         */
        $operator = $this->operator();

        $product = $this->actingAs($operator)->postJson('/api/admin/catalogue/products', [
            'kind' => 'vps',
            'slug' => 'backed-up-vps',
            'name' => ['en' => 'Backed-up VPS', 'ar' => 'خادم بنسخ احتياطي'],
            'is_active' => true,
            'is_public' => true,
        ])->assertCreated();

        $plan = $this->actingAs($operator)->postJson('/api/admin/catalogue/plans', [
            'product_id' => $product->json('data.id'),
            'slug' => 'backed-up-vps-1',
            'name' => ['en' => 'VPS 1', 'ar' => 'خادم ١'],
            'resources' => [
                'vcpu' => 2,
                'memory_mib' => 4096,
                'disk_gib' => 80,
                'backup_retention_days' => 30,
                'backup_max_retained' => 10,
            ],
            'is_active' => true,
            'is_public' => true,
        ])->assertCreated();

        $policy = BackupPolicy::fromPlanResources(
            (array) Plan::query()->findOrFail($plan->json('data.id'))->resources,
        );

        $this->assertSame(30, $policy->retentionDays);
        $this->assertSame(10, $policy->maxRetained);
    }

    #[Test]
    public function the_development_seeder_is_not_the_route_and_still_refuses_production(): void
    {
        /*
         * The other half of the claim. The gap was never "no code can write
         * these tables" — a seeder could. It was that the only thing which
         * could refuses to run where it matters, for a good reason, and
         * nothing replaced it. That refusal stays.
         */
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->expectExceptionMessageMatches('/must never run in production/');

        (new CatalogueSeeder)->run();
    }

    private function operator(Role $role = Role::BillingAdmin): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }
}
