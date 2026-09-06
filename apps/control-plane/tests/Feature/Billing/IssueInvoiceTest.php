<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Application\Actions\IssueInvoice;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Services\PricingEngine;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricingLine;
use Lynomia\Modules\Billing\Domain\ValueObjects\TaxRate;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Billing\Infrastructure\Models\InvoiceItem;
use Lynomia\Modules\Billing\Infrastructure\Services\InvoiceNumberAllocator;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Shared\Domain\Exceptions\CurrencyMismatchException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IssueInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private IssueInvoice $issue;

    private PricingEngine $pricing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->issue = app(IssueInvoice::class);
        $this->pricing = app(PricingEngine::class);

        config()->set('billing.invoice_number.prefix', 'LYN');
        config()->set('billing.invoice_number.padding', 6);
        config()->set('billing.payment_terms_days', 7);

        // A sequence is deliberately not transactional, so RefreshDatabase's
        // rollback does not rewind it between tests.
        DB::statement("SELECT setval('invoice_number_seq', 1, false)");
    }

    #[Test]
    public function issuing_twice_for_the_same_order_returns_one_invoice_and_consumes_one_number(): void
    {
        $customer = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id, 'currency' => 'KWD']);

        $first = $this->issueFor($customer, [$this->line('Cloud VPS', '9.000')], order: $order);
        $second = $this->issueFor($customer, [$this->line('Cloud VPS', '9.000')], order: $order);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('LYN-000001', $first->number);
        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(1, InvoiceItem::query()->count());

        /*
         * The number the next invoice gets proves the repeat call never
         * reached the sequence. Checking only the invoice count would pass
         * even if a number had been allocated and thrown away, which is the
         * failure that shows up months later as an unexplained gap in a
         * series a tax authority is reading.
         */
        $this->assertSame('LYN-000002', app(InvoiceNumberAllocator::class)->next());
    }

    #[Test]
    public function an_issued_invoice_is_open_and_dated_from_the_configured_payment_terms(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-01 09:00:00'));
        config()->set('billing.payment_terms_days', 14);

        $customer = Customer::factory()->create();
        $invoice = $this->issueFor($customer, [$this->line('Cloud VPS', '9.000')]);

        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertTrue($invoice->issued_at->equalTo(CarbonImmutable::parse('2026-03-01 09:00:00')));
        $this->assertTrue($invoice->due_at->equalTo(CarbonImmutable::parse('2026-03-15 09:00:00')));
        $this->assertNull($invoice->paid_at);
    }

    #[Test]
    public function the_generated_amount_due_starts_at_the_full_total(): void
    {
        $customer = Customer::factory()->create();
        $invoice = $this->issueFor($customer, [$this->line('Cloud VPS', '9.000')], tax: TaxRate::of('0.15', 'KW VAT'));

        $this->assertTrue($invoice->amountDue()->equals($invoice->total()));
        $this->assertTrue($invoice->amountPaid()->isZero());
        $this->assertAmountDueAgrees($invoice);
    }

    #[Test]
    public function line_totals_sum_exactly_to_the_invoice_total(): void
    {
        $customer = Customer::factory()->create();

        /*
         * Amounts chosen so that both the discount allocation and the tax
         * round: a 10% coupon over three lines of odd fils, then 15% on each
         * discounted line. If the invoice total were computed from the order
         * gross rather than summed from the lines, it would land a fil or two
         * away from what the lines say.
         */
        $invoice = $this->issueFor(
            $customer,
            [
                $this->line('Cloud VPS', '3.333'),
                $this->line('Backup space', '7.777'),
                $this->line('Extra IPv4', '1.111'),
            ],
            tax: TaxRate::of('0.15', 'KW VAT'),
            percentageDiscount: '0.10',
        );

        $items = $invoice->items()->get();
        $this->assertCount(3, $items);

        $sumOfLines = $items->reduce(
            static fn (Money $carry, InvoiceItem $item): Money => $carry->plus($item->total()),
            Money::zero('KWD'),
        );

        $this->assertTrue(
            $sumOfLines->equals($invoice->total()),
            sprintf('Lines sum to %s but the invoice says %s.', $sumOfLines, $invoice->total()),
        );

        // The breakdown has to close as well, not just the bottom line.
        $this->assertSame(
            $invoice->subtotal()->plus($invoice->tax())->minorUnits(),
            $invoice->total()->minorUnits(),
        );
        $this->assertSame(
            (int) $items->sum('tax_minor'),
            $invoice->tax()->minorUnits(),
        );
        $this->assertSame(
            (int) $items->sum('discount_minor'),
            $invoice->discount()->minorUnits(),
        );
    }

    #[Test]
    public function each_line_carries_the_tax_rate_and_name_it_was_charged(): void
    {
        $customer = Customer::factory()->create();
        $invoice = $this->issueFor($customer, [$this->line('Cloud VPS', '10.000')], tax: TaxRate::of('0.15', 'KW VAT'));

        /** @var InvoiceItem $item */
        $item = $invoice->items()->firstOrFail();

        $this->assertSame('0.150000', $item->tax_rate);
        $this->assertSame('KW VAT', $item->tax_name);
        $this->assertSame(InvoiceItemKind::Plan, $item->kind);
        $this->assertSame(1500, $item->tax()->minorUnits());
        $this->assertTrue($item->taxRate()->taxOn(Money::of('10.000', 'KWD'))->equals(Money::of('1.500', 'KWD')));
    }

    #[Test]
    public function the_billing_snapshot_does_not_change_when_the_customer_later_edits_their_address(): void
    {
        $customer = Customer::factory()->create([
            'display_name' => 'Al-Salem Trading',
            'address_line1' => '12 Fahaheel Road',
            'city' => 'Kuwait City',
            'country' => 'KW',
            'tax_id' => 'KW-0001',
        ]);

        $invoice = $this->issueFor($customer, [$this->line('Cloud VPS', '9.000')]);

        $customer->update([
            'display_name' => 'Al-Salem Holdings',
            'address_line1' => '400 Gulf Street',
            'city' => 'Salmiya',
            'tax_id' => 'KW-0002',
        ]);

        $invoice->refresh();

        /*
         * The invoice is a document that may already have been sent and filed
         * for tax. A join to the customer would quietly rewrite history the
         * moment they move offices.
         */
        $this->assertSame('Al-Salem Trading', $invoice->billing_snapshot['display_name']);
        $this->assertSame('12 Fahaheel Road', $invoice->billing_snapshot['address']['line1']);
        $this->assertSame('Kuwait City', $invoice->billing_snapshot['address']['city']);
        $this->assertSame('KW-0001', $invoice->billing_snapshot['tax_id']);
        $this->assertSame('Al-Salem Holdings', $customer->fresh()->display_name);
    }

    #[Test]
    public function a_three_decimal_and_a_two_decimal_currency_both_round_trip_exactly(): void
    {
        $kuwaiti = Customer::factory()->create(['currency' => 'KWD']);
        $american = Customer::factory()->create(['currency' => 'USD']);

        $kwd = $this->issueFor($kuwaiti, [$this->line('Cloud VPS', '12.345', 'KWD', 3)], tax: TaxRate::of('0.15', 'KW VAT'));
        $usd = $this->issueFor($american, [$this->line('Cloud VPS', '12.34', 'USD', 3)], tax: TaxRate::of('0.15', 'US tax'));

        // 12.345 × 3 = 37.035, tax 5.55525 → 5.555 half-up, total 42.590.
        $this->assertSame(37_035, $kwd->subtotal()->minorUnits());
        $this->assertSame('37.035', $kwd->subtotal()->toDecimalString());
        $this->assertSame('5.555', $kwd->tax()->toDecimalString());
        $this->assertSame('42.590', $kwd->total()->toDecimalString());
        $this->assertSame('KWD', $kwd->total()->currency());

        // 12.34 × 3 = 37.02, tax 5.553 → 5.55 half-up, total 42.57.
        $this->assertSame(3_702, $usd->subtotal()->minorUnits());
        $this->assertSame('37.02', $usd->subtotal()->toDecimalString());
        $this->assertSame('5.55', $usd->tax()->toDecimalString());
        $this->assertSame('42.57', $usd->total()->toDecimalString());
        $this->assertSame('USD', $usd->total()->currency());

        // Read back from PostgreSQL rather than trusting the in-memory model.
        $this->assertSame('42.590', $kwd->fresh()->total()->toDecimalString());
        $this->assertSame('42.57', $usd->fresh()->total()->toDecimalString());
    }

    #[Test]
    public function an_invoice_cannot_mix_currencies_with_the_order_it_bills(): void
    {
        $customer = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id, 'currency' => 'USD']);

        $this->expectException(CurrencyMismatchException::class);

        $this->issueFor($customer, [$this->line('Cloud VPS', '9.000')], order: $order);
    }

    #[Test]
    public function the_invoice_records_the_order_and_the_customer_it_bills(): void
    {
        $customer = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id, 'currency' => 'KWD']);

        $invoice = $this->issueFor($customer, [$this->line('Cloud VPS', '9.000')], order: $order);

        $this->assertSame($order->id, $invoice->order_id);
        $this->assertSame($customer->id, $invoice->customer_id);
        $this->assertSame($customer->id, $invoice->customer->id);
        $this->assertSame($order->id, $invoice->order->id);
    }

    /**
     * @param  list<PricingLine>  $lines
     */
    private function issueFor(
        Customer $customer,
        array $lines,
        ?TaxRate $tax = null,
        ?Order $order = null,
        ?string $percentageDiscount = null,
    ): Invoice {
        $priced = $this->pricing->price($lines, $tax ?? TaxRate::zero(), percentageDiscount: $percentageDiscount);

        return $this->issue->fromPricedOrder($customer, $priced, $lines, $order);
    }

    private function line(string $description, string $unitPrice, string $currency = 'KWD', int $quantity = 1): PricingLine
    {
        return new PricingLine(
            description: $description,
            quantity: $quantity,
            unitPrice: Money::of($unitPrice, $currency),
            setupFee: Money::zero($currency),
        );
    }

    private function assertAmountDueAgrees(Invoice $invoice): void
    {
        /** @var object{total_minor: int|string, amount_paid_minor: int|string, amount_refunded_minor: int|string, amount_due_minor: int|string} $row */
        $row = DB::table('invoices')->where('id', $invoice->getKey())->firstOrFail();

        $this->assertSame(
            (int) $row->total_minor - (int) $row->amount_paid_minor + (int) $row->amount_refunded_minor,
            (int) $row->amount_due_minor,
        );
    }
}
