<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Application\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Audit\Application\Actions\RecordAuditEntry;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Payments\Domain\DTOs\ProviderEvent;
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Payments\Domain\Events\RefundIssued;
use Lynomia\Modules\Payments\Domain\Exceptions\RefundNotYetRecordedException;
use Lynomia\Modules\Payments\Infrastructure\Models\Refund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Moves a refund the provider answered `pending` to where the provider says it
 * now stands.
 *
 * IssueRefund writes the refund row, asks the provider, and records the
 * answer. When the answer is final it announces RefundIssued and the invoice
 * books it (RecordRefundAgainstTheInvoice). When it is `pending` - Stripe
 * answers so for refunds it has accepted and not yet settled, and maps any
 * status it does not know to it - nothing ever moved the row again: the
 * adapter recognised `refund.updated` and IngestWebhookEvent acknowledged it
 * and did nothing. The refund stayed pending for ever, reserving the money
 * against the capture and never booked on the invoice.
 *
 * So the refund's own webhook is read here. The event names the refund (the
 * provider's id for it, which IssueRefund stored as the row's
 * provider_reference) and where it stands (ProviderEvent::$refundStatus, as
 * the adapter maps it - the same mapping it applies to its own answer):
 *
 *  - succeeded: the row is settled and RefundIssued announced after the commit,
 *    exactly as IssueRefund announces a refund answered succeeded, so the
 *    invoice books it the same way;
 *  - failed or cancelled: the row is closed and its reservation released - no
 *    money moved, and the capture is refundable again. When the invoice it was
 *    against has meanwhile been withdrawn (void: an upgrade that lapsed, the
 *    open invoice of a subscription that ended), the withdrawal left this
 *    money to the refund; with the refund gone, what the void invoice holds
 *    again goes to the wallet (ReturnWhatAnInvoiceStillHolds), under the lock
 *    order refund, invoice, wallet. Otherwise a lapse during a pending refund
 *    that then failed left 5.005 on a void invoice, credited nowhere
 *    (verifier's n1_pending_refund_lapse_then_refund_fails);
 *  - still pending, or an event that names no refund: nothing.
 *
 * A pending row moves as above. A row already final is left as it is - a
 * redelivered or out-of-order event cannot announce a refund twice, or turn a
 * failed one into a success - with one exception: a refund booked as
 * succeeded that the provider now reports failed or cancelled. The Stripe
 * adapter maps a `refund.updated` / `charge.refund.updated` so, whatever the
 * refund said before (whether Stripe sends that for a refund it reported
 * succeeded is not confirmed against the real provider; the adapter maps it,
 * so it is acted on). It used to be dropped here silently: the platform went
 * on saying the money was returned while the provider had put it back
 * (OA-4, round four's re-audit). It is reversed instead
 * (reverseASucceededRefund()): the row takes the reported status, it is
 * un-booked from its invoice (amount_refunded; the capture's refunded figure
 * is read from its rows), so WhatAnInvoiceStillHolds holds it again; a void or
 * refunded invoice's holding goes to the wallet
 * (ReturnToTheWalletWhatAFailedRefundLeft::returnWhatAReversedRefundLeft()),
 * a standing invoice is left refundable; and it is audited
 * (`payment.refund_reversed`) and logged. A booking of it heard later books
 * nothing (RecordInvoiceRefund books only a refund standing as succeeded).
 * Stripe's separate `refund.failed` event is not mapped: the adapter's
 * declared contract names `refund.updated` and `charge.refund.updated`, and
 * nothing in it names `refund.failed`.
 *
 * The row is locked while it is decided, which is also what the two copies of
 * a redelivered event serialise on; the invoice after it (refund, invoice,
 * wallet - WhatAnInvoiceStillHolds).
 *
 * A refund event naming a refund no row carries yet is answered retryably
 * (RefundNotYetRecordedException, 503) and the webhook row left unsettled:
 * IssueRefund stores the provider's reference only once the provider has
 * answered, so an event quicker than that answer used to be acknowledged and
 * lost, and the row stayed pending for ever. The provider's redelivery finds
 * the row. A refund made in the provider's own dashboard gets the same answer
 * until the provider stops retrying; every attempt is on the webhook row.
 */
final readonly class SettleRefundFromProvider
{
    public function __construct(
        private ReturnToTheWalletWhatAFailedRefundLeft $withdrawn,
        private RecordAuditEntry $audit,
    ) {}

    /**
     * @return Transaction|null the capture the refund was against, when a refund of this platform's was found
     */
    public function execute(string $provider, ProviderEvent $event): ?Transaction
    {
        if ($event->refundReference === null || $event->refundStatus === null) {
            return null;
        }

        $status = $event->refundStatus;

        return DB::transaction(function () use ($provider, $event, $status): ?Transaction {
            /** @var Refund|null $refund */
            $refund = Refund::query()
                ->where('provider_reference', $event->refundReference)
                ->whereHas('transaction', static fn ($capture) => $capture->where('provider', $provider))
                ->lockForUpdate()
                ->first();

            if ($refund === null) {
                Log::warning('A provider reported a refund no refund row carries yet; answered for a redelivery.', [
                    'provider' => $provider,
                    'refund_reference' => $event->refundReference,
                    'provider_event_id' => $event->providerEventId,
                ]);

                throw RefundNotYetRecordedException::forReference($provider, (string) $event->refundReference);
            }

            /** @var Transaction $capture */
            $capture = $refund->transaction()->firstOrFail();

            if ($refund->status === RefundStatus::Succeeded
                && ($status === RefundStatus::Failed || $status === RefundStatus::Cancelled)) {
                $this->reverseASucceededRefund($provider, $event, $refund, $capture, $status);

                return $capture;
            }

            if ($refund->status !== RefundStatus::Pending || $status === RefundStatus::Pending) {
                return $capture;
            }

            $refund->fill([
                'status' => $status,
                'processed_at' => $status === RefundStatus::Succeeded ? now() : null,
            ])->save();

            if ($status !== RefundStatus::Succeeded) {
                $this->withdrawn->returnWhatAVoidInvoiceHolds($refund, $capture);

                return $capture;
            }

            $issued = new RefundIssued(
                refundId: (string) $refund->getKey(),
                transactionId: (string) $capture->getKey(),
                customerId: (string) $capture->customer_id,
                invoiceId: $refund->invoice_id === null ? null : (string) $refund->invoice_id,
                provider: $provider,
                amount: Money::ofMinor($refund->amount_minor, $refund->currency),
                remainingRefundable: $capture->refundableAmount(),
                reason: (string) $refund->reason,
                issuedByUserId: $refund->issued_by_user_id === null ? null : (string) $refund->issued_by_user_id,
                issuedAt: CarbonImmutable::now(),
            );

            // After the commit, for the rule SettleInvoice follows for
            // InvoicePaid: a queued listener must not find a refund whose
            // settlement is about to roll back.
            DB::afterCommit(static function () use ($issued): void {
                event($issued);
            });

            return $capture;
        });
    }

    /**
     * Un-books a refund the provider reversed after reporting it succeeded.
     * Runs inside execute()'s transaction, under the refund's lock.
     */
    private function reverseASucceededRefund(
        string $provider,
        ProviderEvent $event,
        Refund $refund,
        Transaction $capture,
        RefundStatus $status,
    ): void {
        $unbookedMinor = 0;

        if ($refund->recorded_on_invoice_at !== null && $refund->invoice_id !== null) {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($refund->invoice_id);

            $unbookedMinor = min($refund->amount_minor, (int) $invoice->amount_refunded_minor);
            $invoice->amount_refunded_minor = (int) $invoice->amount_refunded_minor - $unbookedMinor;
            $invoice->save();
        }

        $refund->fill([
            'status' => $status,
            // No longer counted on the invoice; invoice_id still says whose it was.
            'recorded_on_invoice_at' => null,
        ])->save();

        $returnedMinor = $this->withdrawn->returnWhatAReversedRefundLeft($refund, $capture);

        $context = [
            'provider' => $provider,
            'provider_event_id' => $event->providerEventId,
            'refund_reference' => $event->refundReference,
            'reported_status' => $status->value,
            'amount_minor' => $refund->amount_minor,
            'currency' => $refund->currency,
            'invoice_id' => $refund->invoice_id === null ? null : (string) $refund->invoice_id,
            'unbooked_from_invoice_minor' => $unbookedMinor,
            'returned_to_wallet_minor' => $returnedMinor,
        ];

        $this->audit->execute(
            action: AuditAction::RefundReversedByProvider,
            subject: $refund,
            customerId: (string) $capture->customer_id,
            context: $context,
        );

        Log::warning('A payment provider reversed a refund it had reported succeeded; it was un-booked and the money is held again.', [
            'refund_id' => (string) $refund->getKey(),
            ...$context,
        ]);
    }
}
