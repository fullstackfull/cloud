<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Application\Actions;

use Lynomia\Modules\Billing\Application\DTOs\InvoiceLineDraft;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\ValueObjects\LineTotal;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Orders\Infrastructure\Models\OrderItem;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Issues the invoice for a placed order.
 *
 * The lines are built from the **order's own snapshot**, not by re-pricing
 * against the catalogue. The order already recorded what was sold, at the price
 * the customer was shown, with the tax rate that applied at that moment; asking
 * the catalogue again would mean a price change or a VAT change between
 * checkout and invoicing silently produces an invoice for an amount the
 * customer never agreed to.
 *
 * This is deliberately a thin bridge rather than logic. Everything that decides
 * money already happened in PlaceOrder; everything that decides document
 * behaviour lives in IssueInvoice. This class only translates between them.
 */
final readonly class IssueInvoiceForOrder
{
    public function __construct(
        private IssueInvoice $issueInvoice,
    ) {}

    public function execute(Order $order, ?Customer $customer = null): Invoice
    {
        $customer ??= $order->customer()->firstOrFail();

        $order->loadMissing('items');

        $lines = $order->items
            ->map(fn (OrderItem $item): InvoiceLineDraft => $this->lineFor($order, $item))
            ->all();

        return $this->issueInvoice->execute(
            customer: $customer,
            lines: $lines,
            order: $order,
        );
    }

    private function lineFor(Order $order, OrderItem $item): InvoiceLineDraft
    {
        $currency = $order->currency;

        $unit = Money::ofMinor($item->unit_recurring_minor, $currency);
        $setup = Money::ofMinor($item->unit_setup_minor, $currency);

        // Gross is reconstructed the same way the pricing engine built it —
        // quantity times the unit price, plus a setup fee charged once — so the
        // invoice line and the order line cannot disagree.
        $gross = $unit->multipliedBy($item->quantity)->plus($setup);
        $discount = Money::ofMinor($item->discount_minor, $currency);
        $tax = Money::ofMinor($item->tax_minor, $currency);
        $total = Money::ofMinor($item->total_minor, $currency);

        return new InvoiceLineDraft(
            kind: InvoiceItemKind::Plan,
            description: $item->name,
            quantity: $item->quantity,
            unitAmount: $unit,
            totals: new LineTotal(
                gross: $gross,
                discount: $discount,
                net: $gross->minus($discount),
                tax: $tax,
                total: $total,
                taxRate: (string) $item->tax_rate,
                taxName: $item->tax_name,
            ),
        );
    }
}
