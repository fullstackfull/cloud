<?php

declare(strict_types=1);

namespace Tests\Feature\Simulation;

use Illuminate\Support\Carbon;
use Lynomia\Modules\Billing\Application\Actions\IssueInvoice;
use Lynomia\Modules\Billing\Application\DTOs\InvoiceLineDraft;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Services\PricingEngine;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricingLine;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Services\TaxResolver;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationState;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Infrastructure\DomainRegistrarFactory;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainOperation;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A name bought, held, and renewed — through the generic registrar contract.
 *
 * ---------------------------------------------------------------------------
 * What this does not prove
 * ---------------------------------------------------------------------------
 *
 * Anything about `.sy`. The Syrian registry's protocol, endpoints,
 * authentication, contact requirements and term limits are not in this
 * project, its adapter is `NOT_IMPLEMENTED — PROVIDER CONTRACT UNAVAILABLE`,
 * and no amount of exercising a controlled registrar changes that. What passes
 * here is the platform's own software: an invoice settles, a registration is
 * carried out by a worker, the name is held, and a renewal moves the expiry by
 * a term rather than by however long the test took to run.
 *
 * The clock is travelled rather than waited on. A renewal is the one domain
 * operation whose correctness is a date, and a test that slept until expiry
 * would be a test nobody runs.
 */
#[Group('golden-path')]
final class TheDomainGoldenPathTest extends GoldenPathHarness
{
    private const string PAYMENTS_QUEUE = 'payments';

    #[Test]
    public function a_paid_invoice_makes_a_worker_register_the_name_and_the_registrar_hold_it(): void
    {
        [$customer, $domain, $invoice] = $this->committedRegistration('golden-domain-1');

        $this->payThroughTheProvider($invoice, $customer, 'pi_golden_domain_1');

        // Nothing has happened at the registrar: settlement is queued, and so
        // is the registration it leads to.
        $this->assertSame(DomainState::RegistrationPending, $domain->fresh()?->state);

        $this->work(self::PAYMENTS_QUEUE);

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()?->status);

        /*
         * The registration itself runs on the default queue, dispatched by the
         * listener the settlement woke. Two queues, three processes, and the
         * name is only held once the last of them has run.
         */
        $this->work('default');

        $this->assertSame(DomainState::Active, $domain->fresh()?->state);

        $operation = DomainOperation::query()->where('domain_id', $domain->getKey())->sole();
        $this->assertSame(DomainOperationState::Completed, $operation->state);

        // ---- the registrar's own portfolio, read in this process -----------
        $registrar = app(DomainRegistrarFactory::class)->make('fake');

        $held = $registrar->inspect($domain->name);

        $this->assertSame($domain->name, $held->name, 'the worker registered a name this process cannot see');
        $this->assertNotNull($domain->fresh()?->expires_at);
    }

    #[Test]
    public function a_renewal_moves_the_expiry_by_a_term_rather_than_by_the_length_of_the_test(): void
    {
        [$customer, $domain, $invoice] = $this->committedRegistration('golden-domain-renew');

        $this->payThroughTheProvider($invoice, $customer, 'pi_golden_domain_renew');
        $this->work(self::PAYMENTS_QUEUE);
        $this->work('default');

        $registered = $domain->fresh();
        $this->assertSame(DomainState::Active, $registered?->state);

        $firstExpiry = $registered->expires_at;
        $this->assertNotNull($firstExpiry);

        /*
         * Eleven months on, which is when a renewal notice goes out and a
         * customer pays it. Travelled rather than slept: the platform reads the
         * clock, so the clock is the input.
         */
        Carbon::setTestNow($firstExpiry->copy()->subMonth());

        try {
            $renewal = $this->committedRenewal($customer, $domain, 'golden-domain-renew-2');

            $this->payThroughTheProvider($renewal, $customer, 'pi_golden_domain_renew_2');
            $this->work(self::PAYMENTS_QUEUE);
            $this->work('default');

            $renewed = $domain->fresh();

            $this->assertSame(DomainState::Active, $renewed?->state);
            $this->assertNotNull($renewed->expires_at);
            $this->assertTrue(
                $renewed->expires_at->greaterThan($firstExpiry),
                'the renewal did not move the expiry',
            );

            // A year further out, within a day either way of the registry's own
            // arithmetic. Asserted as a span rather than a date so that a test
            // run on the 29th of February is not a failure.
            $this->assertEqualsWithDelta(
                365,
                $firstExpiry->diffInDays($renewed->expires_at, absolute: true),
                2.0,
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * A customer, a name pending registration, and the invoice that pays for it.
     *
     * @return array{0: Customer, 1: Domain, 2: Invoice}
     */
    private function committedRegistration(string $label): array
    {
        return $this->outsideTheTransaction(function () use ($label): array {
            config(['domains.fake.tlds' => ['test']]);

            DomainTld::factory()->onSale()->named('test')->create();

            $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

            $domain = Domain::factory()->create([
                'customer_id' => $customer->getKey(),
                'name' => $label.'.test',
                'tld' => 'test',
                'state' => DomainState::RegistrationPending,
                'provider' => 'fake',
                'term_years' => 1,
            ]);

            $invoice = $this->invoiceFor($customer, 'Domain registration — '.$domain->name.' (1 year)');

            DomainOperation::query()->create([
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
                'idempotency_key' => $label.'-register',
            ]);

            return [$customer, $domain, $invoice];
        });
    }

    private function committedRenewal(Customer $customer, Domain $domain, string $label): Invoice
    {
        return $this->outsideTheTransaction(function () use ($customer, $domain, $label): Invoice {
            $invoice = $this->invoiceFor($customer, 'Domain renewal — '.$domain->name.' (1 year)');

            DomainOperation::query()->create([
                'domain_id' => $domain->getKey(),
                'customer_id' => $customer->getKey(),
                'name' => $domain->name,
                'kind' => DomainOperationKind::Renew,
                'state' => DomainOperationState::Requested,
                'term_years' => 1,
                'currency' => 'KWD',
                'price_minor' => 3_500,
                'invoice_id' => $invoice->getKey(),
                'provider' => 'fake',
                'idempotency_key' => $label.'-renew',
            ]);

            return $invoice;
        });
    }

    private function invoiceFor(Customer $customer, string $description): Invoice
    {
        $lines = [
            new PricingLine(
                description: $description,
                quantity: 1,
                unitPrice: Money::ofMinor(3_500, 'KWD'),
                setupFee: Money::ofMinor(0, 'KWD'),
                discountable: false,
            ),
        ];

        $priced = app(PricingEngine::class)->price(
            $lines,
            app(TaxResolver::class)->forCustomer($customer, now()),
        );

        return app(IssueInvoice::class)->execute(
            $customer,
            InvoiceLineDraft::zip($priced, $lines, InvoiceItemKind::Plan),
        );
    }
}
