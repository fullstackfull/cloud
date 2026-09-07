<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A placed order must arrive at something the customer can pay.
 *
 * The chain is OrderPlaced → PaymentCaptured → InvoicePaid, and its first link
 * was absent: checkout left the order in PENDING_PAYMENT with no invoice, so
 * there was nothing to start a payment against and nothing downstream could
 * ever run. It survived review because the end-to-end test issued the invoice
 * itself at exactly the point where the platform did not.
 */
final class PlacingAnOrderIssuesItsInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
    }

    private function plan(int $monthlyMinor = 9_000): Plan
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => $monthlyMinor,
            'setup_amount_minor' => 0,
        ]);

        return $plan->fresh(['prices', 'product']);
    }

    private function place(Plan $plan, ?string $key = null, int $quantity = 1): Order
    {
        return app(PlaceOrder::class)->execute(
            $this->customer,
            new CheckoutRequest(
                lines: [new CheckoutLine($plan->id, $quantity)],
                billingPeriod: BillingPeriod::Monthly,
                couponCode: null,
                idempotencyKey: $key,
            ),
        );
    }

    #[Test]
    public function an_order_becomes_payable_the_moment_it_is_placed(): void
    {
        $order = $this->place($this->plan(9_000));

        $this->assertSame(OrderStatus::PendingPayment, $order->status);

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('order_id', $order->id)->sole();

        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        // The invoice is built from the order's own snapshot, so the two agree
        // to the fils: an invoice for a different amount from the one the
        // customer agreed to is the worst possible outcome here.
        $this->assertSame($order->total_minor, $invoice->total_minor);
        $this->assertSame($order->currency, $invoice->currency);
        $this->assertSame($order->total_minor, $invoice->amount_due_minor);
    }

    #[Test]
    public function a_double_clicked_checkout_produces_one_invoice(): void
    {
        $plan = $this->plan();

        $first = $this->place($plan, key: 'checkout-once');
        $second = $this->place($plan, key: 'checkout-once');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Invoice::query()->where('order_id', $first->id)->count());
        $this->assertSame(1, Invoice::query()->where('customer_id', $this->customer->id)->count());
    }

    #[Test]
    public function two_separate_purchases_get_an_invoice_each(): void
    {
        $plan = $this->plan();

        $first = $this->place($plan, key: 'checkout-a');
        $second = $this->place($plan, key: 'checkout-b');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, Invoice::query()->where('customer_id', $this->customer->id)->count());
    }

    #[Test]
    public function the_invoice_lines_come_from_the_order_and_not_from_the_catalogue(): void
    {
        $plan = $this->plan(9_000);
        $order = $this->place($plan, quantity: 3);

        // The catalogue price changes after the purchase. The invoice must
        // still be for what was bought at the price that was shown.
        PlanPrice::query()->where('plan_id', $plan->id)->update(['recurring_amount_minor' => 99_000]);

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('order_id', $order->id)->sole();

        $this->assertSame($order->total_minor, $invoice->total_minor);
        $this->assertSame(3, $invoice->items()->sole()->quantity);
    }
}
