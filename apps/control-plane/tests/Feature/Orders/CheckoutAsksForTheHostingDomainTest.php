<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Domain\Exceptions\CheckoutRejectedException;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Orders\Infrastructure\Models\OrderItem;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use PHPUnit\Framework\Attributes\Test;

/**
 * F-04's domain limb: checkout asks for the name a hosting account is for.
 *
 * Before this, the platform asserted a lifecycle step that needs a domain and
 * offered no way to supply one: `PlaceOrderRequest` had no domain field and
 * `CheckoutLine` carried a plan id and a quantity. Every hosting order reached
 * the panel as `<username>.hosting.invalid`.
 *
 * Asked for at checkout, validated there with the platform's own host-name
 * rules, folded once, and recorded on the order line — so the thing that was
 * bought for a name is the thing that is built for it.
 */
final class CheckoutAsksForTheHostingDomainTest extends OrdersApiTestCase
{
    #[Test]
    public function a_hosting_line_records_the_domain_it_was_bought_for_folded_once(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $plan = $this->hostingPlan();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'f04-domain-recorded')
            ->postJson('/api/v1/orders', $this->basket($plan, [
                ['plan_id' => $plan->id, 'quantity' => 1, 'domain' => ' Shop.Example.Test. '],
            ]))
            ->assertCreated();

        $item = OrderItem::query()
            ->whereIn('order_id', Order::query()->where('customer_id', $customer->getKey())->select('id'))
            ->sole();

        $this->assertSame('shop.example.test', $item->domain);
    }

    #[Test]
    public function a_hosting_line_without_a_domain_is_refused(): void
    {
        [, $user] = $this->accountWithOwner();
        $plan = $this->hostingPlan();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'f04-domain-missing')
            ->postJson('/api/v1/orders', $this->basket($plan))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'checkout.domain_required');

        $this->assertSame(0, Order::query()->count());
    }

    #[Test]
    public function a_hosting_line_whose_domain_is_not_a_host_name_is_refused(): void
    {
        [, $user] = $this->accountWithOwner();
        $plan = $this->hostingPlan();

        foreach (['https://shop.example.test/', 'shop example.test', 'café.example.test', 'shop..example.test', '192.0.2.10'] as $index => $bad) {
            $this->actingAs($user)
                ->withHeader('Idempotency-Key', 'f04-domain-bad-'.$index)
                ->postJson('/api/v1/orders', $this->basket($plan, [
                    ['plan_id' => $plan->id, 'quantity' => 1, 'domain' => $bad],
                ]))
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'checkout.domain_unusable');
        }

        $this->assertSame(0, Order::query()->count());
    }

    #[Test]
    public function the_action_refuses_what_the_endpoint_refuses(): void
    {
        /*
         * The rule lives in PlaceOrder, not only in the form request, so a
         * caller that is not the endpoint — a golden path, a console command,
         * the next checkout surface — cannot place a hosting order that the
         * panel will be handed no name for.
         */
        [$customer] = $this->accountWithOwner();
        $plan = $this->hostingPlan();

        foreach ([null => 'checkout.domain_required', 'not a name' => 'checkout.domain_unusable'] as $domain => $code) {
            try {
                app(PlaceOrder::class)->execute($customer, new CheckoutRequest(
                    lines: [new CheckoutLine($plan->id, 1, $domain === '' ? null : (string) $domain)],
                    billingPeriod: BillingPeriod::Monthly,
                ));
                $this->fail('PlaceOrder accepted a hosting line with domain '.json_encode($domain));
            } catch (CheckoutRejectedException $e) {
                $this->assertSame($code, $e->errorCode());
            }
        }
    }

    #[Test]
    public function a_domain_on_a_line_that_is_not_hosting_is_refused_rather_than_ignored(): void
    {
        [, $user] = $this->accountWithOwner();
        $plan = $this->publishedPlan();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'f04-domain-on-vps')
            ->postJson('/api/v1/orders', $this->basket($plan, [
                ['plan_id' => $plan->id, 'quantity' => 1, 'domain' => 'shop.example.test'],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'checkout.domain_not_applicable');
    }

    #[Test]
    public function the_same_key_for_a_different_domain_is_a_different_purchase(): void
    {
        [, $user] = $this->accountWithOwner();
        $plan = $this->hostingPlan();

        $send = fn (string $domain) => $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'f04-same-key')
            ->postJson('/api/v1/orders', $this->basket($plan, [
                ['plan_id' => $plan->id, 'quantity' => 1, 'domain' => $domain],
            ]));

        $send('first.example.test')->assertCreated();

        // A different spelling of the same name is the same purchase…
        $send('FIRST.Example.Test.')->assertSuccessful();

        // …and a different name is not.
        $send('second.example.test')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'checkout.idempotency_key_reused');

        $this->assertSame(1, Order::query()->count());
    }

    #[Test]
    public function a_basket_with_no_domain_keeps_the_fingerprint_it_always_had(): void
    {
        /*
         * An order written before this change carries the old fingerprint, and
         * a client mid-way through retrying it must be handed that order back
         * rather than a 409. So a line with no domain hashes exactly as it did.
         */
        $request = new CheckoutRequest(
            lines: [new CheckoutLine('01jq8m2v9k3d7f5h1n0p2r4s6t', 2)],
            billingPeriod: BillingPeriod::Monthly,
            couponCode: 'Spring',
        );

        $this->assertSame(
            hash('sha256', implode('|', ['monthly', 'spring', '01jq8m2v9k3d7f5h1n0p2r4s6t:2'])),
            $request->fingerprint(),
        );
    }

    private function hostingPlan(): Plan
    {
        $product = Product::factory()->create(['kind' => ProductKind::SharedHosting->value]);
        $plan = Plan::factory()->create(['product_id' => $product->getKey()]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => 4_500,
            'setup_amount_minor' => 0,
        ]);

        HostingPackage::factory()->create(['plan_id' => $plan->getKey()]);

        return $plan->fresh(['prices', 'product']);
    }
}
