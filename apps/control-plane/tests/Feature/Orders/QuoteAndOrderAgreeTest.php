<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Coupon;
use Lynomia\Modules\Catalog\Infrastructure\Models\TaxRule;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use PHPUnit\Framework\Attributes\Test;

/**
 * The quote and the order are the same arithmetic.
 *
 * This is the contract the catalogue's price breakdown rests on. A quote that
 * computed its own total would eventually disagree with the invoice — over a
 * coupon rounding, a tax boundary, a setup fee — and the customer would be
 * right and the platform would be wrong. So both go through OrderPricing, and
 * these tests price a basket both ways and compare every figure rather than
 * trusting that they share a class.
 */
final class QuoteAndOrderAgreeTest extends OrdersApiTestCase
{
    #[Test]
    public function every_figure_in_a_quote_is_the_figure_the_order_is_written_with(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        TaxRule::query()->create([
            'name' => 'Kuwait VAT',
            'country' => 'KW',
            'rate' => '0.150',
            'is_inclusive' => false,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        // A setup fee and a quantity, so rounding and allocation both matter.
        $plan = $this->publishedPlan(monthlyMinor: 9333, setupMinor: 2500);

        $basket = [
            'items' => [['plan_id' => $plan->id, 'quantity' => 3]],
            'billing_period' => BillingPeriod::Monthly->value,
        ];

        $quote = $this->actingAs($user)
            ->postJson('/api/v1/orders/quote', $basket)
            ->assertOk();

        $order = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'quote-agreement-1')
            ->postJson('/api/v1/orders', $basket)
            ->assertCreated();

        foreach (['subtotal', 'discount', 'tax', 'total'] as $figure) {
            $this->assertSame(
                $order->json("data.{$figure}.minor_units"),
                $quote->json("data.{$figure}.minor_units"),
                "The quote and the order disagree about {$figure}.",
            );
            $this->assertSame(
                $order->json("data.{$figure}.currency"),
                $quote->json("data.{$figure}.currency"),
            );
        }

        $this->assertSame($order->json('data.currency'), $quote->json('data.currency'));

        // And line by line, not only in total.
        $this->assertSame(
            $order->json('data.items.0.total.minor_units'),
            $quote->json('data.lines.0.total.minor_units'),
        );
        $this->assertSame(
            $order->json('data.items.0.unit_setup.minor_units'),
            $quote->json('data.lines.0.unit_setup.minor_units'),
        );
        $this->assertSame(
            $order->json('data.items.0.tax.minor_units'),
            $quote->json('data.lines.0.tax.minor_units'),
        );
        // The tax rate is published as the string the invoice will carry, so
        // a client never has to reconstruct it from a float.
        $this->assertSame(
            $order->json('data.items.0.tax_rate'),
            $quote->json('data.lines.0.tax_rate'),
        );
        $this->assertSame('Kuwait VAT', $quote->json('data.lines.0.tax_name'));
    }

    #[Test]
    public function a_coupon_discounts_the_quote_exactly_as_it_discounts_the_order(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $plan = $this->publishedPlan(monthlyMinor: 10000, setupMinor: 1500);

        Coupon::factory()->code('TENOFF')->percentage('0.100000')->create();

        $basket = [
            'items' => [['plan_id' => $plan->id, 'quantity' => 2]],
            'billing_period' => BillingPeriod::Monthly->value,
            'coupon_code' => 'TENOFF',
        ];

        $quote = $this->actingAs($user)->postJson('/api/v1/orders/quote', $basket)->assertOk();

        $order = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'quote-agreement-coupon')
            ->postJson('/api/v1/orders', $basket)
            ->assertCreated();

        $this->assertSame('TENOFF', $quote->json('data.coupon_code'));
        $this->assertTrue($quote->json('data.discount.minor_units') > 0);
        $this->assertSame(
            $order->json('data.discount.minor_units'),
            $quote->json('data.discount.minor_units'),
        );
        $this->assertSame(
            $order->json('data.total.minor_units'),
            $quote->json('data.total.minor_units'),
        );
    }

    #[Test]
    public function the_renewal_figure_drops_the_setup_fee_and_the_coupon(): void
    {
        [, $user] = $this->accountWithOwner();

        $plan = $this->publishedPlan(monthlyMinor: 9000, setupMinor: 5000);

        Coupon::factory()->code('HALF')->percentage('0.500000')->create();

        $quote = $this->actingAs($user)->postJson('/api/v1/orders/quote', [
            'items' => [['plan_id' => $plan->id, 'quantity' => 1]],
            'billing_period' => BillingPeriod::Monthly->value,
            'coupon_code' => 'HALF',
        ])->assertOk();

        // Now: half of 14.000, because the coupon applies to the setup fee too.
        $this->assertSame(7000, $quote->json('data.total.minor_units'));
        $this->assertSame(5000, $quote->json('data.setup.minor_units'));

        // Next time: the plan price, whole, with neither the setup fee nor the
        // coupon, because neither happens again.
        $this->assertSame(9000, $quote->json('data.renewal.total.minor_units'));
        $this->assertFalse($quote->json('data.renewal.includes_setup'));
        $this->assertFalse($quote->json('data.renewal.includes_coupon'));
        $this->assertSame('monthly', $quote->json('data.renewal.billing_period'));
    }

    #[Test]
    public function a_quote_creates_nothing(): void
    {
        [, $user] = $this->accountWithOwner();
        $plan = $this->publishedPlan();

        $this->actingAs($user)
            ->postJson('/api/v1/orders/quote', $this->basket($plan))
            ->assertOk()
            ->assertJsonPath('meta.is_quote', true)
            ->assertJsonPath('meta.creates_nothing', true);

        $this->assertSame(0, Order::query()->count());
    }

    #[Test]
    public function a_quote_refuses_what_a_checkout_refuses(): void
    {
        [, $user] = $this->accountWithOwner();

        $plan = $this->publishedPlan();
        $plan->forceFill(['is_public' => false])->save();

        $this->actingAs($user)
            ->postJson('/api/v1/orders/quote', $this->basket($plan))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'checkout.plan_unavailable');
    }

    #[Test]
    public function a_quote_prices_in_the_customers_own_currency_and_refuses_when_there_is_no_price_in_it(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        // The catalogue prices this plan in KWD only; the account bills in USD.
        $plan = $this->publishedPlan();
        $customer->forceFill(['currency' => 'USD', 'country' => 'US'])->save();

        $this->actingAs($user)
            ->postJson('/api/v1/orders/quote', $this->basket($plan))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'checkout.terms_unavailable');
    }
}
