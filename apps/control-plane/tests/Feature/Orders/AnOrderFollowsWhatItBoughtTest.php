<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Billing\Application\Actions\RecordInvoiceRefund;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\SettlementBasis;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Billing\Domain\Events\OrderFinanciallySettled;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Domain\Exceptions\CouponCustomerLimitReachedException;
use Lynomia\Modules\Catalog\Infrastructure\Models\Coupon;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Application\Services\PlanCapacity;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Domain\Exceptions\CheckoutRejectedException;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Payments\Domain\Events\PaymentFailed;
use Lynomia\Modules\Provisioning\Application\Actions\CompensateFailedJob;
use Lynomia\Modules\Provisioning\Application\Actions\TransitionService;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Contracts\HandlerRegistry;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobStarted;
use Lynomia\Modules\Provisioning\Domain\Events\ServiceStatusChanged;
use Lynomia\Modules\Provisioning\Domain\StateMachines\ProvisioningJobStateMachine;
use Lynomia\Modules\Provisioning\Domain\StateMachines\ServiceStateMachine;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\Subscriptions\Application\Actions\TransitionSubscription;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Support\Lifecycle\EndOfService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuysSharedHosting;
use Tests\Support\PlaceableEstate;
use Tests\TestCase;

/**
 * F-19: an order is what somebody bought, so it lives as long as that does.
 *
 * Nine of the order's thirteen states had no writer. An order was placed, paid
 * for and then never told anything again: fulfilment happened on the service
 * row, so a delivered purchase and one nobody could build both read `paid` for
 * ever, `orders.completed_at` was always null while the customer API published
 * it, and the two clauses that give a plan's last unit and a coupon hold back —
 * `refunded` and `terminated` — named states no order could ever enter.
 *
 * Every case here buys through checkout and pays through settlement, so the
 * order under test is one the platform made, not one a factory described.
 */
