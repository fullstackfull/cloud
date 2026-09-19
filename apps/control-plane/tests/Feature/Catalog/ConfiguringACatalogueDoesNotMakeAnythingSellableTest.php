<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\ProductReadiness\Application\Services\ProductSellability;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product as ReadinessProduct;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductSoftwareState;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An operator can describe anything. Describing it sells nothing.
 *
 * ===========================================================================
 * THE HAZARD E-11'S FIX INTRODUCES
 * ===========================================================================
 *
 * Giving operators a write path to the catalogue creates a route that did not
 * exist before: configure a product, add a plan, set a price, and expect the
 * thing to be on sale. For the five approved products that is only ever as
 * true as readiness says. For WordPress and Domains it must never be true at
 * all — both are `Prepared`, both have software, and neither has a
 * production-capable adapter for what it requires.
 *
 * ===========================================================================
 * WHERE THE LINE ACTUALLY IS
 * ===========================================================================
 *
 * Not in a check somebody remembered to write in the new endpoint. In the
 * type: {@see ProductKind} has three cases — vps, dedicated, shared_hosting —
 * and there is no request body that produces a fourth. WordPress and Domains
 * are readiness rows and have no catalogue kind, so "create a WordPress
 * product and price it" is not a thing the API can be asked to do.
 *
 * And for the three kinds that do exist, sellability is decided by
 * {@see ProductSellability}, which reads the software state and the readiness
 * row. It has never read a catalogue row and this test pins that: a fully
 * configured VPS offering, in a production environment, with no provider
 * registered, is still not sellable.
 */
final class ConfiguringACatalogueDoesNotMakeAnythingSellableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @return list<array{string}>
     */
    public static function preparedProducts(): array
    {
        return [['wordpress'], ['domains'], ['cdn'], ['object_storage'], ['gpu_compute'], ['email_hosting'], ['managed_kubernetes']];
    }

    #[Test]
    #[DataProvider('preparedProducts')]
    public function a_product_outside_the_deliverable_kinds_cannot_be_created(string $kind): void
    {
        // The premise: each of these is a real product in the readiness enum,
        // and none of them is Complete.
        $readiness = ReadinessProduct::from($kind);
        $this->assertNotSame(ProductSoftwareState::Complete, $readiness->softwareState());

        $this->actingAs($this->operator())
            ->postJson('/api/admin/catalogue/products', [
                'kind' => $kind,
                'slug' => 'a-'.str_replace('_', '-', $kind),
                'name' => ['en' => 'Thing', 'ar' => 'شيء'],
                'is_active' => true,
                'is_public' => true,
            ])
            ->assertUnprocessable()
            // This platform's own error contract, not Laravel's default
            // `errors` bag: a code, a sentence, and the fields under details.
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['fields' => ['kind']]]]);

        $this->assertSame(0, Product::query()->count());
    }

    #[Test]
    public function the_deliverable_kinds_are_exactly_the_three_a_build_path_exists_for(): void
    {
        $this->assertSame(
            ['vps', 'dedicated', 'shared_hosting'],
            array_map(static fn (ProductKind $kind): string => $kind->value, ProductKind::cases()),
        );

        // Each one names a readiness product that is Complete. If a kind were
        // ever added for something Prepared, this is what would say so.
        foreach (ProductKind::cases() as $kind) {
            $this->assertSame(
                ProductSoftwareState::Complete,
                ReadinessProduct::from($kind->value)->softwareState(),
                $kind->value.' is sellable as a catalogue kind but its software is not complete.',
            );
        }
    }

    #[Test]
    public function a_fully_configured_offering_is_still_not_sellable_in_production(): void
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
            'recurring_amount_minor' => 15_000,
            'is_active' => true,
        ])->assertCreated();

        /*
         * Production, because readiness is only enforced there — outside it
         * the platform lets a Complete product be rehearsed. Asserting in the
         * test environment would assert the rehearsal rather than the gate.
         */
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->assertFalse(
            app(ProductSellability::class)->maySell(ReadinessProduct::Vps),
            'A catalogue entry made a product sellable. Readiness is meant to decide that, not the price list.',
        );
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::BillingAdmin->value]);

        return $user;
    }
}
