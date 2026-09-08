<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Application\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Lynomia\Modules\Billing\Domain\Events\InvoicePaid;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Payments\Domain\Events\PaymentFailed;
use Lynomia\Modules\Payments\Domain\Events\RefundIssued;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Tells a customer about their money.
 *
 * Billing notifications are the ones a customer cannot switch off, so this is
 * also the listener that has to be most careful about volume: nothing here
 * fires on a state the customer already knows about because they were looking
 * at the screen when it happened.
 *
 * Amounts are formatted here rather than in the template, because the sentence
 * is one translated string with a `:amount` placeholder and the currency has
 * to be inside it. Money::format keeps Western digits in Arabic, which is what
 * a customer reconciling against a bank statement needs.
 */
final class NotifyOnBillingEvent implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public function __construct(
        private readonly NotifyCustomer $notify,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            InvoicePaid::class => 'invoicePaid',
            PaymentFailed::class => 'paymentFailed',
            RefundIssued::class => 'refundIssued',
        ];
    }

    public function invoicePaid(InvoicePaid $event): void
    {
        $invoice = Invoice::query()->find($event->invoiceId);

        if ($invoice === null) {
            return;
        }

        /*
         * A renewal that went through gets a different message from a first
         * purchase: one is a receipt, the other is "nothing is about to be
         * taken away from you", and a customer who has been in dunning wants
         * the second in those words.
         */
        $type = $invoice->subscription_id === null
            ? NotificationType::PaymentSucceeded
            : NotificationType::RenewalSucceeded;

        $this->notify->execute(
            customerId: $event->customerId,
            type: $type,
            idempotencyKey: 'invoice-paid:'.$event->invoiceId,
            subject: $invoice,
            data: [
                'amount' => $this->money($invoice->total_minor, $invoice->currency),
                'number' => $invoice->number,
                'service' => $invoice->number,
                'date' => $invoice->due_at?->toDateString() ?? '',
            ],
            link: '/invoices',
        );
    }

    public function paymentFailed(PaymentFailed $event): void
    {
        $invoice = $event->invoiceId === null ? null : Invoice::query()->find($event->invoiceId);

        $this->notify->execute(
            customerId: $event->customerId,
            type: NotificationType::PaymentFailed,
            /*
             * Keyed on the provider's own reference rather than the
             * transaction: a provider that reports the same failed attempt
             * twice — which they do — must not tell the customer twice.
             */
            idempotencyKey: 'payment-failed:'.$event->provider.':'.$event->providerReference,
            subject: $invoice,
            data: [
                'amount' => $event->amount->format(),
                'number' => $invoice === null ? '' : $invoice->number,
            ],
            link: '/invoices',
        );
    }

    public function refundIssued(RefundIssued $event): void
    {
        $this->notify->execute(
            customerId: $event->customerId,
            type: NotificationType::RefundIssued,
            idempotencyKey: 'refund-issued:'.$event->refundId,
            data: ['amount' => $event->amount->format()],
            link: '/payments',
        );
    }

    private function money(int $minor, string $currency): string
    {
        return Money::ofMinor($minor, $currency)->format();
    }
}
