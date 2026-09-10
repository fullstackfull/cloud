<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Browsing the catalogue over HTTP.
 *
 * The catalogue is the one part of the customer API that is the same for
 * everybody — the products exist once, not per tenant — which makes the three
 * things it must still get right easy to lose sight of:
 *
 *   what is on sale        inactive and unlisted rows are absent, not forbidden
 *   what it costs          the acting customer's currency, never converted
 *   what is not published  placement constraints describe the estate, not the
 *                          product
 */
final class CatalogueBrowsingTest extends TestCase
{
    use RefreshDatabase;

    private function member(Customer $customer, ?User $user = null): User
    {
        $user ??= User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        return $user;
    }

    private function customer(string $currency = 'KWD'): User
    {
        return $this->member(Customer::factory()->create(['currency' => $currency]));
    }

    private function plan(Product $product, array $attributes = []): Plan
    {
        return Plan::factory()->create(['product_id' => $product->id, ...$attributes]);
    }

    #[Test]
    public function the_listing_returns_the_products_on_sale(): void
    {
        $user = $this->customer();

        $product = Product::factory()->create([
            'slug' => 'cloud-vps',
            'name' => ['en' => 'Cloud VPS', 'ar' => 'خادم سحابي'],
        ]);
        $this->plan($product);
        $this->plan($product, ['is_public' => false]);

        $response = $this->actingAs($user)->getJson('/api/v1/catalog/products');

        $response->assertOk()
            ->assertJsonPath('data.0.slug', 'cloud-vps')
            ->assertJsonPath('data.0.name', 'Cloud VPS')
            ->assertJsonPath('data.0.kind', 'vps')
            // Counted through the same visibility predicate the detail
            // endpoint applies, so the count never advertises a plan the
            // customer cannot then see.
            ->assertJsonPath('data.0.plan_count', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.currency', 'KWD');

        // Exactly two top-level keys. The framework's own paginator would add
        // a `links` block of URLs alongside them; the API has one envelope and
        // this is what keeps it to one.
        $this->assertSame(['data', 'meta'], array_keys($response->json()));
    }

    #[Test]
    public function a_product_that_is_not_on_sale_is_not_listed(): void
    {
        $user = $this->customer();

        Product::factory()->create(['slug' => 'on-sale']);
        Product::factory()->create(['slug' => 'withdrawn', 'is_active' => false]);
        Product::factory()->unlisted()->create(['slug' => 'unlisted']);

        $slugs = $this->actingAs($user)->getJson('/api/v1/catalog/products')
            ->assertOk()
            ->json('data.*.slug');

        $this->assertSame(['on-sale'], $slugs);
    }

    #[Test]
    public function the_listing_can_be_narrowed_to_one_kind(): void
    {
        $user = $this->customer();

        Product::factory()->create(['slug' => 'a-vps']);
        Product::factory()->dedicated()->create(['slug' => 'a-box']);

        $slugs = $this->actingAs($user)->getJson('/api/v1/catalog/products?kind=dedicated')
            ->assertOk()
            ->json('data.*.slug');

        $this->assertSame(['a-box'], $slugs);
    }

    #[Test]
    public function the_page_size_is_bounded_however_large_the_request(): void
    {
        $user = $this->customer();
        Product::factory()->count(8)->create();

        // Served, bounded — not refused. A caller must not be able to turn one
        // request into a full table scan of the catalogue.
        $response = $this->actingAs($user)->getJson('/api/v1/catalog/products?per_page=100000');

        $response->assertOk()
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.total', 8);

        $this->assertLessThanOrEqual(100, count($response->json('data')));
    }

    #[Test]
    public function the_listing_pages(): void
    {
        $user = $this->customer();
        Product::factory()->count(5)->create();

        $first = $this->actingAs($user)->getJson('/api/v1/catalog/products?per_page=2')->assertOk();

        $this->assertCount(2, $first->json('data'));
        $this->assertSame(1, $first->json('meta.page'));
        $this->assertSame(3, $first->json('meta.last_page'));
        $this->assertSame(5, $first->json('meta.total'));

        $last = $this->actingAs($user)->getJson('/api/v1/catalog/products?per_page=2&page=3')->assertOk();

        $this->assertCount(1, $last->json('data'));
        $this->assertSame(3, $last->json('meta.page'));
    }

    #[Test]
    public function an_unusable_query_string_is_a_422_naming_the_fields(): void
    {
        $user = $this->customer();

        $response = $this->actingAs($user)
            ->getJson('/api/v1/catalog/products?per_page=plenty&kind=hovercraft&page=0');

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['fields']]]);

        $fields = $response->json('error.details.fields');

        $this->assertArrayHasKey('per_page', $fields);
        $this->assertArrayHasKey('kind', $fields);
        $this->assertArrayHasKey('page', $fields);
    }

    #[Test]
    public function a_product_can_be_fetched_by_slug_or_by_id_with_its_plans(): void
    {
        $user = $this->customer();

        $product = Product::factory()->create(['slug' => 'cloud-vps']);
        $plan = $this->plan($product, ['slug' => 'cx-2']);
        PlanPrice::factory()->create(['plan_id' => $plan->id, 'currency' => 'KWD', 'recurring_amount_minor' => 9000]);

        foreach (['cloud-vps', $product->id] as $identifier) {
            $this->actingAs($user)->getJson("/api/v1/catalog/products/{$identifier}")
                ->assertOk()
                ->assertJsonPath('data.id', $product->id)
                ->assertJsonPath('data.plans.0.slug', 'cx-2')
                ->assertJsonPath('data.plans.0.prices.0.recurring.minor_units', 9000)
                ->assertJsonPath('data.plans.0.prices.0.recurring.currency', 'KWD')
                ->assertJsonPath('data.plans.0.prices.0.recurring.amount', '9.000');
        }
    }

    #[Test]
    public function a_plan_that_is_not_on_sale_is_absent_from_its_product(): void
    {
        $user = $this->customer();

        $product = Product::factory()->create(['slug' => 'cloud-vps']);
        $this->plan($product, ['slug' => 'visible']);
        $this->plan($product, ['slug' => 'retired', 'is_active' => false]);
        $this->plan($product, ['slug' => 'internal', 'is_public' => false]);

        $slugs = $this->actingAs($user)->getJson('/api/v1/catalog/products/cloud-vps')
            ->assertOk()
            ->json('data.plans.*.slug');

        $this->assertSame(['visible'], $slugs);
    }

    #[Test]
    public function a_product_that_is_not_on_sale_is_a_404_rather_than_a_403(): void
    {
        $user = $this->customer();

        $withdrawn = Product::factory()->create(['slug' => 'withdrawn', 'is_active' => false]);
        $unlisted = Product::factory()->unlisted()->create(['slug' => 'unlisted']);

        foreach ([$withdrawn->slug, $withdrawn->id, $unlisted->slug, $unlisted->id, 'never-existed'] as $identifier) {
            $this->actingAs($user)->getJson("/api/v1/catalog/products/{$identifier}")
                ->assertStatus(404)
                ->assertJsonPath('error.code', 'resource.not_found');
        }
    }

    #[Test]
    public function a_hidden_product_is_indistinguishable_from_one_that_never_existed(): void
    {
        $user = $this->customer();
        $unlisted = Product::factory()->unlisted()->create(['slug' => 'unlisted']);

        $hidden = $this->actingAs($user)->getJson("/api/v1/catalog/products/{$unlisted->id}");
        $invented = $this->actingAs($user)->getJson('/api/v1/catalog/products/01JZZZZZZZZZZZZZZZZZZZZZZZ');

        // Any difference — status, code, or wording — would let a caller sort
        // real catalogue ids from invented ones.
        $this->assertSame($hidden->status(), $invented->status());
        $this->assertSame($hidden->json('error.code'), $invented->json('error.code'));
        $this->assertSame($hidden->json('error.message'), $invented->json('error.message'));
    }

    #[Test]
    public function a_plan_is_returned_with_its_prices_and_its_product(): void
    {
        $user = $this->customer();

        $product = Product::factory()->create(['slug' => 'cloud-vps']);
        $plan = $this->plan($product, ['slug' => 'cx-2', 'stock_limit' => 10, 'per_customer_limit' => 2]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => 9000,
            'setup_amount_minor' => 2500,
        ]);
        PlanPrice::factory()->period(BillingPeriod::Yearly)->create([
            'plan_id' => $plan->id,
            'currency' => 'KWD',
            'recurring_amount_minor' => 90000,
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/catalog/plans/{$plan->slug}")->assertOk();

        $response->assertJsonPath('data.id', $plan->id)
            ->assertJsonPath('data.product.slug', 'cloud-vps')
            // Limits are published because a customer can act on them: an
            // order for three of a plan capped at two is a rejection they
            // could have avoided.
            ->assertJsonPath('data.stock_limit', 10)
            ->assertJsonPath('data.per_customer_limit', 2)
            ->assertJsonPath('data.resources.vcpu', 2)
            ->assertJsonPath('data.prices.0.billing_period', 'monthly')
            ->assertJsonPath('data.prices.0.setup.minor_units', 2500)
            ->assertJsonPath('data.prices.0.setup.amount', '2.500')
            ->assertJsonPath('data.prices.1.billing_period', 'yearly');

        // Money is never a bare number: a JSON float would let every client in
        // the chain re-round three minor digits of KWD.
        $this->assertIsArray($response->json('data.prices.0.recurring'));
        $this->assertIsInt($response->json('data.prices.0.recurring.minor_units'));
        $this->assertSame('9.000', $response->json('data.prices.0.recurring.amount'));
    }

    #[Test]
    public function a_plan_that_is_not_on_sale_is_a_404(): void
    {
        $user = $this->customer();

        $product = Product::factory()->create();
        $retired = $this->plan($product, ['slug' => 'retired', 'is_active' => false]);
        $internal = $this->plan($product, ['slug' => 'internal', 'is_public' => false]);

        // A perfectly public plan hanging off a product that has been
        // withdrawn is not purchasable, so it is not visible either.
        $orphan = $this->plan(Product::factory()->unlisted()->create(), ['slug' => 'orphan']);

        foreach ([$retired, $internal, $orphan] as $plan) {
            foreach ([$plan->slug, $plan->id] as $identifier) {
                $this->actingAs($user)->getJson("/api/v1/catalog/plans/{$identifier}")
                    ->assertStatus(404)
                    ->assertJsonPath('error.code', 'resource.not_found');
            }
        }
    }

    #[Test]
    public function a_price_outside_its_availability_window_is_not_quoted(): void
    {
        $user = $this->customer();

        $plan = $this->plan(Product::factory()->create(), ['slug' => 'cx-2']);
        PlanPrice::factory()->expired()->create(['plan_id' => $plan->id, 'currency' => 'KWD']);

        // The plan is still on sale — it simply has nothing quotable left, and
        // saying so is better than inventing a price or hiding the plan.
        $this->actingAs($user)->getJson('/api/v1/catalog/plans/cx-2')
            ->assertOk()
            ->assertJsonPath('data.prices', []);
    }

    #[Test]
    public function nothing_internal_is_published(): void
    {
        $user = $this->customer();

        $product = Product::factory()->create(['slug' => 'cloud-vps']);
        $plan = $this->plan($product, [
            'slug' => 'cx-2',
            'placement_constraints' => ['storage_class' => 'nvme-local', 'rack' => 'KW1-R07'],
        ]);
        PlanPrice::factory()->create(['plan_id' => $plan->id, 'currency' => 'KWD']);

        foreach (['/api/v1/catalog/products/cloud-vps', '/api/v1/catalog/plans/cx-2'] as $url) {
            $body = $this->actingAs($user)->getJson($url)->assertOk()->getContent();

            // Named one by one rather than asserted as a shape: a general
            // "no unexpected keys" test passes the day someone adds a key
            // nobody thought about.
            foreach ([
                'placement_constraints',   // describes the estate, not the product
                'nvme-local',
                'KW1-R07',
                'is_active',
                'is_public',
                'sort_order',
                'deleted_at',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $body);
            }
        }
    }

    #[Test]
    public function localised_copy_is_resolved_rather_than_handed_over_whole(): void
    {
        $user = $this->customer();

        Product::factory()->create([
            'slug' => 'cloud-vps',
            'name' => ['en' => 'Cloud VPS', 'ar' => 'خادم سحابي'],
            'description' => ['en' => 'Virtual machines.', 'ar' => 'أجهزة افتراضية.'],
        ]);

        // Region subtags and quality values are negotiated, not string-matched.
        $this->actingAs($user)
            ->withHeader('Accept-Language', 'ar-KW,ar;q=0.9,en;q=0.5')
            ->getJson('/api/v1/catalog/products/cloud-vps')
            ->assertOk()
            ->assertJsonPath('data.name', 'خادم سحابي')
            ->assertJsonPath('data.description', 'أجهزة افتراضية.')
            ->assertJsonPath('meta.locale', 'ar');

        // A language the platform does not serve falls back to the platform
        // default rather than to an empty string.
        $this->actingAs($user)
            ->withHeader('Accept-Language', 'ja-JP')
            ->getJson('/api/v1/catalog/products/cloud-vps')
            ->assertOk()
            ->assertJsonPath('data.name', 'Cloud VPS')
            ->assertJsonPath('meta.locale', 'en');

        // And the client is never handed the whole map to negotiate itself.
        $this->assertIsString(
            $this->actingAs($user)->getJson('/api/v1/catalog/products/cloud-vps')->json('data.name')
        );
    }

    #[Test]
    public function a_product_with_no_translation_for_the_asked_locale_still_renders(): void
    {
        $user = $this->customer();

        Product::factory()->create([
            'slug' => 'cloud-vps',
            'name' => ['en' => 'Cloud VPS'],
            'description' => null,
        ]);

        $this->actingAs($user)
            ->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/catalog/products/cloud-vps')
            ->assertOk()
            ->assertJsonPath('data.name', 'Cloud VPS')
            ->assertJsonPath('data.description', null);
    }

    #[Test]
    public function browsing_requires_a_login_but_not_a_verified_address(): void
    {
        $this->getJson('/api/v1/catalog/products')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'auth.unauthenticated');

        $unverified = $this->member(
            Customer::factory()->create(),
            User::factory()->unverified()->create(),
        );

        /*
         * The catalogue is the one customer surface that does not require a
         * proved address. A customer three minutes into their first session is
         * looking at prices, and refusing them teaches them only that the
         * product does not work; reading a price moves no money.
         *
         * Everything that does move money still refuses them, and says which
         * refusal it is rather than reporting a permissions problem.
         */
        $this->actingAs($unverified)->getJson('/api/v1/catalog/products')->assertOk();

        $this->actingAs($unverified)
            ->getJson('/api/v1/invoices')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'auth.email_unverified');

        $this->actingAs($unverified)
            ->postJson('/api/v1/orders/quote', [
                'items' => [['plan_id' => '01JQ0000000000000000000000', 'quantity' => 1]],
                'billing_period' => 'monthly',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'auth.email_unverified');
    }
}
