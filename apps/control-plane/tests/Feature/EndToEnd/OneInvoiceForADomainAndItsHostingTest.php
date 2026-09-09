<?php

declare(strict_types=1);

namespace Tests\Feature\EndToEnd;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Lynomia\Modules\Billing\Application\Actions\IssueInvoice;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Application\DTOs\InvoiceLineDraft;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Billing\Domain\Services\PricingEngine;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricingLine;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Services\TaxResolver;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationState;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainOperation;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Domain\Enums\TransactionKind;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderRegistry;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Buying a domain and hosting together, and paying for both once.
 *
 * ===========================================================================
 * WHAT THIS IS ACTUALLY CHECKING
 * ===========================================================================
 *
 * That the two new product families are on the platform's one billing path
 * rather than beside it. The failure this guards against is the shape every
 * phase of this project has found somewhere: two subsystems that each work,
 * and a customer who gets two invoices for one purchase, or one invoice whose
 * settlement only fulfils half of it.
 *
 * The interesting assertion is the last one. A single settlement has to
 * dispatch the domain registration — the listener has to find the operation on
 * an invoice that also carries unrelated lines, rather than only recognising
 * invoices it wrote itself.
 */
final class OneInvoiceForADomainAndItsHostingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function one_invoice_can_carry_a_domain_and_hosting_and_settling_it_registers_the_name(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        config(['domains.fake.tlds' => ['test']]);
        DomainTld::factory()->onSale()->named('test')->create();

        $domain = Domain::factory()->create([
            'customer_id' => $customer->getKey(),
            'name' => 'together.test',
            'tld' => 'test',
            'state' => DomainState::RegistrationPending,
            'provider' => 'fake',
            'term_years' => 1,
        ]);

        /*
         * Two lines on one invoice: a registry fee and a month of hosting.
         * Priced through the same engine, so the tax on a domain is tax and
         * not a special case.
         */
        $lines = [
            new PricingLine(
                description: 'Domain registration — together.test (1 year)',
                quantity: 1,
                unitPrice: Money::ofMinor(3_500, 'KWD'),
                setupFee: Money::ofMinor(0, 'KWD'),
                discountable: false,
            ),
            new PricingLine(
                description: 'WordPress hosting — starter',
                quantity: 1,
                unitPrice: Money::ofMinor(9_000, 'KWD'),
                setupFee: Money::ofMinor(0, 'KWD'),
            ),
        ];

        $priced = app(PricingEngine::class)->price($lines, app(TaxResolver::class)->forCustomer($customer, now()));

        $invoice = app(IssueInvoice::class)->execute(
            $customer,
            InvoiceLineDraft::zip($priced, $lines, InvoiceItemKind::Plan),
        );

        $this->assertSame(12_500, $invoice->subtotal_minor);
        $this->assertSame(2, $invoice->items()->count());

        $operation = DomainOperation::query()->create([
            'domain_id' => $domain->getKey(),
            'customer_id' => $customer->getKey(),
            'name' => $domain->name,
            'kind' => DomainOperationKind::Register,
            'state' => DomainOperationState::Requested,
            'term_years' => 1,
            'currency' => 'KWD',
            'price_minor' => 3_500,
            'invoice_id' => $invoice->getKey(),
            'provider' => 'fake',
            'idempotency_key' => 'combined-'.$domain->getKey(),
        ]);

        $transaction = Transaction::query()->create([
            'customer_id' => $customer->getKey(),
            'provider' => 'fake',
            'kind' => TransactionKind::Charge,
            'status' => TransactionStatus::Succeeded,
            'amount_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
            'provider_reference' => 'pi_'.Str::random(16),
            'processed_at' => now(),
        ]);

        app(PaymentProviderRegistry::class)->get('fake');

        app(SettleInvoice::class)->execute($invoice, $transaction);

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()?->status);

        /*
         * One payment, and the domain half of it was fulfilled. The listener
         * found its operation on an invoice carrying lines it knows nothing
         * about, which is what "combined billing" has to mean if it means
         * anything.
         */
        $this->assertSame(DomainOperationState::Completed, $operation->fresh()?->state);
        $this->assertSame(DomainState::Active, $domain->fresh()?->state);
    }
}
