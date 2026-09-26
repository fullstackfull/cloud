<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Application\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Payments\Domain\DTOs\ProviderEvent;
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Payments\Domain\Events\RefundIssued;
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
 *    money moved, and the capture is refundable again;
 *  - still pending, or an event that names no refund: nothing.
 *
 * Only a pending row moves. A row already final is left as it is: a
 * redelivered or out-of-order event cannot undo a refund, or announce one
 * twice. The row is locked while it is decided, which is also what the two
 * copies of a redelivered event serialise on.
 *
 * A refund event naming a refund this platform has no row for (one made in
 * the provider's own dashboard, or one whose answer IssueRefund has not yet
 * written) is logged and acknowledged. It is not retried: nothing here can
 * make that row appear.
 */
final readonly class SettleRefundFromProvider
{
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
                Log::warning('A provider reported a refund this platform has no record of; nothing was changed.', [
                    'provider' => $provider,
                    'refund_reference' => $event->refundReference,
                    'provider_event_id' => $event->providerEventId,
                ]);

                return null;
            }

            /** @var Transaction $capture */
            $capture = $refund->transaction()->firstOrFail();

            if ($refund->status !== RefundStatus::Pending || $status === RefundStatus::Pending) {
                return $capture;
            }

            $refund->fill([
                'status' => $status,
                'processed_at' => $status === RefundStatus::Succeeded ? now() : null,
            ])->save();

            if ($status !== RefundStatus::Succeeded) {
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
}
