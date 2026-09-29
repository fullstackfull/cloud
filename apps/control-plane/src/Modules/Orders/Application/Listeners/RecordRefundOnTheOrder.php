<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Domain\Events\InvoiceRefunded;
use Lynomia\Modules\Orders\Application\Actions\TransitionOrder;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Domain\StateMachines\OrderStateMachine;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException;

/**
 * An order whose invoice was refunded in full says so (F-19).
 *
 * `refunded` had no writer: a purchase whose money had all gone back still
 * read `paid`, and the plan-stock and coupon rules that named it could never
 * see one.
 *
 * **It records the money and nothing else** — the product decision. The
 * service keeps running, and terminating it stays a separate act somebody
 * takes. What the order holds — the plan unit, an unredeemed coupon use — is
 * given back when that service ends, not now: PlanCapacity keeps counting a
 * refunded order for as long as anything it bought is still live, because the
 * alternative, measured, was the last unit of a finite plan sold to a second
 * customer while the first one's machine was still running.
 *
 * Heard from InvoiceRefunded rather than from RefundIssued, because "refunded
 * in full" is the invoice's judgement — it knows what it took and what has gone
 * back across every refund — and it announces it once, on the transition. The
 * order moves only where its state machine lets it; a refund of an order in the
 * middle of a build, or already ended, is recorded on the invoice and left off
 * the order rather than forced onto it.
 */
final class RecordRefundOnTheOrder implements ShouldQueue
{
    public string $queue = 'payments';

    public int $tries = 5;

    /**
     * The payments queue's ladder (F-08).
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 60, 300];
    }

    public function __construct(
        private readonly TransitionOrder $transition,
        private readonly OrderStateMachine $states,
    ) {}

    public function handle(InvoiceRefunded $event): void
    {
        if ($event->orderId === null) {
            return;
        }

        $order = Order::query()->find($event->orderId);

        if ($order === null) {
            return;
        }

        if (! $this->states->canTransition($order->status, OrderStatus::Refunded)) {
            Log::info('An invoice was refunded in full for an order that cannot record it from where it stands.', [
                'order_id' => (string) $order->getKey(),
                'invoice_id' => $event->invoiceId,
                'status' => $order->status->value,
            ]);

            return;
        }

        try {
            $this->transition->execute(
                $order,
                OrderStatus::Refunded,
                actorType: 'system',
                reason: sprintf('invoice %s was refunded in full', $event->invoiceId),
            );
        } catch (IllegalStateTransitionException $e) {
            Log::info('An order moved on before its refund could be recorded on it.', [
                'order_id' => (string) $order->getKey(),
                'invoice_id' => $event->invoiceId,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
