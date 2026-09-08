<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Application\Services;

use Lynomia\Modules\Billing\Application\Actions\IssueInvoice;
use Lynomia\Modules\Billing\Application\DTOs\InvoiceLineDraft;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Services\PricingEngine;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricingLine;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Services\TaxResolver;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * One invoice line, for one thing done to one name.
 *
 * Shared by registration, renewal and transfer so that all three are priced,
 * taxed and described the same way. Three copies of this would be three
 * chances for a domain line to be taxed differently from the domain line
 * beside it on the same invoice.
 */
final readonly class DomainInvoicing
{
    public function __construct(
        private IssueInvoice $invoices,
        private PricingEngine $pricing,
        private TaxResolver $tax,
    ) {}

    public function forOperation(
        Customer $customer,
        DomainOperationKind $kind,
        string $name,
        int $termYears,
        Money $price,
    ): Invoice {
        $line = new PricingLine(
            description: $this->describe($kind, $name, $termYears),
            quantity: 1,
            unitPrice: $price,
            setupFee: Money::ofMinor(0, $price->currency()),

            /*
             * Not discountable. A percentage coupon applied to a domain would
             * come off a registry fee this platform has already agreed to pay,
             * and on a premium name the discount can exceed the entire margin.
             */
            discountable: false,
        );

        $priced = $this->pricing->price([$line], $this->tax->forCustomer($customer, now()));

        return $this->invoices->execute(
            $customer,
            InvoiceLineDraft::zip($priced, [$line], InvoiceItemKind::Plan),
        );
    }

    /**
     * What the customer will read on the invoice, months later, trying to
     * remember what they bought.
     */
    private function describe(DomainOperationKind $kind, string $name, int $termYears): string
    {
        $years = sprintf('%d year%s', $termYears, $termYears === 1 ? '' : 's');

        return match ($kind) {
            DomainOperationKind::Register => sprintf('Domain registration — %s (%s)', $name, $years),
            DomainOperationKind::Renew => sprintf('Domain renewal — %s (%s)', $name, $years),
            DomainOperationKind::Transfer => sprintf('Domain transfer — %s (%s)', $name, $years),
            DomainOperationKind::Redeem => sprintf('Domain redemption — %s', $name),
        };
    }
}
