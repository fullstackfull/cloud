<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Catalog\Application\Actions\FindPurchasableProduct;
use Lynomia\Modules\Catalog\Application\Actions\ListPurchasableProducts;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Http\Requests\ListProductsRequest;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The contract the catalogue shares with the other twelve collection
 * endpoints, and the bounds that are supposed to hold whoever is calling.
 *
 * Everything in here is a regression: each test failed against the first cut
 * of this module's HTTP surface.
 */
final class CatalogueBrowsingContractTest extends TestCase
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

    /*
     * ------------------------------------------------------------------
     * The paging envelope
     * ------------------------------------------------------------------
     */

    #[Test]
    public function the_paging_envelope_is_the_one_every_other_collection_uses(): void
    {
        $user = $this->customer();
        Product::factory()->count(3)->create();

        $meta = $this->actingAs($user)->getJson('/api/v1/catalog/products?per_page=2')
            ->assertOk()
            ->json('meta');

        // The key is `page`, as it is on invoices, orders, payments, services,
        // machines, tokens and every other list on this API. A client's paging
        // component is written once; an endpoint that calls the same field
        // something else is a bug in that component's first sprint.
        $this->assertSame(1, $meta['page'] ?? null);
        $this->assertSame(2, $meta['per_page']);
        $this->assertSame(2, $meta['last_page']);
        $this->assertSame(3, $meta['total']);

        // Published so a client can size its own requests instead of
        // discovering the ceiling by having one silently applied.
        $this->assertSame(ListProductsRequest::MAX_PER_PAGE, $meta['max_per_page'] ?? null);

        $this->assertArrayNotHasKey('current_page', $meta);
    }

    #[Test]
    public function a_nonsense_page_size_is_answered_rather_than_refused(): void
    {
        $user = $this->customer();
        Product::factory()->count(3)->create();

        // Every other collection on this API treats ?per_page=0 as
        // "unspecified" and answers with the default page. The catalogue
        // refusing it means one endpoint out of thirteen breaks a client that
        // sends an unset filter as a zero.
        foreach (['per_page=0', 'per_page=-1'] as $query) {
            $this->actingAs($user)->getJson('/api/v1/catalog/products?'.$query)
                ->assertOk()
                ->assertJsonPath('meta.per_page', 25);
        }
    }

    #[Test]
    public function an_empty_filter_is_not_a_validation_failure(): void
    {
        $user = $this->customer();
        Product::factory()->create(['slug' => 'on-sale']);

        // `?kind=` is what a form sends for "any kind". The framework turns the
        // empty string into null before validation, so a rule set without
        // `nullable` answers an unfiltered browse with a 422.
        $this->actingAs($user)->getJson('/api/v1/catalog/products?kind=')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'on-sale');
    }

    #[Test]
    public function the_page_size_ceiling_holds_for_a_caller_that_is_not_http(): void
    {
        Product::factory()->count(3)->create();

        // The ceiling is a property of listing the catalogue, not of the
        // controller that happens to list it today. A queue worker or a
        // sitemap builder resolving the action gets the same bound.
        $page = app(ListPurchasableProducts::class)->execute(null, 100_000);

        $this->assertLessThanOrEqual(ListProductsRequest::MAX_PER_PAGE, $page->perPage());
    }

    /*
     * ------------------------------------------------------------------
     * The nested collection
     * ------------------------------------------------------------------
     */

    #[Test]
    public function a_products_plans_are_bounded_like_any_other_collection(): void
    {
        $user = $this->customer();

        $product = Product::factory()->create(['slug' => 'many']);

        foreach (range(1, 150) as $n) {
            Plan::factory()->create([
                'product_id' => $product->id,
                'slug' => sprintf('cx-%03d', $n),
            ]);
        }

        $response = $this->actingAs($user)->getJson('/api/v1/catalog/products/many')->assertOk();

        $slugs = $response->json('data.plans.*.slug');

        // One GET must not be able to pull an entire table just because the
        // rows hang off a relation instead of a paginator. The bound is the
        // same one the listing applies.
        $this->assertCount(FindPurchasableProduct::MAX_PLANS, $slugs);

        // Truncated from a defined order, not from an arbitrary one: a bound
        // that returned some hundred rows rather than the first hundred would
        // make the response depend on the database's mood.
        $expected = array_map(static fn (int $n): string => sprintf('cx-%03d', $n), range(1, 100));
        $this->assertSame($expected, $slugs);

        // And the true count is published, so a truncated list is visible as
        // truncated rather than silently short.
        $this->assertSame(150, $response->json('data.plan_count'));
    }

    /*
     * ------------------------------------------------------------------
     * Visibility and pricing — coverage the first cut did not have
     * ------------------------------------------------------------------
     */

    #[Test]
    public function a_plan_whose_product_has_been_deleted_is_a_404(): void
    {
        $user = $this->customer();

        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['product_id' => $product->id, 'slug' => 'orphaned']);
        $product->delete();

        $this->actingAs($user)->getJson('/api/v1/catalog/plans/orphaned')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource.not_found');
    }

    #[Test]
    public function a_withdrawn_price_and_one_that_has_not_opened_yet_are_both_unquoted(): void
    {
        $user = $this->customer();

        $plan = Plan::factory()->create([
            'product_id' => Product::factory()->create()->id,
            'slug' => 'cx-2',
        ]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'is_active' => false,
            'recurring_amount_minor' => 111,
        ]);
        PlanPrice::factory()->period(BillingPeriod::Yearly)->create([
            'plan_id' => $plan->id,
            'currency' => 'KWD',
            'available_from' => now()->addDay(),
            'recurring_amount_minor' => 222,
        ]);

        $body = $this->actingAs($user)->getJson('/api/v1/catalog/plans/cx-2')
            ->assertOk()
            ->assertJsonPath('data.prices', [])
            ->getContent();

        // A price the customer cannot be charged must not be reachable at any
        // depth of the document — asserted against the numbers the document
        // quotes rather than as a substring of it, because the body carries
        // ULIDs and a ULID's base32 alphabet includes the digits.
        $this->assertNotContains(111, $this->minorUnitsIn($body));
        $this->assertNotContains(222, $this->minorUnitsIn($body));
    }

    #[Test]
    public function two_customers_are_quoted_disjoint_price_lists_from_the_same_product(): void
    {
        $product = Product::factory()->create(['slug' => 'cloud-vps']);
        $plan = Plan::factory()->create(['product_id' => $product->id, 'slug' => 'cx-2']);

        PlanPrice::factory()->create(['plan_id' => $plan->id, 'currency' => 'KWD', 'recurring_amount_minor' => 9000]);
        PlanPrice::factory()->create(['plan_id' => $plan->id, 'currency' => 'USD', 'recurring_amount_minor' => 3000]);

        $kuwaiti = $this->customer('KWD');
        $american = $this->customer('USD');

        // The product detail endpoint reaches prices one relation deeper than
        // the plan endpoint does, so it is asserted separately: an eager load
        // is exactly where a filter applied at the top level gets skipped.
        $mine = $this->actingAs($kuwaiti)->getJson('/api/v1/catalog/products/cloud-vps')->assertOk();
        $this->assertSame(['KWD'], $mine->json('data.plans.0.prices.*.currency'));
        $this->assertStringNotContainsString('USD', $mine->getContent());
        $this->assertNotContains(3000, $this->minorUnitsIn($mine->getContent()));

        $theirs = $this->actingAs($american)->getJson('/api/v1/catalog/products/cloud-vps')->assertOk();
        $this->assertSame(['USD'], $theirs->json('data.plans.0.prices.*.currency'));
        $this->assertStringNotContainsString('KWD', $theirs->getContent());
        $this->assertNotContains(9000, $this->minorUnitsIn($theirs->getContent()));
    }

    /**
     * Every price the document quotes, at any depth.
     *
     * @return list<int>
     */
    private function minorUnitsIn(string $body): array
    {
        preg_match_all('/"minor_units":\s*(\d+)/', $body, $matches);

        return array_map(intval(...), $matches[1]);
    }

    #[Test]
    public function the_listing_publishes_nothing_internal_either(): void
    {
        $user = $this->customer();

        Product::factory()->create(['slug' => 'p1', 'sort_order' => 9]);

        $body = $this->actingAs($user)->getJson('/api/v1/catalog/products')
            ->assertOk()
            ->getContent();

        foreach (['is_active', 'is_public', 'sort_order', 'deleted_at', 'created_at', 'updated_at'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, $forbidden);
        }
    }
}
