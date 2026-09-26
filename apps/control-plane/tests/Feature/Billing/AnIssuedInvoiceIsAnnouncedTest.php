<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Lynomia\Modules\Billing\Application\Actions\IssueInvoice;
use Lynomia\Modules\Billing\Domain\Services\PricingEngine;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricingLine;
use Lynomia\Modules\Billing\Domain\ValueObjects\TaxRate;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Mail\NotificationMail;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\Actions\RenewDueSubscriptions;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * F-46: `InvoiceIssued` was declared, translated, emailed by default — and
 * raised only by the E2E seeder, which is a fixture and not a producer.
 *
 * The platform holds no card on file, so a renewal invoice is money the
 * customer has to come and pay, and the renewal sweep issued one in the middle
 * of the night and told nobody. The subscription's own docblock says the
 * renewal clock may lead the period end "so an invoice reaches the customer
 * before service continues"; nothing made it reach them.
 */
final class AnIssuedInvoiceIsAnnouncedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_issued_invoice_is_announced_once_to_the_accounts_billing_address(): void
    {
        Mail::fake();
        config()->set('billing.payment_terms_days', 7);
        $this->travelTo(CarbonImmutable::parse('2026-03-01 09:00:00'));

        $customer = Customer::factory()->create(['billing_email' => 'finance@example.com', 'currency' => 'KWD']);

        $invoice = $this->issue($customer);

        $told = $this->announcements($customer);
        $this->assertCount(1, $told);

        $notification = $told->first();
        $this->assertNull($notification->user_id, 'An invoice is the account\'s, not one person\'s.');
        $this->assertSame((string) $invoice->getKey(), $notification->subject_id);
        $this->assertSame($invoice->number, $notification->data['number'] ?? null);
        $this->assertSame(Money::ofMinor((int) $invoice->total_minor, 'KWD')->format(), $notification->data['amount'] ?? null);
        $this->assertSame('2026-03-08', $notification->data['due_date'] ?? null);

        Mail::assertSent(NotificationMail::class, static fn (NotificationMail $mail): bool => $mail->hasTo('finance@example.com'));
    }

    #[Test]
    public function issuing_again_for_the_same_order_announces_nothing_new(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'currency' => 'KWD']);

        $first = $this->issue($customer, $order);
        $second = $this->issue($customer, $order);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertCount(1, $this->announcements($customer));
    }

    #[Test]
    public function an_invoice_that_was_rolled_back_was_never_announced(): void
    {
        Mail::fake();
        $customer = Customer::factory()->create(['billing_email' => 'finance@example.com', 'currency' => 'KWD']);

        try {
            DB::transaction(function () use ($customer): void {
                $this->issue($customer);

                throw new RuntimeException('the caller failed after issuing');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, Invoice::query()->count());

        /*
         * Telling a customer about a document that does not exist is worse
         * than telling them nothing: they go looking for it, and the number
         * they were given is one the sequence has burned.
         */
        $this->assertCount(0, $this->announcements($customer));

        // And no mail left before the rollback: a row rolled back with the
        // invoice would be invisible, and an email already sent is not.
        Mail::assertNotSent(NotificationMail::class);
    }

    #[Test]
    public function a_renewal_invoice_issued_by_the_sweep_is_announced(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::parse('2026-01-01 00:00:00'), BillingPeriod::Monthly)
            ->priced(9_000)
            ->create(['customer_id' => $customer->id]);

        $this->travelTo(CarbonImmutable::parse('2026-02-01 00:00:00'));

        $this->assertSame(1, app(RenewDueSubscriptions::class)->execute()->renewed);

        $invoice = Invoice::query()->where('subscription_id', $subscription->id)->sole();
        $told = $this->announcements($customer);

        $this->assertCount(1, $told);
        $this->assertSame((string) $invoice->getKey(), $told->first()->subject_id);
    }

    private function issue(Customer $customer, ?Order $order = null): Invoice
    {
        $lines = [new PricingLine(
            description: 'Cloud VPS',
            quantity: 1,
            unitPrice: Money::of('9.000', 'KWD'),
            setupFee: Money::zero('KWD'),
        )];

        $priced = app(PricingEngine::class)->price($lines, TaxRate::zero());

        return app(IssueInvoice::class)->fromPricedOrder($customer, $priced, $lines, $order);
    }

    /**
     * @return Collection<int, Notification>
     */
    private function announcements(Customer $customer): Collection
    {
        return Notification::query()
            ->where('customer_id', $customer->getKey())
            ->where('type', NotificationType::InvoiceIssued->value)
            ->get();
    }
}
