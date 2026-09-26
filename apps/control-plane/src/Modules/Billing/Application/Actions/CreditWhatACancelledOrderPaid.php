<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Application\Actions;

use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Payments\Domain\Enums\TransactionKind;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;

/**
 * Gives the customer back what they paid for an order that was cancelled
 * (F-05).
 *
 * CancelOrder refuses an order whose invoice has taken money, under the
 * invoice's lock, so a cancelled order beside a paid invoice should not be
 * written any more. Rows written before that refusal existed can still be
 * there, and fulfilment finding one used to throw on every retry until the job
 * landed in failed_jobs, with the money kept and nothing delivered. This is
 * what fulfilment does with such an order instead.
 *
 * Every captured charge applied to the order's invoices goes to the customer's
 * wallet through {@see CompensateUncollectableCapture}, keyed on the capture,
 * so a retried job — or a capture that path already compensated — credits
 * once. The wallet and not the card, for the reason that action gives: nothing
 * automatic pays money out, and a customer who wants it back on the card asks,
 * and an operator issues it from the balance.
 *
 * The invoice is left as it is. It was paid, and that is still true; the
 * wallet entry names the invoice and the capture, which is what a
 * reconciliation needs to see where the money went.
 */
final readonly class CreditWhatACancelledOrderPaid
{
    public function __construct(
        private CompensateUncollectableCapture $compensate,
    ) {}

    /**
     * @return int the minor units newly credited by this call — zero on a retry
     */
    public function execute(string $orderId): int
    {
        $credited = 0;

        $invoices = Invoice::query()->where('order_id', $orderId)->get();

        foreach ($invoices as $invoice) {
            $captures = Transaction::query()
                ->where('invoice_id', $invoice->getKey())
                ->where('customer_id', $invoice->customer_id)
                ->where('currency', $invoice->currency)
                ->where('kind', TransactionKind::Charge->value)
                ->where('status', TransactionStatus::Succeeded->value)
                ->orderBy('id')
                ->get();

            foreach ($captures as $capture) {
                $entry = $this->compensate->execute(
                    $invoice,
                    $capture,
                    sprintf('Payment for invoice %s returned: the order was cancelled', $invoice->number),
                );

                if ($entry !== null && $entry->wasRecentlyCreated) {
                    $credited += $capture->amount_minor;
                }
            }
        }

        return $credited;
    }
}
