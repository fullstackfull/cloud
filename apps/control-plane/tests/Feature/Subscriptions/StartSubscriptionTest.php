<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Coupon;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Orders\Infrastructure\Models\OrderItem;
use Lynomia\Modules\Subscriptions\Application\Actions\StartSubscription;
use Lynomia\Modules\Subscriptions\Domain\Exceptions\SubscriptionCannotStartException;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StartSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private StartSubscription $start;

    protected function setUp(): void
    {
        parent::setUp();

        $this->start = app(StartSubscription::class);
    }

    #[Test]
    public function a_subscription_bought_on_31_january_renews_on_28_february(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-31 08:00:00'));

        $customer = Customer::factory()->create();
        $order = $this->paidOrder($customer);
        $item = $this->planLine($order);

        $subscription = $this->start->execute($order, $item);

        $this->assertSame('2026-01-31 08:00:00', $subscription->current_period_start->toDateTimeString());
        // Not 3 March: adding a calendar month to 31 January clamps to the end
        // of February rather than overflowing, which is what keeps the
        // customer's anniversary where they bought it.
        $this->assertSame('2026-02-28 08:00:00', $subscription->current_period_end->toDateTimeString());
        $this->assertSame('2026-02-28 08:00:00', $subscription->next_invoice_at->toDateTimeString());
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertTrue($subscription->auto_renew);
    }

    #[Test]
    public function a_yearly_subscription_bought_on_29_february_renews_on_28_february(): void
    {
        $this->travelTo(CarbonImmutable::parse('2028-02-29 12:00:00'));

        $customer = Customer::factory()->create();
        $order = $this->paidOrder($customer);
        $item = $this->planLine($order, period: BillingPeriod::Yearly);

        $subscription = $this->start->execute($order, $item);

        $this->assertSame('2029-02-28 12:00:00', $subscription->current_period_end->toDateTimeString());
    }

    #[Test]
    public function the_setup_fee_is_not_part_of_what_renews(): void
    {
        $customer = Customer::factory()->create();
        $order = $this->paidOrder($customer);
        $item = $this->planLine($order, recurringMinor: 9000, setupMinor: 25000, quantity: 2);

        $subscription = $this->start->execute($order, $item);

        // 2 × 9.000 KWD, and not a fils of the 25.000 KWD one-off that stood
        // the service up.
        $this->assertSame(18000, $subscription->recurring_amount_minor);
        $this->assertSame('18.000', $subscription->recurringAmount()->toDecimalString());
    }

    #[Test]
    public function starting_the_same_order_line_twice_creates_one_subscription(): void
    {
        $customer = Customer::factory()->create();
        $order = $this->paidOrder($customer);
        $item = $this->planLine($order);

        $first = $this->start->execute($order, $item);
        $second = $this->start->execute($order, $item);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Subscription::query()->count());
    }

    #[Test]
    public function a_subscription_cannot_start_from_an_unpaid_order(): void
    {
        $customer = Customer::factory()->create();
        $order = Order::factory()->status(OrderStatus::PendingPayment)->create([
            'customer_id' => $customer->id,
            'currency' => 'KWD',
        ]);
        $item = $this->planLine($order);

        $this->expectException(SubscriptionCannotStartException::class);

        $this->start->execute($order, $item);
    }

    #[Test]
    public function a_recurring_coupon_is_carried_onto_the_subscription_and_a_one_off_one_is_not(): void
    {
        $customer = Customer::factory()->create();

        $recurring = $this->coupon(['applies_to_renewals' => true, 'duration_cycles' => 3]);
        $order = $this->paidOrder($customer, $recurring->id);
        $subscription = $this->start->execute($order, $this->planLine($order));

        $this->assertSame($recurring->id, trim((string) $subscription->coupon_id));
        $this->assertSame(3, $subscription->coupon_cycles_remaining);

        $oneOff = $this->coupon(['applies_to_renewals' => false, 'duration_cycles' => null]);
        $otherOrder = $this->paidOrder($customer, $oneOff->id);
        $otherSubscription = $this->start->execute($otherOrder, $this->planLine($otherOrder));

        $this->assertNull($otherSubscription->coupon_id);
        $this->assertNull($otherSubscription->coupon_cycles_remaining);
    }

    #[Test]
    public function an_order_line_from_another_order_is_refused(): void
    {
        $customer = Customer::factory()->create();
        $order = $this->paidOrder($customer);
        $foreignOrder = $this->paidOrder($customer);
        $foreignItem = $this->planLine($foreignOrder);

        $this->expectException(SubscriptionCannotStartException::class);

        $this->start->execute($order, $foreignItem);
    }

    private function paidOrder(Customer $customer, ?string $couponId = null): Order
    {
        return Order::factory()->paid()->create([
            'customer_id' => $customer->id,
            'currency' => 'KWD',
            'coupon_id' => $couponId,
        ]);
    }

    private function planLine(
        Order $order,
        int $recurringMinor = 9000,
        int $setupMinor = 0,
        int $quantity = 1,
        BillingPeriod $period = BillingPeriod::Monthly,
    ): OrderItem {
        /** @var OrderItem $item */
        $item = OrderItem::query()->create([
            'order_id' => $order->id,
            'plan_id' => Plan::factory()->create()->id,
            'kind' => 'plan',
            'name' => 'CX-2',
            'billing_period' => $period,
            'quantity' => $quantity,
            'unit_recurring_minor' => $recurringMinor,
            'unit_setup_minor' => $setupMinor,
            'total_minor' => $recurringMinor * $quantity + $setupMinor,
        ]);

        return $item;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function coupon(array $overrides = []): Coupon
    {
        return Coupon::factory()->create(array_merge([
            'applies_to_renewals' => true,
            'duration_cycles' => 3,
        ], $overrides));
    }
}
