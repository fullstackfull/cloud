<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Exceptions\PaidInvoiceCannotBeVoidedException;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;

/**
 * Withdraws an invoice that should not have been issued.
 *
 * Voiding is only legal before any money has arrived, and the test is the
 * money rather than the status: an invoice that is still open because it was
 * only half paid has taken a payment, and voiding it would leave that payment
 * attached to a document the platform says never applied. The state machine
 * refuses paid → void on its own; this action refuses the partially paid case
 * the machine cannot see.
 *
 * A void invoice keeps its number. Numbers are a series a tax authority may
 * audit, and a gap that turns out to be a withdrawn document is answerable in
 * a way a recycled number is not.
 */
final readonly class VoidInvoice
{
    public function __construct(
        private TransitionInvoice $transitionInvoice,
    ) {}

    /**
     * @throws PaidInvoiceCannotBeVoidedException
     */
    public function execute(Invoice $invoice, ?string $reason = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason): Invoice {
            /** @var Invoice $locked */
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            if ($locked->status === InvoiceStatus::Void) {
                return $locked;
            }

            if ($locked->amountPaid()->isPositive()) {
                throw PaidInvoiceCannotBeVoidedException::forInvoice(
                    (string) $locked->getKey(),
                    $locked->amountPaid(),
                );
            }

            if ($reason !== null) {
                // Written before the transition, which re-reads the row: the
                // reason is part of what the void means and must not be lost
                // between the two writes.
                $locked->notes = $reason;
                $locked->save();
            }

            return $this->transitionInvoice->execute($locked, InvoiceStatus::Void)->refresh();
        });
    }
}
