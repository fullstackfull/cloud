<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Application\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Exceptions\InvoiceRefundExceedsPaymentException;
use Lynomia\Modules\Billing\Domain\Exceptions\UnsettleablePaymentException;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Payments\Infrastructure\Models\Refund;
use Lynomia\Modules\Shared\Domain\Exceptions\CurrencyMismatchException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Records money sent back to the customer against an invoice.
 *
 * The refund is recorded on the document, not deducted from it: the totals
 * still say what was billed, `amount_refunded_minor` says what was returned,
 * and the database derives what that leaves owing. An invoice whose lines were
 * rewritten to match a partial refund would no longer be the document the
 * customer received.
 *
 * The ceiling is what the invoice actually took, not what it billed. A refund
 * of money that was never paid is not a refund, it is a credit note against an
 * unpaid invoice, and that is a different document.
 */
final readonly class RecordInvoiceRefund
{
    public function __construct(
        private TransitionInvoice $transitionInvoice,
    ) {}

    /**
     * @param  Refund|null  $refund  the payment-side row this reduction records, when there is
     *                               one; supplying it makes a redelivered refund webhook record
     *                               the reduction once
     *
     * @throws InvoiceRefundExceedsPaymentException
     * @throws CurrencyMismatchException
     */
    public function execute(Invoice $invoice, Money $amount, ?Refund $refund = null): Invoice
    {
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('A refund must be a positive amount.');
        }

        return DB::transaction(function () use ($invoice, $amount, $refund): Invoice {
            /** @var Invoice $locked */
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            if ($amount->currency() !== $locked->currency) {
                throw CurrencyMismatchException::between($locked->currency, $amount->currency());
            }

            if ($refund !== null && ! $this->attach($refund, $locked)) {
                // Already recorded against this invoice; the reduction stands
                // as it is rather than being applied a second time.
                return $locked;
            }

            $refundable = $locked->refundableAmount();

            if ($amount->isGreaterThan($refundable)) {
                throw InvoiceRefundExceedsPaymentException::forInvoice(
                    (string) $locked->getKey(),
                    $refundable,
                    $amount,
                );
            }

            $locked->amount_refunded_minor = $locked->amountRefunded()->plus($amount)->minorUnits();
            $locked->save();

            // amount_due_minor is derived on write; re-read before deciding
            // anything from it.
            $locked->refresh();

            /*
             * Only a fully refunded invoice that was actually paid becomes
             * refunded. An open invoice that took a partial payment and had it
             * returned is still open and still collectible — it has simply
             * gone back to being unpaid.
             */
            if ($locked->status === InvoiceStatus::Paid && $locked->refundableAmount()->isZero()) {
                $locked = $this->transitionInvoice->execute($locked, InvoiceStatus::Refunded);
            }

            return $locked->refresh();
        });
    }

    /**
     * Links the refund to the invoice, reporting whether this call is the one
     * that did it.
     */
    private function attach(Refund $refund, Invoice $invoice): bool
    {
        /** @var Refund $locked */
        $locked = Refund::query()->lockForUpdate()->findOrFail($refund->getKey());

        if ($locked->invoice_id === $invoice->getKey()) {
            return false;
        }

        if ($locked->invoice_id !== null) {
            throw UnsettleablePaymentException::refundAttachedElsewhere(
                (string) $locked->getKey(),
                (string) $locked->invoice_id,
                (string) $invoice->getKey(),
            );
        }

        $locked->invoice_id = (string) $invoice->getKey();
        $locked->save();

        // Keep the caller's copy in step; it is the same row.
        $refund->invoice_id = $locked->invoice_id;
        $refund->syncOriginalAttribute('invoice_id');

        return true;
    }
}
