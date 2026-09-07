<?php

declare(strict_types=1);

namespace Tests\Feature\EndToEnd;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Lynomia\Modules\Billing\Application\Actions\IssueInvoiceForOrder;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Coupon;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Catalog\Infrastructure\Models\TaxRule;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Identity\Infrastructure\Notifications\QueuedVerifyEmail;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Payments\Domain\DTOs\SignedWebhookPayload;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderRegistry;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Infrastructure\Models\Wallet;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The whole commercial path, once, with nothing stubbed between the steps.
 *
 * Every unit in this chain has its own tests. This one exists because the
 * failures that reach customers are almost never inside a unit — they are at
 * the joins: an event nobody wired, a listener on the wrong queue, a
 * transaction that commits after the listener has already read the row.
 *
 * The payment provider is the fake, because there is no Stripe account here.
 * It is not a stub of the code under test: it signs its webhooks with a real
 * HMAC and the platform verifies them through the same path a live provider
 * takes.
 */
final class PurchaseToActiveServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        /** @var FakePaymentProvider $provider */
        $provider = app(PaymentProviderRegistry::class)->get('fake');
        $this->provider = $provider;
    }

    #[Test]
    public function a_customer_registers_pays_and_ends_up_with_an_active_subscription_and_an_invoice(): void
    {
        Notification::fake();

        // ---- 1. The catalogue an operator has set up -------------------------
        TaxRule::query()->create([
            'name' => 'Kuwait VAT',
            'country' => 'KW',
            'rate' => '0.150',
            'is_inclusive' => false,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ]);

        $plan = $this->publishedPlan(monthlyMinor: 9000);

        // ---- 2. The customer registers ---------------------------------------
        $this->postJson(route('api.v1.register'), [
            'name' => 'Amal Al-Sabah',
            'email' => 'amal@example.com',
            'password' => 'correct-horse-9',
            'password_confirmation' => 'correct-horse-9',
            'country' => 'KW',
            'accepts_terms' => true,
        ])->assertAccepted();

        $user = User::query()->where('email', 'amal@example.com')->sole();
        $customer = Customer::query()->sole();

        // Registration does not sign the user in: verification comes first.
        $this->assertGuest();
        $this->assertFalse($user->hasVerifiedEmail());

        // ---- 3. They verify their address ------------------------------------
        /*
         * The link is taken out of the mail that was actually sent, not built
         * here with URL::temporarySignedRoute.
         *
         * Building it is the tempting shortcut and it is what let a real defect
         * through for weeks: the User model carried Laravel's MustVerifyEmail
         * trait without implementing the contract, so no verification mail was
         * ever sent and nothing ever checked the address — while this test,
         * signing its own URL, went green the whole time. A step performed by
         * the test proves the endpoint works. It says nothing about whether the
         * product ever reaches it.
         */
        $verificationUrl = null;

        Notification::assertSentTo(
            $user,
            QueuedVerifyEmail::class,
            function (QueuedVerifyEmail $notification) use ($user, &$verificationUrl): bool {
                $verificationUrl = $notification->toMail($user)->actionUrl;

                return true;
            },
        );

        $this->assertIsString($verificationUrl);
        $this->get($this->apiPathOf($verificationUrl))->assertOk();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        // ---- 4. They sign in ---------------------------------------------------
        $this->postJson(route('api.v1.login'), [
            'email' => 'amal@example.com',
            'password' => 'correct-horse-9',
        ])->assertOk();

        // ---- 5. They place an order -------------------------------------------
        $order = app(PlaceOrder::class)->execute(
            $customer,
            new CheckoutRequest(
                lines: [new CheckoutLine($plan->id, 1)],
                billingPeriod: BillingPeriod::Monthly,
                idempotencyKey: 'checkout-e2e-1',
            ),
            $user,
        );

        $this->assertSame(OrderStatus::PendingPayment, $order->status);
        // 9.000 KWD + 15% VAT.
        $this->assertSame(10350, $order->total_minor);

        // ---- 6. An invoice is issued for it -----------------------------------
        $invoice = $this->issueInvoiceFor($order, $customer);

        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertSame(10350, $invoice->total_minor);
        $this->assertSame(10350, $invoice->amount_due_minor);

        // ---- 7. The provider takes the money and posts a signed webhook -------
        $signed = $this->provider->emitWebhook(
            ProviderEventKind::PaymentSucceeded,
            'pi_e2e_1',
            Money::ofMinor(10350, 'KWD'),
            metadata: ['customer_id' => $customer->id, 'invoice_id' => $invoice->id],
        );

        $this->deliverWebhook($signed);

        // ---- 8. Everything downstream has happened ----------------------------
        $transaction = Transaction::query()->where('provider_reference', 'pi_e2e_1')->sole();
        $this->assertSame('succeeded', $transaction->status->value);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(0, $invoice->amount_due_minor);

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);

        $subscription = Subscription::query()->sole();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertSame(9000, $subscription->recurring_amount_minor);
        $this->assertTrue($subscription->current_period_end->greaterThan(now()));

        // ---- 9. The audit trail explains how it got here ----------------------
        $trail = $order->transitions()->orderBy('created_at')->orderBy('id')->get();
        $this->assertSame(
            ['draft', 'pending_payment', 'paid'],
            $trail->pluck('from_status')->push($trail->last()->to_status)
                ->map(static fn ($s): string => $s->value)->all(),
        );
    }

    #[Test]
    public function a_redelivered_webhook_does_not_produce_a_second_subscription_or_charge(): void
    {
        $plan = $this->publishedPlan();
        [$customer, $order] = $this->orderFor($plan);
        $invoice = $this->issueInvoiceFor($order, $customer);

        $signed = $this->provider->emitWebhook(
            ProviderEventKind::PaymentSucceeded,
            'pi_e2e_dup',
            Money::ofMinor($invoice->total_minor, 'KWD'),
            metadata: ['customer_id' => $customer->id, 'invoice_id' => $invoice->id],
        );

        // Providers redeliver routinely — twice within milliseconds when a
        // handler is slow. The whole chain has to converge, not just ingestion.
        $this->deliverWebhook($signed);
        $this->deliverWebhook($signed);
        $this->deliverWebhook($signed);

        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, Subscription::query()->count());
        $this->assertSame($invoice->total_minor, (int) $invoice->fresh()->amount_paid_minor);
        $this->assertSame(0, (int) $invoice->fresh()->amount_due_minor);
    }

    #[Test]
    public function a_declined_payment_leaves_the_order_unpaid_and_provisions_nothing(): void
    {
        $plan = $this->publishedPlan();
        [$customer, $order] = $this->orderFor($plan);
        $invoice = $this->issueInvoiceFor($order, $customer);

        $signed = $this->provider->emitWebhook(
            ProviderEventKind::PaymentFailed,
            'pi_e2e_declined',
            Money::ofMinor($invoice->total_minor, 'KWD'),
            metadata: ['customer_id' => $customer->id, 'invoice_id' => $invoice->id],
            failureCode: 'card_declined',
        );

        $this->deliverWebhook($signed);

        // The single most important rule in the platform: no money, no service.
        $this->assertSame(InvoiceStatus::Open, $invoice->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);
        $this->assertSame(0, Subscription::query()->count());
    }

    #[Test]
    public function a_coupon_is_redeemed_only_once_the_order_is_actually_paid(): void
    {
        $plan = $this->publishedPlan(monthlyMinor: 10000);

        $coupon = Coupon::query()->create([
            'code' => 'LAUNCH10',
            'discount_type' => 'percentage',
            'percentage' => '0.100',
            'is_active' => true,
            'max_redemptions' => 1,
            'max_redemptions_per_customer' => 1,
            'redemption_count' => 0,
        ]);

        [$customer, $order] = $this->orderFor($plan, couponCode: 'LAUNCH10');

        $this->assertSame(9000, $order->total_minor);
        $this->assertSame($coupon->id, $order->coupon_id);

        // Still zero: an abandoned basket must not consume a single-use code.
        $this->assertSame(0, (int) $coupon->fresh()->redemption_count);

        $invoice = $this->issueInvoiceFor($order, $customer);
        $signed = $this->provider->emitWebhook(
            ProviderEventKind::PaymentSucceeded,
            'pi_e2e_coupon',
            Money::ofMinor($invoice->total_minor, 'KWD'),
            metadata: ['customer_id' => $customer->id, 'invoice_id' => $invoice->id],
        );
        $this->deliverWebhook($signed);

        $this->assertSame(1, (int) $coupon->fresh()->redemption_count);
        $this->assertSame(1, DB::table('coupon_redemptions')->count());
    }

    #[Test]
    public function an_overpayment_is_credited_to_the_wallet_rather_than_lost(): void
    {
        $plan = $this->publishedPlan();
        [$customer, $order] = $this->orderFor($plan);
        $invoice = $this->issueInvoiceFor($order, $customer);

        $signed = $this->provider->emitWebhook(
            ProviderEventKind::PaymentSucceeded,
            'pi_e2e_over',
            Money::ofMinor($invoice->total_minor + 500, 'KWD'),
            metadata: ['customer_id' => $customer->id, 'invoice_id' => $invoice->id],
        );

        $this->deliverWebhook($signed);

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);

        // By settlement time the money has already left the customer's account.
        $wallet = Wallet::query()
            ->where('customer_id', $customer->id)->sole();
        $this->assertSame(500, (int) $wallet->balance_minor);
    }

    // ---- helpers -------------------------------------------------------------

    private function publishedPlan(int $monthlyMinor = 9000): Plan
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

    /**
     * @return array{0: Customer, 1: Order}
     */
    private function orderFor(Plan $plan, ?string $couponCode = null): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        $order = app(PlaceOrder::class)->execute($customer, new CheckoutRequest(
            lines: [new CheckoutLine($plan->id, 1)],
            billingPeriod: BillingPeriod::Monthly,
            couponCode: $couponCode,
        ));

        return [$customer, $order];
    }

    private function issueInvoiceFor(
        Order $order,
        Customer $customer,
    ): Invoice {
        return app(IssueInvoiceForOrder::class)
            ->execute($order, $customer);
    }

    private function deliverWebhook(SignedWebhookPayload $signed): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($signed->headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->call('POST', route('webhooks.receive', ['provider' => 'fake']), server: $server, content: $signed->rawPayload)
            ->assertOk();
    }

    /**
     * The verification link is absolute and points at the API host. The test
     * client wants a path, and reducing it here keeps the signature intact —
     * rebuilding the URL would defeat the point of reading it from the mail.
     */
    private function apiPathOf(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
