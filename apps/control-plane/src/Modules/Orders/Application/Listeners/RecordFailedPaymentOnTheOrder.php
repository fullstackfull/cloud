<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Orders\Application\Actions\TransitionOrder;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Payments\Domain\Events\PaymentFailed;
use Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException;

/**
 * A first purchase's payment was declined, and the order says so (F-19).
 *
 * `payment_failed` was a state with no writer: a declined card left the order
 * reading `pending_payment`, exactly as if nobody had tried. Subscriptions'
 * StartDunningOnFailedPayment hears the same event for renewals and steps over
 * a first purchase, because there is no recurring commitment to put into
 * dunning; this is the other half, for the order.
 *
 * It records and does nothing else. The invoice stays open and collectible,
 * the order stays cancellable, and a later attempt — or the same one
 * completing after a 3-D Secure challenge — settles it, which the order state
 * machine allows from `payment_failed` straight to `paid`.
 *
 * Only an order still waiting for its money moves. A failure event is often
 * late: for an earlier attempt on an intent that has since succeeded, or
 * simply out of order behind the capture. An order that is already paid,
 * cancelled or anywhere else is left alone, and the check is repeated under
 * the order's row lock by TransitionOrder, where a capture that won the race
 * is reported as an illegal move and stepped over here rather than retried.
 */
final class RecordFailedPaymentOnTheOrder implements ShouldQueue
{
    public string $queue = 'payments';

    public int $tries = 5;

    /**
     * The payments queue's ladder (F-08): five tries are five attempts only if
     * there is a wait between them.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 60, 300];
    }

    public function __construct(
        private readonly TransitionOrder $transition,
    ) {}

    public function handle(PaymentFailed $event): void
    {
        if ($event->invoiceId === null) {
            return;
        }

        $orderId = Invoice::query()->whereKey($event->invoiceId)->value('order_id');

        if ($orderId === null) {
            // A renewal, a plan change or a domain: no order to tell.
            return;
        }

        $order = Order::query()->find($orderId);

        if ($order === null || $order->status !== OrderStatus::PendingPayment) {
            return;
        }

        try {
            $this->transition->execute(
                $order,
                OrderStatus::PaymentFailed,
                actorType: 'system',
                reason: sprintf('payment %s was declined%s', $event->providerReference, $event->failureCode === null ? '' : ' ('.$event->failureCode.')'),
                context: ['transaction_id' => $event->transactionId],
            );
        } catch (IllegalStateTransitionException $e) {
            Log::info('A declined payment arrived for an order that had already moved on; nothing to record.', [
                'order_id' => (string) $order->getKey(),
                'transaction_id' => $event->transactionId,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
