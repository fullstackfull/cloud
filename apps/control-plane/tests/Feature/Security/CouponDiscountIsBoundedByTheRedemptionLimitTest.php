<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Application\Actions\IssueInvoiceForOrder;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Domain\Exceptions\CouponFullyRedeemedException;
use Lynomia\Modules\Catalog\Infrastructure\Models\Coupon;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\Actions\TransitionOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Payments\Domain\DTOs\SignedWebhookPayload;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderRegistry;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression: a single-use coupon's DISCOUNT can only be granted once.
 *
 * RedeemCoupon was already correct — it locks the coupon row, re-validates and
 * refuses a second redemption. But the discount is not granted by RedeemCoupon.
 * It is granted by PlaceOrder, and redemption is deferred to
 * FulfilOrderOnSettlement, which deliberately swallows a redemption failure so
 * that a paying customer is never denied the service they bought.
 *
 * So the counter bounded the audit trail and nothing else: any number of orders
 * placed before the first one was paid all carried the discount, all invoiced at
 * the discounted amount and all fulfilled, while redemption_count stopped at the
 * limit. No threads and no narrow race — the window was the whole interval
 * between checkout and payment.
 *
 * PlaceOrder now counts an unpaid order that carries the coupon as holding one
 * of its uses, the same way an unpaid order already holds plan stock, and takes
 * the coupon row lock while it does so.
 */
final class CouponDiscountIsBoundedByTheRedemptionLimitTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var FakePaymentProvider $provider */
        $provider = app(PaymentProviderRegistry::class)->get('fake');
        $this->provider = $provider;
    }

    #[Test]
    public function a_second_order_cannot_be_placed_on_a_single_use_coupon(): void
    {
        $plan = $this->publishedPlan(monthlyMinor: 10000);

        $coupon = Coupon::query()->create([
            'code' => 'HALFOFF',
            'discount_type' => 'percentage',
            'percentage' => '0.500',
            'is_active' => true,
            // One use in total, and one use for this customer. Both limits.
            'max_redemptions' => 1,
            'max_redemptions_per_customer' => 1,
            'redemption_count' => 0,
        ]);

        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        // The first basket takes the one use the campaign funded.
        $first = $this->placeOrder($customer, $plan, 'basket-1');

        $this->assertSame(5000, $first->discount_minor);
        $this->assertSame(5000, $first->total_minor);

        // The second is opened before the first is paid — the ordinary shape of
        // "open two tabs", not a millisecond race — and must be refused while
        // the first is still holding the use.
        try {
            $this->placeOrder($customer, $plan, 'basket-2');
            $this->fail('A second order was placed on a coupon limited to one redemption.');
        } catch (CouponFullyRedeemedException) {
            // Expected.
        }

        $this->assertSame(1, Order::query()->count());

        // The held use converts into the redemption when the order is paid.
        $firstInvoice = $this->payFor($first, $customer, 'pi_coupon_1');

        $this->assertSame(InvoiceStatus::Paid, $firstInvoice->fresh()->status);
        $this->assertSame(OrderStatus::Paid, $first->fresh()->status);
        $this->assertSame(1, Subscription::query()->count());
        $this->assertSame(1, (int) $coupon->fresh()->redemption_count);
        $this->assertSame(1, DB::table('coupon_redemptions')->count());

        // And the discount actually given never exceeds what the coupon funded.
        $this->assertSame(
            5000,
            (int) Order::query()->sum('discount_minor'),
            'More discount was granted than the coupon was funded for.',
        );

        // Still refused once the redemption is on the books.
        $this->expectException(CouponFullyRedeemedException::class);
        $this->placeOrder($customer, $plan, 'basket-3');
    }

    #[Test]
    public function a_cancelled_order_gives_its_held_use_back(): void
    {
        $plan = $this->publishedPlan(monthlyMinor: 10000);

        Coupon::query()->create([
            'code' => 'HALFOFF',
            'discount_type' => 'percentage',
            'percentage' => '0.500',
            'is_active' => true,
            'max_redemptions' => 1,
            'max_redemptions_per_customer' => 1,
            'redemption_count' => 0,
        ]);

        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        $abandoned = $this->placeOrder($customer, $plan, 'basket-1');

        app(TransitionOrder::class)->execute(
            $abandoned,
            OrderStatus::Cancelled,
            actorType: 'system',
            reason: 'abandoned basket',
        );

        // A coupon must not be held hostage by a customer who closed the tab.
        $replacement = $this->placeOrder($customer, $plan, 'basket-2');

        $this->assertSame(5000, $replacement->discount_minor);
    }

    private function placeOrder(Customer $customer, Plan $plan, string $idempotencyKey): Order
    {
        return app(PlaceOrder::class)->execute($customer, new CheckoutRequest(
            lines: [new CheckoutLine($plan->id, 1)],
            billingPeriod: BillingPeriod::Monthly,
            couponCode: 'HALFOFF',
            idempotencyKey: $idempotencyKey,
        ));
    }

    private function payFor(Order $order, Customer $customer, string $reference): Invoice
    {
        $invoice = app(IssueInvoiceForOrder::class)->execute($order, $customer);

        $this->deliverWebhook($this->provider->emitWebhook(
            ProviderEventKind::PaymentSucceeded,
            $reference,
            Money::ofMinor($invoice->total_minor, 'KWD'),
            metadata: ['customer_id' => $customer->id, 'invoice_id' => $invoice->id],
        ));

        return $invoice;
    }

    private function publishedPlan(int $monthlyMinor): Plan
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => $monthlyMinor,
        ]);

        return $plan->fresh(['prices', 'product']);
    }

    private function deliverWebhook(SignedWebhookPayload $signed): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($signed->headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->call(
            'POST',
            route('webhooks.receive', ['provider' => 'fake']),
            server: $server,
            content: $signed->rawPayload,
        )->assertOk();
    }
}
