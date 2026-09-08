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
 * Whose price list a browse request is answered from.
 *
 * The catalogue rows themselves belong to the platform, not to a tenant, so
 * there is no other customer's product to reach — but there is very much
 * another customer's *pricing context*, and it is chosen the same way every
 * other tenant decision on this API is chosen: from the account the middleware
 * resolved, never from anything the caller sent.
 *
 * The failure this guards against is quiet. Quoting a KWD customer the USD
 * price list is not an error anyone sees until an order is placed against a
 * number the checkout will not honour.
 */
final class CataloguePricingScopeTest extends TestCase
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

    /**
     * One plan sold in two currencies — the same rows for both customers.
     */
    private function plan(): Plan
    {
        $plan = Plan::factory()->create([
            'product_id' => Product::factory()->create(['slug' => 'cloud-vps'])->id,
            'slug' => 'cx-2',
        ]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => 9000,
        ]);
        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency' => 'USD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => 3000,
        ]);

        return $plan;
    }

    #[Test]
    public function each_customer_is_quoted_only_their_own_currency(): void
    {
        $plan = $this->plan();

        $kuwaiti = $this->member(Customer::factory()->create(['currency' => 'KWD']));
        $american = $this->member(Customer::factory()->create(['currency' => 'USD']));

        $theirs = $this->actingAs($kuwaiti)->getJson("/api/v1/catalog/plans/{$plan->id}")->assertOk();
        $this->assertSame(['KWD'], $theirs->json('data.prices.*.currency'));
        $this->assertSame(9000, $theirs->json('data.prices.0.recurring.minor_units'));
        $this->assertSame('KWD', $theirs->json('meta.currency'));

        $others = $this->actingAs($american)->getJson("/api/v1/catalog/plans/{$plan->id}")->assertOk();
        $this->assertSame(['USD'], $others->json('data.prices.*.currency'));
        $this->assertSame(3000, $others->json('data.prices.0.recurring.minor_units'));

        // Omitted, not converted. A converted price is a price nobody set, and
        // the checkout would refuse to honour it.
        $this->assertStringNotContainsString('USD', $theirs->getContent());
        $this->assertStringNotContainsString('KWD', $others->getContent());
    }

    #[Test]
    public function the_currency_cannot_be_chosen_from_the_query_string(): void
    {
        $plan = $this->plan();
        $kuwaiti = $this->member(Customer::factory()->create(['currency' => 'KWD']));

        foreach ([
            "/api/v1/catalog/plans/{$plan->id}?currency=USD",
            '/api/v1/catalog/products/cloud-vps?currency=USD',
        ] as $url) {
            $body = $this->actingAs($kuwaiti)->getJson($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('USD', $body);
            $this->assertNotContains(3000, $this->minorUnitsIn($body));
        }
    }

    /*
     * Asserted against the numbers in the document rather than as a
     * substring of it. The body carries ULIDs, and a ULID is base32 over a
     * character set that includes the digits — "3000" turning up inside a
     * generated id failed this test at random, roughly once in a few
     * hundred runs, with a message that pointed at a price leak that had
     * not happened. Currency codes stay a substring check: they are
     * uppercase and the ids are not, so no id can contain one.
     */
    /**
     * Every recurring price the document quotes, at any depth.
     *
     * @return list<int>
     */
    private function minorUnitsIn(string $body): array
    {
        preg_match_all('/"minor_units":\s*(\d+)/', $body, $matches);

        return array_map(intval(...), $matches[1]);
    }

    #[Test]
    public function a_customer_id_in_the_request_cannot_select_another_account(): void
    {
        $plan = $this->plan();

        $mine = Customer::factory()->create(['currency' => 'KWD']);
        $theirs = Customer::factory()->create(['currency' => 'USD']);
        $user = $this->member($mine);

        // A customer_id smuggled into the query string is simply not read:
        // the response is still the caller's own price list, in their own
        // currency, rather than the account they named.
        $this->actingAs($user)
            ->getJson("/api/v1/catalog/plans/{$plan->id}?customer_id={$theirs->id}&customer={$theirs->id}")
            ->assertOk()
            ->assertJsonPath('meta.currency', 'KWD')
            ->assertJsonPath('data.prices.0.recurring.minor_units', 9000);

        // The header is the only supported way to name an account, and it is
        // checked against membership. Naming an account the login does not
        // belong to is refused outright rather than silently ignored.
        // (Asserted last: withHeader persists for the rest of the test.)
        $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $theirs->id)
            ->getJson("/api/v1/catalog/plans/{$plan->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'tenancy.account_unavailable');
    }

    #[Test]
    public function a_plan_sold_in_no_currency_the_customer_uses_is_visible_without_a_price(): void
    {
        $plan = $this->plan();
        $plan->prices()->delete();
        PlanPrice::factory()->create(['plan_id' => $plan->id, 'currency' => 'USD']);

        $kuwaiti = $this->member(Customer::factory()->create(['currency' => 'KWD']));

        // Listed but unpriced, rather than hidden: the plan exists and is on
        // sale, it is simply not sold on terms this account can be billed on.
        // Hiding it would make "we do not sell that here" indistinguishable
        // from "that does not exist".
        $this->actingAs($kuwaiti)->getJson("/api/v1/catalog/plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.prices', []);
    }
}
