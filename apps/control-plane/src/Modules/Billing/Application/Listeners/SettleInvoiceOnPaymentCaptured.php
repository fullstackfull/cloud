<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Payments\Domain\Events\PaymentCaptured;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;

/**
 * Applies a captured payment to the invoice it was taken for.
 *
 * This is where billing policy lives, deliberately outside the payments module:
 * payments knows that money arrived, and nothing about what it buys.
 *
 * Queued on the payments queue rather than run inline. The webhook endpoint's
 * job is to acknowledge the provider quickly and durably; doing invoice
 * settlement inside the request means a slow settlement turns into a provider
 * timeout and a redelivery, and a failing settlement turns into a 500 that
 * makes the provider retry an event the platform has already recorded.
 *
 * Safe to retry: SettleInvoice derives amount_paid from the captured charges
 * rather than incrementing it, so applying the same transaction twice applies
 * it once.
 */
final class SettleInvoiceOnPaymentCaptured implements ShouldQueue
{
    public string $queue = 'payments';

    public int $tries = 5;

    /**
     * Exponential-ish backoff. A settlement that fails because the invoice row
     * is locked by a concurrent write succeeds on the next attempt; one that
     * fails because the database is down needs the later, longer waits.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 60, 300];
    }

    public function __construct(
        private readonly SettleInvoice $settle,
    ) {}

    public function handle(PaymentCaptured $event): void
    {
        if ($event->invoiceId === null) {
            /*
             * A capture with no invoice is not an error. A customer topping up
             * their wallet, or paying before an invoice has been issued, both
             * land here legitimately — the money is already recorded as a
             * transaction and will be applied when an invoice exists.
             */
            return;
        }

        $invoice = Invoice::query()->find($event->invoiceId);
        $transaction = Transaction::query()->find($event->transactionId);

        if ($invoice === null || $transaction === null) {
            // Do not throw: retrying cannot make a deleted row reappear, and
            // failing the job forever would bury a real signal in the failed
            // queue.
            Log::warning('Captured payment refers to a row that no longer exists.', [
                'transaction_id' => $event->transactionId,
                'invoice_id' => $event->invoiceId,
            ]);

            return;
        }

        $this->settle->execute($invoice, $transaction);
    }
}