final class AnOrderFollowsWhatItBoughtTest extends TestCase
{
    use BuysSharedHosting;
    use PlaceableEstate;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        config([
            'hosting.retention.suspended_days' => 30,
            'provisioning.termination.suspended_retention_days' => 30,
        ]);
    }

    #[Test]
    public function a_delivered_purchase_is_active_and_says_when_it_was_completed(): void
    {
        $order = $this->buySharedHosting($this->customer(), $this->sharedHostingPlan());

        $this->assertSame(ServiceStatus::Active, $this->serviceOf($order)->status);

        $this->assertSame(OrderStatus::Active, $order->status);
        $this->assertNotNull($order->completed_at, 'The customer API publishes completed_at, and it was never written.');

        // Each step is written at the moment it happened, not reconstructed.
        $this->assertSame(
            ['pending_payment', 'paid', 'queued_for_provisioning', 'provisioning', 'active'],
            $this->trailOf($order),
        );
    }

    #[Test]
    public function a_purchase_nobody_could_build_does_not_read_as_delivered(): void
    {
        /*
         * The estate can place a VPS — a cluster, a pool and an image exist, so
         * checkout sells it — and has no node with room, so the build cannot
         * finish. Before this, the order still said `paid`: exactly what a
         * delivered purchase says.
         */
        $this->estateThatCanPlaceAVps();

        $order = $this->buy($this->customer(), $this->vpsPlan());

        $service = $this->serviceOf($order);
        $this->assertSame(ServiceStatus::Failed, $service->status);
        $this->assertSame(
            ProvisioningJobStatus::NeedsReview,
            ProvisioningJob::query()->where('service_id', $service->getKey())->sole()->status,
        );

        $this->assertSame(OrderStatus::ManualReview, $order->refresh()->status);
        $this->assertNull($order->completed_at);
    }

    #[Test]
    public function the_order_says_its_build_has_begun_when_a_worker_claims_it(): void
    {
        /*
         * An ordered service is `provisioning` from the moment its build is
         * asked for, so the claim is the one step of a build that moves no
         * service status — and the only thing that can tell the order its
         * build has actually begun. One attempt, run by hand: no node has
         * room, so it is requeued for a later try, and nothing else moves.
         */
        $this->estateThatCanPlaceAVps();
        Queue::fake([RunProvisioningJob::class]);

        $order = $this->buy($this->customer(), $this->vpsPlan());

        $this->assertSame(OrderStatus::QueuedForProvisioning, $order->status);

        $job = ProvisioningJob::query()->where('order_id', $order->getKey())->sole();

        (new RunProvisioningJob((string) $job->getKey()))->handle(
            app(HandlerRegistry::class),
            app(CompensateFailedJob::class),
            app(TransitionService::class),
            app(ServiceStateMachine::class),
            app(ProvisioningJobStateMachine::class),
            app(SecretRedactor::class),
        );

        $this->assertSame(ProvisioningJobStatus::Queued, $job->fresh()?->status);
        $this->assertSame(1, $job->fresh()?->attempts);
        $this->assertSame(ServiceStatus::Provisioning, $this->serviceOf($order)->status);

        $this->assertSame(OrderStatus::Provisioning, $order->refresh()->status);
    }

    #[Test]
    public function a_missed_wake_up_is_caught_up_along_the_road_the_purchase_travelled(): void
    {
        /*
         * The order's listener swallows its own failures, so a wake-up can be
         * lost. The next one reads the facts afresh and walks the order there
         * through the states the purchase really passed, rather than jumping —
         * `paid → active` is not a move the table has.
         */
        Event::fake([ServiceStatusChanged::class, ProvisioningJobStarted::class]);

        $order = $this->buySharedHosting($this->customer(), $this->sharedHostingPlan());

        // Nothing announced; fulfilment's own last look caught the order up.
        Event::assertDispatched(ServiceStatusChanged::class);
        $this->assertSame(OrderStatus::Active, $order->status);
        $this->assertSame(
            ['pending_payment', 'paid', 'queued_for_provisioning', 'provisioning', 'active'],
            $this->trailOf($order),
        );
    }

    #[Test]
    public function a_suspension_and_a_return_are_the_orders_as_well(): void
    {
        $order = $this->buySharedHosting($this->customer(), $this->sharedHostingPlan());
        $completedAt = $order->completed_at;

        $subscription = Subscription::query()->where('order_id', $order->getKey())->sole();

        app(TransitionSubscription::class)->execute($subscription, SubscriptionStatus::PastDue);
        app(TransitionSubscription::class)->execute($subscription->refresh(), SubscriptionStatus::Suspended);

        $this->assertSame(ServiceStatus::Suspended, $this->serviceOf($order)->status);
        $this->assertSame(OrderStatus::Suspended, $order->refresh()->status);

        app(TransitionSubscription::class)->execute($subscription->refresh(), SubscriptionStatus::Active);

        $this->assertSame(ServiceStatus::Active, $this->serviceOf($order)->status);
        $this->assertSame(OrderStatus::Active, $order->refresh()->status);

        // Completed once. Coming back from a suspension is not a second delivery.
        $this->assertEquals($completedAt, $order->completed_at);
    }

    #[Test]
    public function the_end_of_what_was_bought_ends_the_order_and_gives_the_last_unit_back(): void
    {
        $plan = $this->sharedHostingPlan('last-one', stockLimit: 1);

        $order = $this->buySharedHosting($this->customer(), $plan);

        $this->assertSame(1, app(PlanCapacity::class)->claimed((string) $plan->getKey()));
        $this->assertTheLastUnitIsTaken($plan);

        $this->endTheServiceOf($order);

        $this->assertSame(ServiceStatus::Terminated, $this->serviceOf($order)->status);
        $this->assertSame(OrderStatus::Terminated, $order->refresh()->status);

        // The unit is back, and a second customer can buy it.
        $this->assertSame(0, app(PlanCapacity::class)->claimed((string) $plan->getKey()));
        $this->assertSame(
            OrderStatus::PendingPayment,
            $this->placeSharedHostingOrder($this->customer(), $plan)->status,
        );
    }

    #[Test]
    public function a_refund_records_the_money_and_gives_nothing_back_until_the_service_ends(): void
    {
        /*
         * The product decision: a refund records the money and nothing else.
         * The machine is kept, and the plan unit and the coupon hold come back
         * when the service ends, not when money moves — otherwise the last unit
         * of a finite plan is sold twice while the first customer's site is
         * still serving.
         */
        $plan = $this->sharedHostingPlan('refunded', stockLimit: 1);

        $order = $this->buySharedHosting($this->customer(), $plan);

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('order_id', $order->getKey())->sole();

        app(RecordInvoiceRefund::class)->execute($invoice, Money::ofMinor($invoice->amount_paid_minor, $invoice->currency));

        $this->assertSame(InvoiceStatus::Refunded, $invoice->refresh()->status);
        $this->assertSame(OrderStatus::Refunded, $order->refresh()->status);

        // Nothing else moved.
        $this->assertSame(ServiceStatus::Active, $this->serviceOf($order)->status);
        $this->assertSame(1, app(PlanCapacity::class)->claimed((string) $plan->getKey()));
        $this->assertTheLastUnitIsTaken($plan);

        // Terminating is a separate act, and it is the one that gives the unit back.
        $this->endTheServiceOf($order);

        $this->assertSame(ServiceStatus::Terminated, $this->serviceOf($order)->status);
        $this->assertSame(0, app(PlanCapacity::class)->claimed((string) $plan->getKey()));
    }

    #[Test]
    public function a_coupon_hold_is_given_back_by_the_end_of_the_service_and_not_by_the_refund(): void
    {
        /*
         * An order that carries a coupon and has no redemption row holds a use
         * of it — the case FulfilOrderOnSettlement leaves behind when a
         * redemption cannot be written. The same rule as the plan unit: it is
         * held while what was bought is live, whatever happened to the money.
         */
        $customer = $this->customer();
        $plan = $this->sharedHostingPlan('coupon');

        $coupon = Coupon::factory()->code('ONCE')->percentage('0.100')->perCustomer(1)->create();

        $held = Order::factory()->for($customer)->create([
            'coupon_id' => $coupon->getKey(),
            'status' => OrderStatus::Refunded,
        ]);

        $service = Service::factory()->active()->create([
            'customer_id' => $customer->getKey(),
            'order_id' => $held->getKey(),
            'kind' => 'shared_hosting',
        ]);

        try {
            $this->placeWithCoupon($customer, $plan, 'ONCE');
            $this->fail('A refunded order whose service is still live gave its coupon hold back.');
        } catch (CouponCustomerLimitReachedException $e) {
            $this->assertSame('coupon.customer_limit_reached', $e->errorCode());
        }

        $service->forceFill(['status' => ServiceStatus::Terminated, 'terminated_at' => now()])->save();

        $this->assertSame(OrderStatus::PendingPayment, $this->placeWithCoupon($customer, $plan, 'ONCE')->status);
    }

    #[Test]
    public function a_declined_first_payment_is_the_orders_and_a_later_payment_still_settles_it(): void
    {
        $customer = $this->customer();
        $order = $this->placeSharedHostingOrder($customer, $this->sharedHostingPlan());

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('order_id', $order->getKey())->sole();

        event(new PaymentFailed(
            transactionId: 'txn-declined-1',
            customerId: (string) $customer->getKey(),
            invoiceId: (string) $invoice->getKey(),
            provider: 'fake',
            providerReference: 'pi_declined_1',
            amount: Money::ofMinor($invoice->total_minor, $invoice->currency),
            failureCode: 'card_declined',
            failureMessage: 'Your card was declined.',
            failedAt: CarbonImmutable::now(),
        ));

        $this->assertSame(OrderStatus::PaymentFailed, $order->refresh()->status);
        $this->assertNull($order->paid_at);

        // The invoice is still collectible, and a later attempt settles it.
        $this->settleTheInvoiceOf($order);

        $this->assertSame(OrderStatus::Active, $order->refresh()->status);
        $this->assertSame(
            ['pending_payment', 'payment_failed', 'paid', 'queued_for_provisioning', 'provisioning', 'active'],
            $this->trailOf($order),
        );
    }

    #[Test]
    public function a_redelivered_settlement_converges_on_an_order_that_has_moved_on(): void
    {
        /*
         * FulfilOrderOnSettlement converged on "already paid" by asking for
         * `paid` again and being told nothing changed. Once an order moves past
         * `paid`, the same ask is an illegal move backwards — so a redelivered
         * settlement must recognise an order that has moved on, not throw.
         */
        $order = $this->buySharedHosting($this->customer(), $this->sharedHostingPlan());

        $this->assertSame(OrderStatus::Active, $order->status);

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('order_id', $order->getKey())->sole();

        event(new OrderFinanciallySettled(
            orderId: (string) $order->getKey(),
            customerId: (string) $order->customer_id,
            basis: SettlementBasis::InvoicePaid,
            invoiceId: (string) $invoice->getKey(),
            settledAt: CarbonImmutable::now(),
        ));

        $this->assertSame(OrderStatus::Active, $order->refresh()->status);
        $this->assertSame(1, Service::query()->where('order_id', $order->getKey())->count());
    }

    private function endTheServiceOf(Order $order): void
    {
        $subscription = Subscription::query()->where('order_id', $order->getKey())->sole();

        app(TransitionSubscription::class)->execute($subscription, SubscriptionStatus::PastDue);
        app(TransitionSubscription::class)->execute($subscription->refresh(), SubscriptionStatus::Suspended);

        $this->travel(31)->days();

        $service = $this->serviceOf($order);
        app(EndOfService::class)->execute($service);

        $this->assertSame(
            HostingAccountStatus::Terminated,
            HostingAccount::query()->where('service_id', $service->getKey())->sole()->status,
        );
    }

    private function assertTheLastUnitIsTaken(Plan $plan): void
    {
        try {
            $this->placeSharedHostingOrder($this->customer(), $plan);
            $this->fail('The last unit of the plan was sold twice.');
        } catch (CheckoutRejectedException $e) {
            $this->assertSame('checkout.out_of_stock', $e->errorCode());
        }
    }

    private function placeWithCoupon(Customer $customer, Plan $plan, string $code): Order
    {
        return app(PlaceOrder::class)->execute($customer, new CheckoutRequest(
            lines: [$this->checkoutLineFor($plan)],
            billingPeriod: BillingPeriod::Monthly,
            couponCode: $code,
        ));
    }

    private function buy(Customer $customer, Plan $plan): Order
    {
        $order = app(PlaceOrder::class)->execute($customer, new CheckoutRequest(
            lines: [$this->checkoutLineFor($plan)],
            billingPeriod: BillingPeriod::Monthly,
        ));

        $this->settleTheInvoiceOf($order);

        return $order->refresh();
    }

    private function vpsPlan(): Plan
    {
        $product = Product::factory()->create(['kind' => 'vps']);

        $plan = Plan::factory()->create(['product_id' => $product->getKey()]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => 9_000,
        ]);

        return $plan->fresh(['prices', 'product']) ?? $plan;
    }

    private function customer(): Customer
    {
        return Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
    }

    private function serviceOf(Order $order): Service
    {
        return Service::query()->where('order_id', $order->getKey())->sole();
    }

    /**
     * @return list<string>
     */
    private function trailOf(Order $order): array
    {
        return $order->transitions()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(static fn ($transition): string => $transition->to_status->value)
            ->all();
    }
}
